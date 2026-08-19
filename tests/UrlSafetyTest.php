<?php

namespace Tests;

use App\PageData;
use App\UrlSafety;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlSafetyTest extends TestCase
{
    #[DataProvider('privateUrls')]
    public function testPrivateAddressesAreRejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);

        UrlSafety::inspect($url);
    }

    public function testPageCheckRejectsPrivateAddressBeforeConnecting(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PageData::get('http://127.0.0.1');
    }

    public function testRedirectToPrivateAddressIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UrlSafety::resolveRedirect('https://example.com', 'http://127.0.0.1');
    }

    public function testPublicIpAddressIsAllowed(): void
    {
        $target = UrlSafety::inspect('https://8.8.8.8');

        $this->assertSame('8.8.8.8', $target['ip']);
        $this->assertSame(443, $target['port']);
    }

    public static function privateUrls(): array
    {
        return [
            'loopback IPv4' => ['http://127.0.0.1'],
            'private IPv4' => ['http://10.0.0.1'],
            'link-local metadata address' => ['http://169.254.169.254'],
            'carrier-grade NAT' => ['http://100.64.0.1'],
            'loopback IPv6' => ['http://[::1]'],
        ];
    }
}
