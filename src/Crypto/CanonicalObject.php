<?php

declare(strict_types=1);

namespace YoPay\Crypto;

/** @internal JSON object whose decoded field names retain their protocol text. */
final class CanonicalObject
{
    /** @param array<string, mixed> $fields */
    public function __construct(public readonly array $fields)
    {
    }
}
