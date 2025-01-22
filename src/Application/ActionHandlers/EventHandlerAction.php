<?php

declare(strict_types=1);

namespace App\Application\ActionHandlers;

use App\Services\Interfaces\LoggerInterface;
use App\Skeleton\Actions\Action;
use Psr\Http\Message\ResponseInterface as Response;

//use MongoDB\Client as MongoClient;

class EventHandlerAction extends Action
{
    public function __construct(
        protected LoggerInterface $logger
    ) {
        parent::__construct($this->logger);
    }

    protected function action(): Response
    {
        $this->logger->log($this->getFormData());
        $this->logger->log($this->request->getBody()->getContents());

        return $this->respondNoContent();
    }
}

