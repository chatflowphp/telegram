# Callback Payload Store

Telegram callback data is limited to 64 bytes.

`TelegramCallbackPayloadEncoder` keeps the user-facing `Action` API simple by storing large payloads behind short tokens.

## Encoding Rules

If an action has no payload and the action id fits into the limit:

```text
cart:open
```

If the JSON payload fits into the limit:

```json
{"id":"cart:add","payload":{"id":10}}
```

If the encoded value is too large:

```text
cf:<token>
```

The token points to stored data:

```php
['id' => 'cart:add', 'payload' => ['id' => 10]]
```

## Stores

Production default:

```php
FileTelegramCallbackStore
```

Test/default in-memory option:

```php
InMemoryTelegramCallbackStore
```

## Inbound Decode

On callback query, the adapter decodes the callback data and exposes:

```php
$ctx->getActionId();
$ctx->getActionPayload();
```

If a `cf:<token>` cannot be resolved, action id and payload become `null`, so no action route should match.

## Storage Rules

Payload values must be serializable according to core rules: scalar, `null`, arrays of allowed values or `BackedEnum`. Objects, resources and non-backed `UnitEnum` are not valid payload values.
