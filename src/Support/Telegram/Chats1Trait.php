<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Chats da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait Chats1Trait
{
    /// Send a chat action

    /**
     * See <a href="https://core.telegram.org/bots/api#sendchataction">sendChatAction</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendChatAction(array $content): array
    {
        return $this->endpoint('sendChatAction', $content);
    }

    /// Leave Chat

    /**
     * See <a href="https://core.telegram.org/bots/api#leavechat">leaveChat</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function leaveChat(array $content): array
    {
        return $this->endpoint('leaveChat', $content);
    }

    /// Ban Chat Member

    /**
     * See <a href="https://core.telegram.org/bots/api#banchatmember">banChatMember</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function banChatMember(array $content): array
    {
        return $this->endpoint('banChatMember', $content);
    }

    /// Unban Chat Member

    /**
     * See <a href="https://core.telegram.org/bots/api#unbanchatmember">unbanChatMember</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unbanChatMember(array $content): array
    {
        return $this->endpoint('unbanChatMember', $content);
    }

    /// Get Chat Information

    /**
     * See <a href="https://core.telegram.org/bots/api#getchat">getChat</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChat(array $content): array
    {
        return $this->endpoint('getChat', $content);
    }

    /// Get chat Administrators

    /**
     * See <a href="https://core.telegram.org/bots/api#getchatadministrators">getChatAdministrators</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChatAdministrators(array $content): array
    {
        return $this->endpoint('getChatAdministrators', $content);
    }

    /// Get chat member count

    /**
     * See <a href="https://core.telegram.org/bots/api#getchatmembercount">getChatMemberCount</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChatMemberCount(array $content): array
    {
        return $this->endpoint('getChatMemberCount', $content);
    }

    /**
     * See <a href="https://core.telegram.org/bots/api#getchatmember">getChatMember</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChatMember(array $content): array
    {
        return $this->endpoint('getChatMember', $content);
    }

    /// Set the chat menu button

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatmenubutton">setChatMenuButton</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatMenuButton(array $content): array
    {
        return $this->endpoint('setChatMenuButton', $content);
    }

    /// Get the chat menu button

    /**
     * See <a href="https://core.telegram.org/bots/api#getchatmenubutton">getChatMenuButton</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function getChatMenuButton(array $content): array
    {
        return $this->endpoint('getChatMenuButton', $content);
    }

    /// Restrict Chat Member

    /**
     * See <a href="https://core.telegram.org/bots/api#restrictchatmember">restrictChatMember</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function restrictChatMember(array $content): array
    {
        return $this->endpoint('restrictChatMember', $content);
    }

    /// Promote Chat Member

    /**
     * See <a href="https://core.telegram.org/bots/api#promotechatmember">promoteChatMember</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function promoteChatMember(array $content): array
    {
        return $this->endpoint('promoteChatMember', $content);
    }

    /// Set chat Administrator custom title

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatadministratorcustomtitle">setChatAdministratorCustomTitle</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatAdministratorCustomTitle(array $content): array
    {
        return $this->endpoint('setChatAdministratorCustomTitle', $content);
    }

    /// Ban a channel chat in a super group or channel

    /**
     * See <a href="https://core.telegram.org/bots/api#banchatsenderchat">banChatSenderChat</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function banChatSenderChat(array $content): array
    {
        return $this->endpoint('banChatSenderChat', $content);
    }

    /// Unban a channel chat in a super group or channel

    /**
     * See <a href="https://core.telegram.org/bots/api#unbanchatsenderchat">unbanChatSenderChat</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function unbanChatSenderChat(array $content): array
    {
        return $this->endpoint('unbanChatSenderChat', $content);
    }

    /// Set default chat permission for all members

    /**
     * See <a href="https://core.telegram.org/bots/api#setchatpermissions">setChatPermissions</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function setChatPermissions(array $content): array
    {
        return $this->endpoint('setChatPermissions', $content);
    }

    //// Export Chat Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#exportchatinvitelink">exportChatInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function exportChatInviteLink(array $content): array
    {
        return $this->endpoint('exportChatInviteLink', $content);
    }

    //// Create Chat Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#createchatinvitelink">createChatInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function createChatInviteLink(array $content): array
    {
        return $this->endpoint('createChatInviteLink', $content);
    }

    //// Edit Chat Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#editchatinvitelink">editChatInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function editChatInviteLink(array $content): array
    {
        return $this->endpoint('editChatInviteLink', $content);
    }

    //// Revoke Chat Invite Link

    /**
     * See <a href="https://core.telegram.org/bots/api#revokechatinvitelink">revokeChatInviteLink</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function revokeChatInviteLink(array $content): array
    {
        return $this->endpoint('revokeChatInviteLink', $content);
    }

    //// Approve chat join request

    /**
     * See <a href="https://core.telegram.org/bots/api#approvechatjoinrequest">approveChatJoinRequest</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function approveChatJoinRequest(array $content): array
    {
        return $this->endpoint('approveChatJoinRequest', $content);
    }

    //// Decline chat join request

    /**
     * See <a href="https://core.telegram.org/bots/api#declinechatjoinrequest">declineChatJoinRequest</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function declineChatJoinRequest(array $content): array
    {
        return $this->endpoint('declineChatJoinRequest', $content);
    }

    //// Answer a chat join request query

    /**
     * See <a href="https://core.telegram.org/bots/api#answerchatjoinrequestquery">answerChatJoinRequestQuery</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function answerChatJoinRequestQuery(array $content): array
    {
        return $this->endpoint('answerChatJoinRequestQuery', $content);
    }

    //// Send a Mini App to process a chat join request query

    /**
     * See <a href="https://core.telegram.org/bots/api#sendchatjoinrequestwebapp">sendChatJoinRequestWebApp</a> for the input values
     * \param $content the request parameters as array
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function sendChatJoinRequestWebApp(array $content): array
    {
        return $this->endpoint('sendChatJoinRequestWebApp', $content);
    }

}
