<?php

namespace App\Application\ActionHandlers;

use Google\Rpc\Context\AttributeContext\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class ChartHandlerAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $view = Twig::fromRequest($request);

        return $view->render($response, 'home.html.twig', [
            'name' => 'John',
        ]);
    }
}