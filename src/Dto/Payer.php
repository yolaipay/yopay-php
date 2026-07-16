<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class Payer implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $merchantUserId,
        public readonly int $accountType,
        public readonly string $accountNo,
        public readonly ?string $accountName = null,
        public readonly ?string $identityNo = null,
        public readonly ?string $mobile = null,
        public readonly ?string $email = null,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'merchant_user_id' => $this->merchantUserId,
            'account_type' => $this->accountType,
            'account_no' => $this->accountNo,
            'account_name' => $this->accountName,
            'identity_no' => $this->identityNo,
            'mobile' => $this->mobile,
            'email' => $this->email,
        ]);
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
