<?php
namespace LcpGateway\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class TreeExtractorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $api = $container->has('Omeka\ApiManager') 
            ? $container->get('Omeka\ApiManager') 
            : $container->get('Omeka\Api\Manager');
            
        $profileManager = $container->get('LcpGateway\Service\ProfileManager');
        return new TreeExtractor($api, $profileManager);
    }
}
