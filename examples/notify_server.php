<?php

declare(strict_types=1);

use YoPay\Client;
use YoPay\Config;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI === 'cli' && in_array('--help', $argv, true)) {
    echo <<<'TEXT'
YoPay PHP SDK merchant notification server example

Usage:
  php -S 127.0.0.1:8899 examples/notify_server.php
  php examples/notify_server.php --dry-run

Required:
  YOPAY_MCH_ID, YOPAY_SECRET_KEY, YOPAY_MERCHANT_PRIVATE_KEY

The callback verifies the raw body with the actual received path, decrypts it,
leaves local idempotency to the merchant, then acknowledges with {"code":0}.

TEXT;
    exit(0);
}

if (PHP_SAPI === 'cli' && in_array('--dry-run', $argv, true)) {
    echo "Dry run: start with php -S 127.0.0.1:8899 examples/notify_server.php "
        . "after setting the required YOPAY_* variables.\n";
    exit(0);
}

$client = new Client(new Config(
    baseUrl: getenv('YOPAY_BASE_URL') ?: 'https://pay.example.com',
    mchId: requiredEnv('YOPAY_MCH_ID'),
    secretKey: requiredEnv('YOPAY_SECRET_KEY'),
    merchantPrivateKey: requiredEnv('YOPAY_MERCHANT_PRIVATE_KEY'),
));
$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    exit('read body failed');
}

try {
    $notify = $client->verifyAndDecryptNotify(requestPath(), requestHeaders(), $rawBody);

    // Merchant responsibility: perform idempotent business handling here,
    // using $notify->tradeNo and $notify->notifyType before sending the ACK.
    if ($notify->tradeNo === '' || $notify->notifyType === '') {
        throw new RuntimeException('incomplete notification payload');
    }

    header('Content-Type: application/json');
    http_response_code(200);
    echo '{"code":0}';
} catch (Throwable) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo '{"code":400}';
}

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        throw new RuntimeException($name . ' is required');
    }

    return $value;
}

function requestPath(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);

    return is_string($path) && $path !== '' ? $path : '/';
}

/** @return array<string, string> */
function requestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            /** @var array<string, string> $headers */
            return $headers;
        }
    }
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_') && is_string($value)) {
            $name = str_replace('_', '-', strtolower(substr($key, 5)));
            $headers[ucwords($name, '-')] = $value;
        }
    }

    return $headers;
}
