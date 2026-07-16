<?php

declare(strict_types=1);

namespace YoPay\Dto;

use JsonSerializable;
use YoPay\Contract\Arrayable;

final class WithdrawPayee implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly int $accountType,
        public readonly string $accountName,
        public readonly string $accountNo,
        public readonly ?string $accountOrgCode = null,
        public readonly ?string $accountOrgName = null,
        public readonly ?string $merchantUserId = null,
        public readonly ?string $identityNo = null,
        public readonly ?string $phone = null,
        public readonly ?string $countryCode = null,
        public readonly ?string $appId = null,
        public readonly ?string $providerUid = null,
        public readonly ?string $unionId = null,
        public readonly ?int $verificationStatus = null,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'account_type' => $this->accountType,
            'account_name' => $this->accountName,
            'account_no' => $this->accountNo,
            'account_org_code' => $this->accountOrgCode,
            'account_org_name' => $this->accountOrgName,
            'merchant_user_id' => $this->merchantUserId,
            'identity_no' => $this->identityNo,
            'phone' => $this->phone,
            'country_code' => $this->countryCode,
            'app_id' => $this->appId,
            'provider_uid' => $this->providerUid,
            'union_id' => $this->unionId,
            'verification_status' => $this->verificationStatus,
        ]);
    }

    /** @return array<string, int|string> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
