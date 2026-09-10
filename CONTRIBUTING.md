# Contributing

## Setup

```bash
composer install
```

Until `chatflowphp/core` 2.x and `chatflowphp/automata` 2.x are on Packagist, point Composer at
local checkouts:

```bash
composer config -g repositories.chatflowphp-automata '{"type":"path","url":"/path/to/chatflow-automata","options":{"versions":{"chatflowphp/automata":"2.0.0"}}}'
composer config -g repositories.chatflowphp-core '{"type":"path","url":"/path/to/chatflow-core","options":{"versions":{"chatflowphp/core":"2.0.0"}}}'
```

## Quality Gate

```bash
composer check
php examples/StarterBot/mock.php
php examples/MiniShop/mock.php
```

`composer check` validates `composer.json`, checks code style (PER-CS 2.0), runs PHPStan at
level max with strict rules over `src`, `examples` and `tests`, and runs PHPUnit with warnings
treated as failures. Fix style with `composer cs:fix`.

## Rules

- Telegram-specific behaviour stays in this package; the core never imports the SDK.
- Every update goes through `Application::handle()`. Do not add code paths that bypass
  middleware, sessions or rollback.
- Callback data sent by clients is untrusted: keep payloads signed or stored.
- Public behaviour changes need a test (`TelegramBotTester` for flows) and a changelog entry.
- Commit messages follow Conventional Commits.
