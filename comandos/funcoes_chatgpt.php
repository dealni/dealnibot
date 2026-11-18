<?php

declare(strict_types=1);

/**
 * Funções de comandos/chatgpt.php.
 *
 * Separadas do script porque roteador.php inclui o comando com `require`
 * puro dentro do laço de bot.php sobre o lote de updates: com as declarações
 * no próprio comando, dois updates roteados para cá no mesmo lote abortavam o
 * processo com "Cannot redeclare function". Carregado com require_once, o
 * segundo include é no-op.
 *
 * Só declara funções — nenhum efeito no include —, então os testes podem
 * carregar este arquivo direto, sem o tokenizer.
 */

require_once __DIR__ . '/funcoes_ia_comuns.php';
require_once __DIR__ . '/funcoes_http_openai.php';
require_once __DIR__ . '/funcoes_respostas_ia.php';

/**
 * Versão usada pelo fluxo de produção: envia todos os turnos válidos da
 * memória rolante antes da mensagem atual.
 *
 * @param array<int, array{role: 'user'|'assistant', content: string}> $historico
 * @return array<int, array{role: string, content: string}>
 */
function montarMensagensChatgptComHistorico(
    string $textoCortado,
    array $historico,
    ?string $dataHora = null
): array {
    $messages = [
        ["role" => "developer", "content" => "Você é Dealni, um gato inteligente do Telegram.

CAPACIDADES:
- Processar mensagens de texto
- Analisar e descrever imagens
- Ouvir e transcrever áudios

INSTRUÇÕES:
- Responda no mesmo idioma do usuário
- Use emojis de forma natural e moderada
- Seja útil, amigável e direto

Data e hora em São Paulo: " . ($dataHora ?? date('d/m/Y H:i:s'))]
    ];

    foreach (normalizarHistoricoMemoria($historico) as $mensagem) {
        $messages[] = $mensagem;
    }

    $messages[] = ["role" => "user", "content" => $textoCortado];

    return $messages;
}

/**
 * Corpo JSON da requisição ao chat/completions. Devolve false quando o
 * json_encode falha (o comando avisa o usuário e encerra).
 *
 * @param array<int, array{role: string, content: string}> $messages
 */
function montarCorpoRequisicaoChatgpt(array $messages): string|false
{
    return json_encode([
        'model' => 'openai/gpt-6-luna',
        'provider' => ['allow_fallbacks' => true, 'data_collection' => 'deny', 'ignore' => ['openai']],
        'messages' => $messages,
        'temperature' => 1,
        'max_completion_tokens' => 2048,
        'reasoning' => [
            'effort' => 'xhigh',
            'exclude' => true,
        ],
    ]);
}

/**
 * Executa a chamada à API da OpenAI e devolve o corpo bruto da resposta;
 * false em qualquer erro de transporte (timeout, DNS, conexão recusada).
 *
 * @param callable|null $transporteHttp Substitui o cURL nos testes:
 *        fn(string $url, string $corpo, array<int, string> $cabecalhos): mixed.
 *        Nulo (padrão) é o caminho de produção, com cURL real.
 */
function chamarChatCompletionsOpenAi(
    string $api_url,
    string $api_key,
    string $post_fields,
    ?callable $transporteHttp = null
): string|false {
    return executarChatCompletionsOpenAi($api_url, $api_key, $post_fields, 'chat.completions.texto', $transporteHttp);
}

/**
 * Interpreta o corpo devolvido pela OpenAI e decide o que o comando envia:
 *   - 'resposta': texto do assistente já limitado por cortarMensagem();
 *   - 'erro_api': a API respondeu com o objeto error;
 *   - 'falha': transporte falhou (timeout/false) ou o corpo não traz conteúdo.
 *
 * Continua recebendo `mixed`, e não `string|false`: em modo fraco um
 * `string|false` converteria 500 em "500" e faria TypeError com nulo ou array,
 * trocando a mensagem de erro por uma exceção. A guarda is_string() é que
 * define o contrato, fixado em testRespostaInvalidaViraMensagemGenericaDeErro.
 *
 * @param mixed $response Corpo bruto da API (string) ou false na falha de transporte
 * @return array{tipo: 'resposta'|'erro_api'|'falha', texto: string}
 */
function interpretarRespostaChatgpt(mixed $response): array
{
    return interpretarRespostaDeChat($response, 'chat.completions', "Erro ao gerar resposta. Tente novamente! ❌");
}

/**
 * Executa o comando /chatgpt de ponta a ponta: portão de cota, memória rolante
 * no Redis, chamada ao chat/completions e resposta ao usuário.
 *
 * Mora aqui, e não no corpo de `comandos/chatgpt.php`, pelo mesmo motivo que o
 * resto deste arquivo: script de topo executa no include e nenhum teste pode
 * incluí-lo, então tudo que ficasse lá seria código de produção sem cobertura
 * possível. Como função, a suíte carrega este arquivo com `require_once` de
 * verdade — a cobertura é atribuída — e injeta o transporte, que é o único
 * ponto que falaria com a rede.
 *
 * O `$transporteHttp` tem o mesmo contrato de chamarChatCompletionsOpenAi():
 * `null` usa o cURL de produção.
 */
function executarComandoChatgpt(
    Telegram $telegram,
    Redis $redis,
    ?callable $transporteHttp = null,
    ?string $dataHora = null
): void {
    $id_telegram = $telegram->UserID();
    if ($id_telegram === null) {
        return;
    }
    $texto_recebido = $telegram->Text();
    $mensagem_recebida = mensagemRecebidaDoChatgpt($telegram);
    $chatType = tipoDeChatDoChatgpt($telegram);

    // Comparação explícita: uma mensagem que seja só "0" é falsy e o bot ficava mudo.
    if ($texto_recebido === null || $texto_recebido === '') {
        return;
    }

    // Portão de cota: SEMPRE consome, em privado ou em grupo. Quem paga sai de
    // limite_diario_sujeito() — o usuário no privado, o próprio grupo em
    // grupo/supergrupo. O aviso, porém, só vai no privado: repetir "você atingiu o
    // limite" a cada menção pelo resto do dia transformaria o bot em ruído no grupo.
    $info_limite = consumir_limite_diario(
        $redis,
        limite_diario_sujeito($chatType, $id_telegram, $telegram->ChatID()),
        100
    );

    if ($info_limite['atingiu_limite']) {
        if ($chatType === 'private') {
            $telegram->sendMessage([
                'chat_id' => $telegram->ChatID(),
                'text' => "Você atingiu o limite de {$info_limite['limite_maximo']} requisições diárias. Tente novamente amanhã! 🙂",
                'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
            ]);
        }
        return;
    }

    // O "digitando…" vai DEPOIS do portão de cota, e não antes: em grupo o aviso
    // de limite estourado é omitido de propósito, então com a ordem invertida o
    // bot ficava eternamente "digitando…" pelo resto do dia — a única coisa que o
    // grupo via era um bot que promete resposta e nunca responde.
    if (!iniciarRespostaIa($telegram, $redis)) {
        return;
    }

    // Endpoint de chat do OpenRouter
    $api_url = 'https://openrouter.ai/api/v1/chat/completions';
    // Chave exclusiva do OpenRouter
    $api_key = OPENROUTER_API_KEY;

    $id_telegram = idDoContextoChatgpt($telegram, $chatType, $id_telegram);

    $chave_memoria = chaveMemoriaConversa(
        $chatType,
        $telegram->UserID(),
        $telegram->ChatID(),
        idTopicoDaMensagem($mensagem_recebida)
    );
    // Chaves antigas mantidas somente durante a migração. O modelo passa a ler
    // e gravar a memória rolante em $chave_memoria.
    [$chave_contexto, $chave_assistente] = chavesLegadasDaConversa($redis, $chave_memoria, $id_telegram);

    $historico = lerHistoricoMemoria(
        $redis,
        $chave_memoria,
        $chave_contexto,
        $chave_assistente
    );

    $textoCortado = cortarMensagem($texto_recebido);

    // Define o fuso horário para São Paulo
    date_default_timezone_set('America/Sao_Paulo');

    $messages = montarMensagensChatgptComHistorico($textoCortado, $historico, $dataHora);

    // Espelho legado: permite que uma versão antiga seja substituída sem
    // quebrar conversas já existentes. O histórico novo só recebe o turno
    // depois de uma resposta válida do modelo.
    salvarTextoComTtl($redis, $chave_contexto, $textoCortado, ttlMemoriaConversa());

    $post_fields = montarCorpoRequisicaoChatgpt($messages);
    if ($post_fields === false) {
        $telegram->sendMessage([
            'chat_id' => $telegram->ChatID(),
            'text' => "Erro ao gerar resposta. Tente novamente! ❌",
            'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
        ]);
        return;
    }

    // Executa a chamada à API e interpreta o corpo recebido
    $response = chamarChatCompletionsOpenAi($api_url, $api_key, $post_fields, $transporteHttp);
    $resposta_api = interpretarRespostaChatgpt($response);

    // Resposta da api do ChatGPT: erro do transporte, erro da API e conteúdo
    // ausente caem na mesma mensagem que o comando já enviava e encerram o fluxo.
    if ($resposta_api['tipo'] !== 'resposta') {
        $telegram->sendMessage([
            'chat_id' => $telegram->ChatID(),
            'text' => $resposta_api['texto'],
            'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
        ]);
        return;
    }

    $resposta_chatgpt = $resposta_api['texto'];

    \App\Support\EntregaIaDuravel::memorizar($chave_memoria, $historico, $textoCortado, $resposta_chatgpt, $chave_assistente);
    if (!enviarRespostaIa($telegram, $redis, $resposta_chatgpt)) {
        return;
    }

    salvarTurnoMemoria($redis, $chave_memoria, $historico, $textoCortado, $resposta_chatgpt);

    salvarTextoComTtl($redis, $chave_assistente, $resposta_chatgpt, ttlMemoriaConversa());
}

/** @return array<array-key, mixed> */
function mensagemRecebidaDoChatgpt(Telegram $telegram): array
{
    $dados = $telegram->getData();
    return isset($dados['message']) && is_array($dados['message']) ? $dados['message'] : [];
}

function tipoDeChatDoChatgpt(Telegram $telegram): ?string
{
    $chat = $telegram->Chat() ?? [];
    return isset($chat['type']) && is_string($chat['type']) ? $chat['type'] : null;
}

function idDoContextoChatgpt(Telegram $telegram, ?string $chatType, int $id_telegram): int
{
    if ($chatType === 'group' || $chatType === 'supergroup') {
        $chatId = $telegram->ChatID();
        if ($chatId !== null) {
            $id_telegram = $chatId;
        }
    }

    return $id_telegram;
}
