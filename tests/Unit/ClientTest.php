<?php

declare(strict_types=1);

namespace YoPay\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;
use DateTimeZone;
use YoPay\Client;
use YoPay\Config;
use YoPay\Crypto\Canonicalizer;
use YoPay\Crypto\Envelope;
use YoPay\Crypto\EnvelopeCipher;
use YoPay\Crypto\Signer;
use YoPay\Dto\OrderRequest;
use YoPay\Dto\Payer;
use YoPay\Dto\RefundRequest;
use YoPay\Dto\WithdrawPayee;
use YoPay\Dto\WithdrawRequest;
use YoPay\Dto\CloseOrderRequest;
use YoPay\Dto\QueryOrderRequest;
use YoPay\Dto\QueryWithdrawRequest;
use YoPay\Dto\BillsRequest;
use YoPay\Dto\PayNotify;
use YoPay\Exception\ApiException;
use YoPay\Exception\ConfigException;
use YoPay\Exception\SignatureException;
use YoPay\Http\Transport;
use YoPay\Tests\Support\CallbackHttpClient;
use YoPay\Tests\Support\FixtureKeys;
use YoPay\Tests\Support\RecordingLogger;

final class ClientTest extends TestCase
{
    private const MCH_ID = 'MR20260703000001';

    private const SECRET_KEY = 'test-signing-key-001';

    public function testOrderSendsOnlyEncryptedEnvelopeAndDecryptsResponse(): void
    {
        [$platformPrivate, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $httpClient = new CallbackHttpClient(function ($request) use ($platformPrivate, $merchantPublic): Response {
            self::assertSame('POST', $request->getMethod());
            self::assertSame('/api/v1/open/pay/order', $request->getUri()->getPath());
            self::assertSame('', $request->getHeaderLine('X-Access-Key'));
            self::assertSame('', $request->getHeaderLine('X-Secret-Key'));
            $body = (string) $request->getBody();
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['biz_content', 'encrypted_key'], array_keys($payload));
            self::assertStringNotContainsString('merchant_order_no', $body);
            $plain = EnvelopeCipher::decrypt($platformPrivate, Envelope::fromArray($payload));
            self::assertSame(
                '{"amount":"100.000","currency_code":"USD","merchant_order_no":"MO-001",'
                . '"payer":{"account_no":"openid-001","account_type":3,"merchant_user_id":"U-001"},"scene":"qr"}',
                $plain,
            );
            self::assertSignedRequest($request, 'POST', '/api/v1/open/pay/order', $body);

                return self::encryptedPostResponse('/api/v1/open/pay/order', $merchantPublic, [
                    'trade_no' => 'T-PAYIN',
                    'merchant_order_no' => 'MO-001',
                    'status' => 1,
                ], $request->getHeaderLine('X-Request-Id'));
        });
        $client = self::client($httpClient, $platformPublic, $merchantPrivate);

        $response = $client->order(new OrderRequest(
            'MO-001',
            '100.000',
            'USD',
            'qr',
            new Payer('U-001', 3, 'openid-001'),
        ));

        self::assertSame('T-PAYIN', $response->tradeNo);
        self::assertSame('MO-001', $response->merchantOrderNo);
        self::assertSame(1, $response->status);
    }

    public function testGetQueryUsesCanonicalSignatureAndCodeResponse(): void
    {
        $httpClient = new CallbackHttpClient(function ($request): Response {
            self::assertSame('GET', $request->getMethod());
            self::assertSame('/api/v1/open/pay/query', $request->getUri()->getPath());
            self::assertSame('merchant_order_no=MO-001&trade_no=T-001&trade_type=1', $request->getUri()->getQuery());
            self::assertSignedRequest(
                $request,
                'GET',
                '/api/v1/open/pay/query',
                'merchant_order_no=MO-001&trade_no=T-001&trade_type=1',
            );

            return new Response(200, ['X-Request-Id' => 'server-request'], json_encode([
                'code' => 0,
                'msg' => 'success',
                'data' => ['trade_no' => 'T-001', 'merchant_order_no' => 'MO-001', 'status' => 2],
            ], JSON_THROW_ON_ERROR));
        });
        $client = self::client($httpClient);

        $response = $client->queryOrder(new QueryOrderRequest('T-001', 'MO-001', 1));

        self::assertSame('T-001', $response->tradeNo);
        self::assertSame(2, $response->status);
    }

    public function testPostBusinessErrorDoesNotAttemptResponseDecryption(): void
    {
        [, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate] = FixtureKeys::pair();
        $httpClient = new CallbackHttpClient(
            static fn (): Response => new Response(
                200,
                ['X-Request-Id' => 'error-request'],
                '{"code":11015,"msg":"invalid envelope","data":null}',
            ),
        );
        $client = self::client($httpClient, $platformPublic, $merchantPrivate);

        try {
            $client->order(new OrderRequest('MO-001', '100.000', 'USD', 'qr', new Payer('U-001', 3, 'openid-001')));
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertSame(11015, $exception->getCode());
            self::assertSame('error-request', $exception->requestId);
        }
    }

    public function testNotificationUsesRealPathAndRawBody(): void
    {
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $client = self::client(new CallbackHttpClient(static fn (): Response => new Response()), '', $merchantPrivate);
        $envelope = EnvelopeCipher::encrypt($merchantPublic, Canonicalizer::json([
            'notify_type' => 'payin.success',
            'trade_no' => 'T-NOTIFY',
            'status' => 2,
        ]));
        $body = Canonicalizer::json($envelope->toArray());
        $timestamp = self::currentTimestamp();
        $nonce = 'notify-nonce';
        $path = '/merchant/pay/notify';
        $requestId = 'notify-request';
        $signature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText('POST', $path, self::MCH_ID, $timestamp, $nonce, $requestId, $body),
        );

        $notify = $client->verifyAndDecryptNotify($path, [
            'X-Mch-Id' => self::MCH_ID,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Request-Id' => $requestId,
        ], $body);
        self::assertInstanceOf(PayNotify::class, $notify);
        self::assertSame('T-NOTIFY', $notify->tradeNo);

        $this->expectException(SignatureException::class);
        $client->verifyAndDecryptNotify('/api/v1/open/pay/order', [
            'X-Mch-Id' => self::MCH_ID,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Request-Id' => $requestId,
        ], $body);
    }

    public function testPostResponseAndNotificationRejectTamperedSignatures(): void
    {
        [, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $httpClient = new CallbackHttpClient(
            static function ($request) use ($merchantPublic): Response {
                $response = self::encryptedPostResponse(
                    $request->getUri()->getPath(),
                    $merchantPublic,
                    ['trade_no' => 'T-SIGNED'],
                    $request->getHeaderLine('X-Request-Id'),
                );

                return $response->withHeader('X-Signature', str_repeat('0', 64));
            },
        );
        $client = self::client($httpClient, $platformPublic, $merchantPrivate);

        $this->expectException(SignatureException::class);
        $client->order(new OrderRequest('MO-SIGNED', '1.000', 'USD', 'qr', new Payer('U-001', 3, 'openid-001')));
    }

    public function testPostResponseRejectsWrongMerchantRequestIdReplayAndExpiredTimestamp(): void
    {
        [, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $wrongMerchantClient = self::client(new CallbackHttpClient(
            static function ($request) use ($merchantPublic): Response {
                $response = self::encryptedPostResponse(
                    $request->getUri()->getPath(),
                    $merchantPublic,
                    ['trade_no' => 'T-RESULT'],
                    $request->getHeaderLine('X-Request-Id'),
                );

                return $response->withHeader('X-Mch-Id', 'MR-WRONG');
            },
        ), $platformPublic, $merchantPrivate);
        $this->expectException(SignatureException::class);
        $wrongMerchantClient->closeOrder(new CloseOrderRequest('T-CLOSE'));
    }

    public function testPostResponseRejectsMismatchedRequestIdReplayAndExpiredTimestamp(): void
    {
        [, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $mismatchClient = self::client(new CallbackHttpClient(
            static fn ($request): Response => self::encryptedPostResponse(
                $request->getUri()->getPath(),
                $merchantPublic,
                ['trade_no' => 'T-RESULT'],
                'wrong-request',
            ),
        ), $platformPublic, $merchantPrivate);
        try {
            $mismatchClient->closeOrder(new CloseOrderRequest('T-CLOSE'));
            self::fail('Expected mismatched request ID rejection');
        } catch (SignatureException) {
            self::addToAssertionCount(1);
        }

        $replayClient = self::client(new CallbackHttpClient(
            static fn ($request): Response => self::encryptedPostResponse(
                $request->getUri()->getPath(),
                $merchantPublic,
                ['trade_no' => 'T-RESULT'],
                $request->getHeaderLine('X-Request-Id'),
                self::currentTimestamp(),
                'replay-response-nonce',
            ),
        ), $platformPublic, $merchantPrivate);
        self::assertSame('T-RESULT', $replayClient->closeOrder(new CloseOrderRequest('T-ONE'))->tradeNo);
        $this->expectException(SignatureException::class);
        $replayClient->closeOrder(new CloseOrderRequest('T-TWO'));
    }

    public function testPostResponseRejectsExpiredTimestamp(): void
    {
        [, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $client = self::client(new CallbackHttpClient(
            static fn ($request): Response => self::encryptedPostResponse(
                $request->getUri()->getPath(),
                $merchantPublic,
                ['trade_no' => 'T-RESULT'],
                $request->getHeaderLine('X-Request-Id'),
                '1',
                'expired-response-nonce',
            ),
        ), $platformPublic, $merchantPrivate);
        $this->expectException(SignatureException::class);
        $client->closeOrder(new CloseOrderRequest('T-STALE'));
    }

    public function testNotificationRejectsMerchantMismatchReplayAndExpiredTimestamp(): void
    {
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $client = self::client(new CallbackHttpClient(static fn (): Response => new Response()), '', $merchantPrivate);
        $path = '/merchant/pay/notify';
        $body = Canonicalizer::json(EnvelopeCipher::encrypt($merchantPublic, Canonicalizer::json([
            'notify_type' => 'payin.success', 'trade_no' => 'T-NOTIFY', 'status' => 2,
        ])));
        $timestamp = self::currentTimestamp();
        $requestId = 'notify-security';
        $nonce = 'notify-security-nonce';
        $signature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText('POST', $path, self::MCH_ID, $timestamp, $nonce, $requestId, $body),
        );
        $headers = [
            'X-Mch-Id' => self::MCH_ID,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Request-Id' => $requestId,
        ];
        self::assertSame('T-NOTIFY', $client->verifyAndDecryptNotify($path, $headers, $body)->tradeNo);
        try {
            $client->verifyAndDecryptNotify($path, $headers, $body);
            self::fail('Expected replay rejection');
        } catch (SignatureException) {
            self::addToAssertionCount(1);
        }

        $wrongRequestId = 'wrong-merchant-notify';
        $wrongNonce = 'wrong-merchant-nonce';
        $wrongSignature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText('POST', $path, self::MCH_ID, $timestamp, $wrongNonce, $wrongRequestId, $body),
        );
        try {
            $client->verifyAndDecryptNotify($path, [
                'X-Mch-Id' => 'MR-WRONG',
                'X-Timestamp' => $timestamp,
                'X-Nonce' => $wrongNonce,
                'X-Signature' => $wrongSignature,
                'X-Request-Id' => $wrongRequestId,
            ], $body);
            self::fail('Expected merchant mismatch rejection');
        } catch (SignatureException) {
            self::addToAssertionCount(1);
        }

        $staleRequestId = 'stale-notify';
        $staleNonce = 'stale-notify-nonce';
        $staleSignature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText('POST', $path, self::MCH_ID, '1', $staleNonce, $staleRequestId, $body),
        );
        $this->expectException(SignatureException::class);
        $client->verifyAndDecryptNotify($path, [
            'X-Mch-Id' => self::MCH_ID,
            'X-Timestamp' => '1',
            'X-Nonce' => $staleNonce,
            'X-Signature' => $staleSignature,
            'X-Request-Id' => $staleRequestId,
        ], $body);
    }

    public function testNotificationRejectsTamperedBody(): void
    {
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $client = self::client(new CallbackHttpClient(static fn (): Response => new Response()), '', $merchantPrivate);
        $envelope = EnvelopeCipher::encrypt($merchantPublic, Canonicalizer::json(['trade_no' => 'T-NOTIFY']));
        $body = Canonicalizer::json($envelope->toArray());
        $signature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText(
                'POST',
                '/merchant/pay/notify',
                self::MCH_ID,
                self::currentTimestamp(),
                'nonce',
                'tampered-notify',
                $body
            ),
        );
        $tamperedBody = substr_replace($body, 'A', -2, 1);

        $this->expectException(SignatureException::class);
        $client->verifyAndDecryptNotify('/merchant/pay/notify', [
            'X-Mch-Id' => self::MCH_ID,
            'X-Timestamp' => self::currentTimestamp(),
            'X-Nonce' => 'nonce',
            'X-Signature' => $signature,
            'X-Request-Id' => 'tampered-notify',
        ], $tamperedBody);
    }

    public function testEveryBusinessMethodUsesItsContractPathAndNoProviderId(): void
    {
        [$platformPrivate, $platformPublic] = FixtureKeys::pair();
        [$merchantPrivate, $merchantPublic] = FixtureKeys::pair();
        $seen = [];
        $httpClient = new CallbackHttpClient(
            function ($request) use (&$seen, $platformPrivate, $merchantPublic): Response {
                $path = $request->getUri()->getPath();
                $seen[] = $request->getMethod() . ' ' . $path;
                if ($request->getMethod() === 'POST') {
                    $body = (string) $request->getBody();
                    $envelope = Envelope::fromArray(json_decode($body, true, 512, JSON_THROW_ON_ERROR));
                    $plain = EnvelopeCipher::decrypt($platformPrivate, $envelope);
                    self::assertStringNotContainsString('provider_id', $plain);

                    return self::encryptedPostResponse(
                        $path,
                        $merchantPublic,
                        ['trade_no' => 'T-' . $path, 'status' => 1],
                        $request->getHeaderLine('X-Request-Id'),
                    );
                }

                self::assertStringNotContainsString('provider_id', $request->getUri()->getQuery());

                return new Response(200, [], json_encode([
                'code' => 0,
                'data' => $path === '/api/v1/open/bill/list'
                    ? [
                        'total' => 1,
                        'page' => 1,
                        'page_size' => 20,
                        'list' => [['account_transaction_id' => 'BILL-001']],
                    ]
                    : ['trade_no' => 'T-QUERY', 'status' => 2],
                ], JSON_THROW_ON_ERROR));
            },
        );
        $client = self::client($httpClient, $platformPublic, $merchantPrivate);

        $client->refund(new RefundRequest('T-ORIGIN', 'MO-REFUND', '2.000'));
        $client->withdraw(new WithdrawRequest(
            'MO-PAYOUT',
            '30.000',
            'USD',
            new WithdrawPayee(1, 'Alice', '6222000000000000000'),
        ));
        $client->closeOrder(new CloseOrderRequest(tradeNo: 'T-CLOSE', tradeType: 1));
        $client->queryWithdraw(new QueryWithdrawRequest('T-PAYOUT', 'MO-PAYOUT', 'PO-001'));
        $bills = $client->bills(new BillsRequest(page: 1, pageSize: 20, sourceBizNo: 'T-PAYOUT'));

        self::assertSame(1, $bills->total);
        self::assertSame('BILL-001', $bills->list[0]->accountTransactionId);
        self::assertSame([
            'POST /api/v1/open/pay/refund',
            'POST /api/v1/open/pay/payout',
            'POST /api/v1/open/pay/close',
            'GET /api/v1/open/pay/withdrawQuery',
            'GET /api/v1/open/bill/list',
        ], $seen);
    }

    public function testResponseBodyLimitAndDebugLogDoNotExposeSecretOrPayload(): void
    {
        $logger = new RecordingLogger();
        $httpClient = new CallbackHttpClient(static fn (): Response => new Response(200, [], str_repeat('A', 64)));
        $config = new Config(
            'https://api.example.test',
            self::MCH_ID,
            self::SECRET_KEY,
            logger: $logger,
            debug: true,
            maxResponseBodyBytes: 8,
            httpClient: $httpClient,
        );
        $transport = new Transport(
            $config,
            static fn (): string => 'nonce-test',
            static fn (): string => 'request-test',
            static fn (): int => 1783000000,
        );
        $client = new Client($config, $transport);

        try {
            $client->queryOrder(new QueryOrderRequest('T-001'));
            self::fail('Expected response body limit to fail');
        } catch (\YoPay\Exception\HttpException $exception) {
            self::assertSame(8, strlen($exception->responseBody));
        }

        self::assertNotEmpty($logger->records);
        $text = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SECRET_KEY, $text);
        self::assertStringNotContainsString('biz_content', $text);
        self::assertStringNotContainsString('encrypted_key', $text);
    }

    public function testConfigRejectsBaseUrlWithPathQueryFragmentOrCredentials(): void
    {
        foreach (
            [
            'https://api.example.test/base',
            'https://api.example.test?debug=1',
            'https://api.example.test#anchor',
            'https://user:pass@api.example.test',
            ] as $baseUrl
        ) {
            try {
                new Config($baseUrl, self::MCH_ID);
                self::fail('Expected invalid Base URL: ' . $baseUrl);
            } catch (ConfigException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private static function client(
        CallbackHttpClient $httpClient,
        string $platformPublic = '',
        string $merchantPrivate = '',
    ): Client {
        $config = new Config(
            'https://api.example.test',
            self::MCH_ID,
            self::SECRET_KEY,
            $platformPublic,
            $merchantPrivate,
            httpClient: $httpClient,
        );
        $transport = new Transport(
            $config,
            static fn (): string => 'nonce-test',
            static fn (): string => 'request-test',
            static fn (): int => 1783000000,
        );

        return new Client($config, $transport);
    }

    private static function assertSignedRequest(
        object $request,
        string $method,
        string $path,
        string $canonicalPayload,
    ): void {
        self::assertSame(self::MCH_ID, $request->getHeaderLine('X-Mch-Id'));
        self::assertSame('1783000000', $request->getHeaderLine('X-Timestamp'));
        self::assertSame('nonce-test', $request->getHeaderLine('X-Nonce'));
        self::assertSame('request-test', $request->getHeaderLine('X-Request-Id'));
        $signText = Canonicalizer::signText(
            $method,
            $path,
            self::MCH_ID,
            '1783000000',
            'nonce-test',
            'request-test',
            $canonicalPayload,
        );
        self::assertSame(Signer::sign(self::SECRET_KEY, $signText), $request->getHeaderLine('X-Signature'));
    }

    /** @param array<string, int|string> $payload */
    private static function encryptedPostResponse(
        string $path,
        string $merchantPublic,
        array $payload,
        string $requestId,
        ?string $timestamp = null,
        ?string $nonce = null,
    ): Response {
        $plain = Canonicalizer::json($payload);
        $envelope = EnvelopeCipher::encrypt($merchantPublic, $plain);
        $body = Canonicalizer::json([
            'code' => 0,
            'msg' => 'success',
            'data' => $envelope->toArray(),
        ]);
        $timestamp ??= self::currentTimestamp();
        $nonce ??= 'response-nonce-' . bin2hex(random_bytes(8));
        $signature = Signer::sign(
            self::SECRET_KEY,
            Canonicalizer::signText('POST', $path, self::MCH_ID, $timestamp, $nonce, $requestId, $body),
        );

        return new Response(200, [
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $signature,
            'X-Mch-Id' => self::MCH_ID,
            'X-Request-Id' => $requestId,
        ], $body);
    }

    private static function currentTimestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('U');
    }
}
