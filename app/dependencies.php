<?php

declare(strict_types=1);

use App\Services\Interfaces\LoggerInterface;
use App\Services\LoggerService;
use DI\ContainerBuilder;
use Google\Apps\Chat\V1\Client\ChatServiceClient;
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
        ChatServiceClient::class => function () {
            return getenv('GOOGLE_APPLICATION_CREDENTIALS') !== null
                ? new ChatServiceClient([
                'credentials' => getenv('GOOGLE_APPLICATION_CREDENTIALS')
            ]) : new ChatServiceClient();
        }
    ]);
};
