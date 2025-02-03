<?php

namespace App\Application\Events;

use App\Application\Events\Interfaces\EventInterface;
use App\Domain\Commands\MessageCommandsEnum;
use App\Services\Interfaces\LoggerInterface;
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
    const BOT_NAME = '@PHP Bot';
    const SPACE_NAME = 'AAAASkaq4uc';
    const DATABASE_NAME = 'innowise-automatization';
    const COLLECTION_NAME = 'developers';

    public function __construct(
        private readonly MongoClient       $client,
        private readonly ChatServiceClient $chatServiceClient,
        private readonly Google_Service_Calendar $googleCalendar,
        protected LoggerInterface $logger,
    ) {
    }

    public function handle(array $event): void
    {
        $command = $this->parseCommand($event['message']['text']);

        if (MessageCommandsEnum::from($command['command']) === MessageCommandsEnum::Preparation) {
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

                if (!isset($data[0])) {
                    $this->logger->error("Candidate {$candidate['candidate_name']} is missing in DB!");
                    continue;
                }

                $candidateList[] = [
                    'name' => "{$data[0]['name']['first_name_en']} {$data[0]['name']['last_name_en']}",
                    'link' => $candidate['link'],
                ];

                $attendees[] = $data[0]->email;

                $membersRequest = (new ListMembershipsRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setPageSize(1000)
                    ->setFilter("member.type = \"HUMAN\"");
                $members = $this->chatServiceClient->listMemberships($membersRequest);
                $mNames = '';

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
            $candidateString = join(', ' ,array_map(fn($candidate) => $candidate['name'], $candidateList));

            $message->setText("*{$command['requestName']}* \n👥: $candidateString\nⓂ️: $mString")
                ->setThreadReply(true);

            $request = (new CreateMessageRequest())
                ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                ->setMessage($message);

            $response = $this->chatServiceClient->createMessage($request);

            foreach ($candidateList as $candidate) {
                $thread = new Thread();
                $thread->setName($response->getThread()->getName());

                $threadMessage = new Message();
                $threadMessage->setText("CV {$candidate['name']} {$candidate['link']}")
                    ->setThread($thread);

                $request = (new CreateMessageRequest())
                    ->setParent(ChatServiceClient::spaceName(self::SPACE_NAME))
                    ->setMessageReplyOption(CreateMessageRequest\MessageReplyOption::REPLY_MESSAGE_OR_FAIL)
                    ->setMessage($threadMessage);

                $this->chatServiceClient->createMessage($request);
            }

            $attendees = array_values(array_unique($attendees));
            $busySlots = $this->getBusySlots($attendees);
            $timeRange = $this->findCommonFreeTime($busySlots);

            if (is_null($timeRange)) {
                return;
            }

            $calendarEvent = $this->getCalendarEvent($attendees, $timeRange);
            $r = $this->googleCalendar->events->insert('primary', $calendarEvent, ['conferenceDataVersion' => 1]);
        }
    }

    #[ArrayShape(['command' => 'string', 'cvList' => 'array', 'requestName' => 'string'])]
    private function parseCommand(string $text): array
    {
        $command = explode(' ', mb_substr($text, strlen(self::BOT_NAME . ' '), -1), 2);
        $requestName = preg_split('/CV\s\d+:\s/', $command[1], 2);
        $cvList = preg_split('/CV\s\d+:\s/', $requestName[1], -1, PREG_SPLIT_NO_EMPTY);

        return [
            'command' => $command[0],
            'requestName' => trim($requestName[0]),
            'cvList' => $cvList
        ];
    }

    private function getCalendarEvent(array $attendees, array $timeRange): Google_Service_Calendar_Event
    {
        return new Google_Service_Calendar_Event([
            'summary' => 'Request sync',
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
    private function findCommonFreeTime(array $busySlots, string $workHoursStart = "09:00", string $workHoursEnd = "18:00"): ?array
    {
        $currentTime = $this->roundToNearestHalfHour(new DateTime('now', new DateTimeZone('CET')));
        $endTimeToday = new DateTime("today $workHoursEnd", new DateTimeZone('CET'));

        for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
            $start = ($dayOffset === 0 && $currentTime < $endTimeToday) ? clone $currentTime : new DateTime("+$dayOffset days $workHoursStart", new DateTimeZone('CET'));
            $end = new DateTime("+$dayOffset days $workHoursEnd", new DateTimeZone('CET'));

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
                    if ($conflict) break;
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
        $minutes = (int) $time->format('i');

        if ($minutes < 30) {
            $time->setTime((int) $time->format('H'), 30);
        } else {
            $time->modify('+1 hour')->setTime((int) $time->format('H'), 0);
        }

        return $time;
    }
}
