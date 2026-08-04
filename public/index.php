<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$dbUrl = $_ENV['DATABASE_URL'];

$parsed = parse_url($dbUrl);
$host   = $parsed['host'];
$port   = $parsed['port'] ?? 5432;
$dbname = ltrim($parsed['path'], '/');
$user   = $parsed['user'];
$pass   = $parsed['pass'];

$dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

try {
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Успешное подключение к базе данных!";
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}


$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get(
    '/', function (Request $request, Response $response): Response {
        $renderer = new PhpRenderer(__DIR__ . '/../templates');
        $renderer->setLayout('layout.phtml');
        return $renderer->render($response, 'index.phtml');
    }
);

$app->run();
