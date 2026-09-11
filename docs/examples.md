# Examples

## StarterBot

`examples/StarterBot` is the minimal runnable example: a bot factory with storage, `/start` and
a global `/cancel`, one inline screen with `ack()` and `render()`, one validation-backed scene
with a text shortcut, Russian and English catalogues with a language switch,
`TelegramBotTester` tests, a mock runner and a polling script.

```sh
php examples/StarterBot/mock.php
TELEGRAM_BOT_TOKEN=... php examples/StarterBot/run.php
```

```text
StarterBot/
  StarterBotFactory.php
  StarterBotFlow.php
  StarterBotMessages.php
  PhoneScene.php
  mock.php
  run.php
```

## MiniShop

`examples/MiniShop` is the advanced Telegram-specific example: a declared screen map (root to
shop, shop to checkout only with a non-empty cart, checkout back to shop), scene action buttons
with payloads, smart `render()` edits, a media reply, sessions, validation with a cancel choice,
custom middleware, a domain exception policy, a full Telegram Stars payment (invoice,
`onRawUpdate()` for the pre-checkout query, and the successful payment), mock testing, polling
bootstrap and an optional runtime observer.

```sh
php examples/MiniShop/mock.php
TELEGRAM_BOT_TOKEN=... php examples/MiniShop/run.php
```

```text
MiniShop/
  MiniShopBotFactory.php
  MiniShopFlow.php
  MiniShopVisitorMiddleware.php
  ShopScene.php
  CheckoutScene.php
  OrderService.php
  ProductNotFoundException.php
  mock.php
  run.php
```

Print the screen map of the example:

```php
echo $bot->getTransitions()->toMermaid();
```

## Recommended Pattern

Use one factory for tests, mock runners, polling and webhook entrypoints, and register the flow
through `FlowInterface` so the same code runs on every entrypoint.
