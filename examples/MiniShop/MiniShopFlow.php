<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\MiniShop;

use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Context;
use ChatFlow\View\Action;
use ChatFlow\View\View;
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

        $runtime->registerScene(ShopScene::class);
        $runtime->registerScene(CheckoutScene::class);

        $runtime->middleware([
            MiniShopVisitorMiddleware::class,
        ]);

        $runtime->onCommand('start', static function (Context $ctx): void {
            $visitor = $ctx->get('visitor', 'guest');

            $ctx->reply(
                View::text(
                    "Telegram MiniShop\n\n" .
                    "Session owner: {$visitor}\n\n" .
                    'This Telegram example covers commands, inline callbacks, scenes, smart message edits, media replies and checkout validation.'
                )->addActionRow(new Action('landing:shop', 'Open storefront'))
            );
        });

        $runtime->onActionPrefix('landing:', static function (Context $ctx): void {
            if ($ctx->getActionId() !== 'landing:shop') {
                return;
            }

            $ctx->ack('Opening storefront');
            $ctx->enter(ShopScene::class);
        });

        $runtime->onException(ProductNotFoundException::class, static function (
            Throwable $exception,
            ?Context $ctx
        ): void {
            if ($ctx === null) {
                return;
            }

            $ctx->ack('This product is no longer available.', true);
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

    public static function landingView(string $label = 'Open storefront', string $text = 'Back to the storefront.'): View
    {
        return View::text($text)->addActionRow(new Action('landing:shop', $label));
    }
}
