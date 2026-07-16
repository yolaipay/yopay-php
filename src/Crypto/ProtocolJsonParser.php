<?php

declare(strict_types=1);

namespace YoPay\Crypto;

use YoPay\Exception\CryptoException;

/** @internal Strict JSON parser that preserves number lexemes for signing. */
final class ProtocolJsonParser
{
    private int $index = 0;

    public function __construct(private readonly string $source)
    {
    }

    public function parse(): mixed
    {
        $this->skipWhitespace();
        $value = $this->parseValue();
        $this->skipWhitespace();
        if ($this->index !== strlen($this->source)) {
            throw $this->invalid();
        }

        return $value;
    }

    private function parseValue(): mixed
    {
        $value = $this->peek();
        return match ($value) {
            '{' => $this->parseObject(),
            '[' => $this->parseArray(),
            '"' => $this->parseString(),
            't' => $this->literal('true', true),
            'f' => $this->literal('false', false),
            'n' => $this->literal('null', null),
            '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->parseNumber(),
            default => throw $this->invalid(),
        };
    }

    private function parseObject(): CanonicalObject
    {
        $this->expect('{');
        $this->skipWhitespace();
        $fields = [];
        if ($this->consume('}')) {
            return new CanonicalObject($fields);
        }
        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                throw $this->invalid();
            }
            $key = $this->parseString();
            $this->skipWhitespace();
            $this->expect(':');
            $this->skipWhitespace();
            $fields[$key] = $this->parseValue();
            $this->skipWhitespace();
            if ($this->consume('}')) {
                return new CanonicalObject($fields);
            }
            $this->expect(',');
        }
    }

    /** @return list<mixed> */
    private function parseArray(): array
    {
        $this->expect('[');
        $this->skipWhitespace();
        $items = [];
        if ($this->consume(']')) {
            return $items;
        }
        while (true) {
            $items[] = $this->parseValue();
            $this->skipWhitespace();
            if ($this->consume(']')) {
                return $items;
            }
            $this->expect(',');
            $this->skipWhitespace();
        }
    }

    private function parseString(): string
    {
        $this->expect('"');
        $result = '';
        while ($this->index < strlen($this->source)) {
            $character = $this->source[$this->index++];
            if ($character === '"') {
                return $result;
            }
            if ($character === '\\') {
                $escaped = $this->source[$this->index] ?? '';
                $this->index++;
                $result .= match ($escaped) {
                    '"', '\\', '/' => $escaped,
                    'b' => "\x08",
                    'f' => "\x0c",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'u' => $this->parseUnicodeEscape(),
                    default => throw $this->invalid(),
                };
                continue;
            }
            if (ord($character) < 0x20) {
                throw $this->invalid();
            }
            $result .= $character;
        }
        throw $this->invalid();
    }

    private function parseUnicodeEscape(): string
    {
        $unit = $this->parseHex4();
        if ($unit >= 0xd800 && $unit <= 0xdbff) {
            if (!$this->consume('\\') || !$this->consume('u')) {
                throw $this->invalid();
            }
            $low = $this->parseHex4();
            if ($low < 0xdc00 || $low > 0xdfff) {
                throw $this->invalid();
            }
            $point = 0x10000 + (($unit - 0xd800) << 10) + ($low - 0xdc00);
            return $this->utf8($point);
        }
        if ($unit >= 0xdc00 && $unit <= 0xdfff) {
            throw $this->invalid();
        }

        return $this->utf8($unit);
    }

    private function parseNumber(): RawNumber
    {
        $start = $this->index;
        $this->consume('-');
        if ($this->consume('0')) {
            // Any following digit remains trailing data and is rejected by the caller.
        } else {
            $this->requireDigit('1', '9');
            while ($this->isDigit($this->peek())) {
                $this->index++;
            }
        }
        if ($this->consume('.')) {
            $this->requireDigit('0', '9');
            while ($this->isDigit($this->peek())) {
                $this->index++;
            }
        }
        if ($this->consume('e') || $this->consume('E')) {
            if ($this->peek() === '+' || $this->peek() === '-') {
                $this->index++;
            }
            $this->requireDigit('0', '9');
            while ($this->isDigit($this->peek())) {
                $this->index++;
            }
        }

        return new RawNumber(substr($this->source, $start, $this->index - $start));
    }

    private function literal(string $literal, mixed $value): mixed
    {
        if (!str_starts_with(substr($this->source, $this->index), $literal)) {
            throw $this->invalid();
        }
        $this->index += strlen($literal);

        return $value;
    }

    private function parseHex4(): int
    {
        $hex = substr($this->source, $this->index, 4);
        if (strlen($hex) !== 4 || preg_match('/^[0-9a-fA-F]{4}$/D', $hex) !== 1) {
            throw $this->invalid();
        }
        $this->index += 4;

        return (int) hexdec($hex);
    }

    private function utf8(int $point): string
    {
        if ($point <= 0x7f) {
            return chr($point);
        }
        if ($point <= 0x7ff) {
            return chr(0xc0 | ($point >> 6)) . chr(0x80 | ($point & 0x3f));
        }
        if ($point <= 0xffff) {
            return chr(0xe0 | ($point >> 12)) . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
        }

        return chr(0xf0 | ($point >> 18)) . chr(0x80 | (($point >> 12) & 0x3f))
            . chr(0x80 | (($point >> 6) & 0x3f)) . chr(0x80 | ($point & 0x3f));
    }

    private function skipWhitespace(): void
    {
        while (in_array($this->peek(), [' ', "\t", "\r", "\n"], true)) {
            $this->index++;
        }
    }

    private function requireDigit(string $minimum, string $maximum): void
    {
        $value = $this->peek();
        if ($value < $minimum || $value > $maximum) {
            throw $this->invalid();
        }
        $this->index++;
    }

    private function consume(string $expected): bool
    {
        if ($this->peek() !== $expected) {
            return false;
        }
        $this->index++;

        return true;
    }

    private function expect(string $expected): void
    {
        if (!$this->consume($expected)) {
            throw $this->invalid();
        }
    }

    private function peek(): string
    {
        return $this->source[$this->index] ?? '';
    }

    private function isDigit(string $value): bool
    {
        return $value >= '0' && $value <= '9';
    }

    private function invalid(): CryptoException
    {
        return new CryptoException('invalid JSON for canonicalization');
    }
}
