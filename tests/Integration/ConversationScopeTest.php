<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use ChatFlow\Core\Context;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\ConversationScope;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use ChatFlow\Telegram\Tests\Fixtures\SurveyScene;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class ConversationScopeTest extends TestCase
{
    public function testGroupMembersGetTheirOwnSceneUnderTheChatAndUserScope(): void
    {
        $client = new MockHttpClient();
        $tester = new TelegramBotTester(self::bot(ConversationScope::ChatAndUser, $client), $client);

        $tester->user('-100500', '111', 'alice')
            ->sendCommand('survey')
            ->assertScene(SurveyScene::class)
            ->assertSee('Как вас зовут?');

        $tester->user('-100500', '222', 'bob')
            ->sendMessage('Bob')
            ->assertNotInScene();

        $tester->user('-100500', '111', 'alice')
            ->assertScene(SurveyScene::class)
            ->assertSessionMissing('name');

        foreach ($client->getRequests() as $request) {
            self::assertSame('-100500', $request['params']['chat_id'] ?? null, 'Messages go to the chat, not to the conversation key.');
        }
    }

    public function testTheWholeGroupSharesOneSceneUnderTheDefaultScope(): void
    {
        $client = new MockHttpClient();
        $tester = new TelegramBotTester(self::bot(ConversationScope::Chat, $client), $client);

        $tester->user('-100500', '111', 'alice')
            ->sendCommand('survey')
            ->assertScene(SurveyScene::class);

        $tester->user('-100500', '222', 'bob')
            ->sendMessage('Bob')
            ->assertScene(SurveyScene::class)
            ->assertSessionHas('name', 'Bob');
    }

    public function testPrivateChatsKeepTheChatIdUnderBothScopes(): void
    {
        $scoped = self::bot(ConversationScope::ChatAndUser, new MockHttpClient());
        $shared = self::bot(ConversationScope::Chat, new MockHttpClient());

        self::assertSame('555', $scoped->conversationIdFor('555', '555'));
        self::assertSame('555', $shared->conversationIdFor('555', '555'));
        self::assertSame('-100500:222', $scoped->conversationIdFor('-100500', '222'));
        self::assertSame('-100500', $shared->conversationIdFor('-100500', '222'));
        self::assertSame('-100500', $scoped->conversationIdFor('-100500'));
    }

    public function testASceneCanBeStartedForOneGroupMemberFromOutsideARequest(): void
    {
        $client = new MockHttpClient();
        $bot = self::bot(ConversationScope::ChatAndUser, $client);

        $result = $bot->enterScene('-100500', SurveyScene::class, userId: '222');

        self::assertTrue($result->isSuccess());
        self::assertSame(SurveyScene::class, $bot->getConversations()->resume('-100500:222')->getCurrentScene());
        self::assertSame('-100500', $client->getRequests()[0]['params']['chat_id'] ?? null);

        $text = $client->getRequests()[0]['params']['text'] ?? null;
        self::assertTrue(
            \is_string($text) && str_contains($text, 'Как вас зовут?'),
            'The scene question is sent to the chat when a member is entered from outside a request.',
        );
    }

    private static function bot(ConversationScope $scope, MockHttpClient $client): Bot
    {
        $bot = new Bot(
            'TEST_TOKEN',
            sys_get_temp_dir() . '/chatflow-scope-test',
            api: new Api('TEST_TOKEN', false, $client),
            storage: new MemoryStorage(),
            conversationScope: $scope,
        );

        $bot->registerScene(SurveyScene::class);
        $bot->command('survey', static function (Context $ctx): void {
            $ctx->enter(SurveyScene::class);
        });

        return $bot;
    }
}
