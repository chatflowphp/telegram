<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Observability\RuntimeObserverInterface;
use ChatFlow\Storage\Drivers\FileStorage;
use ChatFlow\Storage\StorageInterface;
use ChatFlow\Telegram\Bot;
use Telegram\Bot\Api;

final class StarterBotFactory
{
    public static function create(
        string $token,
        string $basePath,
        ?Api $api = null,
        ?StorageInterface $storage = null,
        ?RuntimeObserverInterface $runtimeObserver = null,
    ): Bot {
        $bot = new Bot(
            token: $token,
            basePath: $basePath,
            api: $api,
            runtimeObserver: $runtimeObserver,
            storage: $storage ?? new FileStorage($basePath . '/storage/starter-bot'),
        );

        (new StarterBotFlow())->register($bot);

        return $bot;
    }
}
