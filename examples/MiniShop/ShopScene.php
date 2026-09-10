<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;
use ChatFlow\View\MediaAttachment;
use ChatFlow\View\View;

final class ShopScene extends BaseScene
{
    private const ITEMS_PER_PAGE = 3;

    /**
     * @var list<array{id: int, name: string, price: int, image: string, summary: string}>
     */
    private array $products = [
        [
            'id' => 1,
            'name' => 'Laptop Air 14',
            'price' => 84990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3f/Fronalpstock_big.jpg/1024px-Fronalpstock_big.jpg',
            'summary' => 'A light everyday laptop for work and travel.',
        ],
        [
            'id' => 2,
            'name' => 'Noise-Cancel Headphones',
            'price' => 19990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/0/02/Computer-aj_aj_ashton_01.svg/1024px-Computer-aj_aj_ashton_01.svg.png',
            'summary' => 'Wireless headphones with a strong travel profile.',
        ],
        [
            'id' => 3,
            'name' => '4K Monitor 27',
            'price' => 32990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/4/48/Dell_Monitor.jpg/1024px-Dell_Monitor.jpg',
            'summary' => 'A sharp desktop display for design and coding.',
        ],
        [
            'id' => 4,
            'name' => 'Mechanical Keyboard',
            'price' => 11990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/b/bb/Computer_keyboard_US.svg/1024px-Computer_keyboard_US.svg.png',
            'summary' => 'Tactile switches with a compact office layout.',
        ],
        [
            'id' => 5,
            'name' => 'Smartphone Pro X',
            'price' => 68990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/33/Smartphone_icon_-_Noun_Project_283536.svg/1024px-Smartphone_icon_-_Noun_Project_283536.svg.png',
            'summary' => 'A fast phone with a reliable camera stack.',
        ],
        [
            'id' => 6,
            'name' => 'Portable SSD 2TB',
            'price' => 15990,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/6f/Samsung_970_EVO_SSD_1TB-NVMe_M.2.jpg/1024px-Samsung_970_EVO_SSD_1TB-NVMe_M.2.jpg',
            'summary' => 'Fast external storage for projects and backups.',
        ],
    ];

    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function handle(Context $ctx): void
    {
        $ctx->render($this->homeView($ctx));
    }

    /**
     * @param array<string, mixed> $params
     */
    public function onShowCatalog(Context $ctx, array $params = []): void
    {
        $ctx->ack();

        $page = $this->resolvePage($params['page'] ?? 1);
        $ctx->render($this->catalogView($ctx, $page));
    }

    public function onShowFeatured(Context $ctx): void
    {
        $ctx->ack();

        $product = $this->featuredProduct();
        $ctx->render(
            View::text(
                "Featured deal\n\n{$product['name']}\n{$product['summary']}\nPrice: {$this->formatMoney($product['price'])} RUB\n\n" .
                'Telegram keeps this navigational message editable; the product photo is sent as a separate media reply.',
            )
                ->addActionRow($this->sceneAction('Send product photo', 'onSendFeaturedPhoto'))
                ->addActionRow($this->sceneAction('Add featured item', 'onAddFeatured'))
                ->addActionRow($this->sceneAction('Back to home', 'onShowHome')),
        );
    }

    public function onSendFeaturedPhoto(Context $ctx): void
    {
        $ctx->ack('Sending product photo');

        $product = $this->featuredProduct();
        $ctx->reply(
            View::text("Product photo: {$product['name']}")
                ->addMedia(new MediaAttachment('image', $product['image'])),
        );
    }

    public function onAddFeatured(Context $ctx): void
    {
        $this->onAddToCart($ctx, ['id' => $this->featuredProduct()['id']]);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function onAddToCart(Context $ctx, array $params = []): void
    {
        $productId = is_numeric($params['id'] ?? null) ? (int) $params['id'] : 0;
        $product = $this->findProductById($productId);

        if ($product === null) {
            throw new ProductNotFoundException();
        }

        /** @var array<int, int> $cart */
        $cart = $ctx->session()->get('cart', []);
        $cart[$productId] = ($cart[$productId] ?? 0) + 1;
        $ctx->session()->set('cart', $cart);

        $ctx->ack(\sprintf('%s added to the cart.', $product['name']));
    }

    public function onViewCart(Context $ctx): void
    {
        $ctx->ack();
        $ctx->render($this->cartView($ctx));
    }

    public function onClearCart(Context $ctx): void
    {
        $ctx->session()->set('cart', []);
        $ctx->ack('Cart cleared.');
        $ctx->render($this->cartView($ctx));
    }

    public function onCheckout(Context $ctx): void
    {
        $cartItems = $this->getCartItemsWithDetails($ctx);
        if ($cartItems === []) {
            $ctx->ack('Add at least one product before checkout.', true);

            return;
        }

        $ctx->ack('Moving to checkout');
        $ctx->enter(CheckoutScene::class, ['cart_items' => $cartItems], 'Shop');
    }

    public function onShowHome(Context $ctx): void
    {
        $ctx->ack();
        $ctx->render($this->homeView($ctx));
    }

    public function getTitle(): string
    {
        return 'Shop';
    }

    private function homeView(Context $ctx): View
    {
        /** @var array<int, int> $cart */
        $cart = $ctx->session()->get('cart', []);
        $cartCount = array_sum($cart);

        return View::text(
            "Storefront\n\n" .
            'A Telegram-first shop example with inline callbacks, editable screens, media replies, sessions and checkout validation.',
        )
            ->addActionRow($this->sceneAction('Browse catalog', 'onShowCatalog', ['page' => 1]))
            ->addActionRow(
                $this->sceneAction('Featured deal', 'onShowFeatured'),
                $this->sceneAction(\sprintf('Cart (%d)', $cartCount), 'onViewCart'),
            );
    }

    private function catalogView(Context $ctx, int $page): View
    {
        $totalPages = (int) ceil(\count($this->products) / self::ITEMS_PER_PAGE);
        $offset = ($page - 1) * self::ITEMS_PER_PAGE;
        $pageProducts = \array_slice($this->products, $offset, self::ITEMS_PER_PAGE);

        $lines = [];
        $view = View::text('');

        foreach ($pageProducts as $product) {
            $lines[] = \sprintf(
                "%s - %s RUB\n%s",
                $product['name'],
                $this->formatMoney($product['price']),
                $product['summary'],
            );

            $view = $view->addActionRow(
                $this->sceneAction(
                    \sprintf('Add %s (%s RUB)', $product['name'], $this->formatMoney($product['price'])),
                    'onAddToCart',
                    ['id' => $product['id']],
                ),
            );
        }

        $paginationRow = [];
        if ($page > 1) {
            $paginationRow[] = $this->sceneAction('Prev', 'onShowCatalog', ['page' => $page - 1]);
        }
        if ($page < $totalPages) {
            $paginationRow[] = $this->sceneAction('Next', 'onShowCatalog', ['page' => $page + 1]);
        }
        if ($paginationRow !== []) {
            $view = $view->addActionRow(...$paginationRow);
        }

        return $view
            ->withText(
                \sprintf(
                    "Catalog page %d/%d\n\n%s",
                    $page,
                    $totalPages,
                    implode("\n\n", $lines),
                ),
            )
            ->addActionRow(
                $this->sceneAction('View cart', 'onViewCart'),
                $this->sceneAction('Home', 'onShowHome'),
            );
    }

    private function cartView(Context $ctx): View
    {
        $items = $this->getCartItemsWithDetails($ctx);
        $isEmpty = $items === [];

        $view = View::text("Cart\n\n" . $this->orderService->formatCart($items));

        if (!$isEmpty) {
            $view = $view->addActionRow(
                $this->sceneAction('Checkout', 'onCheckout'),
                $this->sceneAction('Clear cart', 'onClearCart'),
            );
        }

        return $view->addActionRow($this->sceneAction('Home', 'onShowHome'));
    }

    /**
     * @return array{id: int, name: string, price: int, image: string, summary: string}
     */
    private function featuredProduct(): array
    {
        return $this->products[0];
    }

    /**
     * @return array{id: int, name: string, price: int, image: string, summary: string}|null
     */
    private function findProductById(int $productId): ?array
    {
        foreach ($this->products as $product) {
            if ($product['id'] === $productId) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: int, name: string, price: int, quantity: int}>
     */
    private function getCartItemsWithDetails(Context $ctx): array
    {
        /** @var array<int, int> $cart */
        $cart = $ctx->session()->get('cart', []);
        $items = [];

        foreach ($cart as $productId => $quantity) {
            $product = $this->findProductById($productId);
            if ($product === null) {
                continue;
            }

            $items[] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $quantity,
            ];
        }

        return $items;
    }

    private function resolvePage(mixed $value): int
    {
        $page = is_numeric($value) ? (int) $value : 1;
        $totalPages = max(1, (int) ceil(\count($this->products) / self::ITEMS_PER_PAGE));

        return max(1, min($page, $totalPages));
    }

    private function formatMoney(int $amount): string
    {
        return number_format($amount, 0, '.', ' ');
    }
}
