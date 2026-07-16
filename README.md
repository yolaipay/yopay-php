# YoPay PHP SDK

YoPay PHP SDK 是 Pay Open API 的商户侧 SDK。它负责 Open API 请求签名、POST 加密信封、POST 成功响应验签解密，以及 YoPay 向商户 `notify_url` 投递的通知验签解密。

SDK 运行于 PHP 8.1+；推荐商户生产环境使用仍在支持周期内的 PHP 8.3+。金额全部使用字符串，不能使用 `float`。

## 发布状态与安装

当前版本通过 Packagist 发布。商户在项目目录执行：

```bash
composer require yopay/yopay-php:^0.1
```

源码仓库：`https://git.yolaila.top/SDK/yopay-php.git`。生产项目应锁定已验证版本并提交 `composer.lock`。

## 配置与下单

`BaseURL` 只包含协议、域名和可选端口，不要包含 `/api/v1/open`；`openApiPrefix` 默认就是 `/api/v1/open`。

```php
use YoPay\Client;
use YoPay\Config;
use YoPay\Dto\OrderRequest;
use YoPay\Dto\Payer;

$client = new Client(new Config(
    baseUrl: 'https://pay.example.com',
    mchId: getenv('YOPAY_MCH_ID'),
    secretKey: getenv('YOPAY_SECRET_KEY'),
    platformPublicKey: getenv('YOPAY_PLATFORM_PUBLIC_KEY'),
    merchantPrivateKey: getenv('YOPAY_MERCHANT_PRIVATE_KEY'),
));

$response = $client->order(new OrderRequest(
    merchantOrderNo: 'MO-202607130001',
    amount: '20.000',
    currencyCode: 'USD',
    scene: 'qr',
    payer: new Payer(
        merchantUserId: 'payer-10001',
        accountType: 3,
        accountNo: 'openid-10001',
    ),
    notifyUrl: getenv('YOPAY_NOTIFY_URL') ?: null,
));

echo $response->tradeNo;
```

环境变量统一使用 `YOPAY_` 前缀：

- `YOPAY_BASE_URL`
- `YOPAY_MCH_ID`
- `YOPAY_SECRET_KEY`
- `YOPAY_PLATFORM_PUBLIC_KEY`
- `YOPAY_MERCHANT_PRIVATE_KEY`
- `YOPAY_NOTIFY_URL`

SDK 只发送 `X-Mch-Id`、`X-Timestamp`、`X-Nonce`、`X-Signature` 与 `X-Request-Id`，不会发送 `X-Access-Key` 或 `X-Secret-Key`。

这些安全头使用固定七行签名：`METHOD`、`PATH`、`MCH_ID`、`TIMESTAMP`、`NONCE`、`REQUEST_ID`、`PAYLOAD_DIGEST`。POST 成功响应和商户通知都校验商户号、请求 ID、±5 分钟时间窗、nonce 防重放和签名；成功响应的 `X-Request-Id` 必须回显本次请求的值。

默认 replay cache 只覆盖当前 PHP 进程。多进程或多实例服务应在 `Config` 中注入实现 `ReplayProtector` 的共享原子缓存；协议 nonce 校验不能替代按 `trade_no + notify_type` 的业务幂等。

## 业务方法

- `order(OrderRequest $request)`：`POST /pay/order`
- `refund(RefundRequest $request)`：`POST /pay/refund`
- `withdraw(WithdrawRequest $request)`：`POST /pay/payout`
- `closeOrder(CloseOrderRequest $request)`：`POST /pay/close`
- `queryOrder(QueryOrderRequest $request)`：`GET /pay/query`
- `queryWithdraw(QueryWithdrawRequest $request)`：`GET /pay/withdrawQuery`
- `bills(BillsRequest $request)`：`GET /bill/list`

POST 会把业务 DTO 加密为仅含 `encrypted_key` 和 `biz_content` 的信封，再对信封签名；GET 只签名、不加密。所有公开 DTO 均不包含 `provider_id` 或内部钱包、路由、数据库 ID。

```php
use YoPay\Dto\QueryOrderRequest;
use YoPay\Dto\RefundRequest;
use YoPay\Dto\WithdrawPayee;
use YoPay\Dto\WithdrawRequest;

$refund = $client->refund(new RefundRequest(
    originTradeNo: '260713070001100100000001',
    merchantOrderNo: 'REFUND-202607130001',
    amount: '2.000',
));

$withdraw = $client->withdraw(new WithdrawRequest(
    merchantOrderNo: 'PAYOUT-202607130001',
    amount: '30.000',
    currencyCode: 'USD',
    payee: new WithdrawPayee(
        accountType: 1,
        accountName: 'Alice',
        accountNo: '6222000000000000000',
        merchantUserId: 'payee-10001',
    ),
));

$order = $client->queryOrder(new QueryOrderRequest(
    tradeNo: '260713070001100100000001',
    tradeType: 1,
));
```

SDK 不自动重试支付类 POST。商户若要重试，必须围绕自身 `merchant_order_no` 的幂等语义，在业务层或所注入 HTTP client 层明确控制。

## PSR-18、日志与协程

`Config` 的 `httpClient`、`requestFactory`、`streamFactory` 使用 PSR-18/17/7 接口，`logger` 使用 PSR-3。默认 HTTP client 是 Guzzle；商户可以注入自身的代理、链路追踪、连接池、熔断或协程客户端。

```php
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use YoPay\Config;

/** @var ClientInterface $merchantHttpClient */
/** @var LoggerInterface $merchantLogger */
$config = new Config(
    baseUrl: 'https://pay.example.com',
    mchId: 'MR...',
    secretKey: '...',
    httpClient: $merchantHttpClient,
    logger: $merchantLogger,
    debug: true,
);
```

协程框架应注入其当前运行时已经配置好的 PSR-18 client。SDK 不创建事件循环、不使用全局可变请求状态，也不自行重试，因此每次调用自然运行在调用方的当前协程内。

Swoole 项目还可以显式使用内置适配器（SDK 不强依赖 `ext-swoole`）：

```php
use YoPay\Config;
use YoPay\Http\SwooleCoroutineClient;

// 在 Swoole 协程内创建或由容器注入。
$config = new Config(
    baseUrl: 'https://pay.example.com',
    mchId: 'MR...',
    secretKey: '...',
    httpClient: new SwooleCoroutineClient(timeoutSeconds: 10.0),
);
```

`SwooleCoroutineClient` 仅在显式调用时检查 `ext-swoole` 和当前协程上下文；未安装扩展的普通 PHP、FPM 和其他框架不受影响。

Debug 日志只包含 method、path、request ID、HTTP 状态和耗时，不包含密钥、私钥、完整公钥、业务 JSON、`encrypted_key`、`biz_content` 或支付身份字段。

## 商户通知

通知处理必须传入商户服务实际收到的 URL path 和原始 body，不能传 Open API path，也不能在验签前格式化 JSON。商户自行完成以 `trade_no + notify_type` 为键的本地幂等处理。

```php
$rawBody = file_get_contents('php://input');
$notify = $client->verifyAndDecryptNotify(
    path: parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/',
    headers: getallheaders(),
    rawBody: $rawBody === false ? '' : $rawBody,
);

// 先执行本地幂等业务处理。
header('Content-Type: application/json');
http_response_code(200);
echo '{"code":0}';
```

平台只认可 HTTP `200 OK` 且 JSON 对象中数值 `code` 为 `0` 的回执。HTTP `204`、其他 2xx、空 body、非 JSON、字符串 `"0"` 或非零 `code` 都会触发重试。

平台每次重试都会使用新的 `X-Timestamp`、`X-Nonce`、`X-Request-Id` 和 `X-Signature`，密文 body 可以保持不变。

## 异常

- `ConfigException`：配置、Base URL、path 或必需密钥无效。
- `ApiException`：HTTP 2xx 内的 YoPay 业务错误；包含业务 code 与 request ID。
- `HttpException`：网络异常、非 2xx 或响应体超过限制；包含有限长度 body 与 request ID。
- `SignatureException`：响应或通知缺失签名、验签失败。
- `CryptoException`：PEM、RSA-OAEP、AES-GCM、信封或解密 JSON 失败。
- `CoroutineException`：显式使用 Swoole 适配器但扩展/协程上下文不满足要求。

## 示例与本地检查

可使用 Docker 执行以下检查：

```bash
docker compose run --rm php83 composer install
docker compose run --rm php83 composer check
docker compose run --rm php81 composer install
docker compose run --rm php81 composer test

docker compose run --rm php83 php examples/payin.php --help
docker compose run --rm php83 php examples/payin.php --dry-run
docker compose run --rm php83 php examples/notify_server.php --help
docker compose run --rm php83 php examples/notify_server.php --dry-run
```
