<?php

declare(strict_types=1);

namespace YoPay;

use JsonException;
use DateTimeImmutable;
use DateTimeZone;
use YoPay\Contract\Arrayable;
use YoPay\Crypto\Canonicalizer;
use YoPay\Crypto\Envelope;
use YoPay\Crypto\EnvelopeCipher;
use YoPay\Crypto\Signer;
use YoPay\Dto\BillsRequest;
use YoPay\Dto\BillsResponse;
use YoPay\Dto\CloseOrderRequest;
use YoPay\Dto\OrderRequest;
use YoPay\Dto\OrderResponse;
use YoPay\Dto\PayNotify;
use YoPay\Dto\QueryOrderRequest;
use YoPay\Dto\QueryWithdrawRequest;
use YoPay\Dto\RefundRequest;
use YoPay\Dto\WithdrawRequest;
use YoPay\Exception\ApiException;
use YoPay\Exception\ConfigException;
use YoPay\Exception\CryptoException;
use YoPay\Exception\HttpException;
use YoPay\Exception\SignatureException;
use YoPay\Http\Transport;
use YoPay\Http\TransportResponse;

final class Client
{
    private readonly Transport $transport;

    public function __construct(private readonly Config $config, ?Transport $transport = null)
    {
        $this->transport = $transport ?? new Transport($config);
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function order(OrderRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->encryptPost('/pay/order', $request));
    }

    public function refund(RefundRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->encryptPost('/pay/refund', $request));
    }

    public function withdraw(WithdrawRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->encryptPost('/pay/payout', $request));
    }

    public function closeOrder(CloseOrderRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->encryptPost('/pay/close', $request));
    }

    public function queryOrder(QueryOrderRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->signGet('/pay/query', $request->toArray()));
    }

    public function queryWithdraw(QueryWithdrawRequest $request): OrderResponse
    {
        return OrderResponse::fromArray($this->signGet('/pay/withdrawQuery', $request->toArray()));
    }

    public function bills(BillsRequest $request): BillsResponse
    {
        return BillsResponse::fromArray($this->signGet('/bill/list', $request->toArray()));
    }

    /**
     * Sends a canonical business payload as an encrypted Open POST and returns
     * the decrypted successful response data.
     *
     * @return array<string, mixed>
     */
    /**
     * @param Arrayable|array<string, bool|float|int|string|array<string, bool|float|int|string>> $payload
     * @return array<string, mixed>
     */
    public function encryptPost(string $relativePath, Arrayable|array $payload): array
    {
        $this->requirePostKeys();
        $plain = Canonicalizer::json($payload instanceof Arrayable ? $payload->toArray() : $payload);
        $envelope = EnvelopeCipher::encrypt($this->config->platformPublicKey, $plain);
        $body = Canonicalizer::json($envelope->toArray());
        $response = $this->transport->send('POST', $relativePath, [], $body);
        $outer = $this->decodeCodeResponse($response);
        if ($outer['code'] !== 0) {
            throw new ApiException($outer['code'], $outer['message'], $response->requestId);
        }
        $this->verifyResponseSignature($response);
        if (!is_array($outer['data'])) {
            throw new CryptoException('encrypted response data is missing', $response->requestId);
        }
        $plainResponse = EnvelopeCipher::decrypt(
            $this->config->merchantPrivateKey,
            Envelope::fromArray($outer['data']),
        );

        return $this->decodeObject($plainResponse, $response->requestId, CryptoException::class);
    }

    /**
     * Sends a signed Open GET and returns standard CodeResp data.
     *
     * @param array<string, scalar|list<scalar>|null> $query
     * @return array<string, mixed>
     */
    public function signGet(string $relativePath, array $query = []): array
    {
        $this->requireSecretKey();
        $response = $this->transport->send('GET', $relativePath, $query);
        $outer = $this->decodeCodeResponse($response);
        if ($outer['code'] !== 0) {
            throw new ApiException($outer['code'], $outer['message'], $response->requestId);
        }
        if ($outer['data'] === null) {
            return [];
        }
        if (!is_array($outer['data'])) {
            throw new HttpException($response->statusCode, $response->body, $response->requestId);
        }

        return $outer['data'];
    }

    /**
     * Verifies a raw merchant notification body against the actual callback
     * path, then returns its decrypted Pay notification payload.
     *
     * @param array<string, string|list<string>> $headers
     */
    public function verifyAndDecryptNotify(string $path, array $headers, string $rawBody): PayNotify
    {
        $this->requireSecretKey();
        if (trim($this->config->merchantPrivateKey) === '') {
            throw new ConfigException('merchantPrivateKey is required for notification decryption');
        }
        $path = trim($path);
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new ConfigException('notification path must be an absolute path without query or fragment');
        }
        $timestamp = $this->header($headers, 'X-Timestamp');
        $nonce = $this->header($headers, 'X-Nonce');
        $signature = $this->header($headers, 'X-Signature');
        $requestId = $this->header($headers, 'X-Request-Id');
        $mchId = $this->header($headers, 'X-Mch-Id');
        if ($timestamp === '' || $nonce === '' || $signature === '' || $requestId === '' || $mchId === '') {
            throw new SignatureException('notification signature headers are required', $requestId);
        }
        if ($mchId !== $this->config->mchId) {
            throw new SignatureException('notification merchant id does not match client configuration', $requestId);
        }
        try {
            $canonicalBody = Canonicalizer::json($rawBody);
        } catch (CryptoException $exception) {
            throw new SignatureException('notification body is not valid JSON', $requestId, $exception);
        }
        $signText = Canonicalizer::signText(
            'POST',
            $path,
            $this->config->mchId,
            $timestamp,
            $nonce,
            $requestId,
            $canonicalBody,
        );
        if (!Signer::verify($this->config->secretKey, $signText, $signature)) {
            throw new SignatureException('notification signature verification failed', $requestId);
        }
        ProtocolSecurity::validateAndClaim(
            $this->config->replayProtector,
            'notify',
            $this->config->mchId,
            $timestamp,
            $nonce,
            $requestId,
            self::now(),
        );
        $envelope = Envelope::fromArray($this->decodeObject($rawBody, $requestId, CryptoException::class));
        $plain = EnvelopeCipher::decrypt($this->config->merchantPrivateKey, $envelope);

        return PayNotify::fromArray($this->decodeObject($plain, $requestId, CryptoException::class));
    }

    private function requireSecretKey(): void
    {
        if (trim($this->config->secretKey) === '') {
            throw new ConfigException('secretKey is required');
        }
    }

    private function requirePostKeys(): void
    {
        $this->requireSecretKey();
        if (trim($this->config->platformPublicKey) === '') {
            throw new ConfigException('platformPublicKey is required for encrypted POST requests');
        }
        if (trim($this->config->merchantPrivateKey) === '') {
            throw new ConfigException('merchantPrivateKey is required for encrypted POST responses');
        }
    }

    /**
     * @return array{code: int, message: string, data: mixed}
     */
    private function decodeCodeResponse(TransportResponse $response): array
    {
        try {
            $payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new HttpException($response->statusCode, $response->body, $response->requestId, $exception);
        }
        if (!is_array($payload) || !isset($payload['code']) || !is_int($payload['code'])) {
            throw new HttpException($response->statusCode, $response->body, $response->requestId);
        }
        $message = $payload['msg'] ?? $payload['message'] ?? '';

        return [
            'code' => $payload['code'],
            'message' => is_string($message) ? $message : '',
            'data' => $payload['data'] ?? null,
        ];
    }

    private function verifyResponseSignature(TransportResponse $response): void
    {
        $timestamp = trim($response->header('X-Timestamp'));
        $nonce = trim($response->header('X-Nonce'));
        $signature = trim($response->header('X-Signature'));
        $responseRequestId = trim($response->header('X-Request-Id'));
        $mchId = trim($response->header('X-Mch-Id'));
        if ($timestamp === '' || $nonce === '' || $signature === '' || $responseRequestId === '' || $mchId === '') {
            throw new SignatureException('response signature headers are required', $response->requestId);
        }
        if ($mchId !== $this->config->mchId) {
            throw new SignatureException(
                'response merchant id does not match client configuration',
                $response->requestId,
            );
        }
        if ($responseRequestId !== $response->expectedRequestId) {
            throw new SignatureException('response request id does not match the request', $response->requestId);
        }
        try {
            $canonicalBody = Canonicalizer::json($response->body);
        } catch (CryptoException $exception) {
            throw new SignatureException('response body is not valid JSON', $response->requestId, $exception);
        }
        $signText = Canonicalizer::signText(
            'POST',
            $response->signPath,
            $this->config->mchId,
            $timestamp,
            $nonce,
            $responseRequestId,
            $canonicalBody,
        );
        if (!Signer::verify($this->config->secretKey, $signText, $signature)) {
            throw new SignatureException('response signature verification failed', $response->requestId);
        }
        ProtocolSecurity::validateAndClaim(
            $this->config->replayProtector,
            'response',
            $this->config->mchId,
            $timestamp,
            $nonce,
            $responseRequestId,
            self::now(),
        );
    }

    /**
     * @param class-string<CryptoException> $exceptionClass
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $requestId, string $exceptionClass): array
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new $exceptionClass('JSON object decoding failed', $requestId, $exception);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new $exceptionClass('JSON object is required', $requestId);
        }

        return $payload;
    }

    /** @param array<string, string|list<string>> $headers */
    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? trim((string) ($value[0] ?? '')) : trim($value);
            }
        }

        return '';
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
