<?php

namespace App;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class PageData
{
    public static function get(string $url): array
    {
        [$response, $finalUrl] = self::request($url);

        $statusCode = $response->getStatusCode();
        $content = (string) $response->getBody();

        if ($content === '') {
            return [
                'statusCode' => $statusCode,
                'h1' => null,
                'title' => null,
                'description' => null,
                'finalUrl' => $finalUrl,
            ];
        }

        $crawler = new Crawler($content);
        $h1 = self::truncate(self::getNodeText($crawler->filter('h1')->getNode(0)));
        $title = self::truncate(self::getNodeText($crawler->filter('title')->getNode(0)));
        $descriptionNode = $crawler->filter('meta[name="description"]')->getNode(0);
        $description = optional($descriptionNode)->getAttribute('content');
        $description = $description === null ? null : trim($description);

        return compact('statusCode', 'h1', 'title', 'description', 'finalUrl');
    }

    private static function request(string $url): array
    {
        $redirects = 0;

        while ($redirects <= 5) {
            $target = UrlSafety::inspect($url);
            $response = self::createClient($target)->get($url);

            if (!self::isRedirect($response) || !$response->hasHeader('Location')) {
                return [$response, $url];
            }

            $url = UrlSafety::resolveRedirect($url, $response->getHeaderLine('Location'));
            ++$redirects;
        }

        throw new \RuntimeException('Too many redirects');
    }

    private static function createClient(array $target): Client
    {
        $httpClient = new Client(
            [
            'timeout' => 10,
            'connect_timeout' => 5,
            'headers' => ['User-Agent' => 'PageAnalyzer/1.0'],
            'http_errors' => false,
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => self::getCurlOptions($target),
            ]
        );

        return $httpClient;
    }

    private static function getCurlOptions(array $target): array
    {
        if (filter_var($target['host'], FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $target['ip'])]];
    }

    private static function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), [301, 302, 303, 307, 308], true);
    }

    public static function prepareChecksForView(array $checks, string $sourceUrl): array
    {
        foreach ($checks as &$check) {
            $finalUrl = $check['final_url'] ?? $sourceUrl;
            $metadataIsMissing = ($check['h1'] ?? null) === null
                && ($check['description'] ?? null) === null;

            $check['final_url'] = $finalUrl;
            $check['final_url_label'] = self::shorten($finalUrl);
            $check['h1_label'] = $metadataIsMissing ? 'Не найден' : self::shorten($check['h1'] ?? '');
            $check['title_label'] = self::shorten($check['title'] ?? '');
            $check['description_label'] = $metadataIsMissing
                ? 'Не найден'
                : self::shorten($check['description'] ?? '');
        }
        unset($check);

        return $checks;
    }

    private static function truncate(?string $value, int $limit = 255): ?string
    {
        if ($value === null || mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit);
    }

    private static function getNodeText($node): ?string
    {
        $text = optional($node)->textContent;
        if ($text === null) {
            return null;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function shorten(?string $value, int $limit = 200): string
    {
        $value ??= '';

        if (iconv_strlen($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return iconv_substr($value, 0, $limit, 'UTF-8') . '...';
    }
}
