<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Support/LeaseWebhook.php';
require_once __DIR__ . '/../src/Support/DiagnosticoTransporteTelegram.php';
require_once __DIR__ . '/../src/Support/Telegram/ObservadorContatos.php';

if (file_exists(__DIR__ . '/TelegramErrorLogger.php')) {
    require_once __DIR__ . '/TelegramErrorLogger.php';
}
require_once __DIR__ . '/funcoes_curl_telegram.php';

// Carregamento local explícito mantém o include legado independente do Composer.
require_once __DIR__ . '/../src/Support/Telegram/TransportTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/ProfileTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/Messages1Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/Messages2Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/StickersTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/Chats1Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/Chats2Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/InteractionsTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/WebhookTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/UpdateDataTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/Accessors1Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/Accessors2Trait.php';
require_once __DIR__ . '/../src/Support/Telegram/KeyboardTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/PaymentsTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/BusinessTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/ForumTrait.php';
require_once __DIR__ . '/../src/Support/Telegram/PollingTrait.php';


/**
 * Telegram Bot Class.
 *
 * @author Gabriele Grillo <gabry.grillo@alice.it>
 * @phpstan-type TelegramResponse array{
 *     ok: bool,
 *     result?: mixed,
 *     description?: string,
 *     error_code?: int,
 *     parameters?: array<string, mixed>
 * }
 */
class Telegram
{
    use \App\Support\Telegram\ObservadorContatos;
    use \App\Support\Telegram\TransportTrait;
    use \App\Support\Telegram\ProfileTrait;
    use \App\Support\Telegram\Messages1Trait;
    use \App\Support\Telegram\Messages2Trait;
    use \App\Support\Telegram\StickersTrait;
    use \App\Support\Telegram\Chats1Trait;
    use \App\Support\Telegram\Chats2Trait;
    use \App\Support\Telegram\InteractionsTrait;
    use \App\Support\Telegram\WebhookTrait;
    use \App\Support\Telegram\UpdateDataTrait;
    use \App\Support\Telegram\Accessors1Trait;
    use \App\Support\Telegram\Accessors2Trait;
    use \App\Support\Telegram\KeyboardTrait;
    use \App\Support\Telegram\PaymentsTrait;
    use \App\Support\Telegram\BusinessTrait;
    use \App\Support\Telegram\ForumTrait;
    use \App\Support\Telegram\PollingTrait;

    /**
     * Constant for type Inline Query.
     */
    public const INLINE_QUERY = 'inline_query';
    /**
     * Constant for type Callback Query.
     */
    public const CALLBACK_QUERY = 'callback_query';
    /**
     * Constant for type Edited Message.
     */
    public const EDITED_MESSAGE = 'edited_message';
    /**
     * Constant for type Reply.
     */
    public const REPLY = 'reply';
    /**
     * Constant for type Message.
     */
    public const MESSAGE = 'message';
    /**
     * Constant for type Photo.
     */
    public const PHOTO = 'photo';
    /**
     * Constant for type Video.
     */
    public const VIDEO = 'video';
    /**
     * Constant for type Audio.
     */
    public const AUDIO = 'audio';
    /**
     * Constant for type Voice.
     */
    public const VOICE = 'voice';
    /**
     * Constant for type animation.
     */
    public const ANIMATION = 'animation';
    /**
     * Constant for type sticker.
     */
    public const STICKER = 'sticker';
    /**
     * Constant for type Document.
     */
    public const DOCUMENT = 'document';
    /**
     * Constant for type Location.
     */
    public const LOCATION = 'location';
    /**
     * Constant for type Contact.
     */
    public const CONTACT = 'contact';
    /**
     * Constant for type Channel Post.
     */
    public const CHANNEL_POST = 'channel_post';
    /**
     * Constant for type New Chat Member.
     */
    public const NEW_CHAT_MEMBER = 'new_chat_member';
    /**
     * Constant for type Left Chat Member.
     */
    public const LEFT_CHAT_MEMBER = 'left_chat_member';
    /**
     * Constant for type My Chat Member.
     */
    public const MY_CHAT_MEMBER = 'my_chat_member';
    /**
     * Constant for type Edited Channel Post.
     */
    public const EDITED_CHANNEL_POST = 'edited_channel_post';
    /**
     * Constant for type Business Connection.
     */
    public const BUSINESS_CONNECTION = 'business_connection';
    /**
     * Constant for type Business Message.
     */
    public const BUSINESS_MESSAGE = 'business_message';
    /**
     * Constant for type Edited Business Message.
     */
    public const EDITED_BUSINESS_MESSAGE = 'edited_business_message';
    /**
     * Constant for type Deleted Business Messages.
     */
    public const DELETED_BUSINESS_MESSAGES = 'deleted_business_messages';
    /**
     * Constant for type Guest Message.
     */
    public const GUEST_MESSAGE = 'guest_message';
    /**
     * Constant for type Message Reaction.
     */
    public const MESSAGE_REACTION = 'message_reaction';
    /**
     * Constant for type Message Reaction Count.
     */
    public const MESSAGE_REACTION_COUNT = 'message_reaction_count';
    /**
     * Constant for type Chosen Inline Result.
     */
    public const CHOSEN_INLINE_RESULT = 'chosen_inline_result';
    /**
     * Constant for type Shipping Query.
     */
    public const SHIPPING_QUERY = 'shipping_query';
    /**
     * Constant for type Pre Checkout Query.
     */
    public const PRE_CHECKOUT_QUERY = 'pre_checkout_query';
    /**
     * Constant for type Purchased Paid Media.
     */
    public const PURCHASED_PAID_MEDIA = 'purchased_paid_media';
    /**
     * Constant for type Poll.
     */
    public const POLL = 'poll';
    /**
     * Constant for type Poll Answer.
     */
    public const POLL_ANSWER = 'poll_answer';
    /**
     * Constant for type Chat Member.
     */
    public const CHAT_MEMBER = 'chat_member';
    /**
     * Constant for type Chat Join Request.
     */
    public const CHAT_JOIN_REQUEST = 'chat_join_request';
    /**
     * Constant for type Chat Boost.
     */
    public const CHAT_BOOST = 'chat_boost';
    /**
     * Constant for type Removed Chat Boost.
     */
    public const REMOVED_CHAT_BOOST = 'removed_chat_boost';
    /**
     * Constant for type Managed Bot.
     */
    public const MANAGED_BOT = 'managed_bot';
    /**
     * Constant for type Subscription.
     */
    public const SUBSCRIPTION = 'subscription';

    /** Maximum time to establish a connection with the Telegram API. */
    public const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Maximum duration of a regular Telegram API request.
     *
     * Baixo de propósito. Em produção o bot roda por webhook, e cada chamada
     * à Bot API segura um filho do PHP-FPM até responder: numa janela de
     * instabilidade do Telegram os filhos acabam antes da fila, a pool fecha e
     * o bot fica mudo para TODO MUNDO — inclusive nos fluxos que não dependiam
     * da chamada travada. Foi o que aconteceu em 2026-08-22, com o teto em 60s.
     * Nenhuma resposta legítima da Bot API demora isso; o que passa daqui é
     * rede ruim, e nesse caso falhar rápido preserva a pool.
     */
    public const REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * Margem somada ao timeout de long polling do getUpdates.
     *
     * O getUpdates é a única chamada que pede ao PRÓPRIO Telegram para segurar
     * a conexão aberta por N segundos. Cortá-la em REQUEST_TIMEOUT_SECONDS
     * mataria todo ciclo de polling assim que N passasse do teto curto; a
     * margem cobre handshake e transferência do lote por cima do que foi
     * pedido. Vale só para o bot.php (desenvolvimento) — produção é webhook.
     */
    public const LONG_POLLING_MARGIN_SECONDS = 10;

    private string $bot_token;
    /** @var array<string, mixed> */
    private array $data = [];
    /** @var TelegramResponse */
    private array $updates = ['ok' => true, 'result' => []];
    private bool $log_errors;
    /** @var array<string, mixed> */
    private array $proxy;
    private ?string $update_type = null;

    /// Class constructor
    /**
     * Create a Telegram instance from the bot token
     * \param $bot_token the bot token
     * \param $log_errors enable or disable the logging
     * \param $proxy array with the proxy configuration (url, port, type, auth)
     * \return an instance of the class.
     * @param array<string, mixed> $proxy
     */
    public function __construct(string $bot_token, bool $log_errors = true, array $proxy = [])
    {
        $this->bot_token = $bot_token;
        $this->data = $this->getData();
        $this->log_errors = $log_errors;
        $this->proxy = $proxy;
    }
}

// Helper for Uploading file using CURL
if (!function_exists('curl_file_create')) {
    /**
     * @param string $filename
     * @param string $mimetype
     * @param string $postname
     * @return string
     */
    function curl_file_create($filename, $mimetype = '', $postname = '')
    {
        return "@$filename;filename="
            . ($postname !== '' ? $postname : basename($filename))
            . ($mimetype !== '' ? ";type=$mimetype" : '');
    }
}
