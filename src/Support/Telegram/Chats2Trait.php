<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Chats da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Chats2Trait
{
    /// Set Chat Photo

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatphoto">setChatPhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatPhoto(array $content): array
    {
        return $this->endpoint('setChatPhoto', $content);
    }

    /// Delete Chat Photo

    /**
     * See <a href="https://core.telegram.org/bots/api#deletechatphoto">deleteChatPhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteChatPhoto(array $content): array
    {
        return $this->endpoint('deleteChatPhoto', $content);
    }

    /// Set Chat Title

    /**
     * See <a href="https://core.telegram.org/bots/api#setchattitle">setChatTitle</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatTitle(array $content): array
    {
        return $this->endpoint('setChatTitle', $content);
    }

    /// Set Chat Description

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatdescription">setChatDescription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatDescription(array $content): array
    {
        return $this->endpoint('setChatDescription', $content);
    }

    /// Pin Chat Message

    /**
     * See <a href="https://core.telegram.org/bots/api#pinchatmessage">pinChatMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function pinChatMessage(array $content): array
    {
        return $this->endpoint('pinChatMessage', $content);
    }

    /// Unpin Chat Message

    /**
     * See <a href="https://core.telegram.org/bots/api#unpinchatmessage">unpinChatMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unpinChatMessage(array $content): array
    {
        return $this->endpoint('unpinChatMessage', $content);
    }

    /// Unpin All Chat Messages

    /**
     * See <a href="https://core.telegram.org/bots/api#unpinallchatmessages">unpinAllChatMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unpinAllChatMessages(array $content): array
    {
        return $this->endpoint('unpinAllChatMessages', $content);
    }

    /// Verify a chat

    /**
     * See <a href="https://core.telegram.org/bots/api#verifychat">verifyChat</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function verifyChat(array $content): array
    {
        return $this->endpoint('verifyChat', $content);
    }

    /// Remove chat verification

    /**
     * See <a href="https://core.telegram.org/bots/api#removechatverification">removeChatVerification</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function removeChatVerification(array $content): array
    {
        return $this->endpoint('removeChatVerification', $content);
    }

    /// Get user chat boosts

    /**
     * See <a href="https://core.telegram.org/bots/api#getuserchatboosts">getUserChatBoosts</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getUserChatBoosts(array $content): array
    {
        return $this->endpoint('getUserChatBoosts', $content);
    }

    /// Set a tag for a regular member in a group or a supergroup

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatmembertag">setChatMemberTag</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatMemberTag(array $content): array
    {
        return $this->endpoint('setChatMemberTag', $content);
    }

    /// Get the last messages from a user's personal chat

    /**
     * See <a href="https://core.telegram.org/bots/api#getuserpersonalchatmessages">getUserPersonalChatMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getUserPersonalChatMessages(array $content): array
    {
        return $this->endpoint('getUserPersonalChatMessages', $content);
    }

}
