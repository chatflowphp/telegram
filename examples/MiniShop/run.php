<?php

declare(strict_types=1);

/**
 * Resolve the project root by walking up to the nearest directory with vendor/autoload.php.
 */
function chatflow_example_project_root(string $startDir): string
{
    $dir = $startDir;

    while (true) {
        if (file_exists($dir . '/vendor/autoload.php')) {
            return $dir;
        }

        $parent = dirname($dir);
        if ($parent === $dir) {
            throw new RuntimeException('Unable to locate vendor/autoload.php for the ChatFlow example.');
        }

        $dir = $parent;
    }
}

$basePath = chatflow_example_project_root(__DIR__);

require_once $basePath . '/vendor/autoload.php';

use ChatFlow\Observability\JsonlRuntimeObserver;
use ChatFlow\Telegram\Examples\MiniShop\MiniShopBotFactory;
use Dotenv\Dotenv;

Dotenv::createImmutable($basePath)->safeLoad();

$token = $_ENV['TELEGRAM_BOT_TOKEN'] ?? ($_ENV['TELEGRAM_TOKEN'] ?? '');
if (!is_string($token) || $token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN is not configured in .env\n");
    exit(1);
}

$runtimeLog = $_ENV['CHATFLOW_RUNTIME_LOG'] ?? $basePath . '/storage/minishop/runtime.jsonl';
if (!is_string($runtimeLog) || $runtimeLog === '') {
    fwrite(STDERR, "CHATFLOW_RUNTIME_LOG must be a non-empty string when configured\n");
    exit(1);
}

$bot = MiniShopBotFactory::create(
    token: $token,
    basePath: $basePath,
    runtimeObserver: new JsonlRuntimeObserver($runtimeLog),
);

fwrite(STDOUT, "MiniShop example is running in polling mode.\n");
fwrite(STDOUT, "Runtime log: {$runtimeLog}\n");
fwrite(STDOUT, "Press Ctrl+C to stop.\n");

$bot->startPolling();
