<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Polling da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait PollingTrait
{
    /// Receive incoming messages using long polling
    /**
     * Use this method to receive incoming updates using long polling. The offset can be negative to retrieve updates from the end of the queue; previously confirmed updates are discarded.
     * \param $offset Integer Optional identifier of the first update to return. An update is confirmed when getUpdates is called with an offset higher than its update_id.
     * \param $limit Integer Optional number of updates to retrieve (1-100, default 100).
     * \param $timeout Integer Optional long-polling timeout in seconds (default 0).
     * \param $update Boolean Internal helper flag. If true, this method performs the existing confirmation request for the last received update (default true).
     * \param $allowed_updates Array of String Optional update types to receive. This method serializes the PHP array as JSON; pass an empty array to request the documented empty list.
     * \return the updates as Array.
     * @param array<int, string>|null $allowed_updates
     * @return TelegramResponse
     */
    public function getUpdates(int $offset = 0, int $limit = 100, int $timeout = 0, bool $update = true, ?array $allowed_updates = null): array
    {
        $encodedAllowedUpdates = null;
        if ($allowed_updates !== null) {
            $encodedAllowedUpdates = $this->codificarAllowedUpdates($allowed_updates);
            if ($encodedAllowedUpdates === false) {
                $this->updates = [
                    'ok' => false,
                    'description' => 'Não foi possível serializar allowed_updates como JSON',
                ];

                return $this->updates;
            }
        }

        $content = ['offset' => $offset, 'limit' => $limit, 'timeout' => $timeout];
        if ($encodedAllowedUpdates !== null) {
            $content['allowed_updates'] = $encodedAllowedUpdates;
        }
        $this->updates = $this->endpoint('getUpdates', $content);
        if ($update) {
            $results = $this->updatesResult();
            if (\count($results) >= 1) { //for CLI working.
                // O último update da lista define o offset da confirmação; só
                // avançamos se o update_id vier mesmo como inteiro. A lista vem
                // de json_decode e updatesResult() preserva as chaves originais,
                // que podem não ser 0..n-1 (um 'result' que chegue como objeto
                // JSON vira array com as chaves do objeto): indexar por
                // count() - 1 leria uma chave inexistente e deixaria o offset
                // parado, reentregando o mesmo update em todo ciclo.
                $lastUpdate = $results[\array_key_last($results)];
                $lastUpdateId = \is_array($lastUpdate) && isset($lastUpdate['update_id']) ? $lastUpdate['update_id'] : null;
                if (\is_int($lastUpdateId)) {
                    $last_element_id = $lastUpdateId + 1;
                    $content = ['offset' => $last_element_id, 'limit' => 1, 'timeout' => $timeout];
                    if ($encodedAllowedUpdates !== null) {
                        $content['allowed_updates'] = $encodedAllowedUpdates;
                    }
                    $this->endpoint('getUpdates', $content);
                }
            }
        }

        return $this->updates;
    }

    /**
     * Serializa a lista de filtros da Bot API sem deixar uma falha de UTF-8
     * removê-la silenciosamente da requisição. `JSON_THROW_ON_ERROR` torna a
     * falha explícita; o retorno false permite que setWebhook()/getUpdates()
     * devolvam o envelope local sem chamar a API sem o filtro solicitado.
     *
     * @param array<int, string> $allowedUpdates
     */
    private function codificarAllowedUpdates(array $allowedUpdates): string|false
    {
        try {
            return \json_encode($allowedUpdates, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }

    /// Serve an update
    /** Use this method to use built-in functions like Text() or Username() on a specific update.
     * \param $update Integer The index of the update in the updates array.
     */
    public function serveUpdate(int $update): void
    {
        $results = $this->updatesResult();
        $selected = isset($results[$update]) ? $results[$update] : null;
        $this->data = $this->arrayOrNull($selected) ?? [];
        $this->update_type = null;
    }

    /**
     * Return the current update type, or `null` when it is not recognized.
     */
    public function getUpdateType(): ?string
    {
        if ($this->update_type !== null) {
            return $this->update_type;
        }

        // A ordem preserva a precedência legada quando coexistem campos.
        $caminhos = [
            self::INLINE_QUERY => ['inline_query'],
            self::CALLBACK_QUERY => ['callback_query'],
            self::EDITED_MESSAGE => ['edited_message'],
            self::MESSAGE => ['message', 'text'],
            self::PHOTO => ['message', 'photo'],
            self::VIDEO => ['message', 'video'],
            self::AUDIO => ['message', 'audio'],
            self::VOICE => ['message', 'voice'],
            self::CONTACT => ['message', 'contact'],
            self::LOCATION => ['message', 'location'],
            self::REPLY => ['message', 'reply_to_message'],
            self::ANIMATION => ['message', 'animation'],
            self::STICKER => ['message', 'sticker'],
            self::DOCUMENT => ['message', 'document'],
            self::NEW_CHAT_MEMBER => ['message', 'new_chat_members'],
            self::LEFT_CHAT_MEMBER => ['message', 'left_chat_member'],
            self::MY_CHAT_MEMBER => ['my_chat_member'],
            self::CHANNEL_POST => ['channel_post'],
        ];
        foreach ($caminhos as $tipo => $caminho) {
            if ($this->dataPath(...$caminho) !== null) {
                $this->update_type = $tipo;

                return $tipo;
            }
        }
        $direct_types = [
            self::EDITED_CHANNEL_POST,
            self::BUSINESS_CONNECTION,
            self::BUSINESS_MESSAGE,
            self::EDITED_BUSINESS_MESSAGE,
            self::DELETED_BUSINESS_MESSAGES,
            self::GUEST_MESSAGE,
            self::MESSAGE_REACTION,
            self::MESSAGE_REACTION_COUNT,
            self::CHOSEN_INLINE_RESULT,
            self::SHIPPING_QUERY,
            self::PRE_CHECKOUT_QUERY,
            self::PURCHASED_PAID_MEDIA,
            self::POLL,
            self::POLL_ANSWER,
            self::CHAT_MEMBER,
            self::CHAT_JOIN_REQUEST,
            self::CHAT_BOOST,
            self::REMOVED_CHAT_BOOST,
            self::MANAGED_BOT,
            self::SUBSCRIPTION,
        ];
        foreach ($direct_types as $type) {
            if ($this->dataPath($type) !== null) {
                $this->update_type = $type;

                return $this->update_type;
            }
        }

        return null;
    }

}
