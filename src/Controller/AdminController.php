<?php
namespace LcpGateway\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use LcpGateway\Service\ProfileManager;
use LcpGateway\Service\TreeExtractor;
use Omeka\Api\Manager as ApiManager;

class AdminController extends AbstractActionController
{
    protected ProfileManager $profileManager;
    protected TreeExtractor $treeExtractor;
    protected ApiManager $api;

    public function __construct(ProfileManager $profileManager, TreeExtractor $treeExtractor, ApiManager $api)
    {
        $this->profileManager = $profileManager;
        $this->treeExtractor = $treeExtractor;
        $this->api = $api;
    }

    public function indexAction()
    {
        return new ViewModel([
            'profiles' => $this->profileManager->getProfiles(),
        ]);
    }

    public function editAction()
    {
        $id = $this->params()->fromRoute('id');
        $isNew = empty($id);
        
        $profile = $isNew ? [
            'id' => '',
            'name' => '',
            'context' => '',
            'resource_class' => '',
            'description' => '',
            'resolve_nextcloud_webdav' => false,
            'include_events' => false,
            'include_tree' => false,
            'fields' => [],
        ] : $this->profileManager->getProfileById($id);

        if (!$profile) {
            $this->messenger()->addError("Profilo non trovato: $id");
            return $this->redirect()->toRoute('admin/lcp-gateway');
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $name = trim($post['name'] ?? '');
            $context = strtolower(trim($post['context'] ?? 'default'));
            $resourceClass = trim($post['resource_class'] ?? '*');
            $description = trim($post['description'] ?? '');
            $resolveWebDav = !empty($post['resolve_nextcloud_webdav']);
            $includeEvents = !empty($post['include_events']);
            $includeTree = !empty($post['include_tree']);

            // Parse selected fields
            $fields = [];
            if (!empty($post['fields_json'])) {
                $decoded = json_decode($post['fields_json'], true);
                if (is_array($decoded)) {
                    $fields = $decoded;
                }
            } elseif (!empty($post['field_terms']) && is_array($post['field_terms'])) {
                foreach ($post['field_terms'] as $idx => $term) {
                    $term = trim($term);
                    $label = trim($post['field_labels'][$idx] ?? $term);
                    if ($term !== '') {
                        $fields[$term] = $label;
                    }
                }
            }

            if ($isNew) {
                $cleanContext = preg_replace('/[^a-z0-9]+/', '_', strtolower($context ?: 'custom'));
                $cleanRc = preg_replace('/[^a-z0-9]+/', '_', strtolower(str_replace([':', '/'], '_', $resourceClass)));
                $baseId = trim($cleanContext . '_' . $cleanRc, '_');
                if (empty($baseId)) {
                    $baseId = 'profile_' . time();
                }
                $candidate = $baseId;
                $counter = 1;
                while ($this->profileManager->getProfileById($candidate)) {
                    $candidate = $baseId . '_' . (++$counter);
                }
                $profile['id'] = $candidate;
            }

            $profile['name'] = $name ?: ('Profilo ' . $context);
            $profile['context'] = $context;
            $profile['profile_target'] = $context; // Keep backwards compatibility
            $profile['resource_class'] = $resourceClass;
            $profile['description'] = $description;
            $profile['resolve_nextcloud_webdav'] = $resolveWebDav;
            $profile['include_events'] = $includeEvents;
            $profile['include_tree'] = $includeTree;
            $profile['fields'] = $fields;

            $this->profileManager->saveProfile($profile);
            $this->messenger()->addSuccess("Profilo '{$profile['name']}' salvato con successo.");
            return $this->redirect()->toRoute('admin/lcp-gateway');
        }

        $apiErrors = [];

        // Fetch available Resource Classes from Omeka S (limit > 0 to retrieve content)
        $resourceClasses = [];
        try {
            $rcs = $this->api->search('resource_classes', ['limit' => 1000])->getContent();
            foreach ($rcs as $rc) {
                $resourceClasses[] = [
                    'id' => $rc->id(),
                    'term' => $rc->term(),
                    'label' => $rc->label() ?: $rc->term(),
                ];
            }
            usort($resourceClasses, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        } catch (\Throwable $e) {
            $apiErrors[] = 'Resource Classes: ' . $e->getMessage();
        }

        // Fetch available Resource Templates and their associated properties
        $resourceTemplates = [];
        try {
            $rts = $this->api->search('resource_templates', ['limit' => 500])->getContent();
            foreach ($rts as $rt) {
                $rc = $rt->resourceClass();
                $props = [];
                foreach ($rt->resourceTemplateProperties() as $rtp) {
                    $prop = $rtp->property();
                    if ($prop) {
                        $props[] = [
                            'term' => $prop->term(),
                            'label' => $prop->label() ?: $prop->term(),
                        ];
                    }
                }
                $resourceTemplates[] = [
                    'id' => $rt->id(),
                    'label' => $rt->label(),
                    'resource_class_id' => $rc ? $rc->id() : null,
                    'resource_class_term' => $rc ? $rc->term() : null,
                    'properties' => $props,
                ];
            }
        } catch (\Throwable $e) {
            $apiErrors[] = 'Resource Templates: ' . $e->getMessage();
        }

        // Fetch all Properties for live search
        $allProperties = [];
        try {
            $props = $this->api->search('properties', ['limit' => 5000])->getContent();
            foreach ($props as $p) {
                $vocab = $p->vocabulary();
                $allProperties[] = [
                    'id' => $p->id(),
                    'term' => $p->term(),
                    'label' => $p->label() ?: $p->term(),
                    'vocab' => $vocab ? $vocab->label() : '',
                    'vocab_prefix' => $vocab ? $vocab->prefix() : '',
                ];
            }
            usort($allProperties, fn($a, $b) => strcasecmp($a['label'], $b['label']));
        } catch (\Throwable $e) {
            $apiErrors[] = 'Properties: ' . $e->getMessage();
        }

        return new ViewModel([
            'profile' => $profile,
            'isNew' => $isNew,
            'resourceClasses' => $resourceClasses,
            'resourceTemplates' => $resourceTemplates,
            'allProperties' => $allProperties,
            'apiErrors' => $apiErrors,
        ]);
    }

    public function deleteAction()
    {
        $id = $this->params()->fromRoute('id');
        if ($id === 'default') {
            $this->messenger()->addError("Il profilo 'default' è il fallback di sistema e non può essere eliminato.");
            return $this->redirect()->toRoute('admin/lcp-gateway');
        }

        if ($id) {
            $this->profileManager->deleteProfile($id);
            $this->messenger()->addSuccess("Profilo rimosso.");
        }
        return $this->redirect()->toRoute('admin/lcp-gateway');
    }

    public function resetDefaultsAction()
    {
        $module = new \LcpGateway\Module();
        $this->profileManager->saveProfiles($module->getDefaultProfiles());
        $this->messenger()->addSuccess("Profili reimpostati al profilo standard 'default'.");
        return $this->redirect()->toRoute('admin/lcp-gateway');
    }

    public function previewAction()
    {
        $itemId = (int)$this->params()->fromQuery('item_id', 0);
        $profileTarget = $this->params()->fromQuery('profile', 'default');
        $outputJson = '';

        if ($itemId > 0) {
            $data = $this->treeExtractor->extract($itemId, $profileTarget);
            $outputJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return new ViewModel([
            'itemId' => $itemId,
            'profileTarget' => $profileTarget,
            'outputJson' => $outputJson,
            'profiles' => $this->profileManager->getProfiles(),
        ]);
    }
}
