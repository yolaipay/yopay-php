<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class RefundRequest implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $originTradeNo,
        public readonly string $merchantOrderNo,
        public readonly string $amount,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $reason = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'origin_trade_no' => $this->originTradeNo,
            'merchant_order_no' => $this->merchantOrderNo,
            'amount' => $this->amount,
            'notify_url' => $this->notifyUrl,
            'reason' => $this->reason,
        ]);
    }

    /** @return array<string, string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
