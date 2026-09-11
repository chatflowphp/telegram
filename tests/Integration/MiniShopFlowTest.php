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

    public function testAConfirmedOrderIsPaidWithTelegramStars(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('landing:shop')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 1])
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onCheckout'])
            ->clear()
            ->sendMessage('+79991234567')
            ->assertSee('Order #1000 is confirmed.')
            ->assertKeyboardHas('Pay 84 Stars')
            ->clear()
            ->clickButton('order:pay', ['id' => 1000])
            ->assertEndpointCalled('sendInvoice');

        // sendInvoice is an immediate Bot API call, so it is recorded before the queued ack().
        $invoices = array_values(array_filter(
            $this->tester->getRequests(),
            static fn(array $request): bool => $request['endpoint'] === 'sendInvoice',
        ));

        self::assertCount(1, $invoices);
        self::assertSame('order-1000', $invoices[0]['params']['payload'] ?? null);
        self::assertSame('XTR', $invoices[0]['params']['currency'] ?? null);

        $this->tester
            ->clear()
            ->sendRawUpdate(self::preCheckoutUpdate('order-1000'))
            ->assertEndpointCalled('answerPreCheckoutQuery');

        self::assertTrue((bool) ($this->tester->getRequests()[0]['params']['ok'] ?? false));

        $this->tester
            ->clear()
            ->sendRawUpdate(self::successfulPaymentUpdate('order-1000'))
            ->assertSee('Payment received for order #1000. Thank you!');

        $orders = $this->orderService->getOrders();

        self::assertTrue($orders[0]['paid']);
        self::assertSame('charge-1', $orders[0]['charge_id']);
    }

    public function testPayingAnOrderTwiceIsRefusedAtTheCheckoutQuery(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('landing:shop')
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onAddToCart'], ['id' => 1])
            ->clear()
            ->clickSceneAction([ShopScene::class, 'onCheckout'])
            ->clear()
            ->sendMessage('+79991234567')
            ->clear()
            ->sendRawUpdate(self::successfulPaymentUpdate('order-1000'))
            ->clear()
            ->sendRawUpdate(self::preCheckoutUpdate('order-1000'));

        $answer = $this->tester->getRequests()[0]['params'] ?? [];

        self::assertSame('answerPreCheckoutQuery', $this->tester->getRequests()[0]['endpoint']);
        self::assertFalse((bool) ($answer['ok'] ?? true));
        self::assertSame('This order can no longer be paid.', $answer['error_message'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function preCheckoutUpdate(string $payload): array
    {
        return [
            'update_id' => 900,
            'pre_checkout_query' => [
                'id' => 'pcq-1',
                'from' => ['id' => 123456789],
                'currency' => 'XTR',
                'total_amount' => 84,
                'invoice_payload' => $payload,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function successfulPaymentUpdate(string $payload): array
    {
        return [
            'update_id' => 901,
            'message' => [
                'message_id' => 999,
                'chat' => ['id' => 123456789, 'type' => 'private'],
                'from' => ['id' => 123456789],
                'successful_payment' => [
                    'currency' => 'XTR',
                    'total_amount' => 84,
                    'invoice_payload' => $payload,
                    'telegram_payment_charge_id' => 'charge-1',
                ],
            ],
        ];
    }
}
