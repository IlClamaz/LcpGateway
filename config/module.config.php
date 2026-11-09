<?php
namespace LcpGateway;

return [
    'controllers' => [
        'factories' => [
            'LcpGateway\Controller\Admin' => Service\Controller\AdminControllerFactory::class,
            'LcpGateway\Controller\Api' => Service\Controller\ApiControllerFactory::class,
            Controller\AdminController::class => Service\Controller\AdminControllerFactory::class,
            Controller\ApiController::class => Service\Controller\ApiControllerFactory::class,
            'Admin' => Service\Controller\AdminControllerFactory::class,
            'Api' => Service\Controller\ApiControllerFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            'LcpGateway\Service\ProfileManager' => Service\ProfileManagerFactory::class,
            'LcpGateway\Service\TreeExtractor' => Service\TreeExtractorFactory::class,
            Service\ProfileManager::class => Service\ProfileManagerFactory::class,
            Service\TreeExtractor::class => Service\TreeExtractorFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'api' => [
                'child_routes' => [
                    'lcp' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/lcp/:id',
                            'constraints' => [
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'LcpGateway\Controller',
                                'controller' => 'Api',
                                'action' => 'item',
                            ],
                        ],
                    ],
                ],
            ],
            // Top-level fallback API route in case /api child routing is intercepted
            'lcp-direct-api' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '/api/lcp/:id',
                    'constraints' => [
                        'id' => '\d+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'LcpGateway\Controller',
                        'controller' => 'Api',
                        'action' => 'item',
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'lcp-gateway' => [
                        'type' => 'Segment',
                        'options' => [
                            'route' => '/lcp-gateway[/:action[/:id]]',
                            'constraints' => [
                                'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                'id' => '[a-zA-Z0-9_-]+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'LcpGateway\Controller',
                                'controller' => 'Admin',
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'acl' => [
        'resources' => [
            'LcpGateway\Controller\Admin',
            'LcpGateway\Controller\Api',
        ],
        'rules' => [
            [
                'type' => 'allow',
                'roles' => ['global_admin', 'site_admin', 'editor', 'author', 'reviewer'],
                'resources' => ['LcpGateway\Controller\Admin'],
            ],
            [
                'type' => 'allow',
                'resources' => ['LcpGateway\Controller\Api'],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'LCP Gateway',
                'route' => 'admin/lcp-gateway',
                'class' => 'lcp-gateway-nav',
            ],
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
];
