<?php
namespace LcpGateway\Service;

use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\ItemRepresentation;

class TreeExtractor
{
    protected ApiManager $api;
    protected ProfileManager $profileManager;

    public function __construct(ApiManager $api, ProfileManager $profileManager)
    {
        $this->api = $api;
        $this->profileManager = $profileManager;
    }

    /**
     * Extracts an item and its associated graph based on the item's resource class and requested target profile context.
     */
    public function extract(int $itemId, string $targetProfile = 'default'): array
    {
        try {
            $rootItem = $this->api->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return [
                'error' => "Item #$itemId not found: " . $e->getMessage(),
            ];
        }

        $resourceClass = $rootItem->resourceClass();
        $classTerm = $resourceClass ? $resourceClass->term() : null;
        $profile = $this->profileManager->resolveProfile($classTerm, $targetProfile);

        // If composite entity (e.g. Environment or include_tree is true)
        if (!empty($profile['include_tree'])) {
            $tree = [];
            $queue = [$itemId];
            $seen = [$itemId => true];

            while (!empty($queue)) {
                $currId = array_shift($queue);
                try {
                    $item = ($currId === $itemId) ? $rootItem : $this->api->read('items', $currId)->getContent();
                } catch (\Exception $e) {
                    continue;
                }

                $cClass = $item->resourceClass();
                $cTerm = $cClass ? $cClass->term() : null;
                // Hierarchically resolve child profile with the same target context, or fallback to default
                $cProfile = ($currId === $itemId) ? $profile : $this->profileManager->resolveProfile($cTerm, $targetProfile);

                $extracted = $this->normalizeItem($item, $cProfile);

                // Discover components and areas
                $componentIds = $this->extractResourceIds($item, 'lcp_form:is_composed_of_f');
                $areaIds = $this->extractResourceIds($item, 'lcp_form:has_participatory_area_f');

                $extracted['components'] = $componentIds;
                $extracted['areas'] = $areaIds;
                $tree[$currId] = $extracted;

                foreach (array_merge($componentIds, $areaIds) as $childId) {
                    if (!isset($seen[$childId])) {
                        $seen[$childId] = true;
                        $queue[] = $childId;
                    }
                }
            }

            $result = [
                'tree' => $tree,
            ];

            // Extract Environment Events if requested
            if (!empty($profile['include_events'])) {
                $result['events'] = $this->extractEventsForEnvironment($itemId);
            }

            return $result;
        }

        // Single Item Extraction (clean output with only configured fields)
        return [
            'item' => $this->normalizeItem($rootItem, $profile),
        ];
    }

    /**
     * Normalizes an ItemRepresentation into clean key-value pairs using native property labels.
     */
    public function normalizeItem(ItemRepresentation $item, array $profile): array
    {
        $fields = $profile['fields'] ?? [];
        $resolveDav = !empty($profile['resolve_nextcloud_webdav']);
        
        $out = [
            'id' => $item->id(),
        ];

        foreach ($fields as $omekaProperty => $fieldLabel) {
            if (empty($fieldLabel)) {
                $fieldLabel = $omekaProperty;
            }

            if ($omekaProperty === 'o:id') {
                $out[$fieldLabel] = $item->id();
                continue;
            }
            if ($omekaProperty === 'o:title') {
                $out[$fieldLabel] = $item->displayTitle();
                continue;
            }

            // Extract property values from Item
            $vals = $item->value($omekaProperty, ['all' => true]);
            if (empty($vals)) {
                if ($omekaProperty === 'dcterms:title') {
                    $out[$fieldLabel] = $item->displayTitle();
                } else {
                    $out[$fieldLabel] = null;
                }
                continue;
            }

            // Nextcloud WebDAV URL resolution if enabled
            if ($resolveDav && (str_ends_with($omekaProperty, 'has_URI') || stripos($fieldLabel, 'uri') !== false || stripos($fieldLabel, 'url') !== false)) {
                $uri = (string)$vals[0]->uri();
                if (empty($uri)) {
                    $uri = (string)$vals[0]->value();
                }
                $out[$fieldLabel] = $this->resolveNextcloudWebDavUrl($uri);
                continue;
            }

            if (count($vals) === 1) {
                $v = $vals[0];
                $out[$fieldLabel] = $v->uri() ?: (string)$v->value();
            } else {
                $arr = [];
                foreach ($vals as $v) {
                    $arr[] = $v->uri() ?: (string)$v->value();
                }
                $out[$fieldLabel] = $arr;
            }
        }

        return $out;
    }

    /**
     * Extracts linked Omeka events for a given environment ID.
     */
    public function extractEventsForEnvironment(int $environmentId): array
    {
        $events = [];
        try {
            $response = $this->api->search('items', [
                'property' => [
                    [
                        'property' => 'lcp_form-event:has_environment',
                        'type' => 'res',
                        'text' => (string)$environmentId
                    ]
                ]
            ]);
            $items = $response->getContent();

            foreach ($items as $evItem) {
                $events[] = [
                    'id' => $evItem->id(),
                    'title' => $evItem->displayTitle(),
                    'environment_id' => $environmentId,
                    'trigger_type' => (string)$evItem->value('lcp_form-event:has_trigger_type_f'),
                    'triggering_item_id' => $this->extractFirstResourceId($evItem, 'lcp_form-event:has_trigger_arg_f'),
                    'preconditions' => $this->extractLiteralList($evItem, 'lcp_form-event:has_trigger_preconditions_f'),
                    'action' => (string)$evItem->value('lcp_form-event:has_action_type_f'),
                    'action_params' => $this->extractResourceIds($evItem, 'lcp_form-event:has_action_params_f'),
                    'effects' => $this->extractLiteralList($evItem, 'lcp_form-event:has_action_effects_f'),
                ];
            }
        } catch (\Exception $e) {
            // Events search failed or predicate not present
        }

        return $events;
    }

    public function resolveNextcloudWebDavUrl(string $url): string
    {
        $url = trim($url);
        if (empty($url)) return '';

        // Match Nextcloud public share URL: https://cloud.org/s/<token>
        if (preg_match('#^(https?://[^/]+)/s/([a-zA-Z0-9]+)(/.*)?$#i', $url, $m)) {
            $baseUrl = $m[1];
            $token = $m[2];
            $davUrl = "$baseUrl/public.php/dav/files/$token";
            if (!empty($m[3])) {
                $sub = ltrim($m[3], '/');
                $davUrl .= '/' . rawurlencode($sub);
            }
            return $davUrl;
        }

        return $url;
    }

    protected function extractResourceIds(ItemRepresentation $item, string $term): array
    {
        $ids = [];
        $vals = $item->value($term, ['all' => true]);
        foreach ($vals as $val) {
            $res = $val->valueResource();
            if ($res) {
                $ids[] = $res->id();
            }
        }
        return $ids;
    }

    protected function extractFirstResourceId(ItemRepresentation $item, string $term): int
    {
        $val = $item->value($term);
        if ($val && $val->valueResource()) {
            return $val->valueResource()->id();
        }
        return 0;
    }

    protected function extractLiteralList(ItemRepresentation $item, string $term): array
    {
        $res = [];
        $vals = $item->value($term, ['all' => true]);
        foreach ($vals as $val) {
            $s = trim((string)$val->value());
            if ($s !== '' && !in_array($s, $res)) {
                $res[] = $s;
            }
        }
        return $res;
    }
}
