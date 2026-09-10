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

final class FlowTest extends TestCase
{
    private TelegramBotTester $tester;

    protected function setUp(): void
    {
        $mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $mockClient);

        $bot = new Bot('TEST_TOKEN', __DIR__, api: $api, storage: new MemoryStorage());
        $bot->registerScene(SurveyScene::class);
        $bot->command('start', static function (Context $ctx): void {
            $ctx->enter(SurveyScene::class);
        });
        $bot->command('help', static function (Context $ctx): void {
            $ctx->reply('Справка');
        });

        $this->tester = new TelegramBotTester($bot, $mockClient);
    }

    public function testFullSurveyFlow(): void
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
            ->assertScene(SurveyScene::class)
            ->clear()
            ->sendMessage('30')
            ->assertSessionHas('age', 30)
            ->assertSee('Данные сохранены')
            ->assertKeyboardHas('ОК')
            ->clear()
            ->clickSceneAction([SurveyScene::class, 'onOk'])
            ->assertNotInScene()
            ->assertSee('Всего доброго!')
            ->assertEndpointCalled('answerCallbackQuery');
    }

    public function testCommandsInterruptTheSceneWithoutLeavingIt(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->sendCommand('/help')
            ->assertSee('Справка')
            ->assertResult('success', 'global_route_processed')
            ->assertScene(SurveyScene::class)
            ->clear()
            ->sendMessage('Alex')
            ->assertSessionHas('name', 'Alex');
    }
}
