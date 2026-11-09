<?php
namespace LcpGateway\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use LcpGateway\Controller\AdminController;

class AdminControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $profileManager = $container->get('LcpGateway\Service\ProfileManager');
        $treeExtractor = $container->get('LcpGateway\Service\TreeExtractor');
        $api = $container->has('Omeka\ApiManager') 
            ? $container->get('Omeka\ApiManager') 
            : $container->get('Omeka\Api\Manager');
            
        return new AdminController($profileManager, $treeExtractor, $api);
    }
}
