<?php

declare(strict_types=1);

use App\Application\ActionHandlers\EventHandlerAction;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    $app->group('/v1', function (Group $group) {
        $group->any('/event-handler', EventHandlerAction::class);
    });
};
