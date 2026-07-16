<?php

declare(strict_types=1);

namespace YoPay\Dto;

final class OrderResponse
{
    public function __construct(
        public readonly string $tradeNo = '',
        public readonly string $merchantOrderNo = '',
        public readonly string $providerOrderNo = '',
        public readonly int $tradeType = 0,
        public readonly int $status = 0,
        public readonly string $statusLabel = '',
        public readonly string $amount = '',
        public readonly string $currencyCode = '',
        public readonly string $feeAmount = '',
        public readonly string $settleAmount = '',
        public readonly string $paymentUrl = '',
        public readonly string $failReason = '',
        public readonly string $createdAt = '',
        public readonly string $expiredAt = '',
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            self::string($payload, 'trade_no'),
            self::string($payload, 'merchant_order_no'),
            self::string($payload, 'provider_order_no'),
            self::int($payload, 'trade_type'),
            self::int($payload, 'status'),
            self::string($payload, 'status_label'),
            self::string($payload, 'amount'),
            self::string($payload, 'currency_code'),
            self::string($payload, 'fee_amount'),
            self::string($payload, 'settle_amount'),
            self::string($payload, 'payment_url'),
            self::string($payload, 'fail_reason'),
            self::string($payload, 'created_at'),
            self::string($payload, 'expired_at'),
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
