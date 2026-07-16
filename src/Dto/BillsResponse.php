<?php

declare(strict_types=1);

namespace YoPay\Dto;

final class BillsResponse
{
    /** @param list<BillItem> $list */
    public function __construct(
        public readonly int $total = 0,
        public readonly int $page = 0,
        public readonly int $pageSize = 0,
        public readonly array $list = [],
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $list = [];
        $rawList = $payload['list'] ?? [];
        if (is_array($rawList)) {
            foreach ($rawList as $item) {
                if (is_array($item)) {
                    $list[] = BillItem::fromArray($item);
                }
            }
        }

        return new self(
            self::int($payload, 'total'),
            self::int($payload, 'page'),
            self::int($payload, 'page_size'),
            $list,
        );
    }

    /** @param array<string, mixed> $payload */
    private static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) || is_string($value) ? (int) $value : 0;
    }
}
