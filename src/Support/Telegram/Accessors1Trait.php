<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Accessors da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Accessors1Trait
{
    /// Get the text of the current message
    /**
     * \return the String users's text.
     */
    public function Text(): ?string
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->stringOrNull($this->dataPath('callback_query', 'data'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->stringOrNull($this->dataPath('channel_post', 'text'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->stringOrNull($this->dataPath('edited_message', 'text'));
        }

        return $this->stringOrNull($this->dataPath('message', 'text'));
    }

    public function Caption(): ?string
    {
        $type = $this->getUpdateType();
        if ($type === self::CHANNEL_POST) {
            return $this->stringOrNull($this->dataPath('channel_post', 'caption'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->stringOrNull($this->dataPath('edited_message', 'caption'));
        }

        return $this->stringOrNull($this->dataPath('message', 'caption'));
    }

    /// Get the chat_id of the current message
    /**
     * \return the String users's chat_id.
     */
    public function ChatID(): ?int
    {
        $chat = $this->Chat();

        return $this->intOrNull($chat['id'] ?? null);
    }

    /**
     * \return the Array chat.
     * @return array<string, mixed>|null
     */
    public function Chat(): ?array
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->arrayOrNull($this->dataPath('callback_query', 'message', 'chat'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->arrayOrNull($this->dataPath('channel_post', 'chat'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->arrayOrNull($this->dataPath('edited_message', 'chat'));
        }
        if ($type === self::INLINE_QUERY) {
            // InlineQuery não tem campo chat — só 'from', que é um User.
            // Para o id de quem consultou, use UserID().
            return null;
        }
        if ($type === self::MY_CHAT_MEMBER) {
            return $this->arrayOrNull($this->dataPath('my_chat_member', 'chat'));
        }

        return $this->arrayOrNull($this->dataPath('message', 'chat'));
    }

    /// Get the message_id of the current message
    /**
     * \return the String message_id.
     */
    public function MessageID(): ?int
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->intOrNull($this->dataPath('callback_query', 'message', 'message_id'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->intOrNull($this->dataPath('channel_post', 'message_id'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->intOrNull($this->dataPath('edited_message', 'message_id'));
        }

        return $this->intOrNull($this->dataPath('message', 'message_id'));
    }

    /// Get the reply_to_message message_id of the current message
    /**
     * \return the String reply_to_message message_id.
     */
    public function ReplyToMessageID(): ?int
    {
        return $this->intOrNull($this->dataPath('message', 'reply_to_message', 'message_id'));
    }

    /// Get the reply_to_message forward_origin user_id of the current message
    /**
     * \return the String reply_to_message forward_origin sender_user id.
     */
    public function ReplyToMessageFromUserID(): ?int
    {
        return $this->intOrNull($this->dataPath('message', 'reply_to_message', 'forward_origin', 'sender_user', 'id'));
    }

    /// Get the inline_query of the current update

    /**
     * \return the Array inline_query.
     * @return array<string, mixed>|null
     */
    public function Inline_Query(): ?array
    {
        return $this->arrayOrNull($this->dataPath('inline_query'));
    }

    /// Get the callback_query of the current update

    /**
     * \return the String callback_query.
     * @return array<string, mixed>|null
     */
    public function Callback_Query(): ?array
    {
        return $this->arrayOrNull($this->dataPath('callback_query'));
    }

    /// Get the callback_query id of the current update
    /**
     * \return the String callback_query id.
     */
    public function Callback_ID(): ?string
    {
        return $this->stringOrNull($this->dataPath('callback_query', 'id'));
    }

    /// Get the Get the data of the current callback
    /**
     * Acessor direto de callback_query.data. Text() devolve o mesmo valor, mas
     * só quando getUpdateType() classifica o update como callback_query — este
     * aqui não depende dessa classificação.
     * \return the String callback_data.
     */
    public function Callback_Data(): ?string
    {
        return $this->stringOrNull($this->dataPath('callback_query', 'data'));
    }

    /// Get the Get the message of the current callback

    /**
     * \return the Message.
     * @return array<string, mixed>|null
     */
    public function Callback_Message(): ?array
    {
        return $this->arrayOrNull($this->dataPath('callback_query', 'message'));
    }

    /// Get the Get the chat_id of the current callback
    /**
     * \deprecated Use ChatID() instead
     * \return the String callback_query.
     */
    public function Callback_ChatID(): ?int
    {
        return $this->intOrNull($this->dataPath('callback_query', 'message', 'chat', 'id'));
    }

    /// Get the Get the from_id of the current callback
    /**
     * \return the String callback_query from_id.
     */
    public function Callback_FromID(): ?int
    {
        return $this->intOrNull($this->dataPath('callback_query', 'from', 'id'));
    }

    /// Get the date of the current message
    /**
     * \return the String message's date.
     */
    public function Date(): ?int
    {
        return $this->intOrNull($this->dataPath('message', 'date'));
    }

    /// Get the first name of the user
    public function FirstName(): ?string
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->stringOrNull($this->dataPath('callback_query', 'from', 'first_name'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->stringOrNull($this->dataPath('channel_post', 'from', 'first_name'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->stringOrNull($this->dataPath('edited_message', 'from', 'first_name'));
        }
        if ($type === self::INLINE_QUERY) {
            return $this->stringOrNull($this->dataPath('inline_query', 'from', 'first_name'));
        }

        return $this->stringOrNull($this->dataPath('message', 'from', 'first_name'));
    }

    /// Get the last name of the user
    public function LastName(): ?string
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->stringOrNull($this->dataPath('callback_query', 'from', 'last_name'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->stringOrNull($this->dataPath('channel_post', 'from', 'last_name'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->stringOrNull($this->dataPath('edited_message', 'from', 'last_name'));
        }
        if ($type === self::INLINE_QUERY) {
            return $this->stringOrNull($this->dataPath('inline_query', 'from', 'last_name'));
        }

        return $this->stringOrNull($this->dataPath('message', 'from', 'last_name'));
    }

    /// Get the username of the user
    public function Username(): ?string
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->stringOrNull($this->dataPath('callback_query', 'from', 'username'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->stringOrNull($this->dataPath('channel_post', 'from', 'username'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->stringOrNull($this->dataPath('edited_message', 'from', 'username'));
        }
        if ($type === self::INLINE_QUERY) {
            return $this->stringOrNull($this->dataPath('inline_query', 'from', 'username'));
        }

        return $this->stringOrNull($this->dataPath('message', 'from', 'username'));
    }

    /// Get the location in the message
    /**
     * @return array<string, mixed>|null
     */
    public function Location(): ?array
    {
        return $this->arrayOrNull($this->dataPath('message', 'location'));
    }

    /// Get the update_id of the message
    public function UpdateID(): ?int
    {
        return $this->intOrNull($this->dataPath('update_id'));
    }

    /// Get the number of updates
    public function UpdateCount(): int
    {
        return \count($this->updatesResult());
    }

    /// Get user's id of current message
    public function UserID(): ?int
    {
        $type = $this->getUpdateType();
        if ($type === self::CALLBACK_QUERY) {
            return $this->intOrNull($this->dataPath('callback_query', 'from', 'id'));
        }
        if ($type === self::CHANNEL_POST) {
            return $this->intOrNull($this->dataPath('channel_post', 'from', 'id'));
        }
        if ($type === self::EDITED_MESSAGE) {
            return $this->intOrNull($this->dataPath('edited_message', 'from', 'id'));
        }
        if ($type === self::INLINE_QUERY) {
            return $this->intOrNull($this->dataPath('inline_query', 'from', 'id'));
        }

        return $this->intOrNull($this->dataPath('message', 'from', 'id'));
    }

    /// Get user's id of current forwarded message
    /**
     * A Bot API 7.0 removeu forward_from em favor de forward_origin
     * (MessageOrigin). O id do remetente original só existe quando a origem é
     * um usuário que não escondeu o perfil (MessageOriginUser.sender_user).
     */
    public function FromID(): ?int
    {
        return $this->intOrNull($this->dataPath('message', 'forward_origin', 'sender_user', 'id'));
    }

    /// Get chat's id where current message forwarded from
    /**
     * A Bot API 7.0 removeu forward_from_chat em favor de forward_origin.
     * Canal traz o chat em MessageOriginChannel.chat; grupo/canal assinando
     * como si mesmo traz em MessageOriginChat.sender_chat.
     */
    public function FromChatID(): ?int
    {
        $id_canal = $this->intOrNull($this->dataPath('message', 'forward_origin', 'chat', 'id'));
        if ($id_canal !== null) {
            return $id_canal;
        }

        return $this->intOrNull($this->dataPath('message', 'forward_origin', 'sender_chat', 'id'));
    }

    /// Tell if a message is from a group or user chat
    /**
     *  \return BOOLEAN true if the message is from a Group chat, false otherwise.
     */
    public function messageFromGroup(): bool
    {
        // Só 'group' e 'supergroup' são grupos; 'private' e 'channel' não são,
        // e update sem a chave message também não (dataPath devolve null).
        $tipo = $this->dataPath('message', 'chat', 'type');

        return $tipo === 'group' || $tipo === 'supergroup';
    }

    /// Get the contact phone number
    /**
     *  \return a String of the contact phone number.
     */
    public function getContactPhoneNumber(): string
    {
        if ($this->getUpdateType() === self::CONTACT) {
            return $this->stringOrNull($this->dataPath('message', 'contact', 'phone_number')) ?? '';
        }

        return '';
    }

}
