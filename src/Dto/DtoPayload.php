<?php

declare(strict_types=1);

namespace YoPay\Dto;

final class DtoPayload
{
    /**
     * Preserve meaningful zero values while omitting null and empty optional strings.
     *
     * @template T of bool|float|int|string|array<string, bool|float|int|string>
     * @param array<string, T|null> $payload
     * @return array<string, T>
     */
    public static function withoutEmpty(array $payload): array
    {
        $filtered = [];
        foreach ($payload as $key => $value) {
            if ($value !== null && $value !== '') {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }
}
