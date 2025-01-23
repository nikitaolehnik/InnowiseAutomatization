<?php

namespace App\Application\Events;

use App\Application\Events\Interfaces\EventInterface;
use App\Domain\Commands\MessageCommandsEnum;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Apps\Chat\V1\CreateMessageRequest;
use Google\Apps\Chat\V1\Message;
use MongoDB\Client as MongoClient;

class MessageEvent implements EventInterface
{
    const BOT_NAME = '@Bitrix24 CRM Helper';
    const SPACE_NAME = 'AAAAWaLPzII';
    const DATABASE_NAME = 'innowise-automatization';
    const COLLECTION_NAME = 'developers';

    public function __construct(
        private readonly MongoClient $client,
        private readonly ChatServiceClient $chatServiceClient,
    ) {
    }

    public function handle(array $event): array
    {
        $command = $this->parseCommand($event['message']['text']);

        if (MessageCommandsEnum::from($command['command']) === MessageCommandsEnum::Preparation) {
            $candidates = [];

            $candidateStrings = preg_split('/CV\s\d+:\s/', $command['message'], -1, PREG_SPLIT_NO_EMPTY);

            foreach ($candidateStrings as $candidate) {
                $pairs = explode(', ', $candidate);
                $result = [];

                foreach ($pairs as $pair) {
                    list($key, $value) = explode(' - ', $pair);
                    $result[$key] = $value;
                }

                $candidates[] = $result;
            }

            foreach ($candidates as $candidate) {
                list($firstName, $lastName) = explode(' ', $candidate['candidate_name']);
                $data = $this->client->selectDatabase(self::DATABASE_NAME)
                    ->selectCollection(self::COLLECTION_NAME)
                    ->aggregate([
                        [
                            '$match' => [
                                '$and' => [[
                                    'name.first_name_ru' => $firstName,
                                    'name.last_name_ru' => $lastName,
                                ]]
                            ]
                        ],
                        [
                            '$lookup' => [
                                'from' => self::COLLECTION_NAME,
                                'localField' => 'M',
                                'foreignField' => '_id',
                                'as' => 'M_objects'
                            ]
                        ],
                        [
                            '$addFields' => [
                                'M' => '$M_objects'
                            ]
                        ],
                        [
                            '$project' => [
                                'M_objects' => 0
                            ]
                        ]
                    ])
                    ->toArray();

                $mString = '';
                foreach ($data[0]['M'] as $m) {
                    $mString .= $m['name']['last_name_en'] . ', ';
                }

                $mString = rtrim($mString, ', ');
                $message = new Message();
                $message->setText("CV - X - X \n👥: {$data[0]['name']['last_name_en']}\nⓂ️: $mString");
                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setMessage($message);

                $response = $this->chatServiceClient->createMessage($request);
            }
        }

        return [];
    }

    private function parseCommand(string $text): array
    {
        $command = explode(' ', mb_ltrim($text, self::BOT_NAME . ' '), 2);

        return [
            'command' => $command[0],
            'message' => $command[1]
        ];
    }
}