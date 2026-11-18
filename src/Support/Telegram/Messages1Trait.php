<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Messages da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Messages1Trait
{
    /// A method for responding http to Telegram.
    /**
     * \return the HTTP 200 to Telegram.
     */
    public function respondSuccess(): string
    {
        \http_response_code(200);

        // JSON_THROW_ON_ERROR em vez do ternário de fallback: o array é literal
        // e json_encode() nunca falha sobre ele, então o ramo de erro era
        // inalcançável — e produzia exatamente a mesma string, o que fazia dele
        // um mutante impossível de matar por construção.
        return \json_encode(['status' => 'success'], JSON_THROW_ON_ERROR);
    }

    /// Send a message

    /**
     * See <a href="https://core.telegram.org/bots/api#sendmessage">sendMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendMessage(array $content): array
    {
        return $this->endpoint('sendMessage', $content);
    }

    /// Copy a message

    /**
     * See <a href="https://core.telegram.org/bots/api#copymessage">copyMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function copyMessage(array $content): array
    {
        return $this->endpoint('copyMessage', $content);
    }

    /// Forward a message

    /**
     * See <a href="https://core.telegram.org/bots/api#forwardmessage">forwardMessage</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function forwardMessage(array $content): array
    {
        return $this->endpoint('forwardMessage', $content);
    }

    /// Forward multiple messages

    /**
     * See <a href="https://core.telegram.org/bots/api#forwardmessages">forwardMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function forwardMessages(array $content): array
    {
        return $this->endpoint('forwardMessages', $content);
    }

    /// Copy multiple messages

    /**
     * See <a href="https://core.telegram.org/bots/api#copymessages">copyMessages</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function copyMessages(array $content): array
    {
        return $this->endpoint('copyMessages', $content);
    }

    /// Send a photo

    /**
     * See <a href="https://core.telegram.org/bots/api#sendphoto">sendPhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendPhoto(array $content): array
    {
        return $this->endpoint('sendPhoto', $content);
    }

    /// Send a live photo

    /**
     * See <a href="https://core.telegram.org/bots/api#sendlivephoto">sendLivePhoto</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendLivePhoto(array $content): array
    {
        return $this->endpoint('sendLivePhoto', $content);
    }

    /// Send an audio

    /**
     * See <a href="https://core.telegram.org/bots/api#sendaudio">sendAudio</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendAudio(array $content): array
    {
        return $this->endpoint('sendAudio', $content);
    }

    /// Send a document

    /**
     * See <a href="https://core.telegram.org/bots/api#senddocument">sendDocument</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendDocument(array $content): array
    {
        return $this->endpoint('sendDocument', $content);
    }

    /// Send an animation

    /**
     * See <a href="https://core.telegram.org/bots/api#sendanimation">sendAnimation</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendAnimation(array $content): array
    {
        return $this->endpoint('sendAnimation', $content);
    }

    /// Send a video

    /**
     * See <a href="https://core.telegram.org/bots/api#sendvideo">sendVideo</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendVideo(array $content): array
    {
        return $this->endpoint('sendVideo', $content);
    }

    /// Send paid media

    /**
     * See <a href="https://core.telegram.org/bots/api#sendpaidmedia">sendPaidMedia</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendPaidMedia(array $content): array
    {
        return $this->endpoint('sendPaidMedia', $content);
    }

    /// Send a voice message

    /**
     * See <a href="https://core.telegram.org/bots/api#sendvoice">sendVoice</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendVoice(array $content): array
    {
        return $this->endpoint('sendVoice', $content);
    }

    /// Send a location

    /**
     * See <a href="https://core.telegram.org/bots/api#sendlocation">sendLocation</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendLocation(array $content): array
    {
        return $this->endpoint('sendLocation', $content);
    }

    /// Edit Message Live Location

    /**
     * See <a href="https://core.telegram.org/bots/api#editmessageliveLocation">editMessageLiveLocation</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editMessageLiveLocation(array $content): array
    {
        return $this->endpoint('editMessageLiveLocation', $content);
    }

    /// Stop Message Live Location

    /**
     * See <a href="https://core.telegram.org/bots/api#stopmessagelivelocation">stopMessageLiveLocation</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function stopMessageLiveLocation(array $content): array
    {
        return $this->endpoint('stopMessageLiveLocation', $content);
    }

    /// Send Media Group

    /**
     * See <a href="https://core.telegram.org/bots/api#sendmediagroup">sendMediaGroup</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendMediaGroup(array $content): array
    {
        return $this->endpoint('sendMediaGroup', $content);
    }

    /// Send Venue

    /**
     * See <a href="https://core.telegram.org/bots/api#sendvenue">sendVenue</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendVenue(array $content): array
    {
        return $this->endpoint('sendVenue', $content);
    }

    //Send contact

    /**
     * See <a href="https://core.telegram.org/bots/api#sendcontact">sendContact</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendContact(array $content): array
    {
        return $this->endpoint('sendContact', $content);
    }

    //Send a poll

    /**
     * See <a href="https://core.telegram.org/bots/api#sendpoll">sendPoll</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendPoll(array $content): array
    {
        return $this->endpoint('sendPoll', $content);
    }

    //Send a dice

    /**
     * See <a href="https://core.telegram.org/bots/api#senddice">sendDice</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendDice(array $content): array
    {
        return $this->endpoint('sendDice', $content);
    }

    /// Get a list of profile pictures for a user

    /**
     * See <a href="https://core.telegram.org/bots/api#getuserprofilephotos">getUserProfilePhotos</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getUserProfilePhotos(array $content): array
    {
        return $this->endpoint('getUserProfilePhotos', $content);
    }

    /// Use this method to get basic info about a file and prepare it for downloading
    /**
     *  Use this method to get basic info about a file and prepare it for downloading. For the moment, bots can download files of up to 20MB in size. On success, a File object is returned. The file can then be downloaded via the link https://api.telegram.org/file/bot<token>/<file_path>, where <file_path> is taken from the response. It is guaranteed that the link will be valid for at least 1 hour. When the link expires, a new one can be requested by calling getFile again.
     * \param $file_id String File identifier to get info about
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getFile(string $file_id): array
    {
        $content = ['file_id' => $file_id];

        return $this->endpoint('getFile', $content);
    }

}
