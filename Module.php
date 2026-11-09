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
            // AMBIENTE -> ENGINE
            [
                'id' => 'env_engine',
                'name' => 'Environment (Engine Offline)',
                'resource_class' => 'lcp_form:Ambiente',
                'profile_target' => 'engine',
                'description' => 'Estratto per Unity/Godot: albero, componenti, aree, eventi e link download media.',
                'resolve_nextcloud_webdav' => true,
                'include_events' => true,
                'include_tree' => true,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'lcp_form:has_participatory_item_type_f' => 'participatory_type',
                    'lcp_form:has_short_text_f' => 'short_description',
                    'lcp_form:has_long_text_f' => 'long_description',
                    'lcp_form:has_catalogue_text_f' => 'catalog_description',
                    'lcp_form:has_URI' => 'media_uri',
                ]
            ],
            // AMBIENTE -> WEB
            [
                'id' => 'env_web',
                'name' => 'Environment (Web Landing / Catalog)',
                'resource_class' => 'lcp_form:Ambiente',
                'profile_target' => 'web',
                'description' => 'Estratto per Web: titoli, autori, descrizioni complete e URL streaming.',
                'resolve_nextcloud_webdav' => false,
                'include_events' => false,
                'include_tree' => true,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'dcterms:creator' => 'author',
                    'dcterms:date' => 'date',
                    'dcterms:description' => 'description',
                    'lcp_form:has_short_text_f' => 'short_description',
                    'lcp_form:has_long_text_f' => 'long_description',
                    'lcp_form:has_catalogue_text_f' => 'catalog_description',
                    'lcp_form:has_URI' => 'stream_url',
                ]
            ],
            // NARRATIVA -> ENGINE
            [
                'id' => 'narrative_engine',
                'name' => 'Narrative (Engine)',
                'resource_class' => 'lcp_form:Narrativa',
                'profile_target' => 'engine',
                'description' => 'Dati per motore di gioco narrativo: nodi, trigger, variabili.',
                'resolve_nextcloud_webdav' => true,
                'include_events' => true,
                'include_tree' => true,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'lcp_form:has_participatory_item_type_f' => 'participatory_type',
                    'lcp_form:has_short_text_f' => 'short_description',
                    'lcp_form:has_URI' => 'media_uri',
                ]
            ],
            // NARRATIVA -> WEB
            [
                'id' => 'narrative_web',
                'name' => 'Narrative (Web)',
                'resource_class' => 'lcp_form:Narrativa',
                'profile_target' => 'web',
                'description' => 'Testi e narrazioni per consultazione web.',
                'resolve_nextcloud_webdav' => false,
                'include_events' => false,
                'include_tree' => true,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'dcterms:creator' => 'author',
                    'dcterms:description' => 'description',
                    'lcp_form:has_long_text_f' => 'content',
                ]
            ],
            // DEFAULT FALLBACK -> ENGINE
            [
                'id' => 'generic_engine',
                'name' => 'Default (Engine)',
                'resource_class' => '*',
                'profile_target' => 'engine',
                'description' => 'Fallback per qualsiasi oggetto richiesto in modalità engine.',
                'resolve_nextcloud_webdav' => true,
                'include_events' => false,
                'include_tree' => false,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'lcp_form:has_participatory_item_type_f' => 'participatory_type',
                    'lcp_form:has_URI' => 'media_uri',
                ]
            ],
            // DEFAULT FALLBACK -> WEB
            [
                'id' => 'generic_web',
                'name' => 'Default (Web)',
                'resource_class' => '*',
                'profile_target' => 'web',
                'description' => 'Fallback per qualsiasi oggetto richiesto in modalità web.',
                'resolve_nextcloud_webdav' => false,
                'include_events' => false,
                'include_tree' => false,
                'fields' => [
                    'o:id' => 'id',
                    'o:title' => 'title',
                    'dcterms:creator' => 'author',
                    'dcterms:date' => 'date',
                    'dcterms:description' => 'description',
                    'lcp_form:has_URI' => 'stream_url',
                ]
            ]
        ];
    }
}
