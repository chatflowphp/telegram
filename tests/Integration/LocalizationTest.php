<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Core\Context;
use ChatFlow\I18n\ArrayTranslator;
use ChatFlow\I18n\TranslatorInterface;
use ChatFlow\Middleware\LocaleMiddleware;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class LocalizationTest extends TestCase
{
    public function testTheBotAnswersInTheLanguageTelegramReports(): void
    {
        $client = new MockHttpClient();
        $tester = new TelegramBotTester(self::bot($client), $client);

        $tester->sendRawUpdate(self::message('/start', 'ru'))->assertSee('Привет');
        $tester->clear();
        $tester->sendRawUpdate(self::message('/start', 'en-GB'))->assertSee('Hello');
    }

    public function testWithoutALanguageTheFallbackLocaleIsUsed(): void
    {
        $client = new MockHttpClient();
        $tester = new TelegramBotTester(self::bot($client), $client);

        $tester->sendRawUpdate(self::message('/start', null))->assertSee('Hello');
    }

    public function testTheStoredPreferenceWinsOverTheTelegramLanguage(): void
    {
        $client = new MockHttpClient();
        $tester = new TelegramBotTester(self::bot($client), $client);

        $tester->sendRawUpdate(self::message('/english', 'ru'));
        $tester->clear();
        $tester->sendRawUpdate(self::message('/start', 'ru'))->assertSee('Hello');
    }

    private static function bot(MockHttpClient $client): Bot
    {
        $bot = new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-i18n-test',
            api: new Api('TEST_TOKEN', false, $client),
            storage: new MemoryStorage(),
        );

        $bot->getContainer()->set(TranslatorInterface::class, new ArrayTranslator([
            'en' => ['greeting' => 'Hello'],
            'ru' => ['greeting' => 'Привет'],
        ]));
        $bot->middleware([LocaleMiddleware::class]);

        $bot->command('start', static function (Context $ctx): void {
            $ctx->reply($ctx->t('greeting'));
        });
        $bot->command('english', static function (Context $ctx): void {
            $ctx->session()->set('locale', 'en');
        });

        return $bot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(string $text, ?string $languageCode): array
    {
        $from = ['id' => 42, 'username' => 'tester'];

        if ($languageCode !== null) {
            $from['language_code'] = $languageCode;
        }

        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 10,
                'text' => $text,
                'chat' => ['id' => 42, 'type' => 'private'],
                'from' => $from,
            ],
        ];
    }
}
