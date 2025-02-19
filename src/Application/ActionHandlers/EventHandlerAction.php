<?php

declare(strict_types=1);

namespace App\Application\ActionHandlers;

use App\Application\Events\AddedToSpaceEvent;
use App\Application\Events\Interfaces\EventInterface;
use App\Application\Events\MessageEvent;
use App\Services\Interfaces\LoggerInterface;
use App\Skeleton\Actions\Action;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google_Service_Calendar;
use Psr\Http\Message\ResponseInterface as Response;
use MongoDB\Client as MongoClient;

class EventHandlerAction extends Action
{
    public function __construct(
        protected LoggerInterface $logger,
        private readonly MongoClient $client,
        private readonly ChatServiceClient $chatServiceClient,
        private readonly Google_Service_Calendar $googleCalendar,
    ) {
        parent::__construct($this->logger);
    }

    protected function action(): Response
    {
        $data = json_decode($this->getFormData(), true);
        $this->logger->log(json_encode($data));
        $this->eventMapping($data['type'])->handle($data);

        return $this->respondNoContent();
    }

    private function eventMapping(string $event): EventInterface
    {
        return match ($event) {
            'MESSAGE' => new MessageEvent($this->client, $this->chatServiceClient, $this->googleCalendar, $this->logger),
            'ADDED_TO_SPACE' => new AddedToSpaceEvent($this->client, $this->chatServiceClient, $this->googleCalendar, $this->logger),
        };
    }
}

