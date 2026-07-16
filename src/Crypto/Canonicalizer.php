<?php

declare(strict_types=1);

namespace YoPay\Crypto;

use YoPay\Exception\CryptoException;

final class Canonicalizer
{
    /**
     * Produce protocol JSON: sorted object keys and no insignificant whitespace.
     * A string input is treated as a JSON document, matching the Go SDK API.
     */
    public static function json(mixed $value): string
    {
        try {
            if ($value === null) {
                return '{}';
            }
            $raw = is_string($value) ? $value : self::encodeInput($value);
            if (self::isJsonWhitespaceOnly($raw)) {
                return '{}';
            }

            return self::encodeValue((new ProtocolJsonParser($raw))->parse());
        } catch (CryptoException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CryptoException('invalid JSON for canonicalization', previous: $exception);
        }
    }

    /** @param array<string, scalar|list<scalar>|null> $query */
    public static function query(array $query): string
    {
        $keys = [];
        foreach ($query as $key => $_) {
            $key = trim((string) $key);
            if ($key === '' || self::isSignatureKey($key)) {
                continue;
            }
            $keys[] = $key;
        }
        sort($keys, SORT_STRING);

        $parts = [];
        foreach ($keys as $key) {
            $values = $query[$key] ?? [];
            if (!is_array($values)) {
                $values = [$values];
            }
            $normalized = [];
            foreach ($values as $value) {
                if (is_bool($value)) {
                    $normalized[] = $value ? 'true' : 'false';
                    continue;
                }
                $normalized[] = (string) $value;
            }
            sort($normalized, SORT_STRING);
            foreach ($normalized as $value) {
                $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return implode('&', $parts);
    }

    /** @param array<string, scalar|list<scalar>|null> $query */
    public static function queryString(array $query): string
    {
        $keys = array_keys($query);
        sort($keys, SORT_STRING);
        $parts = [];
        foreach ($keys as $key) {
            $values = $query[$key];
            if (!is_array($values)) {
                $values = [$values];
            }
            $normalized = [];
            foreach ($values as $value) {
                if (is_bool($value)) {
                    $normalized[] = $value ? 'true' : 'false';
                    continue;
                }
                $normalized[] = (string) $value;
            }
            sort($normalized, SORT_STRING);
            foreach ($normalized as $value) {
                $parts[] = rawurlencode((string) $key) . '=' . rawurlencode($value);
            }
        }

        return implode('&', $parts);
    }

    public static function payloadDigest(string $canonicalPayload): string
    {
        return hash('sha256', $canonicalPayload);
    }

    public static function signText(
        string $method,
        string $path,
        string $mchId,
        string $timestamp,
        string $nonce,
        string $requestId,
        string $canonicalPayload,
    ): string {
        return implode("\n", [
            strtoupper(trim($method)),
            trim($path),
            trim($mchId),
            trim($timestamp),
            trim($nonce),
            trim($requestId),
            self::payloadDigest($canonicalPayload),
        ]);
    }

    private static function encodeInput(mixed $value): string
    {
        try {
            return json_encode($value, self::jsonFlags() | JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CryptoException('cannot encode canonical JSON', previous: $exception);
        }
    }

    private static function encodeValue(mixed $value): string
    {
        if ($value instanceof RawNumber) {
            return $value->value;
        }
        if ($value instanceof CanonicalObject) {
            $keys = array_keys($value->fields);
            usort($keys, self::compareUnicodeCodePoints(...));
            $parts = [];
            foreach ($keys as $key) {
                $parts[] = self::encodeScalar($key) . ':' . self::encodeValue($value->fields[$key]);
            }

            return '{' . implode(',', $parts) . '}';
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::encodeValue($item);
            }

            return '[' . implode(',', $parts) . ']';
        }

        return self::encodeScalar($value);
    }

    private static function encodeScalar(mixed $value): string
    {
        try {
            $encoded = json_encode($value, self::jsonFlags() | JSON_THROW_ON_ERROR);

            return preg_replace_callback(
                '/\\\\u([0-9a-fA-F]{4})/',
                static fn (array $match): string => '\\u' . strtolower($match[1]),
                $encoded,
            ) ?? throw new CryptoException('cannot normalize canonical JSON escapes');
        } catch (\JsonException $exception) {
            throw new CryptoException('cannot encode canonical JSON', previous: $exception);
        }
    }

    private static function isSignatureKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return $key === 'sign' || $key === 'signature';
    }

    private static function jsonFlags(): int
    {
        return JSON_HEX_AMP
            | JSON_HEX_TAG
            | JSON_PRESERVE_ZERO_FRACTION
            | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE;
    }

    private static function isJsonWhitespaceOnly(string $value): bool
    {
        return preg_match('/^[ \t\r\n]*$/D', $value) === 1;
    }

    private static function compareUnicodeCodePoints(string $left, string $right): int
    {
        $leftPoints = self::unicodeCodePoints($left);
        $rightPoints = self::unicodeCodePoints($right);
        $count = min(count($leftPoints), count($rightPoints));
        for ($index = 0; $index < $count; $index++) {
            if ($leftPoints[$index] !== $rightPoints[$index]) {
                return $leftPoints[$index] <=> $rightPoints[$index];
            }
        }

        return count($leftPoints) <=> count($rightPoints);
    }

    /** @return list<int> */
    private static function unicodeCodePoints(string $value): array
    {
        $count = preg_match_all('/./us', $value, $matches);
        if ($count === false || $count === 0) {
            return [];
        }
        $points = [];
        foreach ($matches[0] as $character) {
            $bytes = array_values(unpack('C*', $character) ?: []);
            if (count($bytes) === 1) {
                $points[] = $bytes[0];
            } elseif (count($bytes) === 2) {
                $points[] = (($bytes[0] & 0x1f) << 6) | ($bytes[1] & 0x3f);
            } elseif (count($bytes) === 3) {
                $points[] = (($bytes[0] & 0x0f) << 12) | (($bytes[1] & 0x3f) << 6) | ($bytes[2] & 0x3f);
            } else {
                $points[] = (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3f) << 12)
                    | (($bytes[2] & 0x3f) << 6) | ($bytes[3] & 0x3f);
            }
        }

        return $points;
    }
}
