<?php

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Dotenv\Dotenv;
use Slim\Flash\Messages;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DomCrawler\Crawler;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

session_start();
$flash = new Messages($_SESSION);

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

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS url_checks (
            id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            url_id BIGINT NOT NULL REFERENCES urls (id) ON DELETE CASCADE,
            status_code INTEGER,
            h1 VARCHAR(255),
            title VARCHAR(255),
            description TEXT,
            created_at TIMESTAMP NOT NULL
        )'
    );
} catch (PDOException $e) {
    die("Ошибка подключения: " . $e->getMessage());
}


$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$renderer = new PhpRenderer(__DIR__ . '/../templates');
$renderer->setLayout('layout.phtml');
$httpClient = new Client([
    'timeout' => 10,
    'connect_timeout' => 5,
    'http_errors' => false,
    'headers' => ['User-Agent' => 'PageAnalyzer/1.0'],
]);

$render = function (Response $response, string $template, array $params = []) use ($renderer, $flash, $app): Response {
    return $renderer->render($response, $template, array_merge([
        'flash' => $flash->getMessages(),
        'router' => $app->getRouteCollector()->getRouteParser(),
    ], $params));
};

$getPageData = static function (string $url) use ($httpClient): ?array {
    try {
        $response = $httpClient->get($url);
    } catch (GuzzleException $exception) {
        return null;
    }

    $statusCode = $response->getStatusCode();
    $content = (string) $response->getBody();

    if ($content === '') {
        return [
            'statusCode' => $statusCode,
            'h1' => null,
            'title' => null,
            'description' => null,
        ];
    }

    $crawler = new Crawler($content);
    $getText = static function ($node): ?string {
        $text = optional($node)->textContent;
        if ($text === null) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    };

    $h1 = $getText($crawler->filter('h1')->getNode(0));
    $title = $getText($crawler->filter('title')->getNode(0));
    $descriptionNode = $crawler->filter('meta[name="description"]')->getNode(0);
    $description = optional($descriptionNode)->getAttribute('content');
    $description = $description === null ? null : trim($description);

    return compact('statusCode', 'h1', 'title', 'description');
};

$app->get(
    '/',
    function (Request $request, Response $response) use ($render): Response {
        return $render($response, 'index.phtml');
    }
)->setName('home');

$app->post(
    '/urls',
    function (Request $request, Response $response) use ($pdo, $flash, $render, $app): Response {
        $data = $request->getParsedBody();
        $url = is_array($data) ? ($data['url'] ?? '') : '';
        $urlName = is_string($url) ? trim($url) : '';

        if ($urlName === '') {
            $flash->addMessageNow('danger', 'URL не должен быть пустым');
            return $render($response->withStatus(422), 'index.phtml', ['url' => $urlName]);
        }

        if (strlen($urlName) > 255) {
            $flash->addMessageNow('danger', 'URL превышает 255 символов');
            return $render($response->withStatus(422), 'index.phtml', ['url' => $urlName]);
        }

        $parsedUrl = parse_url($urlName);
        if (
            filter_var($urlName, FILTER_VALIDATE_URL) === false
            || !is_array($parsedUrl)
            || !isset($parsedUrl['scheme'], $parsedUrl['host'])
        ) {
            $flash->addMessageNow('danger', 'Некорректный URL');
            return $render($response->withStatus(422), 'index.phtml', ['url' => $urlName]);
        }

        $normalizedUrl = sprintf('%s://%s', $parsedUrl['scheme'], $parsedUrl['host']);
        if (isset($parsedUrl['port'])) {
            $normalizedUrl .= ':' . $parsedUrl['port'];
        }

        $statement = $pdo->prepare('SELECT id FROM urls WHERE name = :name');
        $statement->execute(['name' => $normalizedUrl]);
        $existingId = $statement->fetchColumn();

        $router = $app->getRouteCollector()->getRouteParser();
        if ($existingId !== false) {
            $flash->addMessage('danger', 'Страница уже существует');
            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $existingId]))
                ->withStatus(302);
        }

        try {
            $statement = $pdo->prepare(
                'INSERT INTO urls (name, created_at) VALUES (:name, CURRENT_TIMESTAMP) RETURNING id'
            );
            $statement->execute(['name' => $normalizedUrl]);
            $id = $statement->fetchColumn();
        } catch (PDOException $exception) {
            if ($exception->getCode() !== '23505') {
                throw $exception;
            }

            $statement = $pdo->prepare('SELECT id FROM urls WHERE name = :name');
            $statement->execute(['name' => $normalizedUrl]);
            $id = $statement->fetchColumn();
            $flash->addMessage('danger', 'Страница уже существует');

            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))
                ->withStatus(302);
        }

        $flash->addMessage('success', 'Страница успешно добавлена');

        return $response
            ->withHeader('Location', $router->urlFor('urls.show', ['id' => $id]))
            ->withStatus(302);
    }
)->setName('urls.create');

$app->get(
    '/urls',
    function (Request $request, Response $response) use ($pdo, $render): Response {
        $statement = $pdo->query(
            'SELECT urls.id, urls.name, urls.created_at,
                    latest_check.created_at AS last_check_created_at,
                    latest_check.status_code
             FROM urls
             LEFT JOIN LATERAL (
                 SELECT status_code, created_at
                 FROM url_checks
                 WHERE url_id = urls.id
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1
             ) AS latest_check ON TRUE
             ORDER BY urls.created_at DESC, urls.id DESC'
        );

        return $render($response, 'urls.phtml', ['urls' => $statement->fetchAll(PDO::FETCH_ASSOC)]);
    }
)->setName('urls.index');

$app->post(
    '/urls/{url_id}/checks',
    function (Request $request, Response $response, array $args) use ($pdo, $flash, $app, $getPageData): Response {
        $urlId = $args['url_id'];
        $router = $app->getRouteCollector()->getRouteParser();

        $statement = $pdo->prepare('SELECT name FROM urls WHERE id = :id');
        $statement->execute(['id' => $urlId]);
        $url = $statement->fetch(PDO::FETCH_ASSOC);

        if ($url === false) {
            return $response->withStatus(404);
        }

        $pageData = $getPageData($url['name']);
        if ($pageData === null) {
            $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlId]))
                ->withStatus(302);
        }

        try {
            $statement = $pdo->prepare(
                'INSERT INTO url_checks (url_id, status_code, h1, title, description, created_at)
                 VALUES (:url_id, :status_code, :h1, :title, :description, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                'url_id' => $urlId,
                'status_code' => $pageData['statusCode'],
                'h1' => $pageData['h1'],
                'title' => $pageData['title'],
                'description' => $pageData['description'],
            ]);
        } catch (PDOException $exception) {
            $flash->addMessage('danger', 'Произошла ошибка при проверке, не удалось подключиться');
            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlId]))
                ->withStatus(302);
        }

        $flash->addMessage('success', 'Страница успешно проверена');
        return $response
            ->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlId]))
            ->withStatus(302);
    }
)->setName('urls.checks.create');

$app->get(
    '/urls/{id}',
    function (Request $request, Response $response, array $args) use ($pdo, $render): Response {
        $statement = $pdo->prepare('SELECT id, name, created_at FROM urls WHERE id = :id');
        $statement->execute(['id' => $args['id']]);
        $url = $statement->fetch(PDO::FETCH_ASSOC);

        if ($url === false) {
            return $response->withStatus(404);
        }

        $statement = $pdo->prepare(
            'SELECT id, status_code, h1, title, description, created_at
             FROM url_checks
             WHERE url_id = :url_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['url_id' => $url['id']]);

        return $render($response, 'url.phtml', [
            'url' => $url,
            'checks' => $statement->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }
)->setName('urls.show');

$app->run();
