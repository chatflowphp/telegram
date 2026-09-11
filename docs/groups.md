# Group Chats

A conversation is a chat by default: the whole group shares one scene, one session and one
pending question. That is right for group-wide flows and for bots that only answer commands, and
wrong as soon as two members can be asked something at the same time.

## Scopes

```php
use ChatFlow\Telegram\ConversationScope;

$bot = new Bot($token, $basePath, storage: $storage, conversationScope: ConversationScope::ChatAndUser);
```

| Scope | A conversation is | In a group |
| --- | --- | --- |
| `ConversationScope::Chat` (default) | the chat | everyone shares the current scene and session |
| `ConversationScope::ChatAndUser` | a member of the chat | each member has their own scene and session |

Private chats behave identically under both scopes, because there the chat is the user. Updates
without a user, such as channel posts, always belong to the chat.

Messages always go to the chat: `ConversationScope::ChatAndUser` splits the state, not the
delivery. Two members can be on different steps of the same dialog in one group, each seeing
their own questions in the shared chat.

## Addressing A Member From Outside A Request

```php
$bot->enterScene($chatId, ReviewScene::class, userId: $userId);
$bot->leaveScene($chatId, userId: $userId);
$bot->run($chatId, $handler, userId: $userId);
$bot->conversationIdFor($chatId, $userId);      // "-100500:222" under ChatAndUser
```

Pass the member whenever the bot runs with `ChatAndUser`; without it the call addresses the
chat-wide conversation.

## Choosing

Use `Chat` when the bot answers commands, posts announcements or runs one flow the group follows
together. Use `ChatAndUser` when a scene asks a question that only one member should answer, or
when members keep private state such as a cart or a draft.

## Notes

- The scope decides the storage key, so switching it on a running bot orphans the stored
  conversations of the other scope. Pick it before launch, or reset conversations when changing.
- Commands work in groups with the group form: `/start@your_bot`.
- Telegram only delivers non-command messages to a bot in a group when privacy mode is off
  (BotFather, *Group Privacy*). A scene that asks questions in a group needs it off.
- A screen tracked by `TelegramScreenManager` is per conversation, so under `ChatAndUser` every
  member gets their own screen.
