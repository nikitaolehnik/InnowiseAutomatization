<?php

namespace App\Services;

use App\Domain\Enums\MessageCommandsEnum;
use JetBrains\PhpStorm\ArrayShape;

class ParseService
{
    const BOT_NAME = '@PHP Bot';
    private array $command;
    private string $space;
    private string $thread;
    private array $chatInfo;
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
                    'command' => 'ERROR',
                    'description' => $this->command[0],
                    'space' => $this->space,
                    'thread' => $this->thread,
                ],
            };
        }

        return [
            'command' => 'error',
        ];
    }

    #[ArrayShape(['command' => 'string', 'requestName' => 'string', 'cvList' => 'array', 'clientName' => 'string', 'requestDescription' => 'string', 'flags' => 'array'])]
    private function parsePreparationCommand(): array
    {
        $flags = $this->getCommandFlags();
        $requestNameBlock = preg_split('/(\r\n|\n|\r){2}/', $this->chatInfo['text']);
        $requestName = preg_split('/\n/', $requestNameBlock[1]);
        $cvList = preg_split('/CV\s\d+:\s/', $this->command[1], -1, PREG_SPLIT_NO_EMPTY);
        $clientName = explode('-', $requestNameBlock[0]);

        $requestDescriptionFull = '';
        for ($i = 2; $i < count($requestNameBlock) || (isset($requestNameBlock[$i]) && $requestNameBlock[$i] === '@all'); $i++) {
            if ($requestNameBlock[$i] === '@all') {
                break;
            }

            $requestDescriptionFull .= $requestNameBlock[$i] . "\n";
        }

        $lines = explode("\n", $requestDescriptionFull);
        $processedLines = array_map(function($line) {
            if (preg_match('/^\d+\./', $line)) {
                return "*$line*";
            }
            return $line;
        }, $lines);
        $requestDescription = implode("\n", $processedLines);

        return [
            'command' => trim($this->command[0]),
            'requestName' => $requestName[0],
            'cvList' => $cvList,
            'clientName' => trim($clientName[3]),
            'requestDescription' => $requestDescription,
            'flags' => $flags,
        ];
    }

    private function parseRequestCommand(): array
    {
        $description = null;
        $data = preg_split('/(\n){2}/', $this->chatInfo['text']);
        $matches = [];
        $requestName = preg_split('/\n/', $data[1]);
        preg_match('/12\..+\n\d{1,2}/', $data[2], $matches[0]);
        preg_match('/14\.(.|\n)+?(\n\*?\d{2}\.)/', $data[2], $matches[1]);
        isset($matches[1][0]) ?: preg_match('/14\.(.|\n)+(\n\*?\d{2}\.)?/', $data[2], $matches[1]);
        $devsAmount = !empty($matches[0][0]) ? trim(mb_substr($matches[0][0], -2, 2)) : null;

        if (isset($matches[1][0])) {
            $description = preg_match('/\d{2}\.$/', $matches[1][0]) ? mb_substr($matches[1][0], 0, -4) : $matches[1][0];
        }

        return [
            'command' => trim($this->command[0]),
            'clientName' => $this->command[1],
            'requestName' => $requestName[0],
            'devsAmount' => $devsAmount ?? null,
            'description' => $description,
            'space' => $this->space,
            'thread' => $this->thread,
        ];
    }

    private function parseInterviewCommand(): array
    {
        $commandInfo = explode(' ', $this->command[1]);
        $firstname = null;
        $dateTime = null;
        $clientName = null;

        switch (count($commandInfo)) {
            case 3:
                $dateTime = $commandInfo[1] . ' ' . $commandInfo[2];
                $clientName = $this->getClientNameFromSpace($this->spaceName);
                break;
            case 4:
                if (preg_match('/:/', $commandInfo[3])) {
                    $firstname = mb_ucfirst($commandInfo[1]);
                    $dateTime = $commandInfo[2] . ' ' . $commandInfo[3];
                    $clientName = $this->getClientNameFromSpace($this->spaceName);
                } else {
                    $dateTime = $commandInfo[1] . ' ' . $commandInfo[2];
                    $clientName = $commandInfo[3];
                }
                break;
            case 5:
                $firstname = mb_ucfirst($commandInfo[1]);
                $dateTime = $commandInfo[2] . ' ' . $commandInfo[3];
                $clientName = $commandInfo[4];
                break;
        }

        return [
            'command' => trim($this->command[0]),
            'clientName' => $clientName,
            'lastNameRu' => mb_ucfirst($commandInfo[0]),
            'firstNameRu' => $firstname,
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

    private function getClientNameFromSpace(string $spaceName): string
    {
        $parts = explode('-', $spaceName, 3);

        return isset($parts[1]) ? trim($parts[1]) : '';
    }

    private function getCommandFlags(): array
    {
        $flags = [];

        $command = preg_replace_callback('/--([A-Z-]+)(?:\s+"([^"]+)")?/', function ($matches) use (&$flags) {
            $flagName = $matches[1];
            $flags[$flagName] = $matches[2] ?? true;
            return '';
        }, $this->command[1]);

        $this->command[1] = trim(preg_replace('/\s+/', ' ', $command));
        return $flags;
    }
}