<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

class OrderService
{
    private int $nextOrderId = 1000;

    /**
     * @var list<array{
     *     id: int,
     *     phone: string,
     *     items: array<int, array{id: int, name: string, price: int, quantity: int}>,
     *     total: int
     * }>
     */
    private array $orders = [];

    /**
     * @param array<int, array{id: int, name: string, price: int, quantity: int}> $cartItems
     */
    public function create(string $phone, array $cartItems): int
    {
        $orderId = $this->nextOrderId++;

        $this->orders[] = [
            'id' => $orderId,
            'phone' => $phone,
            'items' => $cartItems,
            'total' => $this->calculateTotal($cartItems),
        ];

        return $orderId;
    }

    /**
     * @param array<int, array{id: int, name: string, price: int, quantity: int}> $cartItems
     */
    public function formatCart(array $cartItems): string
    {
        if ($cartItems === []) {
            return 'Your cart is empty';
        }

        $lines = [];
        $total = 0;

        foreach ($cartItems as $item) {
            $itemTotal = $item['price'] * $item['quantity'];
            $total += $itemTotal;
            $lines[] = \sprintf(
                '• %s (%d pc.) - %s RUB',
                $item['name'],
                $item['quantity'],
                number_format($itemTotal, 0, '.', ' '),
            );
        }

        $lines[] = '';
        $lines[] = \sprintf('Total: %s RUB', number_format($total, 0, '.', ' '));

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{id: int, name: string, price: int, quantity: int}> $cartItems
     */
    public function calculateTotal(array $cartItems): int
    {
        $total = 0;
        foreach ($cartItems as $item) {
            $total += $item['price'] * $item['quantity'];
        }

        return $total;
    }

    /**
     * @return list<array{
     *     id: int,
     *     phone: string,
     *     items: array<int, array{id: int, name: string, price: int, quantity: int}>,
     *     total: int
     * }>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }
}
