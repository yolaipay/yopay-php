<?php

declare(strict_types=1);

namespace YoPay\Contract;

interface Arrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
