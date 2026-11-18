<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Stickers da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait StickersTrait
{
    /// Send a sticker

    /**
     * See <a href="https://core.telegram.org/bots/api#sendsticker">sendSticker</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendSticker(array $content): array
    {
        return $this->endpoint('sendSticker', $content);
    }

    /// Set Chat Sticker Set

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatstickerset">setChatStickerSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatStickerSet(array $content): array
    {
        return $this->endpoint('setChatStickerSet', $content);
    }

    /// Delete Chat Sticker Set

    /**
     * See <a href="https://core.telegram.org/bots/api#deletechatstickerset">deleteChatStickerSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteChatStickerSet(array $content): array
    {
        return $this->endpoint('deleteChatStickerSet', $content);
    }

    /// Get Sticker Set

    /**
     * See <a href="https://core.telegram.org/bots/api#getstickerset">getStickerSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getStickerSet(array $content): array
    {
        return $this->endpoint('getStickerSet', $content);
    }

    /// Upload Sticker File

    /**
     * See <a href="https://core.telegram.org/bots/api#uploadstickerfile">uploadStickerFile</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function uploadStickerFile(array $content): array
    {
        return $this->endpoint('uploadStickerFile', $content);
    }

    /// Create New Sticker Set

    /**
     * See <a href="https://core.telegram.org/bots/api#createnewstickerset">createNewStickerSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function createNewStickerSet(array $content): array
    {
        return $this->endpoint('createNewStickerSet', $content);
    }

    /// Add Sticker To Set

    /**
     * See <a href="https://core.telegram.org/bots/api#addstickertoset">addStickerToSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function addStickerToSet(array $content): array
    {
        return $this->endpoint('addStickerToSet', $content);
    }

    /// Set Sticker Position In Set

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickerpositioninset">setStickerPositionInSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerPositionInSet(array $content): array
    {
        return $this->endpoint('setStickerPositionInSet', $content);
    }

    /// Delete Sticker From Set

    /**
     * See <a href="https://core.telegram.org/bots/api#deletestickerfromset">deleteStickerFromSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteStickerFromSet(array $content): array
    {
        return $this->endpoint('deleteStickerFromSet', $content);
    }

    /// Replace Sticker In Set

    /**
     * See <a href="https://core.telegram.org/bots/api#replacestickerinset">replaceStickerInSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function replaceStickerInSet(array $content): array
    {
        return $this->endpoint('replaceStickerInSet', $content);
    }

    /// Set Sticker Emoji List

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickeremojilist">setStickerEmojiList</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerEmojiList(array $content): array
    {
        return $this->endpoint('setStickerEmojiList', $content);
    }

    /// Set Sticker Keywords

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickerkeywords">setStickerKeywords</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerKeywords(array $content): array
    {
        return $this->endpoint('setStickerKeywords', $content);
    }

    /// Set Sticker Mask Position

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickermaskposition">setStickerMaskPosition</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerMaskPosition(array $content): array
    {
        return $this->endpoint('setStickerMaskPosition', $content);
    }

    /// Set Sticker Set Title

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickersettitle">setStickerSetTitle</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerSetTitle(array $content): array
    {
        return $this->endpoint('setStickerSetTitle', $content);
    }

    /// Set Sticker Set Thumbnail

    /**
     * See <a href="https://core.telegram.org/bots/api#setstickersetthumbnail">setStickerSetThumbnail</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setStickerSetThumbnail(array $content): array
    {
        return $this->endpoint('setStickerSetThumbnail', $content);
    }

    /// Set Custom Emoji Sticker Set Thumbnail

    /**
     * See <a href="https://core.telegram.org/bots/api#setcustomemojistickersetthumbnail">setCustomEmojiStickerSetThumbnail</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setCustomEmojiStickerSetThumbnail(array $content): array
    {
        return $this->endpoint('setCustomEmojiStickerSetThumbnail', $content);
    }

    /// Delete Sticker Set

    /**
     * See <a href="https://core.telegram.org/bots/api#deletestickerset">deleteStickerSet</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function deleteStickerSet(array $content): array
    {
        return $this->endpoint('deleteStickerSet', $content);
    }

    /// Get forum topic icon stickers

    /**
     * See <a href="https://core.telegram.org/bots/api#getforumtopiciconstickers">getForumTopicIconStickers</a> for the input values
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getForumTopicIconStickers(): array
    {
        return $this->endpoint('getForumTopicIconStickers', [], false);
    }

    /// Get custom emoji stickers

    /**
     * See <a href="https://core.telegram.org/bots/api#getcustomemojistickers">getCustomEmojiStickers</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getCustomEmojiStickers(array $content): array
    {
        return $this->endpoint('getCustomEmojiStickers', $content);
    }

}
