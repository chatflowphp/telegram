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

use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Examples\MiniShop\MiniShopBotFactory;
use ChatFlow\Telegram\Examples\MiniShop\OrderService;
use ChatFlow\Telegram\Examples\MiniShop\ShopScene;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use Telegram\Bot\Api;

$mockClient = new MockHttpClient();
$api = new Api('TEST_TOKEN', false, $mockClient);
$orderService = new OrderService();

$bot = MiniShopBotFactory::create(
    token: 'TEST_TOKEN',
    basePath: $basePath,
    api: $api,
    storage: new MemoryStorage(),
    orderService: $orderService,
);

$tester = new TelegramBotTester($bot, $mockClient);
$tester->user('1001', '1001', '');

$tester
    ->sendCommand('/start')
    ->clickButton('landing:shop')
    ->clickSceneAction([ShopScene::class, 'onShowFeatured'])
    ->clickSceneAction([ShopScene::class, 'onSendFeaturedPhoto'])
    ->clickSceneAction([ShopScene::class, 'onShowHome'])
    ->clickSceneAction([ShopScene::class, 'onShowCatalog'], ['page' => 1])
    ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 1])
    ->clickSceneAction([ShopScene::class, 'onViewCart'])
    ->clickSceneAction([ShopScene::class, 'onCheckout'])
    ->sendMessage('+79991234567');

echo "Mock scenario completed.\n\n";
echo "Created orders:\n";

foreach ($orderService->getOrders() as $order) {
    printf(
        "- Order #%d, phone %s, total %s RUB\n",
        $order['id'],
        $order['phone'],
        number_format($order['total'], 0, '.', ' '),
    );
}

echo "\nOutgoing Telegram API requests:\n";

foreach ($tester->getRequests() as $request) {
    $text = $request['params']['text'] ?? ($request['params']['caption'] ?? '');

    printf(
        "- %s %s %s\n",
        $request['method'],
        $request['endpoint'],
        is_string($text) && $text !== '' ? '[' . str_replace("\n", ' ', $text) . ']' : '',
    );
}
