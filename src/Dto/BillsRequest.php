<?php

declare(strict_types=1);

namespace YoPay\Dto;

use YoPay\Contract\Arrayable;

final class BillsRequest implements Arrayable
{
    public function __construct(
        public readonly ?int $page = null,
        public readonly ?int $pageSize = null,
        public readonly ?string $accountTransactionId = null,
        public readonly ?string $sourceBizNo = null,
        public readonly ?int $bizType = null,
        public readonly ?int $direction = null,
        public readonly ?string $currencyCode = null,
    ) {
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return DtoPayload::withoutEmpty([
            'page' => $this->page,
            'page_size' => $this->pageSize,
            'account_transaction_id' => $this->accountTransactionId,
            'source_biz_no' => $this->sourceBizNo,
            'biz_type' => $this->bizType,
            'direction' => $this->direction,
            'currency_code' => $this->currencyCode,
        ]);
    }
}
