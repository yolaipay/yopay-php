<?php

declare(strict_types=1);

namespace YoPay\Exception;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $responseBody = '',
        public readonly ?string $requestId = null,
        ?Throwable $previous = null,
    ) {
        $message = sprintf('YoPay HTTP request failed with status %d', $statusCode);
        if ($requestId !== null && $requestId !== '') {
            $message .= sprintf(' (request_id=%s)', $requestId);
        }

        parent::__construct($message, $statusCode, $previous);
    }
}
