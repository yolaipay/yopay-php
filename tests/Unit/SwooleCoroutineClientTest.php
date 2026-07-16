<?php

declare(strict_types=1);

namespace YoPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YoPay\Exception\CoroutineException;
use YoPay\Http\SwooleCoroutineClient;

final class SwooleCoroutineClientTest extends TestCase
{
    public function testUnavailableExtensionFailsOnlyWhenAdapterIsUsed(): void
    {
        if (SwooleCoroutineClient::isAvailable()) {
            self::markTestSkipped('The Docker quality image intentionally does not install ext-swoole.');
        }

        $client = new SwooleCoroutineClient();
        $request = new \GuzzleHttp\Psr7\Request('GET', 'https://example.test/health');
        $this->expectException(CoroutineException::class);
        $client->sendRequest($request);
    }
}
