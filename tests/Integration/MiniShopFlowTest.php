<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Examples\MiniShop\CheckoutScene;
use ChatFlow\Telegram\Examples\MiniShop\MiniShopBotFactory;
use ChatFlow\Telegram\Examples\MiniShop\OrderService;
use ChatFlow\Telegram\Examples\MiniShop\ShopScene;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class MiniShopFlowTest extends TestCase
{
    private Bot $bot;

    private TelegramBotTester $tester;

    private OrderService $orderService;

    protected function setUp(): void
    {
        $mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $mockClient);
        $this->orderService = new OrderService();

        $this->bot = MiniShopBotFactory::create(
            token: 'TEST_TOKEN',
            basePath: \dirname(__DIR__, 2),
            api: $api,
            storage: new MemoryStorage(),
            orderService: $this->orderService,
        );

        $this->tester = new TelegramBotTester($this->bot, $mockClient);
    }

    public function testBotImplementsSharedFlowRuntimeContract(): void
    {
        self::assertInstanceOf(FlowRuntimeInterface::class, $this->bot);
        self::assertStringContainsString('stateDiagram-v2', $this->bot->getTransitions()->toMermaid());
    }

    public function testStartRoutePrefixCallbackFeaturedScreenAndMediaReply(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->assertSee('Session owner: @test_user')
            ->assertKeyboardHas('Open storefront')
            ->clear()
            ->clickButton('landing:shop')
            ->assertScene(ShopScene::class)
            ->assertSee('Storefront')
            ->assertKeyboardHas('Browse catalog')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onShowFeatured'])
            ->assertEndpointCalled('editMessageText')
            ->assertSee('Featured deal')
            ->assertKeyboardHas('Send product photo')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onSendFeaturedPhoto'])
            ->assertEndpointCalled('sendPhoto')
            ->assertSee('Product photo')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onShowHome'])
            ->assertSee('Storefront');
    }

    public function testCheckoutFlowValidatesPhoneAndRecordsOrder(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('landing:shop')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onShowCatalog'], ['page' => 1])
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 1])
            ->assertSessionHas('cart', [1 => 1])
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onViewCart'])
            ->assertSee('Cart')
            ->assertKeyboardHas('Checkout')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onCheckout'])
            ->assertScene(CheckoutScene::class)
            ->assertSee('Enter a phone number')
            ->clear()
            ->sendMessage('wrong-phone')
            ->assertSee('Use +79991234567 or 89991234567.')
            ->clear()
            ->sendMessage('+79991234567')
            ->assertNotInScene()
            ->assertSee('Order #1000 is confirmed.')
            ->assertKeyboardHas('Back to storefront')
            ->assertSessionMissing('cart')
            ->assertSessionMissing('cart_items');

        $orders = $this->orderService->getOrders();

        self::assertCount(1, $orders);
        self::assertSame('+79991234567', $orders[0]['phone']);
        self::assertSame(84990, $orders[0]['total']);
    }

    public function testCheckoutIsGuardedByTheCartAndCancelReturnsToTheShop(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('landing:shop')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onCheckout'])
            ->assertScene(ShopScene::class)
            ->assertEndpointCalled('answerCallbackQuery')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 2])
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onCheckout'])
            ->assertScene(CheckoutScene::class)
            ->clear()
            ->sendMessage('Cancel checkout')
            ->assertSee('Checkout cancelled.')
            ->assertScene(ShopScene::class)
            ->assertSee('Storefront');
    }

    public function testProductLookupErrorsAreReportedViaTypedExceptionHandler(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('landing:shop')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 999]);

        $requests = $this->tester->getRequests();
        $lastRequest = end($requests);

        self::assertNotFalse($lastRequest);
        self::assertSame('answerCallbackQuery', $lastRequest['endpoint']);
        self::assertSame('This product is no longer available.', $lastRequest['params']['text'] ?? null);
        self::assertTrue((bool) ($lastRequest['params']['show_alert'] ?? false));
        $this->tester->assertScene(ShopScene::class);
    }
}
