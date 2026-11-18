<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Forum da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait ForumTrait
{
    // ==================== FORUM TOPICS (Bot API 9.3) ====================

    /// Create forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#createforumtopic">createForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function createForumTopic(array $content): array
    {
        return $this->endpoint('createForumTopic', $content);
    }

    /// Edit forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#editforumtopic">editForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editForumTopic(array $content): array
    {
        return $this->endpoint('editForumTopic', $content);
    }

    /// Close forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#closeforumtopic">closeForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function closeForumTopic(array $content): array
    {
        return $this->endpoint('closeForumTopic', $content);
    }

    /// Reopen forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#reopenforumtopic">reopenForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function reopenForumTopic(array $content): array
    {
        return $this->endpoint('reopenForumTopic', $content);
    }

    /// Delete forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#deleteforumtopic">deleteForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteForumTopic(array $content): array
    {
        return $this->endpoint('deleteForumTopic', $content);
    }

    /// Unpin all forum topic messages

    /**
     * See <a href="https://core.telegram.org/bots/api#unpinallforumtopicmessages">unpinAllForumTopicMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unpinAllForumTopicMessages(array $content): array
    {
        return $this->endpoint('unpinAllForumTopicMessages', $content);
    }

    /// Edit general forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#editgeneralforumtopic">editGeneralForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editGeneralForumTopic(array $content): array
    {
        return $this->endpoint('editGeneralForumTopic', $content);
    }

    /// Close general forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#closegeneralforumtopic">closeGeneralForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function closeGeneralForumTopic(array $content): array
    {
        return $this->endpoint('closeGeneralForumTopic', $content);
    }

    /// Reopen general forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#reopengeneralforumtopic">reopenGeneralForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function reopenGeneralForumTopic(array $content): array
    {
        return $this->endpoint('reopenGeneralForumTopic', $content);
    }

    /// Hide general forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#hidegeneralforumtopic">hideGeneralForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function hideGeneralForumTopic(array $content): array
    {
        return $this->endpoint('hideGeneralForumTopic', $content);
    }

    /// Unhide general forum topic

    /**
     * See <a href="https://core.telegram.org/bots/api#unhidegeneralforumtopic">unhideGeneralForumTopic</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unhideGeneralForumTopic(array $content): array
    {
        return $this->endpoint('unhideGeneralForumTopic', $content);
    }

    /// Unpin all general forum topic messages

    /**
     * See <a href="https://core.telegram.org/bots/api#unpinallgeneralforumtopicmessages">unpinAllGeneralForumTopicMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unpinAllGeneralForumTopicMessages(array $content): array
    {
        return $this->endpoint('unpinAllGeneralForumTopicMessages', $content);
    }

}
