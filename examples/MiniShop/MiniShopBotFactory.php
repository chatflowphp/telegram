<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Telegram\Bot;
use Telegram\Bot\Api;

final class MiniShopBotFactory
{
    public static function create(
        string $token,
        string $basePath,
        ?Api $api = null,
        ?StorageInterface $storage = null,
        ?OrderService $orderService = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
    ): Bot {
        $bot = new Bot(
            token: $token,
            basePath: $basePath,
            api: $api,
            runtimeObserver: $runtimeObserver,
            storage: $storage ?? new FileStorage($basePath . '/storage/minishop'),
        );

        $flow = new MiniShopFlow($orderService);
        $flow->register($bot);

        // Telegram-specific wiring: pre-checkout queries carry no chat, so they are not part of
        // the platform-neutral flow contract.
        $bot->onRawUpdate('pre_checkout_query', [$flow, 'approveCheckout']);

        return $bot;
    }
}
