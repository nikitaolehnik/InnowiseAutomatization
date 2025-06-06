<?php

namespace App\Application\Events;

use App\Application\Events\Interfaces\EventInterface;
use App\Domain\Enums\MessageCommandsEnum;
use App\Domain\Enums\PreparationFlagsEnum;
use App\Domain\Enums\RoomsEnum;
use App\Services\Interfaces\LoggerInterface;
use App\Services\ParseService;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Apps\Chat\V1\CreateMessageRequest;
use Google\Apps\Chat\V1\ListMembershipsRequest;
use Google\Apps\Chat\V1\Message;
use Google\Apps\Chat\V1\Thread;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_FreeBusyRequest;
use JetBrains\PhpStorm\ArrayShape;
use MongoDB\Client as MongoClient;

class MessageEvent implements EventInterface
{
    const SPACE_NAME = 'AAAASkaq4uc';
    const DATABASE_NAME = 'innowise-automatization';
    const COLLECTION_NAME_DEVS = 'developers';
    const COLLECTION_NAME_CLIENTS = 'clients';
    const COLLECTION_NAME_REQUESTS = 'requests';
    const COLLECTION_NAME_INTERVIEWS = 'interviews';
    const COLLECTION_NAME_PREPARATIONS = 'preparations';

    public function __construct(
        private readonly MongoClient             $client,
        private readonly ChatServiceClient       $chatServiceClient,
        private readonly Google_Service_Calendar $googleCalendar,
        protected LoggerInterface                $logger,
    )
    {
    }

    public function handle(array $event): void
    {
        $command = $this->parseCommand($event);

        if ($command['command'] === MessageCommandsEnum::Preparation->value) {
            $this->preparation($command);

            return;
        }

        if ($command['command'] === MessageCommandsEnum::Request->value) {
            $this->request($command);

            return;
        }

        if ($command['command'] === MessageCommandsEnum::Interview->value) {
            $this->interview($command, $event['space']['displayName']);

            return;
        }

        if ($command['command'] === MessageCommandsEnum::Result->value) {
            $this->result($command);

            return;
        }

        if ($command['command'] === MessageCommandsEnum::Error->value) {
            $text = $command['description'] . ' command not found. Please check your input';
            $this->sendThreadResponse($command, $text);

            return;
        }
    }

    private function parseCommand(array $text): array
    {
        $parseService = new ParseService($text);
        return $parseService->ruleEngine();
    }

    private function getCalendarEvent(array $attendees, array $timeRange, string $meetName, string $description = ""): Google_Service_Calendar_Event
    {
        return new Google_Service_Calendar_Event([
            'summary' => $meetName,
            'start' => [
                'dateTime' => $timeRange['start']->format(DateTimeInterface::RFC3339),
                'timeZone' => 'CET',
            ],
            'end' => [
                'dateTime' => $timeRange['end']->format(DateTimeInterface::RFC3339),
                'timeZone' => 'CET',
            ],
            'attendees' => array_map(fn($email) => ['email' => $email], $attendees),
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => uniqid(),
                    'conferenceSolutionKey' => [
                        'type' => 'hangoutsMeet',
                    ],
                    'status' => [
                        'statusCode' => 'success',
                    ],
                ],
            ],
            'guestsCanModify' => true,
            'description' => $description,
        ]);
    }

    private function getBusySlots(array $attendees): array
    {
        $timeMin = (new DateTime('now', new DateTimeZone('CET')))->format(DateTimeInterface::RFC3339);
        $timeMax = (new DateTime('+7 days', new DateTimeZone('CET')))->format(DateTimeInterface::RFC3339);

        $items = array_map(fn($email) => ['id' => $email], $attendees);

        $events = $this->googleCalendar->freebusy->query(new Google_Service_Calendar_FreeBusyRequest([
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'items' => $items,
        ]));

        return $events->getCalendars();
    }

    #[ArrayShape(['start' => DateTime::class, 'end' => DateTime::class])]
    private function findCommonFreeTime(array $busySlots, string $workHoursStart = "08:00", string $workHoursEnd = "17:00"): ?array
    {
        $currentTime = $this->roundToNearestHalfHour(new DateTime('now', new DateTimeZone('CET')));
        $endTimeToday = new DateTime("today $workHoursEnd", new DateTimeZone('CET'));

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $day = new DateTime("+$dayOffset days", new DateTimeZone('CET'));
            if ($day->format('N') >= 6) {
                continue;
            }

            if ($dayOffset === 0 && $currentTime < $endTimeToday) {
                $start = clone $currentTime;
            } else {
                $start = new DateTime("+$dayOffset days", new DateTimeZone('CET'));
                list($hour, $minute) = explode(':', $workHoursStart);
                $start->setTime((int)$hour, (int)$minute);
            }

            $end = new DateTime("+$dayOffset days", new DateTimeZone('CET'));
            list($hourEnd, $minuteEnd) = explode(':', $workHoursEnd);
            $end->setTime((int)$hourEnd, (int)$minuteEnd);

            while ($start < $end) {
                $slotEnd = clone $start;
                $slotEnd->modify('+15 minutes');

                if ($slotEnd < $currentTime) {
                    $start->modify('+15 minutes');
                    continue;
                }

                $conflict = false;
                foreach ($busySlots as $busySlot) {
                    foreach ($busySlot->getBusy() as $busy) {
                        $busyStart = new DateTime($busy['start']);
                        $busyEnd = new DateTime($busy['end']);
                        if ($slotEnd > $busyStart && $start < $busyEnd) {
                            $conflict = true;
                            break;
                        }
                    }
                    if ($conflict) {
                        break;
                    }
                }

                if (!$conflict) {
                    return ['start' => clone $start, 'end' => clone $slotEnd];
                }

                $start->modify('+15 minutes');
            }
        }

        return null;
    }

    private function roundToNearestHalfHour(DateTime $time): DateTime
    {
        $minutes = (int)$time->format('i');

        if ($minutes < 30) {
            $time->setTime((int)$time->format('H'), 30);
        } else {
            $time->modify('+1 hour')->setTime((int)$time->format('H'), 0);
        }

        return $time;
    }

    private function getFreeRoom(array $timeRange): string|null
    {
        $start = new DateTime($timeRange['start']->format('r'), new DateTimeZone('CET'));
        $end = new DateTime($timeRange['end']->format('r'), new DateTimeZone('CET'));

        foreach (RoomsEnum::cases() as $case) {
            $freeBusyRequest = new Google_Service_Calendar_FreeBusyRequest([
                'timeMin' => $start->format(DateTimeInterface::RFC3339),
                'timeMax' => $end->format(DateTimeInterface::RFC3339),
                'items' => [
                    ['id' => $case->value]
                ]
            ]);

            $response = $this->googleCalendar->freebusy->query($freeBusyRequest);
            $calendars = $response->getCalendars();

            if (isset($calendars[$case->value])) {
                $busySlots = $calendars[$case->value]->getBusy();

                if (empty($busySlots)) {
                    return $case->value;
                }
            }
        }

        return null;
    }

    private function sendThreadResponse(array $data, string $text): void
    {
        if ($data['space']) {
            $thread = new Thread();
            $thread->setName("spaces/{$data['space']}/threads/{$data['thread']}");

            $message = new Message();
            $message->setText($text)
                ->setThread($thread);

            $request = (new CreateMessageRequest())
                ->setParent(ChatServiceClient::spaceName($data['space']))
                ->setMessageReplyOption(CreateMessageRequest\MessageReplyOption::REPLY_MESSAGE_OR_FAIL)
                ->setMessage($message);

            $this->chatServiceClient->createMessage($request);
        }
    }

    private function preparation(array $command): void
    {
        $candidates = [];

        foreach ($command['cvList'] as $candidate) {
            $pairs = explode(', ', $candidate);
            $result = [];

            foreach ($pairs as $pair) {
                list($key, $value) = explode(' - ', $pair);
                $result[$key] = $value;
            }

            $candidates[] = $result;
        }

        $attendees = ['php-preparations@innowise.com'];
        $mList = [];
        $candidateList = [];

        foreach ($candidates as $candidate) {
            list($firstName, $lastName) = explode(' ', $candidate['candidate_name']);

            $data = $this->client->selectDatabase(self::DATABASE_NAME)
                ->selectCollection(self::COLLECTION_NAME_DEVS)
                ->aggregate([
                    [
                        '$search' => [
                            'index' => 'devs',
                            'compound' => [
                                'must' => [
                                    [
                                        'text' => [
                                            'query' => $firstName,
                                            'path' => 'name.first_name_ru',
                                            'fuzzy' => [
                                                'maxEdits' => 1
                                            ]
                                        ]
                                    ],
                                    [
                                        'text' => [
                                            'query' => $lastName,
                                            'path' => 'name.last_name_ru',
                                            'fuzzy' => [
                                                'maxEdits' => 1
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        '$lookup' => [
                            'from' => self::COLLECTION_NAME_DEVS,
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

            if (!isset($data[0])) {
                $this->logger->error("Candidate {$candidate['candidate_name']} is missing, inserting new document in DB.");
                $data[0]['name']['first_name_ru'] = $firstName;
                $data[0]['name']['last_name_ru'] = $lastName;

                $this->client->selectDatabase(self::DATABASE_NAME)
                    ->selectCollection(self::COLLECTION_NAME_DEVS)
                    ->insertOne([
                        'name' => [
                            'first_name_ru' => $firstName,
                            'last_name_ru' => $lastName
                        ]
                    ]);
            }

            $this->client->selectDatabase(self::DATABASE_NAME)
                ->selectCollection(self::COLLECTION_NAME_PREPARATIONS)
                ->insertOne([
                    'request_name' => $command['requestName'],
                    'client_name' => $command['clientName'],
                    'dev' => "{$data[0]['name']['first_name_ru']} {$data[0]['name']['last_name_ru']}",
                    'cv' => $candidate['link'],
                ]);

            $candidateList[] = [
                'name' => "{$data[0]['name']['first_name_ru']} {$data[0]['name']['last_name_ru']}",
                'link' => $candidate['link'],
            ];

            if (isset($data[0]->email)) {
                $attendees[] = $data[0]->email;
            }

            $membersRequest = (new ListMembershipsRequest())
                ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                ->setPageSize(1000)
                ->setFilter("member.type = \"HUMAN\"");
            $members = $this->chatServiceClient->listMemberships($membersRequest);
            $mNames = '';

            if (isset($data[0]['M'])) {
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

                    $mList[] = (is_null($id) ? "{$m['name']['first_name_en']} {$m['name']['last_name_en']}" : ("<users/$id>"));
                    $mNames .= "{$m['name']['first_name_en']} {$m['name']['last_name_en']}, ";
                    $attendees[] = $m['email'];
                }
            }

            $mNames = rtrim($mNames, ', ');

            if (!isset($data[0]['space'])) {
                continue;
            }

            $message = new Message();
            $message->setText("You have been send to a new request. Here is your CV: {$candidate['link']}. If you don't have access to it, please contact $mNames")
                ->setThreadReply(true);

            $request = (new CreateMessageRequest())
                ->setParent(ChatServiceClient::spaceName($data[0]['space']))
                ->setMessage($message);

            $this->chatServiceClient->createMessage($request);
        }

        $message = new Message();
        $mString = join(', ', array_unique($mList));
        $candidateString = join(', ', array_map(fn($candidate) => $candidate['name'], $candidateList));

        $text = "*{$command['requestName']}* \n👥: $candidateString\nⓂ️: $mString";
        if (isset($command['flags'][PreparationFlagsEnum::COMMENT->value])) {
            $text .= "\n*Additional info:* " . $command['flags'][PreparationFlagsEnum::COMMENT->value];
        }

        $message->setText($text)
            ->setThreadReply(true);

        $request = (new CreateMessageRequest())
            ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
            ->setMessage($message);

        $response = $this->chatServiceClient->createMessage($request);

        $this->sendMessageToThread($response, $command['requestDescription']);

        foreach ($candidateList as $candidate) {
            $this->sendMessageToThread($response, "CV {$candidate['name']} {$candidate['link']}");
        }

        if (isset($command['flags'][PreparationFlagsEnum::NOSYNC->value]) && $command['flags'][PreparationFlagsEnum::NOSYNC->value]) {
            return;
        }

        $attendees = array_values(array_unique($attendees));
        $busySlots = $this->getBusySlots($attendees);
        $timeRange = $this->findCommonFreeTime($busySlots);

        if (is_null($timeRange)) {
            return;
        }

        $description = join(PHP_EOL, $command['cvList']);

        if (isset($command['flags'][PreparationFlagsEnum::COMMENT->value])) {
            $description = "Additional info: " . $command['flags'][PreparationFlagsEnum::COMMENT->value] . PHP_EOL . $description;
        }

        $meetName = 'Request sync ' . $command['clientName'];
        $calendarEvent = $this->getCalendarEvent($attendees, $timeRange, $meetName, $description);
        $this->googleCalendar->events->insert('primary', $calendarEvent, ['conferenceDataVersion' => 1]);
    }

    private function request($command): void
    {
        $pipeline = [
            [
                '$search' => [
                    'index' => 'client',
                    'compound' => [
                        'must' => [
                            [
                                'text' => [
                                    'query' => $command['clientName'],
                                    'path' => 'name',
                                    'fuzzy' => [
                                        'maxEdits' => 1
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ],
            [
                '$limit' => 1
            ]
        ];

        $cursor = $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_CLIENTS)
            ->aggregate($pipeline);

        $document = current(iterator_to_array($cursor));

        if (isset($document['name'])) {
            $command['clientName'] = $document['name'];
        }

        if (!$document) {
            $this->client->selectDatabase(self::DATABASE_NAME)
                ->selectCollection(self::COLLECTION_NAME_CLIENTS)
                ->insertOne([
                    'name' => $command['clientName']
                ]);
        }

        $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_REQUESTS)
            ->insertOne([
                'name' => $command['requestName'],
                'description' => $command['description'],
                'devs_amount' => $command['devsAmount'],
                'client' => $command['clientName'],
            ]);

        $preparations = $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_PREPARATIONS)
            ->find(['client_name' => $command['clientName']])->toArray();

        $interviews = $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_INTERVIEWS)
            ->find(['client' => $command['clientName']])->toArray();
        $text = null;

        if (!empty($preparations) || !empty($interviews)) {
            $text = $command['clientName'] . ". The candidates we sent:\n";

            if (!empty($preparations)) {
                $text .= "\n*Preparations:*\n";

                foreach ($preparations as $key => $preparation) {
                    $key += 1;
                    $text .= "$key. " . $preparation['dev'] . "\n" . $preparation['cv'] . "\n";
                }
            }

            if (!empty($interviews)) {
                $text .= "\n*Interviews:*\n";

                foreach ($interviews as $key => $interview) {
                    $key += 1;
                    $text .= "$key. " . $interview['dev'] . "\n";
                }
            }
        }

        $this->sendThreadResponse($command, $text);
    }

    private function interview($command, $spaceName): void
    {
        $matchFilter = [
            'name.last_name_ru' => $command['lastNameRu']
        ];

        if (!empty($command['firstNameRu'])) {
            $matchFilter['name.first_name_ru'] = $command['firstNameRu'];
        }

        $cursor = $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_DEVS)
            ->aggregate([
                ['$match' => $matchFilter],
                ['$lookup' => [
                    'from' => self::COLLECTION_NAME_DEVS,
                    'localField' => 'M',
                    'foreignField' => '_id',
                    'as' => 'M_objects'
                ]]
            ]);

        $data = iterator_to_array($cursor);

        if (!isset($data[0])) {
            $this->logger->error("Candidate {$command['lastNameRu']} is missing in DB!");

            return;
        }

        $attendees[] = $data[0]->email;
        $attendees[] = 'php-interviews@innowise.com';
        $attendees[] = 'dmitry.coolgun@innowise.com';
        $attendees[] = 'mikita.shyrayeu@innowise.com';
        $spaces[] = $data[0]->space;

        foreach ($data[0]['M_objects'] as $m) {
            $attendees[] = $m['email'];
            $spaces[] = $m['space'] ?? null;
        }

        $timeStart = (DateTime::createFromFormat('d.m H:i', $command['dateTime'], new DateTimeZone('CET')));
        $timeEnd = clone $timeStart;
        $timeRange = [
            'start' => $timeStart->modify('-15 minutes'),
            'end' => $timeEnd->modify('+1 hours'),
        ];

        $attendees[] = $this->getFreeRoom($timeRange);
        $meetName = $data[0]['name']['last_name_en'] . '. Support. ' . $command['clientName'];
        $calendarEvent = $this->getCalendarEvent($attendees, $timeRange, $meetName);
        $this->googleCalendar->events->insert('primary', $calendarEvent, ['conferenceDataVersion' => 1]);

        $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_INTERVIEWS)
            ->insertOne([
                'dev' => $data[0]['name']['last_name_ru'],
                'client' => $command['clientName'],
                'request' => $spaceName,
            ]);

        $start = $timeRange['start']->format('r');
        foreach ($spaces as $space) {
            if ($space) {
                $message = new Message();
                $message->setText("You have an appointment. Support meeting is scheduled for $start")
                    ->setThreadReply(true);

                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName($space))
                    ->setMessage($message);

                $this->chatServiceClient->createMessage($request);
            }
        }
    }

    private function result($command): void
    {
        $this->client->selectDatabase(self::DATABASE_NAME)
            ->selectCollection(self::COLLECTION_NAME_INTERVIEWS)
            ->updateOne(
                [
                    '$and' => [
                        ['dev' => $command['lastNameRu']],
                        ['request' => $command['spaceName']],
                        [
                            '$or' => [
                                ['result' => ['$exists' => false]],
                                ['result' => '']
                            ]
                        ]
                    ]
                ],
                [
                    '$set' => [
                        'result' => $command['result']
                    ],
                    '$setOnInsert' => [
                        'dev' => $command['lastNameRu'],
                        'client' => $command['clientName'],
                        'request' => $command['spaceName'],
                    ]
                ],
                [
                    'upsert' => true
                ]
            );
    }
    private function sendMessageToThread(Message $response, string $message): void
    {
        $thread = new Thread();
        $thread->setName($response->getThread()->getName());

        $threadMessage = new Message();
        $threadMessage->setText($message)
            ->setThread($thread);

        $request = (new CreateMessageRequest())
            ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
            ->setMessageReplyOption(CreateMessageRequest\MessageReplyOption::REPLY_MESSAGE_OR_FAIL)
            ->setMessage($threadMessage);

        $this->chatServiceClient->createMessage($request);
    }
}
