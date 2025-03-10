<?php

namespace App\Application\ActionHandlers;

use Google\Rpc\Context\AttributeContext\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class ChartHandlerAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args)
    {
        $response->getBody()->write("Hello");

        return $response;
    }
}