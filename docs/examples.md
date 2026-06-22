# Examples

If you are new to the package, finish [Getting Started](getting-started.md) first.

## StarterBot

`StarterBot` is the minimal runnable onboarding example:

```text
examples/StarterBot
```

It demonstrates:

- bot factory
- `/start` command
- one inline callback screen
- `ack()` plus `render()`
- one validation-backed scene
- `TelegramBotTester`
- mock runner
- local polling script

Run the mock scenario:

```sh
php examples/StarterBot/mock.php
```

Run polling locally:

```sh
TELEGRAM_BOT_TOKEN=... php examples/StarterBot/run.php
```

Structure:

```text
StarterBot/
  StarterBotFactory.php
  StarterBotFlow.php
  PhoneScene.php
  mock.php
  run.php
```

## MiniShop

`MiniShop` is the advanced Telegram-specific example:

```text
examples/MiniShop
```

It demonstrates:

- bot factory
- commands
- inline callback actions
- scene action buttons
- smart `render()` edit behavior
- media reply
- sessions
- validation
- custom middleware
- domain exception policy
- mock testing
- polling bootstrap
- optional runtime observer

Run the mock scenario:

```sh
php examples/MiniShop/mock.php
```

Run polling locally:

```sh
TELEGRAM_BOT_TOKEN=... php examples/MiniShop/run.php
```

Structure:

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

## Recommended Pattern For New Bots

Use the same factory for tests, mock runners, polling and webhook entrypoints.
