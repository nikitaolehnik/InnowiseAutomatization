<?php

namespace App\Application\Events;

use App\Application\Events\Interfaces\EventInterface;
use App\Domain\Commands\MessageCommandsEnum;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Apps\Chat\V1\CreateMessageRequest;
use Google\Apps\Chat\V1\ListMembershipsRequest;
use Google\Apps\Chat\V1\Message;
use Google\Apps\Chat\V1\Thread;
use JetBrains\PhpStorm\ArrayShape;
use MongoDB\Client as MongoClient;

class MessageEvent implements EventInterface
{
    const BOT_NAME = '@Bitrix24 CRM Helper';
    const SPACE_NAME = 'AAAAWaLPzII';
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

                $membersRequest = (new ListMembershipsRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setPageSize(1000)
                    ->setFilter("member.type = \"HUMAN\"");
                $members = $this->chatServiceClient->listMemberships($membersRequest);

                $mString = '';
                foreach ($data[0]['M'] as $m) {
                    $iterator = $members->getIterator();

                    $id = null;
                    while (($current = $iterator->current()) !== null) {
                        if ($current->getMember()->getDisplayName() !== "{$m['name']['first_name_en']} {$m['name']['last_name_en']}") {
                            $iterator->next();
                            continue;
                        }

                        $id = explode('/', $current->getMember()->getName())[1];
                        $iterator->next();
                    }

                    $mString .= (is_null($id) ? "{$m['name']['first_name_en']} {$m['name']['last_name_en']}" : ("<users/$id>")) . ', ';
                }

                $mString = rtrim($mString, ', ');
                $message = new Message();
                $message->setText("CV - X - X \n👥: {$data[0]['name']['last_name_en']}\nⓂ️: $mString")
                    ->setThreadReply(true);

                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setMessage($message);

                $response = $this->chatServiceClient->createMessage($request);

                $thread = new Thread();
                $thread->setName($response->getThread()->getName());

                $threadMessage = new Message();
                $threadMessage->setText("CV {$candidate['link']}")
                    ->setThread($thread);

                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setMessageReplyOption(CreateMessageRequest\MessageReplyOption::REPLY_MESSAGE_OR_FAIL)
                    ->setMessage($threadMessage);

                $this->chatServiceClient->createMessage($request);

                if (!isset($data[0]['space'])) {
                    return;
                }

                $message = new Message();
                $message->setText("You have been send to a new request: XX. Here is your CV: {$candidate['link']}. If you don't have access to it, please contact your M1/M2")
                    ->setThreadReply(true);

                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName($data[0]['space']))
                    ->setMessage($message);

                $this->chatServiceClient->createMessage($request);
            }
        }
    }

    #[ArrayShape(['command' => 'string', 'message' => 'string'])]
    private function parseCommand(string $text): array
    {
        $command = explode(' ', mb_ltrim($text, self::BOT_NAME . ' '), 2);

        return [
            'command' => $command[0],
            'message' => $command[1]
        ];
    }
}