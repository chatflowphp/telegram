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

    public function testSmallPayloadsAreSignedInline(): void
    {
        $data = $this->encoder->encode(new Action('cart:add', 'Add', ['id' => 10]));
        $decoded = $this->encoder->decode($data);

        self::assertStringStartsWith('cs:', $data);
        self::assertLessThanOrEqual(64, \strlen($data));
        self::assertTrue($decoded->isAccepted());
        self::assertSame('cart:add', $decoded->actionId);
        self::assertSame(['id' => 10], $decoded->payload);
    }

    public function testTamperedAndUnsignedPayloadsAreRejected(): void
    {
        $data = $this->encoder->encode(new Action('order:pay', 'Pay', ['amount' => 100]));
        $tampered = str_replace('100', '1', $data);

        self::assertTrue($this->encoder->decode($tampered)->isRejected());
        self::assertTrue($this->encoder->decode('{"id":"order:pay","payload":{"amount":0}}')->isRejected());
        self::assertTrue($this->encoder->decode('cs:deadbeefcafe:{"id":"x"}')->isRejected());
        self::assertTrue($this->encoder->decode('cs:bad')->isRejected());
        self::assertTrue((new TelegramCallbackPayloadEncoder($this->store, 'other'))->decode($data)->isRejected());
    }

    public function testLargePayloadsAreStoredBehindATokenAndExpire(): void
    {
        $payload = ['text' => str_repeat('x', 120)];
        $data = $this->encoder->encode(new Action('admin:approve', 'Approve', $payload));

        self::assertStringStartsWith('cf:', $data);
        self::assertSame(['admin:approve', $payload], [$this->encoder->decode($data)->actionId, $this->encoder->decode($data)->payload]);

        $this->store->delete(substr($data, 3));
        $expired = $this->encoder->decode($data);

        self::assertSame(DecodedCallback::EXPIRED, $expired->status);
        self::assertFalse($expired->isRejected());
        self::assertNull($expired->actionId);
    }

    public function testSecretMustNotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TelegramCallbackPayloadEncoder($this->store, '');
    }
}
