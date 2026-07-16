<?php

declare(strict_types=1);

namespace YoPay\Dto;

final class BillItem
{
    public function __construct(
        public readonly string $accountTransactionId = '',
        public readonly string $sourceBizNo = '',
        public readonly string $sourceType = '',
        public readonly string $sourceTypeLabel = '',
        public readonly int $bizType = 0,
        public readonly string $bizTypeLabel = '',
        public readonly string $txnType = '',
        public readonly int $direction = 0,
        public readonly string $directionLabel = '',
        public readonly string $amount = '',
        public readonly string $beforeAmount = '',
        public readonly string $balanceAfter = '',
        public readonly string $currencyCode = '',
        public readonly string $remark = '',
        public readonly string $occurredAt = '',
        public readonly string $createdAt = '',
        public readonly string $updatedAt = '',
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            self::string($payload, 'account_transaction_id'),
            self::string($payload, 'source_biz_no'),
            self::string($payload, 'source_type'),
            self::string($payload, 'source_type_label'),
            self::int($payload, 'biz_type'),
            self::string($payload, 'biz_type_label'),
            self::string($payload, 'txn_type'),
            self::int($payload, 'direction'),
            self::string($payload, 'direction_label'),
            self::string($payload, 'amount'),
            self::string($payload, 'before_amount'),
            self::string($payload, 'balance_after'),
            self::string($payload, 'currency_code'),
            self::string($payload, 'remark'),
            self::string($payload, 'occurred_at'),
            self::string($payload, 'created_at'),
            self::string($payload, 'updated_at'),
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
