<?php

declare(strict_types=1);

use App\Services\Interfaces\LoggerInterface;
use App\Services\LoggerService;
use DI\ContainerBuilder;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
use Google\Client as GoogleClient;
use MongoDB\Client as MongoClient;
use MongoDB\Driver\ServerApi;
use Psr\Container\ContainerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function () {
            return new LoggerService();
        },
        MongoClient::class => function (ContainerInterface $c) {
            $apiVersion = new ServerApi((string)ServerApi::V1);

            return new MongoClient($_ENV['MONGO_CONNECTION_STRING'], [], ['serverApi' => $apiVersion]);
        },
        ChatServiceClient::class => function (ContainerInterface $c) {
            return new ChatServiceClient([
                'credentials' => json_decode(getenv('GOOGLE_APPLICATION_CREDENTIALS'), true)
            ]);
        },
        Google_Service_Calendar::class => function () {
            $client = new GoogleClient();
// TODO: Replace access token with another auth method
//            $client->setAuthConfig(json_decode(getenv('GOOGLE_APPLICATION_CREDENTIALS'), true));
//            $client->addScope(
//                scope_or_scopes: Google_Service_Calendar::CALENDAR
//            );
            $client->setClientId($_ENV['GOOGLE_CLIENT_ID']);
            $client->setClientSecret($_ENV['GOOGLE_CLIENT_SECRET']);
            $client->setRedirectUri($_ENV['GOOGLE_REDIRECT_URI']);
            $client->setAccessType('offline');
            $client->refreshToken($_ENV['REFRESH_TOKEN']);

            if ($client->isAccessTokenExpired()) {
                $accessToken = $client->fetchAccessTokenWithRefreshToken($_ENV['REFRESH_TOKEN']);
                $client->setAccessToken($accessToken);
            }

            return new Google_Service_Calendar(
                clientOrConfig: $client
            );
        }
    ]);
};
