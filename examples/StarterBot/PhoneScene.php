<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask("Profile phone\n\nSend your phone in +79991234567 format, or /cancel.")
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->onText('cancel', 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('profile.phone', $ctx->getText());
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'Phone saved.'));
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'Phone unchanged.'));
        $ctx->leave();
    }

    public function getTitle(): string
    {
        return 'Profile phone';
    }
}
