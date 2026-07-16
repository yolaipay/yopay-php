<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class OrderRequest implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $merchantOrderNo,
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly string $scene,
        public readonly Payer $payer,
        public readonly ?string $subject = null,
        public readonly ?string $description = null,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $expiredAt = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $extra = null,
    ) {
    }

    /** @return array<string, array<string, int|string>|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'merchant_order_no' => $this->merchantOrderNo,
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'scene' => $this->scene,
            'subject' => $this->subject,
            'description' => $this->description,
            'notify_url' => $this->notifyUrl,
            'return_url' => $this->returnUrl,
            'expired_at' => $this->expiredAt,
            'client_ip' => $this->clientIp,
            'payer' => $this->payer->toArray(),
            'extra' => $this->extra,
        ]);
    }

    /** @return array<string, array<string, int|string>|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
