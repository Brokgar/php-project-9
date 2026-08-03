<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

// Главная страница
$app->get('/', function (Request $request, Response $response): Response {
    $response->getBody()->write('<h1>URL Analyzer</h1>');
    return $response;
});

$app->run();
