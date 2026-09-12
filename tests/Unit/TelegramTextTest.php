<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\Exception\TelegramMessageTooLongException;
use ChatFlow\Telegram\Media\TelegramMedia;
use ChatFlow\Telegram\Media\TelegramMediaSource;
use ChatFlow\Telegram\TelegramPublisher;
use ChatFlow\Telegram\TelegramText;
use ChatFlow\Telegram\Testing\MockHttpClient;
use PHPUnit\Framework\TestCase;
use Telegram\Bot\Api;

final class TelegramTextTest extends TestCase
{
    public function testTextThatFitsIsLeftAlone(): void
    {
        self::assertSame(['short'], TelegramText::split('short'));
        self::assertSame([''], TelegramText::split(''));
        self::assertFalse(TelegramText::exceeds(str_repeat('a', 4096), TelegramText::MESSAGE_LIMIT));
        self::assertTrue(TelegramText::exceeds(str_repeat('a', 4097), TelegramText::MESSAGE_LIMIT));
    }

    public function testSplittingPrefersLineBreaks(): void
    {
        $text = str_repeat("line\n", 10);

        $chunks = TelegramText::split(trim($text), 12);

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(12, mb_strlen($chunk));
            self::assertStringNotContainsString('lineline', $chunk, 'Lines are not glued together.');
        }

        self::assertSame(str_repeat("line\n", 10), implode("\n", $chunks) . "\n");
    }

    public function testALongLineIsSplitOnSpaces(): void
    {
        $chunks = TelegramText::split('alpha beta gamma delta epsilon', 12);

        self::assertSame(['alpha beta', 'gamma delta', 'epsilon'], $chunks);
    }

    public function testAWordLongerThanTheLimitIsCut(): void
    {
        $chunks = TelegramText::split(str_repeat('x', 25), 10);

        self::assertSame([str_repeat('x', 10), str_repeat('x', 10), str_repeat('x', 5)], $chunks);
    }

    public function testUnicodeIsCountedInCharacters(): void
    {
        $text = str_repeat('привет ', 1000);
        $chunks = TelegramText::split($text);

        self::assertGreaterThan(1, \count($chunks));

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(TelegramText::MESSAGE_LIMIT, mb_strlen($chunk));
        }
    }

    public function testTruncateMarksWhatWasCut(): void
    {
        self::assertSame('abc', TelegramText::truncate('abc', 5));
        self::assertSame('ab…', TelegramText::truncate('abcdef', 3));
    }

    public function testThePublisherRefusesAnOversizedMessage(): void
    {
        $publisher = new TelegramPublisher(new Api('TEST_TOKEN', false, new MockHttpClient()));

        $this->expectException(TelegramMessageTooLongException::class);
        $this->expectExceptionMessage('Telegram text is limited to 4096 characters, got 5000');

        $publisher->sendMessage('123', str_repeat('a', 5000));
    }

    public function testThePublisherRefusesAnOversizedCaption(): void
    {
        $publisher = new TelegramPublisher(new Api('TEST_TOKEN', false, new MockHttpClient()));

        $this->expectException(TelegramMessageTooLongException::class);
        $this->expectExceptionMessage('Telegram caption is limited to 1024 characters');

        $publisher->sendMedia('123', new TelegramMedia('photo', TelegramMediaSource::fileId('f1'), str_repeat('c', 1100)));
    }
}
