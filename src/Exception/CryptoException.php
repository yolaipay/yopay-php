<?php

declare(strict_types=1);

namespace YoPay\Exception;

use RuntimeException;
use Throwable;

final class CryptoException extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $requestId = null, ?Throwable $previous = null)
    {
        if ($requestId !== null && $requestId !== '') {
            $message .= sprintf(' (request_id=%s)', $requestId);
        }

        parent::__construct($message, 0, $previous);
    }
}
