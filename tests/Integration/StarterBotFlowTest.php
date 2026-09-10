<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Examples\StarterBot\PhoneScene;
use ChatFlow\Telegram\Examples\StarterBot\StarterBotFactory;
use ChatFlow\Telegram\Examples\StarterBot\StarterBotFlow;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class StarterBotFlowTest extends TestCase
{
    private TelegramBotTester $tester;

    protected function setUp(): void
    {
        $mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $mockClient);

        $bot = StarterBotFactory::create(
            token: 'TEST_TOKEN',
            basePath: \dirname(__DIR__, 2),
            api: $api,
            storage: new MemoryStorage(),
        );

        $this->tester = new TelegramBotTester($bot, $mockClient);
    }

    public function testStartRouteAndSettingsNavigationUseCallbackAckAndRender(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->assertSee('Starter Bot')
            ->assertKeyboardHas('Open settings')
            ->clear()
            ->clickButton('settings:open')
            ->assertEndpointCalled('answerCallbackQuery')
            ->assertEndpointCalled('editMessageText')
            ->assertSee('Settings')
            ->assertKeyboardHas('Update phone');
    }

    public function testScenesWorkWithTheDefaultInMemoryStorage(): void
    {
        $mockClient = new MockHttpClient();
        $bot = new Bot('TEST_TOKEN', \dirname(__DIR__, 2), api: new Api('TEST_TOKEN', false, $mockClient));
        (new StarterBotFlow())->register($bot);

        (new TelegramBotTester($bot, $mockClient))
            ->clickButton('profile:phone')
            ->assertScene(PhoneScene::class)
            ->assertSee('Send your phone');
    }

    public function testPhoneSceneValidationAndSuccessPersistThePhoneNumber(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('settings:open')
            ->clear()
            ->clickButton('profile:phone')
            ->assertScene(PhoneScene::class)
            ->assertSee('Send your phone in +79991234567 format')
            ->clear()
            ->sendMessage('123')
            ->assertScene(PhoneScene::class)
            ->assertSee('Use +79991234567.')
            ->clear()
            ->sendMessage('+79991234567')
            ->assertNotInScene()
            ->assertSessionHas('profile.phone', '+79991234567')
            ->assertSee('Phone saved.')
            ->assertSee('Phone: +79991234567')
            ->assertKeyboardHas('Back to home');
    }

    public function testPhoneSceneCanBeCancelledByTextOrByGlobalCommand(): void
    {
        $this->tester
            ->clickButton('profile:phone')
            ->assertScene(PhoneScene::class)
            ->clear()
            ->sendMessage('cancel')
            ->assertNotInScene()
            ->assertSee('Phone unchanged.')
            ->assertSessionMissing('profile.phone')
            ->clear()
            ->clickButton('profile:phone')
            ->assertScene(PhoneScene::class)
            ->clear()
            ->sendCommand('/cancel')
            ->assertNotInScene()
            ->assertSee('Phone unchanged.');
    }
}
