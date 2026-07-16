<?php

declare(strict_types=1);

namespace YoPay\Crypto;

use JsonSerializable;
use YoPay\Contract\Arrayable;
use YoPay\Exception\CryptoException;

final class Envelope implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $encryptedKey,
        public readonly string $bizContent,
    ) {
        if (trim($this->encryptedKey) === '' || trim($this->bizContent) === '') {
            throw new CryptoException('encrypted envelope is incomplete');
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $encryptedKey = $payload['encrypted_key'] ?? null;
        $bizContent = $payload['biz_content'] ?? null;
        if (!is_string($encryptedKey) || !is_string($bizContent)) {
            throw new CryptoException('encrypted envelope is invalid');
        }

        return new self($encryptedKey, $bizContent);
    }

    /**
     * @return array{encrypted_key: string, biz_content: string}
     */
    public function toArray(): array
    {
        return [
            'encrypted_key' => $this->encryptedKey,
            'biz_content' => $this->bizContent,
        ];
    }

    /**
     * @return array{encrypted_key: string, biz_content: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
