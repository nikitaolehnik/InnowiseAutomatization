<?php

namespace App\Application\Events;

use App\Application\Events\Interfaces\EventInterface;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Apps\Chat\V1\CreateMessageRequest;
use Google\Apps\Chat\V1\Message;
use MongoDB\Client as MongoClient;

class AddedToSpaceEvent implements EventInterface
{
    const DATABASE_NAME = 'innowise-automatization';
    const COLLECTION_NAME = 'developers';

    public function __construct(
        private readonly MongoClient       $client,
        private readonly ChatServiceClient $chatServiceClient,
    )
    {
    }

    public function handle(array $event): void
    {
        if ($event['space']['spaceType'] !== 'DIRECT_MESSAGE') {
            return;
        }

        $spaceId = explode('/', $event['space']['name'])[1];
        list($firstName, $lastName) = explode(" ", $event['user']['displayName']);
        $user = $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME)
            ->findOneAndUpdate([
                "name.first_name_en" => $firstName,
                "name.last_name_en" => $lastName
            ], [
                '$set' => [
                    "space" => $spaceId
                ]
            ]);

        if (is_null($user)) {
            return;
        }

        $message = new Message();
        $message->setText("Configuration completed!")
            ->setThreadReply(true);

        $request = (new CreateMessageRequest())
            ->setParent($event['space']['name'])
            ->setMessage($message);

        $this->chatServiceClient->createMessage($request);
    }
}