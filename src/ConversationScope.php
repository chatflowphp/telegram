<?php

declare(strict_types=1);

namespace ChatFlow\Telegram;

/**
 * What a conversation is in a group chat.
 *
 * In a private chat the chat is the user, so both scopes behave the same. In a group they differ:
 * with `Chat` the whole group shares one scene, with `ChatAndUser` every member gets their own.
 */
enum ConversationScope
{
    /**
     * One conversation per chat: everyone in a group shares the current scene, the session and the
     * pending question. Right for group-wide flows and for bots that only answer commands.
     */
    case Chat;

    /**
     * One conversation per member of a chat: two people can be in different scenes of the same
     * group without seeing each other's questions. Messages still go to the chat.
     */
    case ChatAndUser;

    /**
     * The storage key for a chat and the user acting in it. The user is ignored when the chat is
     * the user (a private chat), when it is unknown (channel posts, anonymous admins) and under
     * the `Chat` scope.
     */
    public function conversationId(string|int $chatId, string|int|null $userId = null): string
    {
        if ($this === self::Chat || $userId === null || (string) $chatId === (string) $userId) {
            return (string) $chatId;
        }

        return $chatId . ':' . $userId;
    }
}
