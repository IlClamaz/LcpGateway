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
     * Extracts an item and its associated graph as a nested JSON-LD document.
     */
    public function extract(int $itemId, string $targetProfile = 'default'): array
    {
        try {
            $rootItem = $this->api->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return [
                '@context' => 'https://schema.org',
                'error' => "Item #$itemId not found: " . $e->getMessage(),
            ];
        }

        $visited = [];
        return $this->extractItemJsonLd($rootItem, $targetProfile, $visited, true);
    }

    /**
     * Recursively extracts an ItemRepresentation into a nested JSON-LD node.
     */
    public function extractItemJsonLd(ItemRepresentation $item, string $targetProfile, array &$visited, bool $isRoot = false): array
    {
        $id = $item->id();
        if (isset($visited[$id])) {
            return [
                '@id' => $item->apiUrl(),
                'id' => $id,
            ];
        }
        $visited[$id] = true;

        $rc = $item->resourceClass();
        $classTerm = $rc ? $rc->term() : null;
        $profile = $this->profileManager->resolveProfile($classTerm, $targetProfile);

        $node = [];

        // In JSON-LD, @context is placed at root of the document
        if ($isRoot) {
            $parsedHost = parse_url($item->apiUrl(), PHP_URL_HOST);
            $parsedScheme = parse_url($item->apiUrl(), PHP_URL_SCHEME) ?: 'https';
            $parsedPort = parse_url($item->apiUrl(), PHP_URL_PORT);
            $baseUrl = $parsedScheme . '://' . $parsedHost . ($parsedPort ? ':' . $parsedPort : '');
            $node['@context'] = $baseUrl . '/api-context';
        }

        $node['@id'] = $item->apiUrl();

        $types = ['o:Item'];
        if ($classTerm) {
            $types[] = $classTerm;
        }
        $node['@type'] = $types;
        $node['id'] = $id;

        $fields = $profile['fields'] ?? [];
        $resolveDav = !empty($profile['resolve_nextcloud_webdav']);
        $includeTree = !empty($profile['include_tree']);

        $componentIds = [];
        $areaIds = [];

        foreach ($fields as $omekaProperty => $fieldLabel) {
            if (empty($fieldLabel)) {
                $fieldLabel = $omekaProperty;
            }

            if ($omekaProperty === 'o:id') {
                continue;
            }
            if ($omekaProperty === 'o:title') {
                $node[$fieldLabel] = $item->displayTitle();
                continue;
            }

            // Extract property values from Item
            $vals = $item->value($omekaProperty, ['all' => true]);
            if (empty($vals)) {
                if ($omekaProperty === 'dcterms:title') {
                    $node[$fieldLabel] = $item->displayTitle();
                } else {
                    $node[$fieldLabel] = null;
                }
                continue;
            }

            $extractedVals = [];
            $isRelationProperty = str_ends_with($omekaProperty, 'has_participatory_area_f') || str_ends_with($omekaProperty, 'is_composed_of_f');

            foreach ($vals as $v) {
                // 1. Resource reference (linked Item)
                $res = $v->valueResource();
                if ($res) {
                    if (str_ends_with($omekaProperty, 'has_participatory_area_f')) {
                        $areaIds[] = $res->id();
                    }
                    if (str_ends_with($omekaProperty, 'is_composed_of_f')) {
                        $componentIds[] = $res->id();
                    }

                    if ($includeTree) {
                        $childItem = null;
                        if ($res instanceof ItemRepresentation) {
                            $childItem = $res;
                        } else {
                            try {
                                $childItem = $this->api->read('items', $res->id())->getContent();
                            } catch (\Exception $e) {
                                $childItem = null;
                            }
                        }

                        if ($childItem) {
                            // Recursively extract nested child JSON-LD node
                            $extractedVals[] = $this->extractItemJsonLd($childItem, $targetProfile, $visited, false);
                        } else {
                            $extractedVals[] = [
                                '@id' => $res->apiUrl(),
                                'id' => $res->id(),
                            ];
                        }
                    } else {
                        // Leaf/flat reference by ID
                        $extractedVals[] = $res->id();
                    }
                    continue;
                }

                // 2. URI value
                $uri = $v->uri();
                if (!empty($uri)) {
                    if ($resolveDav) {
                        $extractedVals[] = $this->resolveNextcloudWebDavUrl($uri);
                    } else {
                        $extractedVals[] = $uri;
                    }
                    continue;
                }

                // 3. Literal / Custom vocabulary / Text value
                $strVal = (string)$v->value();
                if ($resolveDav && (str_ends_with($omekaProperty, 'has_URI') || stripos($fieldLabel, 'uri') !== false || stripos($fieldLabel, 'url') !== false)) {
                    $extractedVals[] = $this->resolveNextcloudWebDavUrl($strVal);
                } else {
                    $extractedVals[] = $strVal;
                }
            }

            // Keep relation collections as arrays even if count is 1
            if ($isRelationProperty) {
                $node[$fieldLabel] = $extractedVals;
            } elseif (count($extractedVals) === 1) {
                $node[$fieldLabel] = $extractedVals[0];
            } else {
                $node[$fieldLabel] = $extractedVals;
            }
        }

        if (!empty($componentIds) || !empty($areaIds) || $includeTree) {
            $node['components'] = array_values(array_unique($componentIds));
            $node['areas'] = array_values(array_unique($areaIds));
        }

        // Attach Environment Events if requested on root
        if ($isRoot && !empty($profile['include_events'])) {
            $events = $this->extractEventsForEnvironment($id);
            foreach ($events as &$ev) {
                $ev = array_merge([
                    '@type' => 'lcp_form-event:Event',
                ], $ev);
            }
            $node['events'] = $events;
        }

        return $node;
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
                    '@id' => $evItem->apiUrl(),
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
