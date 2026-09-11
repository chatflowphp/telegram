# Testing

`TelegramBotTester` drives a `Bot` without network access when the SDK `Api` is created with
`MockHttpClient`.

```php
$client = new MockHttpClient();
$bot = new Bot('TEST_TOKEN', __DIR__, api: new Api('TEST_TOKEN', false, $client), storage: new MemoryStorage());
(new MyFlow())->register($bot);

$tester = new TelegramBotTester($bot, $client);

$tester
    ->sendCommand('start')
    ->assertSee('Welcome')
    ->assertKeyboardHas('Open menu')
    ->clear()
    ->clickButton('menu:open')
    ->assertEndpointCalled('answerCallbackQuery')
    ->assertEndpointCalled('editMessageText');
```

## Input

```php
$tester->user('123', '456', 'username');
$tester->sendMessage('hello');
$tester->sendCommand('start');
$tester->clickButton('cart:add', ['id' => 10]);          // signed like a rendered button
$tester->clickSceneAction([ShopScene::class, 'onAdd'], ['id' => 10]);
$tester->clickCallbackData($rawCallbackData);           // raw, for negative tests
$tester->sendMedia('photo', 'file_id', 'caption');
$tester->sendMediaGroup([['type' => 'photo'], ['type' => 'photo']]);
$tester->sendRawUpdate($update);
$tester->clear();                                       // forget recorded requests
```

## Assertions

```php
$tester->assertSee('Text');
$tester->assertDontSee('Text');
$tester->assertKeyboardHas('Button');
$tester->assertEndpointCalled('sendMessage');
$tester->assertEndpointNotCalled('deleteMessage');
$tester->assertScene(CheckoutScene::class);             // class or scene id
$tester->assertNotInScene();
$tester->assertScenePending(CheckoutScene::class);      // scheduled with enterLater()
$tester->assertNoScenePending();
$tester->assertSessionHas('key', 'value');
$tester->assertSessionMissing('key');
$tester->assertResult('success', 'scene_processed');
```

## Inspection

```php
$tester->getRequests();        // endpoint, method, params per Telegram call
$tester->getLastResult();      // core Result of the last update
$tester->conversation();       // current scene, history, session data
```

`MockHttpClient::failEndpoint('editMessageText', 'Bad Request: ...')` simulates Bot API errors.

## Example Suites

`tests/Integration/StarterBotFlowTest.php` and `tests/Integration/MiniShopFlowTest.php` test the
bundled examples end to end; `examples/*/mock.php` run the same scenarios from the command line.
