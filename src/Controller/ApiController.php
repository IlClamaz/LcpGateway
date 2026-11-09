<?php
namespace LcpGateway\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use LcpGateway\Service\TreeExtractor;

class ApiController extends AbstractActionController
{
    protected TreeExtractor $treeExtractor;

    public function __construct(TreeExtractor $treeExtractor)
    {
        $this->treeExtractor = $treeExtractor;
    }

    public function itemAction()
    {
        $response = $this->getResponse();

        // Configure CORS
        $response->getHeaders()->addHeaderLine('Access-Control-Allow-Origin', '*');
        $response->getHeaders()->addHeaderLine('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->getHeaders()->addHeaderLine('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        if ($this->getRequest()->isOptions()) {
            return $response;
        }

        $id = (int)$this->params()->fromRoute('id');
        $profileTarget = $this->params()->fromQuery('profile', 'engine');

        if ($id <= 0) {
            $response->setStatusCode(400);
            $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
            $response->setContent(json_encode([
                'ok' => false,
                'error' => 'Invalid or missing Item ID.'
            ], JSON_PRETTY_PRINT));
            return $response;
        }

        $data = $this->treeExtractor->extract($id, $profileTarget);

        if (empty($data['ok'])) {
            $response->setStatusCode(404);
        }

        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json; charset=utf-8');
        $response->setContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response;
    }
}
