<?php

declare(strict_types=1);

namespace YoPay\Tests\Support;

use Closure;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class CallbackHttpClient implements ClientInterface
{
    public ?RequestInterface $request = null;

    /** @var Closure(RequestInterface): ResponseInterface */
    private readonly Closure $callback;

    /** @param Closure(RequestInterface): ResponseInterface $callback */
    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return ($this->callback)($request);
    }
}
