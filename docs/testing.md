# Testing

`TelegramBotTester` runs a `Bot` instance without real Telegram network calls when paired with `MockHttpClient`.

If you are new to the package, finish [Getting Started](getting-started.md) first. The `StarterBot` example is the minimal reference. `MiniShop` shows the larger pattern.

## Example

```php
use ChatFlow\Telegram\Testing\TelegramBotTester;

$tester = new TelegramBotTester($bot, $httpClient);

$tester
    ->sendCommand('start')
    ->assertSee('Welcome')
    ->assertKeyboardHas('Open menu')
    ->clickButton('menu:open')
    ->assertEndpointCalled('answerCallbackQuery');
```

## Supported Input Helpers

```php
$tester->user('123', '456', 'username');
$tester->sendMessage('hello');
$tester->sendCommand('start');
$tester->clickButton('action:id', ['payload' => true]);
$tester->clickSceneAction([$scene, 'method'], ['id' => 1]);
$tester->sendMedia('photo', 'file_id', 'caption');
$tester->sendMediaGroup([['type' => 'photo'], ['type' => 'photo']]);
$tester->sendRawUpdate($update);
$tester->clickCallbackData($rawCallbackData);
```

## Assertions

```php
$tester->assertSee('Text');
$tester->assertKeyboardHas('Button');
$tester->assertEndpointCalled('sendMessage');
$tester->assertScene(SceneClass::class);
$tester->assertNotInScene();
$tester->assertSessionHas('key', 'value');
```

## Inspecting Requests

```php
$requests = $tester->getRequests();
```

Each request contains:

- endpoint
- method
- params

## Example Scenario

The `StarterBot` example has the smallest runnable mock runner:

```sh
php examples/StarterBot/mock.php
```

`MiniShop` is the larger end-to-end reference:

```sh
php examples/MiniShop/mock.php
```

Use the same pattern for end-to-end example tests.
