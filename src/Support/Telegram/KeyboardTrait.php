<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Keyboard da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait KeyboardTrait
{
    /// Set a custom keyboard
    /** This object represents a custom keyboard with reply options
     * \param $options Array of Array of String; Array of button rows, each represented by an Array of Strings
     * \param $onetime Boolean Requests clients to hide the keyboard as soon as it's been used. Defaults to false.
     * \param $resize Boolean Requests clients to resize the keyboard vertically for optimal fit (e.g., make the keyboard smaller if there are just two rows of buttons). Defaults to false, in which case the custom keyboard is always of the same height as the app's standard keyboard.
     * \param $selective Boolean Use this parameter if you want to show the keyboard to specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a message in the same chat and forum topic, sender of the original message. Defaults to false.
     * \param $is_persistent Boolean Requests clients to always show the keyboard when the regular keyboard is hidden. Defaults to false.
     * \param $input_field_placeholder String The placeholder to be shown in the input field when the keyboard is active; 1-64 characters.
     * \return the requested keyboard as Json.
     * @param array<int, mixed> $options
     */
    public function buildKeyBoard(array $options, bool $onetime = false, bool $resize = false, bool $selective = false, bool $is_persistent = false, string $input_field_placeholder = ''): string
    {
        $replyMarkup = [
            'keyboard'          => $options,
            'one_time_keyboard' => $onetime,
            'resize_keyboard'   => $resize,
            'selective'         => $selective,
            'is_persistent'     => $is_persistent,
        ];
        // input_field_placeholder não tem valor padrão na API: só vai se preenchido.
        if ($input_field_placeholder !== '') {
            $replyMarkup['input_field_placeholder'] = $input_field_placeholder;
        }
        $encodedMarkup = \json_encode($replyMarkup);

        return $encodedMarkup === false ? '{}' : $encodedMarkup;
    }

    /// Set an InlineKeyBoard
    /** This object represents an inline keyboard that appears right next to the message it belongs to.
     * \param $options Array of Array of InlineKeyboardButton; Array of button rows, each represented by an Array of InlineKeyboardButton
     * \return the requested keyboard as Json.
     * @param array<int, mixed> $options
     */
    public function buildInlineKeyboard(array $options): string
    {
        $replyMarkup = [
            'inline_keyboard' => $options,
        ];
        $encodedMarkup = \json_encode($replyMarkup);

        return $encodedMarkup === false ? '{}' : $encodedMarkup;
    }

    /// Create an InlineKeyboardButton
    /** This object represents one button of an inline keyboard. You must use exactly one of the optional fields.
     * \param $text String; Array of button rows, each represented by an Array of Strings
     * \param $url String Optional. HTTP url to be opened when button is pressed
     * \param $callback_data String Optional. Data to be sent in a callback query to the bot when button is pressed
     * \param $switch_inline_query String Optional. If set, pressing the button will prompt the user to select one of their chats, open that chat and insert the bot‘s username and the specified inline query in the input field. Can be empty, in which case just the bot’s username will be inserted.
     * \param $switch_inline_query_current_chat String Optional. Optional. If set, pressing the button will insert the bot‘s username and the specified inline query in the current chat's input field. Can be empty, in which case only the bot’s username will be inserted.
     * \param $callback_game  String Optional. Description of the game that will be launched when the user presses the button.
     * \param $pay  Boolean Optional. Specify True, to send a <a href="https://core.telegram.org/bots/api#payments">Pay button</a>.
     * \param $copy_text String Optional. Texto copiado para a área de transferência ao pressionar o botão (CopyTextButton, 1-256 caracteres).
     * \param $web_app_url String Optional. URL HTTPS do Web App aberto ao pressionar o botão (WebAppInfo). Só funciona em chat privado.
     * \return the requested button as Array.
     * @param bool|string $pay
     * @return array<string, mixed>
     */
    public function buildInlineKeyboardButton(
        string $text,
        ?string $url = null,
        string $callback_data = '',
        ?string $switch_inline_query = null,
        ?string $switch_inline_query_current_chat = null,
        string $callback_game = '',
        $pay = '',
        string $style = '',
        string $icon_custom_emoji_id = '',
        string $copy_text = '',
        string $web_app_url = ''
    ): array {
        $replyMarkup = [
            'text' => $text,
        ];
        if ($url !== null && $url !== '') {
            $replyMarkup['url'] = $url;
        } elseif ($callback_data !== '') {
            $replyMarkup['callback_data'] = $callback_data;
        } elseif (!\is_null($switch_inline_query)) {
            $replyMarkup['switch_inline_query'] = $switch_inline_query;
        } elseif (!\is_null($switch_inline_query_current_chat)) {
            $replyMarkup['switch_inline_query_current_chat'] = $switch_inline_query_current_chat;
        } elseif ($callback_game !== '') {
            $replyMarkup['callback_game'] = $callback_game;
            // $pay é bool|string: o `!= ''` de antes descartava '' e false juntos
            // (false == '' no PHP), e false é o "não informado" desse parâmetro.
        } elseif ($pay !== false && $pay !== '') {
            $replyMarkup['pay'] = $pay;
        } elseif ($copy_text !== '') {
            // CopyTextButton (Bot API 8.0+): útil para o PIX Copia-e-Cola.
            $replyMarkup['copy_text'] = ['text' => $copy_text];
        } elseif ($web_app_url !== '') {
            $replyMarkup['web_app'] = ['url' => $web_app_url];
        }

        return $this->estilizarBotao($replyMarkup, $style, $icon_custom_emoji_id);
    }

    /**
     * Aplica a apresentação sem alterar a ação escolhida para o botão.
     *
     * @param array<string, mixed> $replyMarkup
     * @return array<string, mixed>
     */
    private function estilizarBotao(array $replyMarkup, string $style, string $icon_custom_emoji_id): array
    {
        // Estilo do botão: "danger" (vermelho), "success" (verde) ou "primary" (azul)
        if ($style !== '' && \in_array($style, ['danger', 'success', 'primary'], true)) {
            $replyMarkup['style'] = $style;
        }

        // Ícone de emoji customizado (Bot API 9.4+)
        if ($icon_custom_emoji_id !== '') {
            $replyMarkup['icon_custom_emoji_id'] = $icon_custom_emoji_id;
        }

        return $replyMarkup;
    }

    /// Create a KeyboardButton
    /** This object represents one button of a reply keyboard. You must use exactly one of the optional fields.
     * \param $text String; Text of the button. If none of the optional fields are used, it will be sent as a message when the button is pressed
     * \param $request_contact Boolean Optional. If True, the user's phone number will be sent as a contact when the button is pressed. Available in private chats only
     * \param $request_location Boolean Optional. If True, the user's current location will be sent when the button is pressed. Available in private chats only
     * \param $style String Optional. "danger" (vermelho), "success" (verde) ou "primary" (azul).
     * \param $icon_custom_emoji_id String Optional. Emoji customizado exibido no botão.
     * \return the requested button as Array.
     * @return array<string, mixed>
     */
    public function buildKeyboardButton(string $text, bool $request_contact = false, bool $request_location = false, string $style = '', string $icon_custom_emoji_id = ''): array
    {
        $replyMarkup = [
            'text' => $text,
        ];
        if ($request_contact) {
            $replyMarkup['request_contact'] = true;
        } elseif ($request_location) {
            $replyMarkup['request_location'] = true;
        }
        // Mesma ordem de buildInlineKeyboardButton: style antes do ícone.
        if ($style !== '' && \in_array($style, ['danger', 'success', 'primary'], true)) {
            $replyMarkup['style'] = $style;
        }
        if ($icon_custom_emoji_id !== '') {
            $replyMarkup['icon_custom_emoji_id'] = $icon_custom_emoji_id;
        }

        return $replyMarkup;
    }

    /// Hide a custom keyboard
    /** Upon receiving a message with this object, Telegram clients will hide the current custom keyboard and display the default letter-keyboard. By default, custom keyboards are displayed until a new keyboard is sent by a bot. An exception is made for one-time keyboards that are hidden immediately after the user presses a button.
     * \param $selective Boolean Use this parameter if you want to remove the keyboard for specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a message in the same chat and forum topic, sender of the original message. Defaults to false.
     * \return the requested keyboard hide as Array.
     */
    public function buildKeyBoardHide(bool $selective = false): string
    {
        $replyMarkup = [
            'remove_keyboard' => true,
            'selective'       => $selective,
        ];
        $encodedMarkup = \json_encode($replyMarkup);

        return $encodedMarkup === false ? '{}' : $encodedMarkup;
    }

    /// Display a reply interface to the user
    /* Upon receiving a message with this object, Telegram clients will display a reply interface to the user (act as if the user has selected the bot‘s message and tapped ’Reply'). This can be extremely useful if you want to create user-friendly step-by-step interfaces without having to sacrifice privacy mode.
     * \param $selective Boolean Use this parameter if you want to force reply from specific users only. Targets: 1) users that are @mentioned in the text of the Message object; 2) if the bot's message is a reply to a message in the same chat and forum topic, sender of the original message. Defaults to false.
     * \param $input_field_placeholder String The placeholder to be shown in the input field when the reply is active; 1-64 characters.
     * \return the requested force reply as Array
     */
    public function buildForceReply(bool $selective = false, string $input_field_placeholder = ''): string
    {
        $replyMarkup = [
            'force_reply' => true,
            'selective'   => $selective,
        ];
        if ($input_field_placeholder !== '') {
            $replyMarkup['input_field_placeholder'] = $input_field_placeholder;
        }
        $encodedMarkup = \json_encode($replyMarkup);

        return $encodedMarkup === false ? '{}' : $encodedMarkup;
    }

}
