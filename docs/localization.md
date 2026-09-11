# Localization

The core owns the translation contract and the locale resolution; the Telegram package
contributes the language Telegram reports. The catalogue side is documented in the core package,
`docs/i18n.md`.

## Setup

```php
use ChatFlow\I18n\ArrayTranslator;
use ChatFlow\I18n\TranslatorInterface;
use ChatFlow\Middleware\LocaleMiddleware;

$bot->getContainer()->set(TranslatorInterface::class, new ArrayTranslator([
    'en' => ['greeting' => 'Hello, {name}'],
    'ru' => ['greeting' => 'Привет, {name}'],
]));

$bot->middleware([LocaleMiddleware::class]);
```

`Bot` binds a default resolver: the locale stored in the session wins, and the Telegram
`language_code` of the user is used until the user chooses something. Handlers translate with
`$ctx->t()`:

```php
$bot->command('start', static function (Context $ctx): void {
    $ctx->reply($ctx->t('greeting', ['name' => 'Alex']));
});
```

## Letting The User Choose

```php
$bot->onAction('lang:ru', static function (Context $ctx): void {
    $ctx->session()->set('locale', 'ru');
    $ctx->ack();
    $ctx->render(View::text($ctx->t('menu.title')));
});
```

## Custom Resolution

Override the binding to change the order or to add your own source:

```php
use ChatFlow\I18n\ChainLocaleResolver;
use ChatFlow\I18n\LocaleResolverInterface;
use ChatFlow\I18n\SessionLocaleResolver;
use ChatFlow\Telegram\I18n\TelegramLocaleResolver;

$bot->getContainer()->set(LocaleResolverInterface::class, new ChainLocaleResolver(
    new CrmLocaleResolver($crm),
    new SessionLocaleResolver(),
    new TelegramLocaleResolver(),
));
```

`TelegramLocaleResolver` reads `language_code` from the user of the update. It has no opinion
when the update carries no user, for example a channel post, and the translator then uses its
fallback locale.

## Notes

- Telegram sends `language_code` with every message, so the first `/start` is already in the
  user's language.
- Values like `en-GB` are handled by `ArrayTranslator`, which falls back to the `en` catalogue.
- Button labels are part of a view: translate them where the view is built.
