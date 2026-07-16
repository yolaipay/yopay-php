<?php

declare(strict_types=1);

namespace YoPay\Crypto;

final class Signer
{
    public static function sign(string $secretKey, string $signText): string
    {
        return hash_hmac('sha256', $signText, trim($secretKey));
    }

    public static function verify(string $secretKey, string $signText, string $signature): bool
    {
        return hash_equals(self::sign($secretKey, $signText), strtolower(trim($signature)));
    }
}
