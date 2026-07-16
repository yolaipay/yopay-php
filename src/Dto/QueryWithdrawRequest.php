<?php

declare(strict_types=1);

namespace YoPay\Dto;

use YoPay\Contract\Arrayable;

final class QueryWithdrawRequest implements Arrayable
{
    public function __construct(
        public readonly ?string $tradeNo = null,
        public readonly ?string $merchantOrderNo = null,
        public readonly ?string $providerOrderNo = null,
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'trade_no' => $this->tradeNo,
            'merchant_order_no' => $this->merchantOrderNo,
            'provider_order_no' => $this->providerOrderNo,
        ]);
    }
}
