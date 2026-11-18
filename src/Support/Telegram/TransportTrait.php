<?php

declare(strict_types=1);

namespace App\Support\Telegram;

/**
 * Responsabilidade Transport da API Telegram; composição da classe global legada.
 *
 * @phpstan-import-type TelegramResponse from \Telegram
 */
trait TransportTrait
{
    /// Do requests to Telegram Bot API
    /**
     * Contacts the various API's endpoints
     * \param $api the API endpoint
     * \param $content the request parameters as array
     * \param $post boolean tells if $content needs to be sends
     * \return the JSON Telegram's reply.
     * @param array<string, mixed> $content
     * @return TelegramResponse
     */
    public function endpoint(string $api, array $content, bool $post = true): array
    {
        \App\Support\LeaseWebhook::renovar();
        $momentoContato = time();
        $url = 'https://api.telegram.org/bot' . $this->bot_token . '/' . $api;
        if ($post) {
            $reply = $this->sendAPIRequest($url, $content);
        } else {
            $reply = $this->sendAPIRequest($url, [], false);
        }

        $decoded = \json_decode($reply, true);
        if (!\is_array($decoded) || !isset($decoded['ok']) || !\is_bool($decoded['ok'])) {
            return [
                'ok' => false,
                'description' => 'Resposta inválida da API do Telegram',
            ];
        }

        $respostaContato = $this->normalizarResposta($decoded);
        $this->observarRespostaContato($api, $content, $respostaContato, $momentoContato);
        return $respostaContato;
    }

    /**
     * Preserva apenas os campos tipados do envelope da Bot API.
     *
     * @param array<mixed> $decoded
     * @return TelegramResponse
     */
    private function normalizarResposta(array $decoded): array
    {
        $response = ['ok' => $decoded['ok'] === true];
        if (\array_key_exists('result', $decoded)) {
            $response['result'] = $decoded['result'];
        }
        if (isset($decoded['description']) && \is_string($decoded['description'])) {
            $response['description'] = $decoded['description'];
        }
        if (isset($decoded['error_code']) && \is_int($decoded['error_code'])) {
            $response['error_code'] = $decoded['error_code'];
        }
        if (isset($decoded['parameters']) && \is_array($decoded['parameters'])) {
            $parameters = $this->arrayOrNull($decoded['parameters']);
            if ($parameters !== null) {
                $response['parameters'] = $parameters;
            }
        }

        return $response;
    }

    /**
     * Duração máxima desta requisição, em segundos.
     *
     * O long polling do getUpdates é o único caso em que o teto tem de ser o
     * timeout PEDIDO mais a margem: o Telegram segura a conexão de propósito, e
     * o teto curto a cortaria antes de a resposta chegar. Todo o resto — que em
     * produção é a totalidade das chamadas, porque o bot roda por webhook — usa
     * REQUEST_TIMEOUT_SECONDS. Nenhum outro método da Bot API tem campo
     * `timeout`, então a chave identifica o caso sem ambiguidade.
     *
     * @param array<string, mixed> $content
     */
    protected function timeoutDaRequisicaoEmSegundos(array $content): int
    {
        $longPolling = $content['timeout'] ?? null;
        if (\is_numeric($longPolling) && (int) $longPolling > 0) {
            return (int) $longPolling + self::LONG_POLLING_MARGIN_SECONDS;
        }

        return self::REQUEST_TIMEOUT_SECONDS;
    }

    /**
     * Contexto TÉCNICO de uma falha da Bot API — nunca o conteúdo.
     *
     * O que este método não pode devolver, em hipótese nenhuma: o update cru
     * (`getData()`), o corpo que ia ser enviado (`$content`) ou a `$url`, que
     * carrega o TOKEN_BOT no caminho.
     *
     * Isso já esteve aqui. Até 2026-08-24 o log recebia
     * `[$this->getData(), $content]` e gravava, a CADA `ok:false` da Bot API,
     * o update inteiro do usuário mais a resposta que o bot ia mandar, em
     * `logs/app-AAAA-MM-DD.log`, com 14 dias de retenção. Não era caminho
     * raro: Markdown desbalanceado, "message is not modified" (dois cliques no
     * mesmo botão), "query is too old" e "bot was blocked by the user" são
     * corriqueiros. O que caía no disco dependia do bot — código de
     * verificação e corpo do SMS, nome e CPF do pagador, resposta completa do
     * modelo.
     *
     * O diagnóstico real de um erro da Bot API está na `description` que ela
     * mesma devolve, mais o método chamado e QUAIS campos foram enviados. O
     * valor dos campos nunca ajudou a resolver um 400.
     *
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    protected function contextoTecnicoDaFalha(string $url, array $content): array
    {
        // parse_url + basename sobre o CAMINHO, nunca sobre a URL inteira: o
        // token vive em /bot<TOKEN>/<metodo>, e basename() da URL crua traria
        // a query junto.
        $caminho = \parse_url($url, PHP_URL_PATH);
        $metodo = \is_string($caminho) && $caminho !== '' ? \basename($caminho) : 'desconhecido';

        $contexto = [
            'metodo' => $metodo,
            // Só os NOMES dos campos: dizem qual chamada falhou e com que
            // forma, sem carregar texto, telefone, código ou documento.
            'campos' => \array_keys($content),
        ];

        // chat_id é identificador de roteamento, não conteúdo — sem ele não dá
        // para saber qual conversa ficou sem resposta. Entra só se escalar, e
        // este método é chamado ANTES de o chat_id migrar para a query string.
        if (isset($content['chat_id']) && \is_scalar($content['chat_id'])) {
            $contexto['chat_id'] = (string) $content['chat_id'];
        }

        return $contexto;
    }

    /**
     * Move o `chat_id` do corpo para a query string da URL.
     *
     * É função pura de propósito. Enquanto isto morava dentro do
     * sendAPIRequest(), a montagem da URL só era observável através do cURL — e
     * o cURL DESCARTA a query de uma URL `file://`, que é o único protocolo que
     * a suíte alcança sem rede. O resultado é que apagar o VALOR do chat_id
     * (deixando `?chat_id=`) não mudava nada que um teste pudesse ver, embora
     * em produção quebrasse toda chamada. Extraído, o caso é uma asserção
     * direta.
     *
     * Só migra se for escalar (int/string, como a Bot API exige); qualquer
     * outro tipo segue no corpo do POST, onde ao menos não vira
     * "Array to string conversion".
     *
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array{0: non-empty-string, 1: array<string, mixed>}
     */
    protected function migrarChatIdParaAQuery(string $url, array $content): array
    {
        if (!isset($content['chat_id']) || !\is_scalar($content['chat_id'])) {
            return [$url, $content];
        }

        $url = $url . '?chat_id=' . \rawurlencode((string) $content['chat_id']);
        unset($content['chat_id']);

        return [$url, $content];
    }

    /**
     * Todas as opções de cURL de uma chamada à Bot API, como dado.
     *
     * É função pura de propósito. Enquanto isto era uma sequência de
     * `curl_setopt()` dentro do sendAPIRequest(), NADA na suíte conseguia
     * afirmar nenhuma delas: o único protocolo que a suíte alcança sem rede é
     * `file://`, e o cURL ignora ali o método POST, a query da URL, o proxy e
     * o handshake de TLS. Na prática, apagar
     * `curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true)` não quebrava teste
     * nenhum — e a regra de semgrep só pega o literal `false`, não a ausência
     * da linha. Como array devolvido, cada opção é uma asserção direta.
     *
     * A ordem das chaves não importa para o cURL, mas importa para a leitura:
     * transporte, corpo, proxy e, por último, as travas de segurança.
     *
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     * @return array<int, mixed>
     */
    protected function opcoesDaRequisicao(string $url, array $content, bool $post): array
    {
        // A montagem — validação do proxy incluída — mora em
        // inclusos/funcoes_curl_telegram.php; aqui entram só os valores que
        // pertencem a esta instância e a esta chamada.
        return \opcoesCurlDaBotApi(
            $url,
            $content,
            $post,
            $this->proxy,
            self::CONNECT_TIMEOUT_SECONDS,
            $this->timeoutDaRequisicaoEmSegundos($content)
        );
    }

    /**
     * @param non-empty-string $url
     * @param array<string, mixed> $content
     */
    protected function sendAPIRequest(string $url, array $content, bool $post = true): string
    {
        // Montado aqui, e não no fim do método, porque o chat_id sai de
        // $content logo abaixo. Ver contextoTecnicoDaFalha().
        $contextoDoLog = $this->contextoTecnicoDaFalha($url, $content);
        [$url, $content] = $this->migrarChatIdParaAQuery($url, $content);
        $ch = \curl_init();
        \curl_setopt_array($ch, $this->opcoesDaRequisicao($url, $content, $post));
        $result = \curl_exec($ch);
        $curlErrorCode = \curl_errno($ch);
        $curlError = \curl_error($ch);
        if (!\is_string($result)) {
            if ($this->log_errors) {
                $contextoDoLog += \App\Support\DiagnosticoTransporteTelegram::capturar($ch);
            }
            $result = \json_encode(
                [
                    'ok' => false,
                    'error_code' => $curlErrorCode,
                    'description' => 'cURL error: ' . ($curlError !== '' ? $curlError : 'unknown'),
                    'curl_error_code' => $curlErrorCode,
                    'curl_error' => $curlError
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($result === false) {
                $result = '{"ok":false,"error_code":' . $curlErrorCode . ',"description":"cURL error"}';
            }
        }
        // A partir do PHP 8.0 o cURL devolve um objeto CurlHandle: o recurso é
        // liberado sozinho quando $ch sai de escopo no fim deste método, e
        // curl_close() virou no-op (descontinuada no PHP 8.5). Por isso a
        // chamada foi removida em vez de trocada por unset().
        if ($this->log_errors) {
            if (\class_exists('TelegramErrorLogger')) {
                \TelegramErrorLogger::log(\json_decode($result, true), $contextoDoLog);
            }
        }

        return $result;
    }

}
