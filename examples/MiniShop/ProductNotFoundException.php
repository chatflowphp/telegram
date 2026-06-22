<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Exception\UserFriendlyException;
use RuntimeException;
use Throwable;

class ProductNotFoundException extends RuntimeException implements UserFriendlyException
{
    public function __construct(
        string $message = 'Product not found',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
