<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Core\Context;
use ChatFlow\Scene\BaseScene;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $ctx->ask($ctx->t('phone.ask'))
            ->validate('regex:/^\+7\d{10}$/', $ctx->t('phone.invalid'))
            ->onText(['cancel', 'отмена'], 'onCancel')
            ->handle('savePhone');
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('profile.phone', $ctx->getText());
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'phone.saved'));
        $ctx->leave();
    }

    public function onCancel(Context $ctx): void
    {
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'phone.unchanged'));
        $ctx->leave();
    }

    public function getTitle(): string
    {
        return 'Profile phone';
    }
}
