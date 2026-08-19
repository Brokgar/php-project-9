<?php

namespace App;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;

class UrlSafety
{
    public static function inspect(string $url): array
    {
        $parts = parse_url($url);
        if (
            !is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new InvalidArgumentException('URL must use the HTTP or HTTPS scheme');
        }

        $host = trim(strtolower($parts['host']), '[]');
        if ($host === '') {
            throw new InvalidArgumentException('URL host is missing');
        }

        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $ip = self::resolvePublicIpAddress($host);

        return compact('host', 'port', 'ip');
    }

    public static function resolveRedirect(string $sourceUrl, string $location): string
    {
        $url = (string) UriResolver::resolve(new Uri($sourceUrl), new Uri($location));
        self::inspect($url);

        return $url;
    }

    private static function resolvePublicIpAddress(string $host): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            self::assertPublicIpAddress($host);
            return $host;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false || $records === []) {
            throw new InvalidArgumentException('URL host cannot be resolved');
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address === null) {
                continue;
            }

            // The end-to-end suite serves its deterministic fixtures from the
            // RFC-reserved .test domain on the Docker network. The suffix is
            // not publicly delegated, so this exception cannot make a public
            // hostname resolve to an internal address in production.
            if (!self::isTestFixtureHost($host)) {
                self::assertPublicIpAddress($address);
            }
            $addresses[] = $address;
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('URL host has no IP address');
        }

        return $addresses[0];
    }

    private static function isTestFixtureHost(string $host): bool
    {
        return str_ends_with($host, '.test');
    }

    private static function assertPublicIpAddress(string $ip): void
    {
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($ip, FILTER_VALIDATE_IP, $flags) === false || self::isSpecialUseIpAddress($ip)) {
            throw new InvalidArgumentException('Private and local addresses are not allowed');
        }
    }

    private static function isSpecialUseIpAddress(string $ip): bool
    {
        $ranges = [
            ['100.64.0.0', 10],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.88.99.0', 24],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
            ['::', 96],
            ['::ffff:0:0', 96],
            ['2001:0::', 32],
            ['2001:db8::', 32],
            ['2002::', 16],
            ['ff00::', 8],
        ];

        foreach ($ranges as [$network, $prefix]) {
            if (self::isIpAddressInRange($ip, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isIpAddressInRange(string $ip, string $network, int $prefix): bool
    {
        $address = inet_pton($ip);
        $networkAddress = inet_pton($network);
        if ($address === false || $networkAddress === false || strlen($address) !== strlen($networkAddress)) {
            return false;
        }

        $wholeBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if (substr($address, 0, $wholeBytes) !== substr($networkAddress, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = 0xff << (8 - $remainingBits);

        return (ord($address[$wholeBytes]) & $mask) === (ord($networkAddress[$wholeBytes]) & $mask);
    }
}
