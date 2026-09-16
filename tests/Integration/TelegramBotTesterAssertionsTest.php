<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Integration;

use Automata\Clock\FrozenClock;
use ChatFlow\Core\Context;
use ChatFlow\Event\ConversationRef;
use ChatFlow\Scene\Conversation;
use ChatFlow\SideEffect\SideEffect;
use ChatFlow\Storage\Drivers\MemoryStorage;
use ChatFlow\Telegram\Bot;
use ChatFlow\Telegram\Testing\MockHttpClient;
use ChatFlow\Telegram\Testing\TelegramBotTester;
use ChatFlow\Telegram\Tests\Fixtures\SurveyScene;
use ChatFlow\Timer\Drivers\MemoryTimerStore;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramBotTesterAssertionsTest extends TestCase
{
    public function testSideEffectsTimersAndStateAssertionsThroughRealBot(): void
    {
        $mockClient = new MockHttpClient();
        $api = new Api('TEST_TOKEN', false, $mockClient);
        $clock = FrozenClock::at('2026-09-12 10:00:00+00:00');
        $timers = new MemoryTimerStore();

        $bot = new Bot(
            'TEST_TOKEN',
            __DIR__,
            api: $api,
            storage: new MemoryStorage(),
            timers: $timers,
            clock: $clock,
        );
        $bot->registerScene(SurveyScene::class);

        $attempts = 0;
        $bot->getApplication()->registerSideEffect('crm_sync', static function (SideEffect $effect) use (&$attempts): array {
            $attempts++;
            if ($attempts === 1) {
                throw new \RuntimeException('temporary crm failure');
            }

            return ['id' => $effect->payload['id']];
        });

        $bot->command('order', static function (Context $ctx): void {
            $ctx->session()->set('order_id', 123);
            $ctx->schedule('crm_sync', ['id' => 123]);
            $ctx->wakeAt(new DateTimeImmutable('2026-09-12 12:00:00+00:00'), 'order_check');
            $ctx->reply('Order placed');
        });

        $bot->command('broken', static function (Context $ctx): void {
            $ctx->schedule('unregistered_handler');
        });

        $tester = new TelegramBotTester($bot, $mockClient);

        // Initial checks
        $tester->assertNotInScene()
            ->assertNoScenePending()
            ->assertNoSideEffectsPending()
            ->assertNoTimer('order_check')
            ->assertSessionMissing('order_id');

        self::assertInstanceOf(ConversationRef::class, $tester->conversation());
        self::assertInstanceOf(Conversation::class, $tester->resume());

        // Dispatch command triggering side effect, timer, and session
        $tester->sendCommand('/order')
            ->assertSee('Order placed')
            ->assertResult('success', 'route_processed')
            ->assertSessionHas('order_id')
            ->assertSessionHas('order_id', 123)
            ->assertSideEffectPending('crm_sync')
            ->assertTimerScheduled('order_check')
            ->assertTimerScheduled('order_check', new DateTimeImmutable('2026-09-12 12:00:00+00:00'))
            ->assertNoTimer('missing_timer');

        // Drain side effects
        $bot->getApplication()->drain($tester->conversation()->getId());
        $tester->assertNoSideEffectsPending();

        // Trigger failing side effect
        $tester->sendCommand('/broken')
            ->assertSideEffectFailed('unregistered_handler')
            ->assertNoSideEffectsPending();

        // Pending scene transition
        $bot->getConversations()->enterLater($tester->conversation()->getId(), SurveyScene::class);
        $tester->assertScenePending(SurveyScene::class);

        // Switch user: new user should have isolated state
        $tester->user('999888', '777666');
        $tester->assertNotInScene()
            ->assertNoScenePending()
            ->assertNoSideEffectsPending()
            ->assertNoTimer('order_check')
            ->assertSessionMissing('order_id');
    }
}
