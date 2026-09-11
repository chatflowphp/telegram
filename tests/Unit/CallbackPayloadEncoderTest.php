<?php

declare(strict_types=1);

namespace ChatFlow\Telegram\Tests\Unit;

use ChatFlow\Telegram\Callback\DecodedCallback;
use ChatFlow\Telegram\Callback\InMemoryTelegramCallbackStore;
use ChatFlow\Telegram\Callback\TelegramCallbackPayloadEncoder;
use ChatFlow\View\Action;
use PHPUnit\Framework\TestCase;

final class CallbackPayloadEncoderTest extends TestCase
{
    private InMemoryTelegramCallbackStore $store;

    private TelegramCallbackPayloadEncoder $encoder;

    protected function setUp(): void
    {
        $this->store = new InMemoryTelegramCallbackStore();
        $this->encoder = new TelegramCallbackPayloadEncoder($this->store, 'secret');
    }

    public function testActionsWithoutPayloadArePlainIds(): void
    {
        $data = $this->encoder->encode(new Action('menu:open', 'Open'));

        self::assertSame('menu:open', $data);
        self::assertSame(DecodedCallback::ACCEPTED, $this->encoder->decode($data)->status);
        self::assertSame('menu:open', $this->encoder->decode($data)->actionId);
    }

    public function testSmallPayloadsAreSignedInlineInACompactForm(): void
    {
        $data = $this->encoder->encode(new Action('campaigns:report:download', 'Download', ['id' => 123]));
        $decoded = $this->encoder->decode($data);

        self::assertStringStartsWith('cs:', $data);
        self::assertStringEndsWith(':["campaigns:report:download",{"id":123}]', $data);
        self::assertLessThanOrEqual(64, \strlen($data));
        self::assertTrue($decoded->isAccepted());
        self::assertSame('campaigns:report:download', $decoded->actionId);
        self::assertSame(['id' => 123], $decoded->payload);
        self::assertSame(0, $this->store->count(), 'payloads that fit are not stored');
    }

    public function testTamperedAndUnsignedPayloadsAreRejected(): void
    {
        $data = $this->encoder->encode(new Action('order:pay', 'Pay', ['amount' => 100]));
        $tampered = str_replace('100', '1', $data);

        self::assertTrue($this->encoder->decode($tampered)->isRejected());
        self::assertTrue($this->encoder->decode('{"id":"order:pay","payload":{"amount":0}}')->isRejected());
        self::assertTrue($this->encoder->decode('["order:pay",{"amount":0}]')->isRejected());
        self::assertTrue($this->encoder->decode('cs:deadbeef00:["x",null]')->isRejected());
        self::assertTrue($this->encoder->decode('cs:bad')->isRejected());
        self::assertTrue($this->encoder->decode('cf:not-a-token')->isRejected());
        self::assertTrue((new TelegramCallbackPayloadEncoder($this->store, 'other'))->decode($data)->isRejected());
    }

    public function testLargePayloadsAreStoredBehindAContentAddressedTokenAndExpire(): void
    {
        $payload = ['text' => str_repeat('x', 120)];
        $first = $this->encoder->encode(new Action('admin:approve', 'Approve', $payload));
        $second = $this->encoder->encode(new Action('admin:approve', 'Approve', $payload));
        $other = $this->encoder->encode(new Action('admin:approve', 'Approve', ['text' => str_repeat('y', 120)]));

        self::assertStringStartsWith('cf:', $first);
        self::assertSame(19, \strlen($first));
        self::assertSame($first, $second, 'rendering the same button again reuses the token');
        self::assertNotSame($first, $other);
        self::assertSame(2, $this->store->count());
        self::assertSame(['admin:approve', $payload], [$this->encoder->decode($first)->actionId, $this->encoder->decode($first)->payload]);

        $this->store->delete(substr($first, 3));
        $expired = $this->encoder->decode($first);

        self::assertSame(DecodedCallback::EXPIRED, $expired->status);
        self::assertFalse($expired->isRejected());
        self::assertNull($expired->actionId);
    }

    public function testTokensDependOnTheSecret(): void
    {
        $payload = ['text' => str_repeat('x', 120)];
        $a = $this->encoder->encode(new Action('a', 'A', $payload));
        $b = (new TelegramCallbackPayloadEncoder($this->store, 'other'))->encode(new Action('a', 'A', $payload));

        self::assertNotSame($a, $b);
    }

    public function testSecretMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TelegramCallbackPayloadEncoder($this->store, '');
    }
}
