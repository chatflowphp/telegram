# Examples

## MiniShop

The active example is Telegram-specific and lives in:

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

## Run Mock Scenario

```sh
php examples/MiniShop/mock.php
```

The mock scenario runs the same factory as the live bot and verifies behavior through `TelegramBotTester`.

## Run Polling

```sh
TELEGRAM_BOT_TOKEN=... php examples/MiniShop/run.php
```

## Example Structure

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

Create a factory:

```php
final class BotFactory
{
    public static function create(string $token, string $basePath): Bot
    {
        $bot = new Bot($token, $basePath);
        $bot->useStorage(new FileStorage($basePath . '/storage/bot'));
        (new BotFlow())->register($bot);

        return $bot;
    }
}
```

Create a flow:

```php
final class BotFlow implements FlowInterface
{
    public function register(FlowRuntimeInterface $runtime): void
    {
        $runtime->onCommand('start', static function (Context $ctx): void {
            $ctx->reply('Welcome');
        });
    }
}
```

Use the same factory for polling, webhook and tests.
