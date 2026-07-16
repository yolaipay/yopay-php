<?php

declare(strict_types=1);

use GuzzleHttp\Client as GuzzleClient;
use Psr\Log\NullLogger;
use YoPay\Client;
use YoPay\Config;
use YoPay\Dto\OrderRequest;
use YoPay\Dto\Payer;

require dirname(__DIR__) . '/vendor/autoload.php';

if (in_array('--help', $argv, true)) {
    echo <<<'TEXT'
YoPay PHP SDK pay-in example

Usage:
  php examples/payin.php [--dry-run]

Required for a real request:
  YOPAY_BASE_URL, YOPAY_MCH_ID, YOPAY_SECRET_KEY,
  YOPAY_PLATFORM_PUBLIC_KEY, YOPAY_MERCHANT_PRIVATE_KEY

Optional:
  YOPAY_NOTIFY_URL

TEXT;
    exit(0);
}

$config = new Config(
    baseUrl: getenv('YOPAY_BASE_URL') ?: 'https://pay.example.com',
    mchId: getenv('YOPAY_MCH_ID') ?: 'MR_EXAMPLE',
    secretKey: getenv('YOPAY_SECRET_KEY') ?: '',
    platformPublicKey: getenv('YOPAY_PLATFORM_PUBLIC_KEY') ?: '',
    merchantPrivateKey: getenv('YOPAY_MERCHANT_PRIVATE_KEY') ?: '',
    // Any PSR-18 client may be injected. Guzzle is the default HTTP choice.
    httpClient: new GuzzleClient(['timeout' => 10.0]),
    // The SDK emits only metadata when debug is true; it never logs payloads or keys.
    logger: new NullLogger(),
    debug: true,
);
$client = new Client($config);
$request = new OrderRequest(
    merchantOrderNo: 'PHP-DEMO-' . date('YmdHis'),
    amount: '20.000',
    currencyCode: 'USD',
    scene: 'qr',
    payer: new Payer(
        merchantUserId: 'merchant-user-10001',
        accountType: 3,
        accountNo: 'openid-example',
    ),
    subject: 'YoPay PHP SDK demo',
    notifyUrl: getenv('YOPAY_NOTIFY_URL') ?: null,
    clientIp: '127.0.0.1',
);

if (in_array('--dry-run', $argv, true)) {
    echo "Dry run: request DTO prepared. No HTTP request was sent.\n";
    echo json_encode(
        $request,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
    exit(0);
}

$response = $client->order($request);
echo json_encode([
    'trade_no' => $response->tradeNo,
    'merchant_order_no' => $response->merchantOrderNo,
    'status' => $response->status,
    'payment_url' => $response->paymentUrl,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
