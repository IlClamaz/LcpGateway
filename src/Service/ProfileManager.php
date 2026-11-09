<?php
namespace LcpGateway\Service;

use Omeka\Settings\Settings;

class ProfileManager
{
    protected Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function getProfiles(): array
    {
        $raw = $this->settings->get('lcp_gateway_profiles');
        if (!$raw) {
            $module = new \LcpGateway\Module();
            $defaults = $module->getDefaultProfiles();
            $this->saveProfiles($defaults);
            return $defaults;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public function saveProfiles(array $profiles): void
    {
        $this->settings->set('lcp_gateway_profiles', json_encode($profiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    public function getProfileById(string $id): ?array
    {
        foreach ($this->getProfiles() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    public function saveProfile(array $profile): void
    {
        $profiles = $this->getProfiles();
        $found = false;
        foreach ($profiles as $k => $p) {
            if ($p['id'] === $profile['id']) {
                $profiles[$k] = $profile;
                $found = true;
                break;
            }
        }
        if (!$found) {
            $profiles[] = $profile;
        }
        $this->saveProfiles($profiles);
    }

    public function deleteProfile(string $id): void
    {
        $profiles = array_filter($this->getProfiles(), fn($p) => $p['id'] !== $id);
        $this->saveProfiles(array_values($profiles));
    }

    /**
     * Resolves the profile based on the item's resource class and requested target context (e.g. 'web', 'engine', 'default').
     * If a specific profile is not found for the class and context, falls back hierarchically to 'default'.
     */
    public function resolveProfile(?string $resourceClassTerm, string $targetProfile = 'default'): array
    {
        $profiles = $this->getProfiles();
        $targetProfile = strtolower(trim($targetProfile ?: 'default'));

        $getContext = function(array $p): string {
            return strtolower(trim((string)($p['context'] ?? $p['profile_target'] ?? 'default')));
        };

        // 1. Exact match on resource class and context
        if ($resourceClassTerm) {
            foreach ($profiles as $p) {
                if ($getContext($p) === $targetProfile) {
                    $pRc = (string)($p['resource_class'] ?? '');
                    if ($pRc !== '' && strcasecmp($pRc, $resourceClassTerm) === 0) {
                        return $p;
                    }
                }
            }

            // 2. Loose match (e.g. "Ambiente" matching "lcp_form:Ambiente")
            $colonPos = strpos($resourceClassTerm, ':');
            $cleanTerm = strtolower($colonPos !== false ? substr($resourceClassTerm, $colonPos + 1) : $resourceClassTerm);
            foreach ($profiles as $p) {
                if ($getContext($p) === $targetProfile) {
                    $pRc = (string)($p['resource_class'] ?? '');
                    if ($pRc !== '') {
                        $pColon = strpos($pRc, ':');
                        $pTerm = strtolower($pColon !== false ? substr($pRc, $pColon + 1) : $pRc);
                        if ($cleanTerm === $pTerm) {
                            return $p;
                        }
                    }
                }
            }
        }

        // 3. Fallback to wildcard '*' for this target context
        foreach ($profiles as $p) {
            $pRc = (string)($p['resource_class'] ?? '');
            if ($pRc === '*' && $getContext($p) === $targetProfile) {
                return $p;
            }
        }

        // 4. Default fallback: search for profile with id 'default' or context 'default'
        foreach ($profiles as $p) {
            $pId = (string)($p['id'] ?? '');
            $pName = (string)($p['name'] ?? '');
            if ($pId === 'default' || $getContext($p) === 'default' || strcasecmp($pName, 'default') === 0) {
                return $p;
            }
        }

        // 5. Ultimate fallback if default profile is missing
        return $profiles[0] ?? [
            'id' => 'default',
            'name' => 'Default',
            'context' => 'default',
            'resource_class' => '*',
            'description' => 'Profilo predefinito di fallback',
            'resolve_nextcloud_webdav' => false,
            'include_events' => false,
            'include_tree' => false,
            'fields' => [
                'dcterms:title' => 'Title',
                'dcterms:description' => 'Description',
            ]
        ];
    }
}
