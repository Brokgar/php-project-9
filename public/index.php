<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\PageData;
use DI\ContainerBuilder;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Dotenv\Dotenv;
use Slim\Flash\Messages;
use GuzzleHttp\Exception\GuzzleException;
use Valitron\Validator;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

session_start();

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(
    [
    'flash' => static function (): Messages {
        return new Messages();
    },
    'renderer' => static function (): PhpRenderer {
        $renderer = new PhpRenderer(__DIR__ . '/../templates');
        $renderer->setLayout('layout.phtml');
        return $renderer;
    },
    'pdo' => static function (): PDO {
        $dbUrl = $_ENV['DATABASE_URL'] ?? null;
        $parsed = is_string($dbUrl) ? parse_url($dbUrl) : false;

        if (
            !is_array($parsed)
            || !isset($parsed['host'], $parsed['path'], $parsed['user'])
            || ltrim($parsed['path'], '/') === ''
        ) {
            throw new RuntimeException('DATABASE_URL is missing or invalid');
        }

        $host = $parsed['host'];
        $port = $parsed['port'] ?? 5432;
        $dbname = ltrim($parsed['path'], '/');
        $user = $parsed['user'];
        $pass = $parsed['pass'] ?? null;
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    },
    ]
);
$container = $containerBuilder->build();
AppFactory::setContainer($container);
$app = AppFactory::create();
$router = $app->getRouteCollector()->getRouteParser();
$renderer = $container->get('renderer');
$flash = $container->get('flash');
$renderer->addAttribute('router', $router);
$renderer->addAttribute('flash', $flash);

$app->addBodyParsingMiddleware();
$app->add(
    function (Request $request, $handler) use ($container): Response {
        $pdo = $container->get('pdo');
        return $handler->handle($request->withAttribute('pdo', $pdo));
    }
);
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$customErrorHandler = function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use (
    $app,
    $renderer
): Response {
    $status = $exception instanceof HttpNotFoundException ? 404 : 500;
    if ($status === 500 && $logErrors) {
        error_log("Application error: {$exception->getMessage()}");
    }
    $response = $app->getResponseFactory()->createResponse($status);

    return $renderer->render($response, "errors/{$status}.phtml");
};
$errorMiddleware->setDefaultErrorHandler($customErrorHandler);

$app->get(
    '/',
    function (Request $request, Response $response) use ($renderer): Response {
        return $renderer->render($response, 'index.phtml');
    }
)->setName('home');

$app->post(
    '/urls',
    function (Request $request, Response $response) use ($flash, $renderer, $router): Response {
        $pdo = $request->getAttribute('pdo');
        $data = $request->getParsedBody();
        $url = is_array($data) ? ($data['url'] ?? '') : '';
        $urlName = is_string($url) ? trim($url) : '';

        $validator = new Validator(['url' => $urlName]);
        $validator->rule('required', 'url')->message('URL не должен быть пустым');
        $validator->rule('lengthMax', 'url', 255)->message('URL превышает 255 символов');
        $validator->rule('url', 'url')->message('Некорректный URL');

        if (!$validator->validate()) {
            $errors = $validator->errors();
            $flash->addMessageNow('danger', $errors['url'][0]);
            return $renderer->render($response->withStatus(422), 'index.phtml', ['url' => $urlName]);
        }

        $parsedUrl = parse_url($urlName);
        if (!is_array($parsedUrl) || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            $flash->addMessageNow('danger', 'Некорректный URL');
            return $renderer->render($response->withStatus(422), 'index.phtml', ['url' => $urlName]);
        }

        $normalizedUrl = sprintf('%s://%s', $parsedUrl['scheme'], $parsedUrl['host']);
        if (isset($parsedUrl['port'])) {
            $normalizedUrl .= ':' . $parsedUrl['port'];
        }

        $statement = $pdo->prepare('SELECT id FROM urls WHERE name = :name');
        $statement->execute(['name' => $normalizedUrl]);
        $existingId = $statement->fetchColumn();

        if ($existingId !== false) {
            $flash->addMessage('danger', 'Страница уже существует');
            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $existingId]))
                ->withStatus(302);
        }

        $statement = $pdo->prepare(
            'INSERT INTO urls (name, created_at)
             VALUES (:name, CURRENT_TIMESTAMP)
             ON CONFLICT (name) DO NOTHING
             RETURNING id'
        );
        $statement->execute(['name' => $normalizedUrl]);
        $id = $statement->fetchColumn();

        if ($id === false) {
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
    function (Request $request, Response $response) use ($renderer): Response {
        $pdo = $request->getAttribute('pdo');
        $queryParams = $request->getQueryParams();
        $pageValue = $queryParams['page'] ?? 1;
        $page = is_string($pageValue)
            ? filter_var($pageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $page = $page === false ? 1 : $page;
        $perPage = 10;

        $statement = $pdo->prepare(
            'SELECT id, name, created_at
             FROM urls
             ORDER BY created_at DESC, id DESC
             LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $perPage + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $urls = $statement->fetchAll(PDO::FETCH_ASSOC);

        $hasNextPage = count($urls) > $perPage;
        if ($hasNextPage) {
            array_pop($urls);
        }

        $lastChecks = [];
        if ($urls !== []) {
            $placeholders = [];
            foreach ($urls as $index => $url) {
                $placeholders[] = ":url_id{$index}";
            }

            $statement = $pdo->prepare(
                'SELECT DISTINCT ON (url_id) url_id, status_code, created_at
                 FROM url_checks
                 WHERE url_id IN (' . implode(', ', $placeholders) . ')
                 ORDER BY url_id, created_at DESC, id DESC'
            );
            foreach ($urls as $index => $url) {
                $statement->bindValue(":url_id{$index}", $url['id'], PDO::PARAM_INT);
            }
            $statement->execute();

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $check) {
                $lastChecks[$check['url_id']] = $check;
            }
        }

        foreach ($urls as &$url) {
            $lastCheck = $lastChecks[$url['id']] ?? null;
            $url['last_check_created_at'] = $lastCheck['created_at'] ?? null;
            $url['status_code'] = $lastCheck['status_code'] ?? null;
        }
        unset($url);

        return $renderer->render(
            $response,
            'urls/index.phtml',
            [
                'urls' => $urls,
                'pagination' => [
                    'page' => $page,
                    'hasNextPage' => $hasNextPage,
                ],
            ]
        );
    }
)->setName('urls.index');

$app->post(
    '/urls/{url_id:[0-9]+}/checks',
    function (
        Request $request,
        Response $response,
        array $args
    ) use (
        $flash,
        $router
    ): Response {
        $pdo = $request->getAttribute('pdo');
        $urlId = $args['url_id'];

        $statement = $pdo->prepare('SELECT name FROM urls WHERE id = :id');
        $statement->execute(['id' => $urlId]);
        $url = $statement->fetch(PDO::FETCH_ASSOC);

        if ($url === false) {
            throw new HttpNotFoundException($request);
        }

        try {
            $pageData = PageData::get($url['name']);
        } catch (GuzzleException | InvalidArgumentException $exception) {
            error_log(sprintf('Unable to check URL "%s": %s', $url['name'], $exception->getMessage()));
            $flash->addMessage('danger', 'Не удалось проверить страницу. Повторите попытку позже.');
            return $response
                ->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlId]))
                ->withStatus(302);
        }

        $statement = $pdo->prepare(
            'INSERT INTO url_checks (url_id, status_code, h1, title, description, final_url, created_at)
             VALUES (:url_id, :status_code, :h1, :title, :description, :final_url, CURRENT_TIMESTAMP)'
        );
        $statement->execute(
            [
            'url_id' => $urlId,
            'status_code' => $pageData['statusCode'],
            'h1' => $pageData['h1'],
            'title' => $pageData['title'],
            'description' => $pageData['description'],
            'final_url' => $pageData['finalUrl'],
            ]
        );

        $metadataIsMissing = $pageData['h1'] === null && $pageData['description'] === null;
        if ($metadataIsMissing) {
            $flash->addMessage(
                'warning',
                sprintf(
                    'Проверка завершилась на странице %s. На ней не найдены h1 и meta description.',
                    $pageData['finalUrl']
                )
            );
        } else {
            $flash->addMessage('success', 'Страница успешно проверена');
        }
        return $response
            ->withHeader('Location', $router->urlFor('urls.show', ['id' => $urlId]))
            ->withStatus(302);
    }
)->setName('urls.checks.create');

$app->get(
    '/urls/{id:[0-9]+}',
    function (Request $request, Response $response, array $args) use ($renderer): Response {
        $pdo = $request->getAttribute('pdo');
        $statement = $pdo->prepare('SELECT id, name, created_at FROM urls WHERE id = :id');
        $statement->execute(['id' => $args['id']]);
        $url = $statement->fetch(PDO::FETCH_ASSOC);

        if ($url === false) {
            throw new HttpNotFoundException($request);
        }

        $statement = $pdo->prepare(
            'SELECT id, status_code, h1, title, description, final_url, created_at
             FROM url_checks
             WHERE url_id = :url_id
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['url_id' => $url['id']]);
        $checks = PageData::prepareChecksForView($statement->fetchAll(PDO::FETCH_ASSOC), $url['name']);

        return $renderer->render(
            $response,
            'urls/show.phtml',
            [
                'url' => $url,
                'checks' => $checks,
            ]
        );
    }
)->setName('urls.show');

$app->run();
