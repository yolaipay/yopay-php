<?php

declare(strict_types=1);

namespace YoPay\Tests\Unit;

use PHPUnit\Framework\TestCase;
use YoPay\Crypto\Canonicalizer;
use YoPay\Crypto\Signer;

final class CanonicalizerTest extends TestCase
{
    public function testProtocolVectorsMatchGoSdk(): void
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/protocol_vectors.json');
        self::assertNotFalse($content);
        /** @var array{vectors: list<array<string, mixed>>} $fixture */
        $fixture = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        foreach ($fixture['vectors'] as $vector) {
            $canonical = $vector['method'] === 'GET'
                ? Canonicalizer::query($vector['query'])
                : Canonicalizer::json($vector['json_payload']);
            self::assertSame($vector['canonical_payload'], $canonical, (string) $vector['name']);
            self::assertSame(
                $vector['payload_digest'],
                Canonicalizer::payloadDigest($canonical),
                (string) $vector['name'],
            );
            $signText = Canonicalizer::signText(
                (string) $vector['method'],
                (string) $vector['path'],
                (string) $vector['mch_id'],
                (string) $vector['timestamp'],
                (string) $vector['nonce'],
                (string) $vector['request_id'],
                $canonical,
            );
            self::assertSame($vector['sign_text'], $signText, (string) $vector['name']);
            self::assertSame(
                $vector['signature'],
                Signer::sign((string) $vector['secret_key'], $signText),
                (string) $vector['name'],
            );
            self::assertTrue(
                Signer::verify((string) $vector['secret_key'], $signText, (string) $vector['signature']),
            );
        }
    }

    public function testCanonicalQueryExcludesSignaturesAndUsesRfc3986Encoding(): void
    {
        self::assertSame(
            'a=second&a=two%20words&z=x%2Fy',
            Canonicalizer::query([
                'signature' => 'not-signed',
                'SIGN' => 'also-not-signed',
                'z' => 'x/y',
                'a' => ['two words', 'second'],
            ]),
        );
    }

    public function testCanonicalJsonSortsNestedObjectKeys(): void
    {
        self::assertSame(
            '{"a":{"a":1,"b":2},"z":[{"a":"x","b":"y"}]}',
            Canonicalizer::json('{"z":[{"b":"y","a":"x"}],"a":{"b":2,"a":1}}'),
        );
    }

    public function testCanonicalJsonPreservesNumberLexemesAndRejectsNonJsonWhitespaceAndTrailingData(): void
    {
        self::assertSame(
            '{"a":{"c":3,"d":4},"n":10.50,"z":[2,{"a":null,"b":true}]}',
            Canonicalizer::json(' { "z" : [2, {"b":true,"a":null}], "a" : {"d":4,"c":3}, "n": 10.50 } '),
        );
        self::assertSame('{"a":1,"😀":2}', Canonicalizer::json('{"😀":2,"a":1}'));
        $this->expectException(\YoPay\Exception\CryptoException::class);
        Canonicalizer::json("{\"a\":1}\u{2003}");
    }

    public function testCanonicalJsonRejectsTrailingJsonData(): void
    {
        $this->expectException(\YoPay\Exception\CryptoException::class);
        Canonicalizer::json('{"a":1}{"b":2}');
    }

    public function testCanonicalJsonEscapesProtocolSensitiveCharactersAndSortsNonBmpKeys(): void
    {
        self::assertSame(
            '{"unsafe":"\\u003cscript\\u003e\\u0026\\u003c/script\\u003e"}',
            Canonicalizer::json(['unsafe' => '<script>&</script>']),
        );
        self::assertSame('{"":1,"😀":2}', Canonicalizer::json('{"😀":2,"":1}'));
    }
}
