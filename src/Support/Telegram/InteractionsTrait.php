<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Interactions da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait InteractionsTrait
{
    /**
     * See <a href="https://core.telegram.org/bots/api#answerinlinequery">answerInlineQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerInlineQuery(array $content): array
    {
        return $this->endpoint('answerInlineQuery', $content);
    }

    /// Answer Web App Query

    /**
     * See <a href="https://core.telegram.org/bots/api#answerwebappquery">answerWebAppQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerWebAppQuery(array $content): array
    {
        return $this->endpoint('answerWebAppQuery', $content);
    }

    /// Save Prepared Inline Message

    /**
     * See <a href="https://core.telegram.org/bots/api#savepreparedinlinemessage">savePreparedInlineMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function savePreparedInlineMessage(array $content): array
    {
        return $this->endpoint('savePreparedInlineMessage', $content);
    }

    /// Set Game Score

    /**
     * See <a href="https://core.telegram.org/bots/api#setgamescore">setGameScore</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setGameScore(array $content): array
    {
        return $this->endpoint('setGameScore', $content);
    }

    /// Get Game Hi Scores

    /**
     * See <a href="https://core.telegram.org/bots/api#getgamehighscores">getGameHighScores</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getGameHighScores(array $content): array
    {
        return $this->endpoint('getGameHighScores', $content);
    }

    /// Answer a callback Query

    /**
     * See <a href="https://core.telegram.org/bots/api#answercallbackquery">answerCallbackQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerCallbackQuery(array $content): array
    {
        return $this->endpoint('answerCallbackQuery', $content);
    }

    /// Send a Game

    /**
     * See <a href="https://core.telegram.org/bots/api#sendgame">sendGame</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendGame(array $content): array
    {
        return $this->endpoint('sendGame', $content);
    }

    /// Reply to a received guest message

    /**
     * See <a href="https://core.telegram.org/bots/api#answerguestquery">answerGuestQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerGuestQuery(array $content): array
    {
        return $this->endpoint('answerGuestQuery', $content);
    }

    /// Store a keyboard button to be used by a user within a Mini App

    /**
     * See <a href="https://core.telegram.org/bots/api#savepreparedkeyboardbutton">savePreparedKeyboardButton</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function savePreparedKeyboardButton(array $content): array
    {
        return $this->endpoint('savePreparedKeyboardButton', $content);
    }

}
