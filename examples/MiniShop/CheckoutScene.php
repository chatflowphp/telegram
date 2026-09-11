<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\View\Action;
use ChatFlow\View\Choice;
use ChatFlow\View\View;

final class CheckoutScene extends BaseScene
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function handle(Context $ctx): void
    {
        /** @var array<int, array{id: int, name: string, price: int, quantity: int}> $cartItems */
        $cartItems = $ctx->session()->get('cart_items', []);

        if ($cartItems === []) {
            $ctx->reply('Your cart is empty.');
            $ctx->back();

            return;
        }

        $total = $this->orderService->calculateTotal($cartItems);
        $ctx->ask(
            View::text(
                \sprintf(
                    "Checkout\n\nOrder total: %s RUB\nEnter a phone number in +79991234567 format.",
                    number_format($total, 0, '.', ' '),
                ),
            )->addChoiceRow(new Choice('Cancel checkout', 'Cancel checkout')),
        )
            ->validate('regex:/^(\+7|7|8)\d{10}$/', 'Use +79991234567 or 89991234567.')
            ->onText('Cancel checkout', [$this, 'onCancelCheckout'])
            ->handle([$this, 'handlePhone']);
    }

    public function handlePhone(Context $ctx): void
    {
        $ctx->session()->set('customer_phone', $this->normalizePhone($ctx->getText()));
        $this->finalizeOrder($ctx);
    }

    public function onCancelCheckout(Context $ctx): void
    {
        $ctx->reply('Checkout cancelled.');
        $ctx->back();
    }

    public function getTitle(): string
    {
        return 'Checkout';
    }

    private function finalizeOrder(Context $ctx): void
    {
        /** @var string $phone */
        $phone = $ctx->session()->get('customer_phone', '');
        $cartItems = $this->cartItems($ctx);

        $orderId = $this->orderService->create($phone, $cartItems);
        $total = $this->orderService->calculateTotal($cartItems);

        $ctx->session()->remove('cart');
        $ctx->session()->remove('cart_items');
        $ctx->session()->remove('customer_phone');

        $ctx->reply('Order placed.');
        $ctx->reply(
            View::text(
                \sprintf(
                    "Order #%d is confirmed.\nTotal: %s RUB\nPhone: %s",
                    $orderId,
                    number_format($total, 0, '.', ' '),
                    $phone,
                ),
            )
                ->addActionRow(new Action(
                    'order:pay',
                    \sprintf('Pay %d Stars', OrderService::starsFor($total)),
                    ['id' => $orderId],
                ))
                ->addActionRow(new Action('landing:shop', 'Back to storefront')),
        );

        $ctx->leave();
    }

    /**
     * @return array<int, array{id: int, name: string, price: int, quantity: int}>
     */
    private function cartItems(Context $ctx): array
    {
        /** @var array<int, array{id: int, name: string, price: int, quantity: int}> $items */
        $items = $ctx->session()->get('cart_items', []);

        return $items;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits !== '' && $digits[0] === '8') {
            $digits = '7' . substr($digits, 1);
        }

        return '+' . ltrim($digits, '+');
    }
}
