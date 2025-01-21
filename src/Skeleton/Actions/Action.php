<?php

declare(strict_types=1);

namespace App\Skeleton\Actions;

use App\Domain\DomainException\DomainRecordNotFoundException;
use App\Services\Interfaces\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use OpenApi\Attributes as OA;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

#[OA\Info(version: "0.1", title: "Optimize title OpenApi")]
abstract class Action
{
    public const USE_CRON_BY_DEFAULT = 1;

    protected LoggerInterface $logger;

    protected Request $request;

    protected Response $response;

    protected array $args;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @throws HttpNotFoundException
     * @throws HttpBadRequestException
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;

        try {
            return $this->action();
        } catch (DomainRecordNotFoundException $e) {
            throw new HttpNotFoundException($this->request, $e->getMessage());
        }
    }

    /**
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    abstract protected function action(): Response;

    protected function getFormData()
    {
        return $this->request->getBody()->getContents();
    }

    protected function getTestData()
    {
        return $this->request->getParsedBody();
    }

    protected function resolveArg(string $name): mixed
    {
        if (!isset($this->args[$name])) {
            throw new HttpBadRequestException($this->request, "Could not resolve argument `{$name}`.");
        }

        return $this->args[$name];
    }

    protected function respondWithData(mixed $data = null, int $statusCode = 200): Response
    {
        $payload = new ActionPayload($statusCode, $data);

        return $this->respond($payload);
    }

    protected function respond(ActionPayload $payload): Response
    {
        $json = json_encode($payload);
        $this->response->getBody()->write($json);

        return $this->response
            ->withHeader('Content-Type', 'application/json');
    }

    protected function respondNoContent(): Response
    {
        return $this->response
            ->withStatus(204);
    }
}
