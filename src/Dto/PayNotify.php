<?php

declare(strict_types=1);

namespace YoPay\Dto;

final class PayNotify
{
    public function __construct(
        public readonly string $notifyType = '',
        public readonly string $tradeNo = '',
        public readonly string $merchantOrderNo = '',
        public readonly int $tradeType = 0,
        public readonly int $status = 0,
        public readonly string $statusLabel = '',
        public readonly string $amount = '',
        public readonly string $currencyCode = '',
        public readonly string $feeAmount = '',
        public readonly string $providerOrderNo = '',
        public readonly string $completedAt = '',
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            self::string($payload, 'notify_type'),
            self::string($payload, 'trade_no'),
            self::string($payload, 'merchant_order_no'),
            self::int($payload, 'trade_type'),
            self::int($payload, 'status'),
            self::string($payload, 'status_label'),
            self::string($payload, 'amount'),
            self::string($payload, 'currency_code'),
            self::string($payload, 'fee_amount'),
            self::string($payload, 'provider_order_no'),
            self::string($payload, 'completed_at'),
        );
    }

    /** @param array<string, mixed> $payload */
    private static function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string, mixed> $payload */
    private static function int(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) || is_string($value) ? (int) $value : 0;
    }
}
