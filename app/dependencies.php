<?php

declare(strict_types=1);

use App\Services\Interfaces\LoggerInterface;
use App\Services\LoggerService;
use DI\ContainerBuilder;
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
        }
    ]);
};
