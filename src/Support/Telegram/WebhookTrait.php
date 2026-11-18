<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Webhook da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait WebhookTrait
{
    /// Use this method to download a file
    /**
     *  Use this method to to download a file from the Telegram servers.
     * \param $telegram_file_path String File path on Telegram servers
     * \param $local_file_path String File path where save the file.
     */
    public function downloadFile(string $telegram_file_path, string $local_file_path): void
    {
        $file_url = 'https://api.telegram.org/file/bot' . $this->bot_token . '/' . $telegram_file_path;
        $in = \fopen($file_url, 'rb', false, \stream_context_create(['socket' => ['bindto' => '0.0.0.0:0']]));
        if ($in === false) {
            return;
        }

        // Só abre o destino em modo destrutivo depois que a origem remota foi
        // confirmada. Se o Telegram estiver indisponível, um arquivo local já
        // existente continua íntegro em vez de ser truncado para zero bytes.
        $out = \fopen($local_file_path, 'wb');
        if ($out === false) {
            \fclose($in);

            return;
        }

        // A cópia nativa gerencia a leitura até o fim do stream, evitando
        // condições manuais de parada que poderiam produzir um laço infinito.
        \stream_copy_to_stream($in, $out);
        \fclose($in);
        \fclose($out);
    }

    /// Set a webhook for the bot
    /**
     * Use this method to specify a URL and receive incoming updates via an outgoing webhook. Telegram sends an HTTPS POST request containing a JSON-serialized Update. If the request does not receive a successful 2xx response, Telegram retries it for a reasonable period.
     *
     * Use secret_token to authenticate webhook requests; Telegram sends it in the X-Telegram-Bot-Api-Secret-Token header.
     *
     * \param $url String HTTPS URL to send updates to. Use an empty string to remove webhook integration.
     * \param $certificate InputFile Optional public key certificate. This method accepts the local certificate path.
     * \param $ip_address String Optional fixed IP address used for webhook requests.
     * \param $max_connections Integer Optional maximum number of simultaneous HTTPS connections (1-100).
     * \param $allowed_updates Array of String Optional update types to receive. This method serializes the PHP array as JSON.
     * \param $drop_pending_updates Boolean Optional whether to discard pending updates.
     * \param $secret_token String Optional secret token sent in the webhook header (1-256 characters; A-Z, a-z, 0-9, _ and -).
     * @param array<int, string>|null $allowed_updates
     * @return TelegramResponse
     */
    public function setWebhook(
        string $url,
        string $certificate = '',
        ?string $ip_address = null,
        ?int $max_connections = null,
        ?array $allowed_updates = null,
        ?bool $drop_pending_updates = null,
        ?string $secret_token = null
    ): array {
        if ($certificate === '') {
            $requestBody = ['url' => $url];
        } else {
            $requestBody = ['url' => $url, 'certificate' => new \CURLFile($certificate)];
        }

        if ($ip_address !== null) {
            $requestBody['ip_address'] = $ip_address;
        }
        if ($max_connections !== null) {
            $requestBody['max_connections'] = $max_connections;
        }
        if ($allowed_updates !== null) {
            $encodedAllowedUpdates = $this->codificarAllowedUpdates($allowed_updates);
            if ($encodedAllowedUpdates === false) {
                return [
                    'ok' => false,
                    'description' => 'Não foi possível serializar allowed_updates como JSON',
                ];
            }
            $requestBody['allowed_updates'] = $encodedAllowedUpdates;
        }
        if ($drop_pending_updates !== null) {
            $requestBody['drop_pending_updates'] = $drop_pending_updates;
        }
        if ($secret_token !== null) {
            $requestBody['secret_token'] = $secret_token;
        }

        return $this->endpoint('setWebhook', $requestBody, true);
    }

    /// Delete the webhook for the bot

    /**
     * Use this method to remove webhook integration when switching back to <a href="https://core.telegram.org/bots/api#getupdates">getUpdates</a>. Returns True on success.
     * @param bool|null $drop_pending_updates Optional. Pass true to discard all pending updates.
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function deleteWebhook(?bool $drop_pending_updates = null): array
    {
        $content = [];
        if ($drop_pending_updates !== null) {
            $content['drop_pending_updates'] = $drop_pending_updates;
        }

        return $this->endpoint('deleteWebhook', $content, $drop_pending_updates !== null);
    }

    /// Get webhook info

    /**
     * See <a href="https://core.telegram.org/bots/api#getwebhookinfo">getWebhookInfo</a> for the input values
     * \return the JSON Telegram's reply.
     * @return TelegramResponse
     */
    public function getWebhookInfo(): array
    {
        return $this->endpoint('getWebhookInfo', [], false);
    }

}
