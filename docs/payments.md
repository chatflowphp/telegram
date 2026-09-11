# Payments And Updates Without A Chat

Telegram sends some updates outside any chat: `pre_checkout_query`, `shipping_query`,
`inline_query`, `poll`, `poll_answer`, `chosen_inline_result`. There is no chat to attach a
conversation to, so they never reach routes, scenes or the session. `onRawUpdate()` hands them to
a handler with the Bot API injected.

```php
$bot->onRawUpdate('pre_checkout_query', static function (Api $api, array $update): void {
    $query = $update['pre_checkout_query'];

    $api->answerPreCheckoutQuery(['pre_checkout_query_id' => $query['id'], 'ok' => true]);
});
```

Parameters are resolved through the container: `Telegram\Bot\Api`, the raw `array $update`, the
`string $type` and any service you registered. Update types the conversation runtime owns
(`message`, `edited_message`, `callback_query`, `my_chat_member`, `chat_member`) are refused, so a
raw handler can never shadow a route or a scene.

## A Payment End To End

The Bot API SDK already implements every payment method, so the package adds none of its own.

```php
// 1. Send the invoice from a normal handler.
$bot->onAction('cart:pay', static function (Context $ctx, Api $api): void {
    $ctx->ack('Opening payment');
    $api->sendInvoice([
        'chat_id' => $ctx->getConversationId(),
        'title' => 'Order #42',
        'description' => 'Two items',
        'payload' => 'order-42',
        'currency' => 'XTR',
        'prices' => [['label' => 'Order #42', 'amount' => 500]],
    ]);
});

// 2. Approve or decline the checkout within 10 seconds.
$bot->onRawUpdate('pre_checkout_query', static function (Api $api, array $update, OrderService $orders): void {
    $query = $update['pre_checkout_query'];
    $ok = $orders->canFulfil((string) $query['invoice_payload']);

    $api->answerPreCheckoutQuery([
        'pre_checkout_query_id' => $query['id'],
        'ok' => $ok,
        'error_message' => $ok ? null : 'This item is no longer available.',
    ]);
});

// 3. The payment itself arrives as a message in the chat, with a conversation and a session.
$bot->fallback(static function (Context $ctx, OrderService $orders): void {
    $payment = $ctx->getMetadata()['telegram']['message']['successful_payment'] ?? null;

    if (!is_array($payment)) {
        return;
    }

    $orders->markPaid((string) $payment['invoice_payload'], (string) $payment['telegram_payment_charge_id']);
    $ctx->reply('Payment received. Thank you!');
});
```

| Update | Reaches | Notes |
| --- | --- | --- |
| `pre_checkout_query` | `onRawUpdate()` | must be answered within 10 seconds |
| `shipping_query` | `onRawUpdate()` | same |
| `successful_payment` | the chat's conversation, as a message with empty text | a scene receives it in `handle()`, otherwise `fallback()` runs |
| `inline_query`, `poll`, `poll_answer` | `onRawUpdate()` | answer with `Telegram\Bot\Api` |

## Rules

- A raw handler runs outside the conversation: no session, no scene, no middleware, no rollback,
  because there is no chat behind the update.
- A failure is logged and passed to `onException()` handlers with a `null` context; handlers
  registered with `setErrorHandler()` are skipped, since there is nobody to answer.
- Telegram retries updates it considers undelivered: keep the work idempotent, keyed by
  `invoice_payload` or `telegram_payment_charge_id`.
- Store what you must remember about an order in your own storage, not in a session: the
  pre-checkout handler cannot reach one.
