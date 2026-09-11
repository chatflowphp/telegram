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

use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Examples\StarterBot\StarterBotFactory;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use Telegram\Bot\Api;

$mockClient = new MockHttpClient();
$api = new Api('TEST_TOKEN', false, $mockClient);

$bot = StarterBotFactory::create(
    token: 'TEST_TOKEN',
    basePath: $basePath,
    api: $api,
    storage: new MemoryStorage(),
);

$tester = new TelegramBotTester($bot, $mockClient);

$tester
    ->sendCommand('/start')
    ->clickButton('settings:open')
    ->clickButton('profile:phone')
    ->sendMessage('123')
    ->sendMessage('+79991234567')
    // The same flow in Russian: the choice is stored in the session and survives the next update.
    ->clickButton('lang:ru')
    ->sendCommand('/start')
    ->clickButton('settings:open');

echo "StarterBot mock scenario completed.\n\n";
echo "Outgoing Telegram API requests:\n";

foreach ($tester->getRequests() as $request) {
    $text = $request['params']['text'] ?? ($request['params']['caption'] ?? '');

    printf(
        "- %s %s %s\n",
        $request['method'],
        $request['endpoint'],
        is_string($text) && $text !== '' ? '[' . str_replace("\n", ' ', $text) . ']' : '',
    );
}
