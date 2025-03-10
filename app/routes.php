<?php

declare(strict_types=1);

use App\Application\ActionHandlers\EventHandlerAction;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;
use Slim\Views\Twig;

return function (App $app) {
    $app->group('/v1', function (Group $group) {
        $group->any('/event-handler', EventHandlerAction::class);
//        $group->any('/asd', \App\Application\ActionHandlers\ChartHandlerAction::class);
        $group->get('/asd', function ($request, $response) {
            $view = Twig::fromRequest($request);

            return $view->render($response, 'home.html.twig', [
                'name' => 'John',
            ]);
        });
    });
};
