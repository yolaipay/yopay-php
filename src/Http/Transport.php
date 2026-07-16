<?php

declare(strict_types=1);

namespace YoPay\Http;

use Psr\Http\Message\ResponseInterface;
use Throwable;
use YoPay\Config;
use YoPay\Crypto\Canonicalizer;
use YoPay\Crypto\Signer;
use YoPay\Exception\ConfigException;
use YoPay\Exception\HttpException;

/** @phpstan-type QueryValue bool|float|int|string|null|list<bool|float|int|string|null> */
final class Transport
{
    /** @var \Closure(): string */
    private readonly \Closure $nonceGenerator;

    /** @var \Closure(): string */
    private readonly \Closure $requestIdGenerator;

    /** @var \Closure(): int */
    private readonly \Closure $timestampGenerator;

    /**
     * @param null|\Closure(): string $nonceGenerator
     * @param null|\Closure(): string $requestIdGenerator
     * @param null|\Closure(): int $timestampGenerator
     */
    public function __construct(
        private readonly Config $config,
        ?\Closure $nonceGenerator = null,
        ?\Closure $requestIdGenerator = null,
        ?\Closure $timestampGenerator = null,
    ) {
        $this->nonceGenerator = $nonceGenerator ?? static fn (): string => bin2hex(random_bytes(16));
        $this->requestIdGenerator = $requestIdGenerator ?? static fn (): string => 'req_' . bin2hex(random_bytes(16));
        $this->timestampGenerator = $timestampGenerator ?? static fn (): int => time();
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     */
    public function send(
        string $method,
        string $relativePath,
        array $query = [],
        ?string $body = null,
    ): TransportResponse {
        $method = strtoupper(trim($method));
        if ($method === '') {
            throw new ConfigException('HTTP method is required');
        }
        if (trim($this->config->secretKey) === '') {
            throw new ConfigException('secretKey is required');
        }

        [$url, $signPath] = $this->endpoint($relativePath, $query);
        $timestamp = (string) ($this->timestampGenerator)();
        $nonce = ($this->nonceGenerator)();
        $requestId = ($this->requestIdGenerator)();
        if (strlen($nonce) === 0 || strlen($requestId) === 0) {
            throw new ConfigException('nonce and request ID generators must not return empty values');
        }
        $canonicalPayload = $method === 'GET'
            ? Canonicalizer::query($query)
            : ($body ?? '{}');
        $signText = Canonicalizer::signText(
            $method,
            $signPath,
            $this->config->mchId,
            $timestamp,
            $nonce,
            $requestId,
            $canonicalPayload,
        );

        $request = $this->config->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('X-Mch-Id', $this->config->mchId)
            ->withHeader('X-Timestamp', $timestamp)
            ->withHeader('X-Nonce', $nonce)
            ->withHeader('X-Signature', Signer::sign($this->config->secretKey, $signText))
            ->withHeader('X-Request-Id', $requestId);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->config->streamFactory->createStream($body));
        }

        $startedAt = microtime(true);
        $this->debug('YoPay request', [
            'method' => $method,
            'path' => $signPath,
            'request_id' => $requestId,
        ]);
        try {
            $response = $this->config->httpClient->sendRequest($request);
        } catch (Throwable $exception) {
            $this->debug('YoPay transport failure', [
                'method' => $method,
                'path' => $signPath,
                'request_id' => $requestId,
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            throw new HttpException(0, '', $requestId, $exception);
        }

        try {
            $result = $this->toTransportResponse($response, $requestId, $signPath);
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HttpException(0, '', $requestId, $exception);
        }
        $this->debug('YoPay response', [
            'method' => $method,
            'path' => $signPath,
            'request_id' => $result->requestId,
            'status' => $result->statusCode,
            'duration_ms' => $this->durationMs($startedAt),
        ]);
        if ($result->statusCode < 200 || $result->statusCode >= 300) {
            throw new HttpException($result->statusCode, $result->body, $result->requestId);
        }

        return $result;
    }

    /**
     * @param array<string, scalar|list<scalar>|null> $query
     * @return array{string, string}
     */
    private function endpoint(string $relativePath, array $query): array
    {
        $relativePath = trim($relativePath);
        if ($relativePath === '' || str_contains($relativePath, '?') || str_contains($relativePath, '#')) {
            throw new ConfigException('relativePath must be a non-empty path without query or fragment');
        }
        if (!str_starts_with($relativePath, '/')) {
            $relativePath = '/' . $relativePath;
        }
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new ConfigException('relativePath must not contain parent segments');
            }
        }
        $relativePath = preg_replace('#/{2,}#', '/', $relativePath) ?: $relativePath;
        $relativePath = rtrim($relativePath, '/');
        $signPath = ($this->config->openApiPrefix . $relativePath) ?: '/';
        $queryString = Canonicalizer::queryString($query);
        $url = $this->config->baseUrl . $signPath;
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }

        return [$url, $signPath];
    }

    private function toTransportResponse(
        ResponseInterface $response,
        string $fallbackRequestId,
        string $signPath,
    ): TransportResponse {
        $body = $this->readBody($response);
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }
        $requestId = trim($headers['x-request-id'] ?? '');

        return new TransportResponse(
            $response->getStatusCode(),
            $headers,
            $body,
            $requestId !== '' ? $requestId : $fallbackRequestId,
            $fallbackRequestId,
            $signPath,
        );
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = $response->getBody();
        $body = '';
        try {
            while (!$stream->eof()) {
                $chunk = $stream->read(8192);
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (strlen($body) > $this->config->maxResponseBodyBytes) {
                    $body = substr($body, 0, $this->config->maxResponseBodyBytes);
                    throw new HttpException(
                        $response->getStatusCode(),
                        $body,
                        $response->getHeaderLine('X-Request-Id'),
                    );
                }
            }
        } catch (HttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new HttpException(0, '', $response->getHeaderLine('X-Request-Id'), $exception);
        }

        return $body;
    }

    /**
     * @param array<string, int|string> $context
     */
    private function debug(string $message, array $context): void
    {
        if ($this->config->debug && $this->config->logger !== null) {
            $this->config->logger->debug($message, $context);
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
