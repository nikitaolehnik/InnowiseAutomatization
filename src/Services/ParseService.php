<?php

namespace App\Services;

use App\Domain\Enums\MessageCommandsEnum;

class ParseService
{
    const BOT_NAME = '@PHP Bot';
    private array $command;
    private string $space;
    private string $thread;
    private  array $chatInfo;
    private string $spaceName;

    public function __construct(array $text)
    {
        if (isset($text['message']['text'])) {
            $this->command = explode(' ', mb_substr($text['message']['text'], strlen(self::BOT_NAME . ' ')), 2);
            $chat = explode('/', $text['message']['thread']['name']);
            $this->space = $chat[1];
            $this->thread = $chat[3];
            $chatServiceRead = new ChatServiceClientRead();
            $this->chatInfo = $chatServiceRead->getFirstMessageInThread($this->space, $this->thread);
            $this->spaceName = $text['space']['displayName'];
        }
    }

    public function ruleEngine(): array
    {
        if (isset($this->command)) {
            return match ($this->command[0]) {
                MessageCommandsEnum::Preparation->value => $this->parsePreparationCommand(),
                MessageCommandsEnum::Request->value => $this->parseRequestCommand(),
                MessageCommandsEnum::Interview->value => $this->parseInterviewCommand(),
                MessageCommandsEnum::Result->value => $this->parseResultCommand(),
                default => [
                    'command' => 'error',
                    'description' => $this->command[0],
                ],
            };
        }

        return [
            'command' => 'error',
        ];
    }

    private function parsePreparationCommand(): array
    {
        $requestNameBlock = preg_split('/(\r\n|\n|\r){2}/', $this->chatInfo['text']);
        $requestName = preg_split('/\n/', $requestNameBlock[1]);
        $cvList = preg_split('/CV\s\d+:\s/', $this->command[1], -1, PREG_SPLIT_NO_EMPTY);
        $clientName = explode('-', $requestNameBlock[0]);

        return [
            'command' => trim($this->command[0]),
            'requestName' => $requestName[0],
            'cvList' => $cvList,
            'clientName' => trim($clientName[3]),
        ];
    }

    private function parseRequestCommand(): array
    {
        $data = preg_split('/(\r\n|\n|\r){2}/', $this->chatInfo['text']);
        $matches = [];
        $requestName = preg_split('/\n/', $data[1]);
        preg_match('/12\..+\n\d{1,2}/', $data[2], $matches[0]);
        preg_match('/14\.(.|\n)+?(\n\d{2}\.)/', $data[2], $matches[1]);
        $devsAmount = !empty($matches[0][0]) ? trim(mb_substr($matches[0][0], -2, 2)) : null;
        $description = preg_match('/\d{2}\.$/', $matches[1][0]) ? mb_substr($matches[1][0], 0, -4) : null;

        return [
            'command' => trim($this->command[0]),
            'clientName' => $this->command[1],
            'requestName' => $requestName[0],
            'devsAmount' => $devsAmount ?? null,
            'description' => $description,
        ];
    }

    private function parseInterviewCommand(): array
    {
        $commandInfo = explode(' ', $this->command[1]);
        $dateTime = $commandInfo[1] . ' ' . $commandInfo[2];
        $clientName = $commandInfo[3] ?? trim(explode('-', $this->spaceName, 3)[1]);

        return [
            'command' => trim($this->command[0]),
            'clientName' => $clientName,
            'lastNameRu' => $commandInfo[0],
            'dateTime' => $dateTime,
        ];
    }

    private function parseResultCommand(): array
    {
        $commandInfo = explode(' ', $this->command[1], 2);
        $clientName = explode('-', $this->spaceName, 3);

        return [
            'command' => trim($this->command[0]),
            'clientName' => trim($clientName[1]),
            'lastNameRu' => $commandInfo[0],
            'result' => $commandInfo[1],
            'spaceName' => $this->spaceName,
        ];
    }
}