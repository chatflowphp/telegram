<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Context;
use ChatFlow\Scene\RootScene;
use ChatFlow\Scene\SceneContext;
use ChatFlow\View\Action;
use ChatFlow\View\View;
use Telegram\Bot\Api;
use Throwable;

final class MiniShopFlow implements FlowInterface
{
    private readonly OrderService $orderService;

    public function __construct(?OrderService $orderService = null)
    {
        $this->orderService = $orderService ?? new OrderService();
    }

    public function register(FlowRuntimeInterface $runtime): void
    {
        $runtime->getContainer()->set(OrderService::class, $this->orderService);

        $runtime->registerScene(ShopScene::class, 'Shop');
        $runtime->registerScene(CheckoutScene::class, 'Checkout');

        // The screen map: the storefront opens from the root, checkout needs a non-empty cart,
        // and checkout can only go back to the storefront.
        $runtime->allowTransition(RootScene::ID, ShopScene::class);
        $runtime->allowTransition(ShopScene::class, CheckoutScene::class, static fn(SceneContext $session): bool => $session->getArray('cart') !== []);
        $runtime->allowTransition(CheckoutScene::class, ShopScene::class);

        $runtime->middleware([
            MiniShopVisitorMiddleware::class,
        ]);

        $runtime->onCommand('start', static function (Context $ctx): void {
            $visitor = $ctx->get('visitor', 'guest');
            $visitor = \is_string($visitor) ? $visitor : 'guest';

            $ctx->leave();
            $ctx->reply(
                View::text(
                    "Telegram MiniShop\n\n" .
                    "Session owner: {$visitor}\n\n" .
                    'This Telegram example covers commands, inline callbacks, scenes, smart message edits, media replies and checkout validation.',
                )->addActionRow(new Action('landing:shop', 'Open storefront')),
            );
        });

        $runtime->onActionPrefix('landing:', static function (Context $ctx): void {
            if ($ctx->getActionId() !== 'landing:shop') {
                return;
            }

            $ctx->ack('Opening storefront');
            $ctx->enter(ShopScene::class);
        })->global();

        // Paying for a confirmed order with Telegram Stars. The invoice is a plain Bot API call;
        // the package only has to deliver the updates around it.
        $runtime->onAction('order:pay', static function (Context $ctx, Api $api, OrderService $orders): void {
            $payload = $ctx->getActionPayload();
            $order = $orders->find(\is_array($payload) ? self::intValue($payload['id'] ?? null) : 0);

            if ($order === null) {
                $ctx->ack('This order is gone.', true);

                return;
            }

            if ($order['paid']) {
                $ctx->ack('This order is already paid.', true);

                return;
            }

            $ctx->ack('Opening payment');
            $api->sendInvoice([
                'chat_id' => $ctx->getConversationId(),
                'title' => \sprintf('Order #%d', $order['id']),
                'description' => \sprintf('%d item(s) from the MiniShop catalog.', \count($order['items'])),
                'payload' => 'order-' . $order['id'],
                'currency' => 'XTR',
                'prices' => [[
                    'label' => \sprintf('Order #%d', $order['id']),
                    'amount' => OrderService::starsFor($order['total']),
                ]],
            ]);
        })->global();

        // A successful payment arrives as a message in the chat, so it reaches the conversation.
        $runtime->fallback(static function (Context $ctx, OrderService $orders): void {
            $payment = self::successfulPayment($ctx);

            if ($payment === null) {
                $ctx->reply(self::landingView());

                return;
            }

            $orderId = self::orderIdFromPayload($payment['invoice_payload'] ?? null);
            $orders->markPaid($orderId, self::stringValue($payment['telegram_payment_charge_id'] ?? null));

            $ctx->reply(\sprintf('Payment received for order #%d. Thank you!', $orderId));
        });

        $runtime->onException(ProductNotFoundException::class, static function (Throwable $exception, ?Context $ctx): void {
            $ctx?->ack('This product is no longer available.', true);
        });

        $runtime->setErrorHandler(static function (Throwable $exception, Context $ctx): void {
            if ($ctx->isAction()) {
                $ctx->ack('Unexpected error. Please retry.', true);

                return;
            }

            $ctx->reply('Unexpected error. Type /start to reset the flow.');
        });
    }

    public function getOrderService(): OrderService
    {
        return $this->orderService;
    }

    /**
     * Approves the checkout Telegram asks about before charging the user. It carries no chat, so
     * it is registered with Bot::onRawUpdate() and runs outside the conversation runtime.
     *
     * @param array<string, mixed> $update
     */
    public function approveCheckout(Api $api, array $update): void
    {
        $query = $update['pre_checkout_query'] ?? null;

        if (!\is_array($query)) {
            return;
        }

        $order = $this->orderService->find(self::orderIdFromPayload($query['invoice_payload'] ?? null));
        $ok = $order !== null && !$order['paid'];

        $api->answerPreCheckoutQuery([
            'pre_checkout_query_id' => self::stringValue($query['id'] ?? null),
            'ok' => $ok,
            'error_message' => $ok ? null : 'This order can no longer be paid.',
        ]);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function successfulPayment(Context $ctx): ?array
    {
        $telegram = $ctx->getMetadata()['telegram'] ?? null;
        $message = \is_array($telegram) ? $telegram['message'] ?? null : null;
        $payment = \is_array($message) ? $message['successful_payment'] ?? null : null;

        return \is_array($payment) ? $payment : null;
    }

    /**
     * The order behind an invoice payload; this example sends "order-1000".
     */
    private static function orderIdFromPayload(mixed $payload): int
    {
        return self::intValue(str_replace('order-', '', self::stringValue($payload)));
    }

    private static function stringValue(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function landingView(string $label = 'Open storefront', string $text = 'Back to the storefront.'): View
    {
        return View::text($text)->addActionRow(new Action('landing:shop', $label));
    }
}
