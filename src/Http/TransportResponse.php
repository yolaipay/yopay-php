<?php

declare(strict_types=1);

namespace YoPay\Http;

final class TransportResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $requestId,
        public readonly string $expectedRequestId,
        public readonly string $signPath,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
