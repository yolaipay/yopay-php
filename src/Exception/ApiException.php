<?php

declare(strict_types=1);

namespace YoPay\Exception;

use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        int $code,
        string $message,
        public readonly ?string $requestId = null,
    ) {
        parent::__construct($message, $code);
    }
}
