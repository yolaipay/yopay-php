<?php

declare(strict_types=1);

namespace YoPay;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use YoPay\Exception\SignatureException;

/** @internal Shared timestamp window and replay checks for signed inbound messages. */
final class ProtocolSecurity
{
    public const SIGNATURE_WINDOW_SECONDS = 300;

    private function __construct()
    {
    }

    public static function validateAndClaim(
        ReplayProtector $protector,
        string $kind,
        string $mchId,
        string $timestamp,
        string $nonce,
        string $requestId,
        DateTimeImmutable $now,
    ): void {
        $timestamp = trim($timestamp);
        if ($timestamp === '' || preg_match('/^[1-9][0-9]*$/', $timestamp) !== 1) {
            throw new SignatureException('signature timestamp is invalid or expired', $requestId);
        }
        try {
            $seconds = (int) $timestamp;
            $at = (new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            throw new SignatureException('signature timestamp is invalid or expired', $requestId, $exception);
        }
        if (abs($now->getTimestamp() - $at->getTimestamp()) > self::SIGNATURE_WINDOW_SECONDS) {
            throw new SignatureException('signature timestamp is invalid or expired', $requestId);
        }
        $nonce = trim($nonce);
        if ($nonce === '') {
            throw new SignatureException('signature nonce is required', $requestId);
        }
        $key = trim($kind) . ':' . trim($mchId) . ':' . $nonce;
        try {
            $claimed = $protector->claim(
                $key,
                $at->modify('+' . (self::SIGNATURE_WINDOW_SECONDS * 2) . ' seconds'),
                $now,
            );
        } catch (Throwable $exception) {
            throw new SignatureException('replay protection failed', $requestId, $exception);
        }
        if (!$claimed) {
            throw new SignatureException('signature nonce replay detected', $requestId);
        }
    }
}
