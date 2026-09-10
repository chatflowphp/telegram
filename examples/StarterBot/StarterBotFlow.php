<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Context;
use ChatFlow\Scene\RootScene;
use ChatFlow\View\Action;
use ChatFlow\View\View;

final class StarterBotFlow implements FlowInterface
{
    public function register(FlowRuntimeInterface $runtime): void
    {
        $runtime->registerScene(PhoneScene::class);
        $runtime->allowTransition(RootScene::ID, PhoneScene::class);

        $runtime->onCommand('start', static function (Context $ctx): void {
            $ctx->reply(self::homeView());
        });

        $runtime->onCommand('cancel', static function (Context $ctx): void {
            $ctx->reply(self::settingsView($ctx, 'Phone unchanged.'));
            $ctx->leave();
        });

        $runtime->onAction('settings:open', static function (Context $ctx): void {
            $ctx->ack('Opening settings');
            $ctx->render(self::settingsView($ctx));
        });

        $runtime->onAction('home:open', static function (Context $ctx): void {
            $ctx->ack();
            $ctx->render(self::homeView());
        });

        $runtime->onAction('profile:phone', static function (Context $ctx): void {
            $ctx->ack('Updating phone');
            $ctx->enter(PhoneScene::class);
        });

        $runtime->fallback(static function (Context $ctx): void {
            $ctx->reply('Type /start to open the starter bot.');
        });
    }

    public static function homeView(): View
    {
        return View::text(
            "Starter Bot\n\n" .
            'This minimal example shows a command, a callback route and one validation-backed scene.',
        )->addActionRow(new Action('settings:open', 'Open settings'));
    }

    public static function settingsView(Context $ctx, ?string $notice = null): View
    {
        $prefix = $notice !== null ? $notice . "\n\n" : '';
        $phone = $ctx->session()->getString('profile.phone');

        return View::text(
            $prefix .
            "Settings\n\n" .
            'Phone: ' . ($phone !== '' ? $phone : 'Not set') . "\n" .
            'Use the button below to update the saved phone number.',
        )
            ->addActionRow(new Action('profile:phone', 'Update phone'))
            ->addActionRow(new Action('home:open', 'Back to home'));
    }
}
