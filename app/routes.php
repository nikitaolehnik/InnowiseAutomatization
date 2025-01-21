<?php

declare(strict_types=1);

use App\Application\ActionHandlers\TestAction;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    $app->group('/v1', function (Group $group) {
        $group->any('/test', TestAction::class);
    });
};
