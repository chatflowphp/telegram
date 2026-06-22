<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use ChatFlow\Telegram\Tests\Fixtures\SurveyScene;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

class FlowTest extends TestCase
{
    private TelegramBotTester $tester;

    private Bot $bot;

    protected function setUp(): void
    {
        // 1. Подготовка заглушки сети
        $mockClient = new MockHttpClient();

        // 2. Инициализация API с заглушкой
        $api = new Api('TEST_TOKEN', false, $mockClient);

        $this->bot = new Bot('TEST_TOKEN', __DIR__, api: $api);

        $this->bot->useStorage(new MemoryStorage());

        $this->bot->registerScene(SurveyScene::class);

        // Используем type-hint Context для корректного внедрения зависимости
        $this->bot->command('start', function (Context $ctx) {
            $ctx->enter(SurveyScene::class);
        });

        $this->tester = new TelegramBotTester($this->bot, $mockClient);
    }

    public function test_full_survey_flow(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->assertSee('Как вас зовут?')
            ->assertScene(SurveyScene::class)
            ->clear()
            ->sendMessage('Alex')
            ->assertSessionHas('name', 'Alex')
            ->assertSee('Сколько вам лет?')
            ->clear()
            ->sendMessage('not a number')
            ->assertSee('Возраст должен быть числом')
            ->clear()
            ->sendMessage('30')
            ->assertSessionHas('age', 30)
            ->assertSee('Данные сохранены')
            ->assertKeyboardHas('ОК')
            ->clear()
            ->clickSceneAction([SurveyScene::class, 'onOk'])
            ->assertNotInScene()
            ->assertSee('Всего доброго!');
    }
}
