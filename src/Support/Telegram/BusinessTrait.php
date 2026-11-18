<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Business da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait BusinessTrait
{
    // ==================== STORIES METHODS (Bot API 9.2-9.3) ====================

    /// Post a story

    /**
     * See <a href="https://core.telegram.org/bots/api#poststory">postStory</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function postStory(array $content): array
    {
        return $this->endpoint('postStory', $content);
    }

    /// Repost a story (Bot API 9.3)

    /**
     * See <a href="https://core.telegram.org/bots/api#repoststory">repostStory</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function repostStory(array $content): array
    {
        return $this->endpoint('repostStory', $content);
    }

    /// Edit a story

    /**
     * See <a href="https://core.telegram.org/bots/api#editstory">editStory</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editStory(array $content): array
    {
        return $this->endpoint('editStory', $content);
    }

    /// Delete a story

    /**
     * See <a href="https://core.telegram.org/bots/api#deletestory">deleteStory</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteStory(array $content): array
    {
        return $this->endpoint('deleteStory', $content);
    }

    // ==================== BUSINESS ACCOUNT METHODS (Bot API 9.2-9.3) ====================

    /// Read a business message

    /**
     * See <a href="https://core.telegram.org/bots/api#readbusinessmessage">readBusinessMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function readBusinessMessage(array $content): array
    {
        return $this->endpoint('readBusinessMessage', $content);
    }

    /// Delete business messages

    /**
     * See <a href="https://core.telegram.org/bots/api#deletebusinessmessages">deleteBusinessMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteBusinessMessages(array $content): array
    {
        return $this->endpoint('deleteBusinessMessages', $content);
    }

    /// Set business account name

    /**
     * See <a href="https://core.telegram.org/bots/api#setbusinessaccountname">setBusinessAccountName</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setBusinessAccountName(array $content): array
    {
        return $this->endpoint('setBusinessAccountName', $content);
    }

    /// Set business account username

    /**
     * See <a href="https://core.telegram.org/bots/api#setbusinessaccountusername">setBusinessAccountUsername</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setBusinessAccountUsername(array $content): array
    {
        return $this->endpoint('setBusinessAccountUsername', $content);
    }

    /// Set business account bio

    /**
     * See <a href="https://core.telegram.org/bots/api#setbusinessaccountbio">setBusinessAccountBio</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setBusinessAccountBio(array $content): array
    {
        return $this->endpoint('setBusinessAccountBio', $content);
    }

    /// Set business account profile photo

    /**
     * See <a href="https://core.telegram.org/bots/api#setbusinessaccountprofilephoto">setBusinessAccountProfilePhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setBusinessAccountProfilePhoto(array $content): array
    {
        return $this->endpoint('setBusinessAccountProfilePhoto', $content);
    }

    /// Remove business account profile photo

    /**
     * See <a href="https://core.telegram.org/bots/api#removebusinessaccountprofilephoto">removeBusinessAccountProfilePhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function removeBusinessAccountProfilePhoto(array $content): array
    {
        return $this->endpoint('removeBusinessAccountProfilePhoto', $content);
    }

    /// Get business connection

    /**
     * See <a href="https://core.telegram.org/bots/api#getbusinessconnection">getBusinessConnection</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getBusinessConnection(array $content): array
    {
        return $this->endpoint('getBusinessConnection', $content);
    }

}
