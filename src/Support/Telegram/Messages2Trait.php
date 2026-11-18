<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Messages da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Messages2Trait
{
    /**
     * See <a href="https://core.telegram.org/bots/api#editmessagetext">editMessageText</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageText(array $content): array
    {
        return $this->endpoint('editMessageText', $content);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#editmessagecaption">editMessageCaption</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageCaption(array $content): array
    {
        return $this->endpoint('editMessageCaption', $content);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#editmessagemedia">editMessageMedia</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageMedia(array $content): array
    {
        return $this->endpoint('editMessageMedia', $content);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#editmessagereplymarkup">editMessageReplyMarkup</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageReplyMarkup(array $content): array
    {
        return $this->endpoint('editMessageReplyMarkup', $content);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#stoppoll">stopPoll</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function stopPoll(array $content): array
    {
        return $this->endpoint('stopPoll', $content);
    }

    /// Send a video note

    /**
     * See <a href="https://core.telegram.org/bots/api#sendvideonote">sendVideoNote</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendVideoNote(array $content): array
    {
        return $this->endpoint('sendVideoNote', $content);
    }

    /// Delete a message

    /**
     * See <a href="https://core.telegram.org/bots/api#deletemessage">deleteMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteMessage(array $content): array
    {
        return $this->endpoint('deleteMessage', $content);
    }

    /// Delete multiple messages

    /**
     * See <a href="https://core.telegram.org/bots/api#deletemessages">deleteMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteMessages(array $content): array
    {
        return $this->endpoint('deleteMessages', $content);
    }

    // ==================== BOT API 9.1-9.3 NEW METHODS ====================

    /// Send a checklist (Bot API 9.1)

    /**
     * See <a href="https://core.telegram.org/bots/api#sendchecklist">sendChecklist</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendChecklist(array $content): array
    {
        return $this->endpoint('sendChecklist', $content);
    }

    /// Edit a message checklist (Bot API 9.1)

    /**
     * See <a href="https://core.telegram.org/bots/api#editmessagechecklist">editMessageChecklist</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageChecklist(array $content): array
    {
        return $this->endpoint('editMessageChecklist', $content);
    }

    /// Send a message draft (Bot API 9.3)

    /**
     * See <a href="https://core.telegram.org/bots/api#sendmessagedraft">sendMessageDraft</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendMessageDraft(array $content): array
    {
        return $this->endpoint('sendMessageDraft', $content);
    }

    /// Approve a suggested post (Bot API 9.2)

    /**
     * See <a href="https://core.telegram.org/bots/api#approvesuggestedpost">approveSuggestedPost</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function approveSuggestedPost(array $content): array
    {
        return $this->endpoint('approveSuggestedPost', $content);
    }

    /// Decline a suggested post (Bot API 9.2)

    /**
     * See <a href="https://core.telegram.org/bots/api#declinesuggestedpost">declineSuggestedPost</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function declineSuggestedPost(array $content): array
    {
        return $this->endpoint('declineSuggestedPost', $content);
    }

    // ==================== MISC NEW METHODS ====================

    /// Set user emoji status

    /**
     * See <a href="https://core.telegram.org/bots/api#setuseremojistatus">setUserEmojiStatus</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setUserEmojiStatus(array $content): array
    {
        return $this->endpoint('setUserEmojiStatus', $content);
    }

    /// Set message reaction

    /**
     * See <a href="https://core.telegram.org/bots/api#setmessagereaction">setMessageReaction</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMessageReaction(array $content): array
    {
        return $this->endpoint('setMessageReaction', $content);
    }

    /// Remove a reaction from a message

    /**
     * See <a href="https://core.telegram.org/bots/api#deletemessagereaction">deleteMessageReaction</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteMessageReaction(array $content): array
    {
        return $this->endpoint('deleteMessageReaction', $content);
    }

    /// Remove up to 10000 recent reactions added by a given user or chat

    /**
     * See <a href="https://core.telegram.org/bots/api#deleteallmessagereactions">deleteAllMessageReactions</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteAllMessageReactions(array $content): array
    {
        return $this->endpoint('deleteAllMessageReactions', $content);
    }

    /// Get the audios displayed on a user's profile

    /**
     * See <a href="https://core.telegram.org/bots/api#getuserprofileaudios">getUserProfileAudios</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getUserProfileAudios(array $content): array
    {
        return $this->endpoint('getUserProfileAudios', $content);
    }

    /// Send a rich message

    /**
     * See <a href="https://core.telegram.org/bots/api#sendrichmessage">sendRichMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendRichMessage(array $content): array
    {
        return $this->endpoint('sendRichMessage', $content);
    }

    /// Stream a partial rich message while it is being generated

    /**
     * See <a href="https://core.telegram.org/bots/api#sendrichmessagedraft">sendRichMessageDraft</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendRichMessageDraft(array $content): array
    {
        return $this->endpoint('sendRichMessageDraft', $content);
    }

    /// Edit an ephemeral text message

    /**
     * See <a href="https://core.telegram.org/bots/api#editephemeralmessagetext">editEphemeralMessageText</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editEphemeralMessageText(array $content): array
    {
        return $this->endpoint('editEphemeralMessageText', $content);
    }

    /// Edit the media of an ephemeral message

    /**
     * See <a href="https://core.telegram.org/bots/api#editephemeralmessagemedia">editEphemeralMessageMedia</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editEphemeralMessageMedia(array $content): array
    {
        return $this->endpoint('editEphemeralMessageMedia', $content);
    }

    /// Edit the caption of an ephemeral message

    /**
     * See <a href="https://core.telegram.org/bots/api#editephemeralmessagecaption">editEphemeralMessageCaption</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editEphemeralMessageCaption(array $content): array
    {
        return $this->endpoint('editEphemeralMessageCaption', $content);
    }

    /// Edit only the reply markup of an ephemeral message

    /**
     * See <a href="https://core.telegram.org/bots/api#editephemeralmessagereplymarkup">editEphemeralMessageReplyMarkup</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editEphemeralMessageReplyMarkup(array $content): array
    {
        return $this->endpoint('editEphemeralMessageReplyMarkup', $content);
    }

    /// Delete an ephemeral message

    /**
     * See <a href="https://core.telegram.org/bots/api#deleteephemeralmessage">deleteEphemeralMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteEphemeralMessage(array $content): array
    {
        return $this->endpoint('deleteEphemeralMessage', $content);
    }

}
