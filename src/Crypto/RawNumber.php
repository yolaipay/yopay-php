<?php

declare(strict_types=1);

namespace YoPay\Crypto;

/** @internal JSON number lexeme preserved exactly for protocol canonicalization. */
final class RawNumber
{
    public function __construct(public readonly string $value)
    {
    }
}
