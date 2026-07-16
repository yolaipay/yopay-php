<?php

declare(strict_types=1);

namespace YoPay;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use YoPay\Exception\ConfigException;

/**
 * Merchant-side connection and credential configuration.
 *
 * Base URL contains only the scheme, host and optional port. Open API paths
 * are consistently built from openApiPrefix plus each method's relative path.
 */
final class Config
{
    public const DEFAULT_OPEN_API_PREFIX = '/api/v1/open';

    public readonly string $baseUrl;

    public readonly string $openApiPrefix;

    public readonly string $mchId;

    public readonly string $secretKey;

    public readonly string $platformPublicKey;

    public readonly string $merchantPrivateKey;

    public readonly ClientInterface $httpClient;

    public readonly RequestFactoryInterface $requestFactory;

    public readonly StreamFactoryInterface $streamFactory;

    public readonly ReplayProtector $replayProtector;

    public function __construct(
        string $baseUrl,
        string $mchId,
        string $secretKey = '',
        string $platformPublicKey = '',
        string $merchantPrivateKey = '',
        ?string $openApiPrefix = null,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        public readonly ?LoggerInterface $logger = null,
        public readonly bool $debug = false,
        public readonly int $maxResponseBodyBytes = 4194304,
        ?ReplayProtector $replayProtector = null,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->openApiPrefix = self::normalizePathPrefix($openApiPrefix ?? self::DEFAULT_OPEN_API_PREFIX);
        $this->mchId = trim($mchId);
        $this->secretKey = trim($secretKey);
        $this->platformPublicKey = trim($platformPublicKey);
        $this->merchantPrivateKey = trim($merchantPrivateKey);

        if ($this->mchId === '') {
            throw new ConfigException('mchId is required');
        }
        if ($this->maxResponseBodyBytes <= 0) {
            throw new ConfigException('maxResponseBodyBytes must be greater than zero');
        }

        $factory = new HttpFactory();
        $this->httpClient = $httpClient ?? new GuzzleClient(['timeout' => 30.0, 'allow_redirects' => false]);
        $this->requestFactory = $requestFactory ?? $factory;
        $this->streamFactory = $streamFactory ?? $factory;
        $this->replayProtector = $replayProtector ?? new MemoryReplayProtector();
    }

    private static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if ($baseUrl === '' || $parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigException('baseUrl must include scheme and host');
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new ConfigException('baseUrl must include an http or https scheme and host');
        }
        if (
            array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
        ) {
            throw new ConfigException('baseUrl must not include credentials, query, or fragment');
        }
        $path = isset($parts['path']) ? $parts['path'] : '';
        if ($path !== '' && $path !== '/') {
            throw new ConfigException('baseUrl must not include a path');
        }

        return $baseUrl;
    }

    private static function normalizePathPrefix(string $prefix): string
    {
        $prefix = trim($prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        if (!str_starts_with($prefix, '/')) {
            $prefix = '/' . $prefix;
        }
        if (str_contains($prefix, '?') || str_contains($prefix, '#')) {
            throw new ConfigException('openApiPrefix must be a path');
        }
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '..') {
                throw new ConfigException('openApiPrefix must not contain parent segments');
            }
        }

        return rtrim((string) preg_replace('#/{2,}#', '/', $prefix), '/');
    }
}
