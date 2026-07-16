<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class WithdrawRequest implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $merchantOrderNo,
        public readonly string $amount,
        public readonly string $currencyCode,
        public readonly WithdrawPayee $payee,
        public readonly ?string $notifyUrl = null,
        public readonly ?string $subject = null,
        public readonly ?string $description = null,
        public readonly ?string $clientIp = null,
        public readonly ?string $regionCode = null,
        public readonly ?int $subjectType = null,
        public readonly ?string $extra = null,
    ) {
    }

    /** @return array<string, array<string, int|string>|int|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'merchant_order_no' => $this->merchantOrderNo,
            'amount' => $this->amount,
            'currency_code' => $this->currencyCode,
            'notify_url' => $this->notifyUrl,
            'subject' => $this->subject,
            'description' => $this->description,
            'client_ip' => $this->clientIp,
            'region_code' => $this->regionCode,
            'subject_type' => $this->subjectType,
            'payee' => $this->payee->toArray(),
            'extra' => $this->extra,
        ]);
    }

    /** @return array<string, array<string, int|string>|int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
