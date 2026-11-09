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
     * Resolves the profile based on the item's resource class and requested target profile (e.g. 'engine' or 'web').
     */
    public function resolveProfile(?string $resourceClassTerm, string $targetProfile): array
    {
        $profiles = $this->getProfiles();
        $targetProfile = strtolower(trim($targetProfile));

        // 1. Exact match on resource class and profile_target
        if ($resourceClassTerm) {
            foreach ($profiles as $p) {
                if (strtolower($p['profile_target']) === $targetProfile) {
                    if (strcasecmp($p['resource_class'], $resourceClassTerm) === 0) {
                        return $p;
                    }
                }
            }

            // 2. Loose match (e.g. "Ambiente" matching "lcp_form:Ambiente")
            $cleanTerm = strtolower(substr($resourceClassTerm, strpos($resourceClassTerm, ':') !== false ? strpos($resourceClassTerm, ':') + 1 : 0));
            foreach ($profiles as $p) {
                if (strtolower($p['profile_target']) === $targetProfile) {
                    $pTerm = strtolower(substr($p['resource_class'], strpos($p['resource_class'], ':') !== false ? strpos($p['resource_class'], ':') + 1 : 0));
                    if ($cleanTerm === $pTerm) {
                        return $p;
                    }
                }
            }
        }

        // 3. Fallback to wildcard '*' for this targetProfile
        foreach ($profiles as $p) {
            if ($p['resource_class'] === '*' && strtolower($p['profile_target']) === $targetProfile) {
                return $p;
            }
        }

        // 4. Default fallback to first available profile
        return $profiles[0] ?? [
            'id' => 'fallback',
            'name' => 'Default Fallback',
            'resource_class' => '*',
            'profile_target' => $targetProfile,
            'resolve_nextcloud_webdav' => ($targetProfile === 'engine'),
            'include_events' => true,
            'include_tree' => true,
            'fields' => [
                'o:id' => 'id',
                'o:title' => 'title',
                'lcp_form:has_URI' => 'media_uri',
            ]
        ];
    }
}
