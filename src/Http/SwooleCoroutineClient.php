<?php

declare(strict_types=1);

namespace YoPay\Http;

use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use YoPay\Exception\CoroutineException;

/**
 * Optional PSR-18 adapter for Swoole coroutine applications.
 *
 * It deliberately does not bootstrap an event loop or manage a connection
 * pool. Construct it inside the merchant application's coroutine-aware
 * container and inject it into Config.
 */
final class SwooleCoroutineClient implements ClientInterface
{
    public function __construct(private readonly float $timeoutSeconds = 30.0)
    {
        if ($timeoutSeconds <= 0) {
            throw new CoroutineException('Swoole coroutine timeout must be greater than zero');
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('swoole') && class_exists('Swoole\\Coroutine\\Http\\Client');
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (!self::isAvailable()) {
            throw new CoroutineException('ext-swoole with Swoole\\Coroutine\\Http\\Client is required');
        }
        if (class_exists('Swoole\\Coroutine') && \Swoole\Coroutine::getCid() < 0) {
            throw new CoroutineException('SwooleCoroutineClient must be used inside a Swoole coroutine');
        }

        $uri = $request->getUri();
        $host = $uri->getHost();
        if ($host === '') {
            throw new CoroutineException('request URI host is required');
        }
        $ssl = strtolower($uri->getScheme()) === 'https';
        $port = $uri->getPort() ?? ($ssl ? 443 : 80);
        /** @var \Swoole\Coroutine\Http\Client $client */
        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        $client->set(['timeout' => $this->timeoutSeconds]);
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }
        $client->setHeaders($headers);
        $client->setMethod($request->getMethod());
        $client->setData((string) $request->getBody());
        $path = $uri->getPath() === '' ? '/' : $uri->getPath();
        if ($uri->getQuery() !== '') {
            $path .= '?' . $uri->getQuery();
        }

        try {
            $success = $client->execute($path);
            if ($success !== true) {
                $message = isset($client->errMsg) ? (string) $client->errMsg : 'unknown Swoole HTTP error';
                throw new CoroutineException('Swoole coroutine request failed: ' . $message);
            }
            $statusCode = isset($client->statusCode) ? (int) $client->statusCode : 0;
            if ($statusCode <= 0) {
                throw new CoroutineException('Swoole coroutine request returned no HTTP status');
            }
            $responseHeaders = isset($client->headers) && is_array($client->headers) ? $client->headers : [];
            $body = isset($client->body) ? (string) $client->body : '';

            return new Response($statusCode, $responseHeaders, $body);
        } finally {
            $client->close();
        }
    }
}
