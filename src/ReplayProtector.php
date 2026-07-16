<?php

declare(strict_types=1);

namespace YoPay;

use DateTimeImmutable;

/**
 * Atomically records a verified protocol nonce until its expiry time.
 *
 * The default implementation is process-local. Multi-process merchant
 * deployments should inject a shared, atomic cache-backed implementation.
 */
interface ReplayProtector
{
    public function claim(string $key, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool;
}
