<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Profile da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait ProfileTrait
{
    /// A method for testing your bot.

    /**
     * See <a href="https://core.telegram.org/bots/api#getme">getMe</a>
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getMe(): array
    {
        return $this->endpoint('getMe', [], false);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#logout">logOut</a>
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function logOut(): array
    {
        return $this->endpoint('logOut', [], false);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#close">close</a>
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function close(): array
    {
        return $this->endpoint('close', [], false);
    }

    /// Set the list of the bot commands

    /**
     * See <a href="https://core.telegram.org/bots/api#setmycommands">setMyCommands</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyCommands(array $content): array
    {
        return $this->endpoint('setMyCommands', $content);
    }

    /// Delete the list of the bot commands

    /**
     * See <a href="https://core.telegram.org/bots/api#deletemycommands">deleteMyCommands</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteMyCommands(array $content): array
    {
        return $this->endpoint('deleteMyCommands', $content);
    }

    /// Get the list of the bot commands

    /**
     * See <a href="https://core.telegram.org/bots/api#getmycommands">getMyCommands</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getMyCommands(array $content): array
    {
        return $this->endpoint('getMyCommands', $content);
    }

    /// Set bot name

    /**
     * See <a href="https://core.telegram.org/bots/api#setmyname">setMyName</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyName(array $content): array
    {
        return $this->endpoint('setMyName', $content);
    }

    /// Get bot name

    /**
     * See <a href="https://core.telegram.org/bots/api#getmyname">getMyName</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getMyName(array $content = []): array
    {
        return $this->endpoint('getMyName', $content);
    }

    /// Set bot description

    /**
     * See <a href="https://core.telegram.org/bots/api#setmydescription">setMyDescription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyDescription(array $content): array
    {
        return $this->endpoint('setMyDescription', $content);
    }

    /// Get bot description

    /**
     * See <a href="https://core.telegram.org/bots/api#getmydescription">getMyDescription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getMyDescription(array $content = []): array
    {
        return $this->endpoint('getMyDescription', $content);
    }

    /// Set bot short description

    /**
     * See <a href="https://core.telegram.org/bots/api#setmyshortdescription">setMyShortDescription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyShortDescription(array $content): array
    {
        return $this->endpoint('setMyShortDescription', $content);
    }

    /// Get bot short description

    /**
     * See <a href="https://core.telegram.org/bots/api#getmyshortdescription">getMyShortDescription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getMyShortDescription(array $content = []): array
    {
        return $this->endpoint('getMyShortDescription', $content);
    }

    /// Set the default aministrator rights

    /**
     * See <a href="https://core.telegram.org/bots/api#setmydefaultadministratorrights">setMyDefaultAdministratorRights</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyDefaultAdministratorRights(array $content): array
    {
        return $this->endpoint('setMyDefaultAdministratorRights', $content);
    }

    /// Get the default aministrator rights

    /**
     * See <a href="https://core.telegram.org/bots/api#getmydefaultadministratorrights">getMyDefaultAdministratorRights</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getMyDefaultAdministratorRights(array $content): array
    {
        return $this->endpoint('getMyDefaultAdministratorRights', $content);
    }

    // ==================== VERIFICATION METHODS (Bot API 9.1) ====================

    /// Verify a user

    /**
     * See <a href="https://core.telegram.org/bots/api#verifyuser">verifyUser</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function verifyUser(array $content): array
    {
        return $this->endpoint('verifyUser', $content);
    }

    /// Remove user verification

    /**
     * See <a href="https://core.telegram.org/bots/api#removeuserverification">removeUserVerification</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function removeUserVerification(array $content): array
    {
        return $this->endpoint('removeUserVerification', $content);
    }

    /// Change the profile photo of the bot

    /**
     * See <a href="https://core.telegram.org/bots/api#setmyprofilephoto">setMyProfilePhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setMyProfilePhoto(array $content): array
    {
        return $this->endpoint('setMyProfilePhoto', $content);
    }

    /// Remove the profile photo of the bot

    /**
     * See <a href="https://core.telegram.org/bots/api#removemyprofilephoto">removeMyProfilePhoto</a> for the input values
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function removeMyProfilePhoto(): array
    {
        return $this->endpoint('removeMyProfilePhoto', [], false);
    }

    /// Get the token of a managed bot

    /**
     * See <a href="https://core.telegram.org/bots/api#getmanagedbottoken">getManagedBotToken</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getManagedBotToken(array $content): array
    {
        return $this->endpoint('getManagedBotToken', $content);
    }

    /// Revoke the current token of a managed bot and generate a new one

    /**
     * See <a href="https://core.telegram.org/bots/api#replacemanagedbottoken">replaceManagedBotToken</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function replaceManagedBotToken(array $content): array
    {
        return $this->endpoint('replaceManagedBotToken', $content);
    }

    /// Get the access settings of a managed bot

    /**
     * See <a href="https://core.telegram.org/bots/api#getmanagedbotaccesssettings">getManagedBotAccessSettings</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getManagedBotAccessSettings(array $content): array
    {
        return $this->endpoint('getManagedBotAccessSettings', $content);
    }

    /// Change the access settings of a managed bot

    /**
     * See <a href="https://core.telegram.org/bots/api#setmanagedbotaccesssettings">setManagedBotAccessSettings</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setManagedBotAccessSettings(array $content): array
    {
        return $this->endpoint('setManagedBotAccessSettings', $content);
    }

}
