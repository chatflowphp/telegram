<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

require_once dirname(__DIR__, 2) . '/examples/StarterBot/bootstrap.php';

use ChatFlow\Core\Result;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Examples\StarterBot\PhoneScene;
use ChatFlow\Telegram\Examples\StarterBot\StarterBotFactory;
use ChatFlow\Telegram\Examples\StarterBot\StarterBotFlow;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

final class StarterBotFlowTest extends TestCase
{
    private Bot $bot;

    private TelegramBotTester $tester;

    private MockHttpClient $mockClient;

    protected function setUp(): void
    {
        $this->mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $this->mockClient);

        $this->bot = StarterBotFactory::create(
            token: 'TEST_TOKEN',
            basePath: dirname(__DIR__, 2),
            api: $api,
            storage: new MemoryStorage(),
        );

        $this->tester = new TelegramBotTester($this->bot, $this->mockClient);
    }

    public function test_start_route_and_settings_navigation_use_callback_ack_and_render(): void
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

    public function test_phone_scene_requires_storage_backed_state(): void
    {
        $mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $mockClient);
        $bot = new Bot('TEST_TOKEN', dirname(__DIR__, 2), api: $api);
        (new StarterBotFlow())->register($bot);

        $result = $bot->handle(new Update([
            'update_id' => 1,
            'callback_query' => [
                'id' => '10001',
                'from' => [
                    'id' => 123456789,
                    'is_bot' => false,
                    'first_name' => 'Test',
                    'username' => 'test_user',
                ],
                'message' => [
                    'message_id' => 1,
                    'chat' => [
                        'id' => 123456789,
                        'type' => 'private',
                        'username' => 'test_user',
                    ],
                    'date' => time(),
                    'text' => 'Settings',
                ],
                'data' => 'profile:phone',
            ],
        ]));

        self::assertInstanceOf(Result::class, $result);
        self::assertTrue($result->isError());
        self::assertSame('State manager is not configured.', $result->getMessage());
    }

    public function test_phone_scene_validation_and_success_persist_the_phone_number(): void
    {
        $this->tester
            ->sendCommand('/start')
            ->clear()
            ->clickButton('settings:open')
            ->clear()
            ->clickButton('profile:phone')
            ->assertScene(PhoneScene::class)
            ->assertSee('Send your phone in +79991234567 format.')
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
}
