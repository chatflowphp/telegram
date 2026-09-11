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
     *     total: int,
     *     paid: bool,
     *     charge_id: string|null
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
            'paid' => false,
            'charge_id' => null,
        ];

        return $orderId;
    }

    /**
     * @return array{
     *     id: int,
     *     phone: string,
     *     items: array<int, array{id: int, name: string, price: int, quantity: int}>,
     *     total: int,
     *     paid: bool,
     *     charge_id: string|null
     * }|null
     */
    public function find(int $orderId): ?array
    {
        foreach ($this->orders as $order) {
            if ($order['id'] === $orderId) {
                return $order;
            }
        }

        return null;
    }

    /**
     * Records a completed Telegram payment. The charge id makes the call idempotent: Telegram may
     * deliver the same successful payment more than once.
     */
    public function markPaid(int $orderId, string $chargeId): void
    {
        foreach ($this->orders as $index => $order) {
            if ($order['id'] === $orderId) {
                $this->orders[$index]['paid'] = true;
                $this->orders[$index]['charge_id'] = $chargeId;

                return;
            }
        }
    }

    /**
     * The price of an order in Telegram Stars. The example charges one star per 1000 RUB.
     */
    public static function starsFor(int $totalRub): int
    {
        return max(1, intdiv($totalRub, 1000));
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
     *     total: int,
     *     paid: bool,
     *     charge_id: string|null
     * }>
     */
    public function getOrders(): array
    {
        return $this->orders;
    }
}
