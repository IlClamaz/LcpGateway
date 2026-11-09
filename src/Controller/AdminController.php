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
            'id' => 'custom_' . time(),
            'name' => 'Nuovo Profilo',
            'resource_class' => '*',
            'profile_target' => 'engine',
            'description' => '',
            'resolve_nextcloud_webdav' => true,
            'include_events' => true,
            'include_tree' => true,
            'fields' => [
                'o:id' => 'id',
                'o:title' => 'title',
            ]
        ] : $this->profileManager->getProfileById($id);

        if (!$profile) {
            $this->messenger()->addError("Profilo non trovato: $id");
            return $this->redirect()->toRoute('admin/lcp-gateway');
        }

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $profile['name'] = trim($post['name'] ?? $profile['name']);
            $profile['resource_class'] = trim($post['resource_class'] ?? '*');
            $profile['profile_target'] = strtolower(trim($post['profile_target'] ?? 'engine'));
            $profile['description'] = trim($post['description'] ?? '');
            $profile['resolve_nextcloud_webdav'] = !empty($post['resolve_nextcloud_webdav']);
            $profile['include_events'] = !empty($post['include_events']);
            $profile['include_tree'] = !empty($post['include_tree']);

            // Parse custom fields mapping (textarea with lines "omeka_property => alias")
            $rawFields = $post['raw_fields'] ?? '';
            $fields = [];
            foreach (preg_split('/[\r\n]+/', $rawFields) as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) continue;
                if (str_contains($line, '=>')) {
                    [$k, $v] = explode('=>', $line, 2);
                    $fields[trim($k)] = trim($v);
                } else {
                    $fields[$line] = $line;
                }
            }
            $profile['fields'] = $fields;

            $this->profileManager->saveProfile($profile);
            $this->messenger()->addSuccess("Profilo '{$profile['name']}' salvato con successo.");
            return $this->redirect()->toRoute('admin/lcp-gateway');
        }

        // Fetch available Resource Classes from Omeka S for convenience
        $resourceClasses = [];
        try {
            $rcs = $this->api->search('resource_classes', ['limit' => 200])->getContent();
            foreach ($rcs as $rc) {
                $resourceClasses[] = $rc->term();
            }
        } catch (\Exception $e) {}

        return new ViewModel([
            'profile' => $profile,
            'isNew' => $isNew,
            'resourceClasses' => $resourceClasses,
        ]);
    }

    public function deleteAction()
    {
        $id = $this->params()->fromRoute('id');
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
        $this->messenger()->addSuccess("Profili reimpostati ai valori di default standard LCP.");
        return $this->redirect()->toRoute('admin/lcp-gateway');
    }

    public function previewAction()
    {
        $itemId = (int)$this->params()->fromQuery('item_id', 0);
        $profileTarget = $this->params()->fromQuery('profile', 'engine');
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
