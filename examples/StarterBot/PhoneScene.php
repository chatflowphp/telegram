<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Core\Context;
use ChatFlow\FSM\BaseScene;

final class PhoneScene extends BaseScene
{
    public function handle(Context $ctx): void
    {
        $this->ask("Profile phone\n\nSend your phone in +79991234567 format.")
            ->validate('regex:/^\+7\d{10}$/', 'Use +79991234567.')
            ->handle([$this, 'savePhone']);
    }

    public function savePhone(Context $ctx): void
    {
        $ctx->session()->set('profile.phone', $ctx->getText());
        $ctx->reply(StarterBotFlow::settingsView($ctx, 'Phone saved.'));
        $this->leave();
    }

    public function getTitle(): string
    {
        return 'Profile phone';
    }
}
