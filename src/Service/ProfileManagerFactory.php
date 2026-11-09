<?php
namespace LcpGateway\Service;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ProfileManagerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $settings = $container->has('Omeka\Settings') 
            ? $container->get('Omeka\Settings') 
            : $container->get('Omeka\Settings\Settings');
            
        return new ProfileManager($settings);
    }
}
