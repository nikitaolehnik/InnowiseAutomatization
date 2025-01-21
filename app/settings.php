<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => true,
                'logError' => true,
                'logErrorDetails' => true,
                'logger' => [
                    'name' => 'retitle-api',
                    'path' => 'php://stdout',
                    'level' => Logger::DEBUG,
                ],
            ]);
        }
    ]);
};
