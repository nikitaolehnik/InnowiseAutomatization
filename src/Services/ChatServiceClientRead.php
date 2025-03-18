<?php

namespace App\Services;

use Exception;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Apps\Chat\V1\ListMessagesRequest;
use Google\Client as GoogleClient;
use Symfony\Component\Dotenv\Dotenv;

class ChatServiceClientRead
{
    private ChatServiceClient $chatServiceClientRead;

    public function __construct()
    {
        if (!getenv('IS_CLOUD_RUN')) {
            $dotenv = new Dotenv();
            $dotenv->load(__DIR__ . '/../../.env');
            putenv("GOOGLE_APPLICATION_CREDENTIALS_2=" . file_get_contents(__DIR__ . '/../../' . 'credentials_2.json'));
        }

        $client = new GoogleClient();
        $client->setClientId($_ENV['GOOGLE_CLIENT_ID_2']);
        $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET_2']);
        $client->setAccessType('offline');
        $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI_2']);

        $accessToken = $client->fetchAccessTokenWithRefreshToken($_ENV['REFRESH_TOKEN_2']);

        if (!isset($accessToken['access_token'])) {
            throw new Exception('Unable to retrieve access token');
        }

        $client->setAccessToken($accessToken['access_token']);

        $this->chatServiceClientRead = new ChatServiceClient([
            'credentials' => json_decode(getenv('GOOGLE_APPLICATION_CREDENTIALS_2'), true)
        ]);
    }

    public function getClient(): ChatServiceClient
    {
        return $this->chatServiceClientRead;
    }

    public function getFirstMessageInThread(string $spaceId, string $threadId): array|null
    {
        $threadName = "spaces/$spaceId/threads/$threadId";
        $spaceName = "spaces/$spaceId";

        $request = new ListMessagesRequest();
        $request->setParent($spaceName);
        $request->setFilter("thread.name=\"$threadName\"");
        $request->setPageSize(1);

        $response = $this->chatServiceClientRead->listMessages($request);

        foreach ($response->iterateAllElements() as $message) {
            return [
                'message_id' => $message->getName(),
                'text' => $message->getText(),
            ];
        }

        return null;
    }
}