<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Examples\StarterBot;

use ChatFlow\Contracts\FlowInterface;
use ChatFlow\Contracts\FlowRuntimeInterface;
use ChatFlow\Core\Context;
use ChatFlow\I18n\ArrayTranslator;
use ChatFlow\I18n\TranslatorInterface;
use ChatFlow\Middleware\LocaleMiddleware;
use ChatFlow\Scene\RootScene;
use ChatFlow\View\Action;
use ChatFlow\View\View;

final class StarterBotFlow implements FlowInterface
{
    public function register(FlowRuntimeInterface $runtime): void
    {
        // Texts come from the catalogue; the locale is the one the user chose, and the language
        // Telegram reports until then.
        $runtime->getContainer()->set(TranslatorInterface::class, new ArrayTranslator(StarterBotMessages::CATALOGUES));
        $runtime->middleware([LocaleMiddleware::class]);

        $runtime->registerScene(PhoneScene::class);
        $runtime->allowTransition(RootScene::ID, PhoneScene::class);

        $runtime->onCommand('start', static function (Context $ctx): void {
            $ctx->reply(self::homeView($ctx));
        });

        $runtime->onCommand('cancel', static function (Context $ctx): void {
            $ctx->reply(self::settingsView($ctx, 'phone.unchanged'));
            $ctx->leave();
        });

        $runtime->onAction('settings:open', static function (Context $ctx): void {
            $ctx->ack($ctx->t('ack.settings'));
            $ctx->render(self::settingsView($ctx));
        });

        $runtime->onAction('home:open', static function (Context $ctx): void {
            $ctx->ack();
            $ctx->render(self::homeView($ctx));
        });

        $runtime->onAction('profile:phone', static function (Context $ctx): void {
            $ctx->ack($ctx->t('ack.phone'));
            $ctx->enter(PhoneScene::class);
        });

        // Works inside the scene as well, so the language can be switched at any point.
        $runtime->onActionPrefix('lang:', static function (Context $ctx): void {
            $locale = substr($ctx->getActionId() ?? '', 5);

            $ctx->session()->set('locale', $locale);
            $ctx->setLocale($locale);

            $ctx->ack($ctx->t('ack.language'));
            $ctx->render(self::homeView($ctx));
        })->global();

        $runtime->fallback(static function (Context $ctx): void {
            $ctx->reply($ctx->t('fallback'));
        });
    }

    public static function homeView(Context $ctx): View
    {
        return View::text($ctx->t('home.title') . "\n\n" . $ctx->t('home.body'))
            ->addActionRow(new Action('settings:open', $ctx->t('home.settings')))
            ->addActionRow(self::languageAction($ctx));
    }

    public static function settingsView(Context $ctx, ?string $noticeKey = null): View
    {
        $phone = $ctx->session()->getString('profile.phone');

        return View::text(
            ($noticeKey !== null ? $ctx->t($noticeKey) . "\n\n" : '') .
            $ctx->t('settings.title') . "\n\n" .
            $ctx->t('settings.phone', ['phone' => $phone !== '' ? $phone : $ctx->t('settings.phone_missing')]) . "\n" .
            $ctx->t('settings.body'),
        )
            ->addActionRow(new Action('profile:phone', $ctx->t('settings.update')))
            ->addActionRow(new Action('home:open', $ctx->t('settings.home')));
    }

    /**
     * A single button that switches to the other language.
     */
    private static function languageAction(Context $ctx): Action
    {
        $next = ($ctx->getLocale() ?? 'en') === 'ru' ? 'en' : 'ru';

        return new Action('lang:' . $next, $ctx->t('language.switch'));
    }
}
