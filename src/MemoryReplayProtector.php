<?php

declare(strict_types=1);

namespace YoPay;

use DateTimeImmutable;

/** Default process-local replay protection for a client configuration. */
final class MemoryReplayProtector implements ReplayProtector
{
    /** @var array<string, int> */
    private array $claims = [];

    public function claim(string $key, DateTimeImmutable $expiresAt, DateTimeImmutable $now): bool
    {
        $key = trim($key);
        if ($key === '') {
            return false;
        }
        $nowTimestamp = $now->getTimestamp();
        foreach ($this->claims as $existingKey => $existingExpiry) {
            if ($existingExpiry <= $nowTimestamp) {
                unset($this->claims[$existingKey]);
            }
        }
        if (($this->claims[$key] ?? 0) > $nowTimestamp) {
            return false;
        }
        $this->claims[$key] = $expiresAt->getTimestamp();

        return true;
    }
}
