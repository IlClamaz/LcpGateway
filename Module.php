<?php
namespace LcpGateway;

use Omeka\Module\AbstractModule;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function install(ServiceLocatorInterface $services)
    {
        $settings = $services->get('Omeka\Settings');
        if (!$settings->get('lcp_gateway_profiles')) {
            $defaultProfiles = $this->getDefaultProfiles();
            $settings->set('lcp_gateway_profiles', json_encode($defaultProfiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Unconditionally allow LcpGateway controllers in ACL
        $sharedEventManager->attach(
            '*',
            'acl.init',
            function ($event) {
                $acl = $event->getTarget();
                if ($acl instanceof \Laminas\Permissions\Acl\Acl) {
                    $resources = [
                        'LcpGateway\Controller\Admin',
                        'LcpGateway\Controller\Api',
                        Controller\AdminController::class,
                        Controller\ApiController::class,
                        'Admin',
                        'Api',
                    ];
                    foreach ($resources as $res) {
                        if (!$acl->hasResource($res)) {
                            $acl->addResource($res);
                        }
                        $acl->allow(null, $res);
                    }
                }
            }
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $url = $renderer->url('admin/lcp-gateway');
        return sprintf(
            '<div class="field" style="padding: 15px; background: #f9f9f9; border-radius: 4px; margin-bottom: 20px;">' .
            '<h3>LCP Gateway &bull; Configurazione</h3>' .
            '<p>La gestione dei profili di esportazione e i test live per Unity, Godot e Web si trovano nella sezione dedicata:</p>' .
            '<p><a href="%s" class="button button-primary" style="font-weight: bold; padding: 8px 16px;">&rarr; Apri Gestione Profili LCP Gateway</a></p>' .
            '</div>',
            $url
        );
    }

    public function getDefaultProfiles(): array
    {
        return [
            [
                'id' => 'default',
                'name' => 'Default',
                'context' => 'default',
                'profile_target' => 'default',
                'resource_class' => '*',
                'description' => 'Profilo predefinito di fallback: esporta dcterms:title e dcterms:description.',
                'resolve_nextcloud_webdav' => false,
                'include_events' => false,
                'include_tree' => false,
                'fields' => [
                    'dcterms:title' => 'Title',
                    'dcterms:description' => 'Description',
                ]
            ]
        ];
    }
}
