<?php

declare(strict_types=1);

namespace YoPay\Crypto;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;
use Throwable;
use YoPay\Exception\CryptoException;

/**
 * Implements the fixed YoPay envelope: RSA-OAEP-SHA256(content key) and
 * AES-256-GCM(canonical business JSON).
 */
final class EnvelopeCipher
{
    private const CONTENT_KEY_BYTES = 32;

    private const NONCE_BYTES = 12;

    private const GCM_TAG_BYTES = 16;

    public static function encrypt(string $receiverPublicKey, string $plainJson): Envelope
    {
        try {
            $contentKey = random_bytes(self::CONTENT_KEY_BYTES);
            $nonce = random_bytes(self::NONCE_BYTES);
            $publicKey = self::loadPublicKey($receiverPublicKey);
            $encryptedKey = self::configureOaep($publicKey)->encrypt($contentKey);
            if (!is_string($encryptedKey)) {
                throw new CryptoException('RSA-OAEP encryption failed');
            }
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plainJson,
                'aes-256-gcm',
                $contentKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                '',
                self::GCM_TAG_BYTES,
            );
            if ($ciphertext === false || strlen($tag) !== self::GCM_TAG_BYTES) {
                throw new CryptoException('AES-256-GCM encryption failed');
            }

            return new Envelope(base64_encode($encryptedKey), base64_encode($nonce . $ciphertext . $tag));
        } catch (CryptoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CryptoException('encrypted envelope creation failed', previous: $exception);
        }
    }

    public static function decrypt(string $receiverPrivateKey, Envelope $envelope): string
    {
        try {
            $encryptedKey = base64_decode(trim($envelope->encryptedKey), true);
            $payload = base64_decode(trim($envelope->bizContent), true);
            if ($encryptedKey === false || $payload === false) {
                throw new CryptoException('encrypted envelope must use valid Base64');
            }
            if (strlen($payload) < self::NONCE_BYTES + self::GCM_TAG_BYTES) {
                throw new CryptoException('biz_content is too short');
            }

            $privateKey = self::loadPrivateKey($receiverPrivateKey);
            $contentKey = self::configureOaep($privateKey)->decrypt($encryptedKey);
            if (!is_string($contentKey)) {
                throw new CryptoException('RSA-OAEP decryption failed');
            }
            if (strlen($contentKey) !== self::CONTENT_KEY_BYTES) {
                throw new CryptoException('RSA-OAEP content key is invalid');
            }

            $nonce = substr($payload, 0, self::NONCE_BYTES);
            $tag = substr($payload, -self::GCM_TAG_BYTES);
            $ciphertext = substr($payload, self::NONCE_BYTES, -self::GCM_TAG_BYTES);
            $plain = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $contentKey,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
            );
            if ($plain === false) {
                throw new CryptoException('AES-256-GCM decryption failed');
            }

            return $plain;
        } catch (CryptoException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CryptoException('encrypted envelope decryption failed', previous: $exception);
        }
    }

    private static function loadPublicKey(string $raw): PublicKey
    {
        foreach (self::pemCandidates($raw, false) as $pem) {
            try {
                $key = PublicKeyLoader::load($pem);
                if ($key instanceof PublicKey) {
                    return $key;
                }
            } catch (Throwable) {
                // Try the other supported PEM container before returning a safe error.
            }
        }

        throw new CryptoException('invalid RSA public key');
    }

    private static function loadPrivateKey(string $raw): PrivateKey
    {
        foreach (self::pemCandidates($raw, true) as $pem) {
            try {
                $key = PublicKeyLoader::load($pem);
                if ($key instanceof PrivateKey) {
                    return $key;
                }
            } catch (Throwable) {
                // Try the other supported PEM container before returning a safe error.
            }
        }

        throw new CryptoException('invalid RSA private key');
    }

    /**
     * @template T of PublicKey|PrivateKey
     * @param T $key
     * @return T
     */
    private static function configureOaep(PublicKey|PrivateKey $key): PublicKey|PrivateKey
    {
        /** @var T $configured */
        $configured = $key
            ->withPadding(RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');

        return $configured;
    }

    /**
     * @return list<string>
     */
    private static function pemCandidates(string $raw, bool $private): array
    {
        $raw = trim(str_replace(["\\r\\n", "\\n"], "\n", $raw));
        if ($raw === '') {
            return [];
        }
        if (str_contains($raw, '-----BEGIN ')) {
            return [$raw];
        }

        $body = preg_replace('/\s+/', '', $raw);
        if (!is_string($body) || $body === '') {
            return [];
        }
        if ($private) {
            return [
                self::wrapPem('PRIVATE KEY', $body),
                self::wrapPem('RSA PRIVATE KEY', $body),
            ];
        }

        return [
            self::wrapPem('PUBLIC KEY', $body),
            self::wrapPem('RSA PUBLIC KEY', $body),
        ];
    }

    private static function wrapPem(string $type, string $body): string
    {
        return sprintf("-----BEGIN %s-----\n%s\n-----END %s-----", $type, chunk_split($body, 64, "\n"), $type);
    }
}
