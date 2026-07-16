<?php

declare(strict_types=1);

namespace YoPay\Tests\Support;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;

final class FixtureKeys
{
    /** @return array{string, string} */
    public static function pair(): array
    {
        /** @var PrivateKey $private */
        $private = RSA::createKey(2048);
        /** @var PublicKey $public */
        $public = $private->getPublicKey();

        return [$private->toString('PKCS8'), $public->toString('PKCS8')];
    }

    /** @return array{string, string} */
    public static function pkcs1Pair(): array
    {
        /** @var PrivateKey $private */
        $private = RSA::createKey(2048);
        /** @var PublicKey $public */
        $public = $private->getPublicKey();

        return [$private->toString('PKCS1'), $public->toString('PKCS1')];
    }
}
