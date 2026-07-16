<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class CloseOrderRequest implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly ?string $tradeNo = null,
        public readonly ?string $merchantOrderNo = null,
        public readonly ?int $tradeType = null,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'trade_no' => $this->tradeNo,
            'merchant_order_no' => $this->merchantOrderNo,
            'trade_type' => $this->tradeType,
        ]);
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
