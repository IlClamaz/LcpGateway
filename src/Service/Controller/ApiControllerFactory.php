<?php
namespace LcpGateway\Service\Controller;

use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use LcpGateway\Controller\ApiController;

class ApiControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
    {
        $treeExtractor = $container->get('LcpGateway\Service\TreeExtractor');
        return new ApiController($treeExtractor);
    }
}
