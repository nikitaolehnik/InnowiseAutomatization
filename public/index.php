<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Skeleton\Handlers\HttpErrorHandler;
use App\Skeleton\Handlers\ShutdownHandler;
use App\Skeleton\ResponseEmitter\ResponseEmitter;
use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (!getenv('IS_CLOUD_RUN')) {
    $dotenv = new Dotenv();
    $dotenv->load(__DIR__ . '/../.env');
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . file_get_contents(__DIR__ . '/../' . '/credentials.json'));
}

$containerBuilder = new ContainerBuilder();

$settings = require __DIR__ . '/../app/settings.php';
$settings($containerBuilder);

$dependencies = require __DIR__ . '/../app/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();
$callableResolver = $app->getCallableResolver();

$middleware = require __DIR__ . '/../app/middleware.php';
$middleware($app);

$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

$settings = $container->get(SettingsInterface::class);

$displayErrorDetails = $settings->get('displayErrorDetails');
$logError = $settings->get('logError');
$logErrorDetails = $settings->get('logErrorDetails');

$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

$responseFactory = $app->getResponseFactory();
$errorHandler = new HttpErrorHandler($callableResolver, $responseFactory);

$shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

set_error_handler(function ($errno, $errstr, $errfile, $errline) { // TODO: Tricky solution for handle deprecated messages from google chat app package
    // Suppress only E_USER_DEPRECATED errors from Google Apps Chat library
    if (str_contains($errfile, 'vendor/google/apps-chat') && $errno == E_USER_DEPRECATED) {
        return true; // Suppress deprecation warnings
    }

    return false;
});

$app->addRoutingMiddleware();

$app->addBodyParsingMiddleware();

$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, $logError, $logErrorDetails);
$errorMiddleware->setDefaultErrorHandler($errorHandler);

$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);
