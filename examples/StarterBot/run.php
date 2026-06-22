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
require_once __DIR__ . '/bootstrap.php';

use ChatFlow\Telegram\Examples\StarterBot\StarterBotFactory;

$token = $_ENV['TELEGRAM_BOT_TOKEN']
    ?? $_ENV['TELEGRAM_TOKEN']
    ?? getenv('TELEGRAM_BOT_TOKEN')
    ?: (getenv('TELEGRAM_TOKEN') ?: '');
if (!is_string($token) || $token === '') {
    fwrite(STDERR, "TELEGRAM_BOT_TOKEN is not configured\n");
    exit(1);
}

$bot = StarterBotFactory::create(
    token: $token,
    basePath: $basePath,
);

fwrite(STDOUT, "StarterBot example is running in polling mode.\n");
fwrite(STDOUT, "Press Ctrl+C to stop.\n");

$bot->startPolling();
