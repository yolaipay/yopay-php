<?php

declare(strict_types=1);

namespace YoPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YoPay\Crypto\Envelope;
use YoPay\Crypto\EnvelopeCipher;
use YoPay\Exception\CryptoException;
use YoPay\Tests\Support\FixtureKeys;

final class EnvelopeCipherTest extends TestCase
{
    public function testEncryptsAndDecryptsEnvelope(): void
    {
        [$private, $public] = FixtureKeys::pair();
        $plain = '{"amount":"100.000","merchant_order_no":"MO-001"}';
        $envelope = EnvelopeCipher::encrypt($public, $plain);

        self::assertNotSame('', $envelope->encryptedKey);
        self::assertNotSame('', $envelope->bizContent);
        self::assertSame($plain, EnvelopeCipher::decrypt($private, $envelope));
    }

    public function testAcceptsPublicKeyBodyAndEscapedPrivateKeyNewlines(): void
    {
        [$private, $public] = FixtureKeys::pair();
        $publicBody = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $public);
        self::assertIsString($publicBody);
        $envelope = EnvelopeCipher::encrypt($publicBody, '{"ok":true}');

        self::assertSame('{"ok":true}', EnvelopeCipher::decrypt(str_replace("\n", '\\n', $private), $envelope));
    }

    public function testAcceptsPkcs1KeysAndRejectsWrongPrivateKey(): void
    {
        [$private, $public] = FixtureKeys::pkcs1Pair();
        [$wrongPrivate] = FixtureKeys::pair();
        $envelope = EnvelopeCipher::encrypt($public, '{"ok":true}');

        self::assertSame('{"ok":true}', EnvelopeCipher::decrypt($private, $envelope));

        $this->expectException(CryptoException::class);
        EnvelopeCipher::decrypt($wrongPrivate, $envelope);
    }

    public function testRejectsTamperedCiphertext(): void
    {
        [$private, $public] = FixtureKeys::pair();
        $envelope = EnvelopeCipher::encrypt($public, '{"ok":true}');
        $payload = base64_decode($envelope->bizContent, true);
        self::assertNotFalse($payload);
        $payload[13] = chr(ord($payload[13]) ^ 1);

        $this->expectException(CryptoException::class);
        EnvelopeCipher::decrypt($private, new Envelope($envelope->encryptedKey, base64_encode($payload)));
    }

    public function testAcceptsAnEmptyPlaintextEnvelope(): void
    {
        [$private, $public] = FixtureKeys::pair();
        $envelope = EnvelopeCipher::encrypt($public, '');

        self::assertSame('', EnvelopeCipher::decrypt($private, $envelope));
    }
}
