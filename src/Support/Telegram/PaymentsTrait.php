<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Payments da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait PaymentsTrait
{
    // Payments
    /// Send an invoice

    /**
     * See <a href="https://core.telegram.org/bots/api#sendinvoice">sendInvoice</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendInvoice(array $content): array
    {
        return $this->endpoint('sendInvoice', $content);
    }

    /// Answer a shipping query

    /**
     * See <a href="https://core.telegram.org/bots/api#answershippingquery">answerShippingQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerShippingQuery(array $content): array
    {
        return $this->endpoint('answerShippingQuery', $content);
    }

    /// Answer a PreCheckout query

    /**
     * See <a href="https://core.telegram.org/bots/api#answerprecheckoutquery">answerPreCheckoutQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerPreCheckoutQuery(array $content): array
    {
        return $this->endpoint('answerPreCheckoutQuery', $content);
    }

    /// Create invoice link

    /**
     * See <a href="https://core.telegram.org/bots/api#createinvoicelink">createInvoiceLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function createInvoiceLink(array $content): array
    {
        return $this->endpoint('createInvoiceLink', $content);
    }

    /// Set Passport data errors

    /**
     * See <a href="https://core.telegram.org/bots/api#setpassportdataerrors">setPassportDataErrors</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setPassportDataErrors(array $content): array
    {
        return $this->endpoint('setPassportDataErrors', $content);
    }

    //// Create Chat Subscription Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#createchatsubscriptioninvitelink">createChatSubscriptionInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function createChatSubscriptionInviteLink(array $content): array
    {
        return $this->endpoint('createChatSubscriptionInviteLink', $content);
    }

    //// Edit Chat Subscription Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#editchatsubscriptioninvitelink">editChatSubscriptionInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editChatSubscriptionInviteLink(array $content): array
    {
        return $this->endpoint('editChatSubscriptionInviteLink', $content);
    }

    // ==================== GIFTS METHODS (Bot API 9.1-9.3) ====================

    /// Get available gifts

    /**
     * See <a href="https://core.telegram.org/bots/api#getavailablegifts">getAvailableGifts</a> for the input values
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getAvailableGifts(): array
    {
        return $this->endpoint('getAvailableGifts', [], false);
    }

    /// Send a gift

    /**
     * See <a href="https://core.telegram.org/bots/api#sendgift">sendGift</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendGift(array $content): array
    {
        return $this->endpoint('sendGift', $content);
    }

    /// Gift a premium subscription

    /**
     * See <a href="https://core.telegram.org/bots/api#giftpremiumsubscription">giftPremiumSubscription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function giftPremiumSubscription(array $content): array
    {
        return $this->endpoint('giftPremiumSubscription', $content);
    }

    /// Get user gifts

    /**
     * See <a href="https://core.telegram.org/bots/api#getusergifts">getUserGifts</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getUserGifts(array $content): array
    {
        return $this->endpoint('getUserGifts', $content);
    }

    /// Get chat gifts

    /**
     * See <a href="https://core.telegram.org/bots/api#getchatgifts">getChatGifts</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChatGifts(array $content): array
    {
        return $this->endpoint('getChatGifts', $content);
    }

    /// Convert gift to stars

    /**
     * See <a href="https://core.telegram.org/bots/api#convertgifttostars">convertGiftToStars</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function convertGiftToStars(array $content): array
    {
        return $this->endpoint('convertGiftToStars', $content);
    }

    /// Upgrade a gift

    /**
     * See <a href="https://core.telegram.org/bots/api#upgradegift">upgradeGift</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function upgradeGift(array $content): array
    {
        return $this->endpoint('upgradeGift', $content);
    }

    /// Transfer a gift

    /**
     * See <a href="https://core.telegram.org/bots/api#transfergift">transferGift</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function transferGift(array $content): array
    {
        return $this->endpoint('transferGift', $content);
    }

    /// Set business account gift settings

    /**
     * See <a href="https://core.telegram.org/bots/api#setbusinessaccountgiftsettings">setBusinessAccountGiftSettings</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setBusinessAccountGiftSettings(array $content): array
    {
        return $this->endpoint('setBusinessAccountGiftSettings', $content);
    }

    /// Get business account star balance

    /**
     * See <a href="https://core.telegram.org/bots/api#getbusinessaccountstarbalance">getBusinessAccountStarBalance</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getBusinessAccountStarBalance(array $content): array
    {
        return $this->endpoint('getBusinessAccountStarBalance', $content);
    }

    /// Transfer business account stars

    /**
     * See <a href="https://core.telegram.org/bots/api#transferbusinessaccountstars">transferBusinessAccountStars</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function transferBusinessAccountStars(array $content): array
    {
        return $this->endpoint('transferBusinessAccountStars', $content);
    }

    /// Get business account gifts

    /**
     * See <a href="https://core.telegram.org/bots/api#getbusinessaccountgifts">getBusinessAccountGifts</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getBusinessAccountGifts(array $content): array
    {
        return $this->endpoint('getBusinessAccountGifts', $content);
    }

    // ==================== TELEGRAM STARS & PAYMENTS (Bot API 9.3) ====================

    /// Get my star balance

    /**
     * See <a href="https://core.telegram.org/bots/api#getmystarbalance">getMyStarBalance</a> for the input values
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getMyStarBalance(): array
    {
        return $this->endpoint('getMyStarBalance', [], false);
    }

    /// Get star transactions

    /**
     * See <a href="https://core.telegram.org/bots/api#getstartransactions">getStarTransactions</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getStarTransactions(array $content = []): array
    {
        return $this->endpoint('getStarTransactions', $content);
    }

    /// Refund star payment

    /**
     * See <a href="https://core.telegram.org/bots/api#refundstarpayment">refundStarPayment</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function refundStarPayment(array $content): array
    {
        return $this->endpoint('refundStarPayment', $content);
    }

    /// Edit user star subscription

    /**
     * See <a href="https://core.telegram.org/bots/api#edituserstarsubscription">editUserStarSubscription</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editUserStarSubscription(array $content): array
    {
        return $this->endpoint('editUserStarSubscription', $content);
    }

}
