<?php

declare(strict_types=1);

/**
 * Helpers de comandos/ver_imagem.php.
 *
 * Separadas do script porque roteador.php inclui o comando com `require`
 * puro dentro do laço de bot.php sobre o lote de updates: com as declarações
 * no próprio comando, dois updates roteados para cá no mesmo lote abortavam o
 * processo com "Cannot redeclare function". Carregado com require_once, o
 * segundo include é no-op.
 * Só declara funções; o teste carrega o arquivo sem executar o comando.
 */

require_once __DIR__ . '/funcoes_ia_comuns.php';
require_once __DIR__ . '/funcoes_http_openai.php';
require_once __DIR__ . '/funcoes_respostas_ia.php';
require_once __DIR__ . '/funcoes_admissao_midia.php';

require_once __DIR__ . '/funcoes_arquivo_imagem.php';

/**
 * Monta o payload de imagem com o histórico textual rolante. A imagem atual
 * continua sendo enviada somente na mensagem corrente; URLs antigas não são
 * persistidas no Redis porque podem expirar.
 *
 * @param array<int, array{role: 'user'|'assistant', content: string}> $historico
 * @return array<int, array{role: string, content: string|array<int, array<string, mixed>>}>
 */
function montarMensagensImagemComHistorico(
    string $caption,
    string $image_url,
    array $historico,
    ?string $dataHora = null
): array {
    $messages = [
        [
            "role" => "developer",
            "content" => "Você é Dealni, um gato inteligente do Telegram.

CAPACIDADES:
- Processar mensagens de texto
- Analisar e descrever imagens
- Ouvir e transcrever áudios

CONTEXTO: O usuário enviou uma IMAGEM. Você está vendo a imagem agora.

INSTRUÇÕES:
- Responda no mesmo idioma do usuário
- Use emojis de forma natural e moderada
- Seja útil, amigável e direto
- Se algo não estiver visível ou claro na imagem, diga explicitamente

Data e hora em São Paulo: " . ($dataHora ?? date('d/m/Y H:i:s'))
        ]
    ];

    foreach (normalizarHistoricoMemoria($historico) as $mensagem) {
        $messages[] = $mensagem;
    }

    $messages[] = [
        "role" => "user",
        "content" => [
            [
                "type" => "text",
                "text" => $caption
            ],
            [
                "type" => "image_url",
                "image_url" => [
                    "url" => $image_url,
                    "detail" => "high"
                ]
            ]
        ]
    ];

    return $messages;
}

/**
 * Corpo JSON da requisição ao chat/completions. Devolve false quando o
 * json_encode falha (o comando avisa o usuário e encerra).
 *
 * @param array<int, array{role: string, content: string|array<int, array<string, mixed>>}> $messages
 */
function montarCorpoRequisicaoImagem(array $messages): string|false
{
    return json_encode([
        'model' => 'openai/gpt-6-luna', // Modelo atual com suporte a visão
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
function chamarChatCompletionsImagem(
    string $api_url,
    string $api_key,
    string $post_fields,
    ?callable $transporteHttp = null
): string|false {
    return executarChatCompletionsOpenAi($api_url, $api_key, $post_fields, 'chat.completions.imagem', $transporteHttp);
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
 * define o contrato, fixado em testRespostaInvalidaDaImagemViraMensagemGenericaDeErro.
 *
 * @param mixed $response Corpo bruto da API (string) ou false na falha de transporte
 * @return array{tipo: 'resposta'|'erro_api'|'falha', texto: string}
 */
function interpretarRespostaImagem(mixed $response): array
{
    return interpretarRespostaDeChat($response, 'chat.completions.imagem', "Erro ao analisar a imagem. Tente novamente! ❌");
}

/**
 * Executa o comando /ver_imagem (descreve a foto com o modelo de visão) de ponta a ponta.
 *
 * Mora aqui, e não no corpo de `comandos/ver_imagem.php`, pelo mesmo motivo que o resto
 * deste arquivo: script de topo executa no include e nenhum teste pode incluí-lo,
 * então tudo que ficasse lá seria código de produção sem cobertura possível.
 * Como função, a suíte carrega este arquivo com `require_once` de verdade — a
 * cobertura é atribuída — e injeta os pontos que falariam com a rede.
 *
 * Os `?callable` têm o mesmo contrato das funções que os recebem: `null` usa o
 * cURL de produção.
 */
function executarComandoVerImagem(
    Telegram $telegram,
    Redis $redis,
    ?callable $transporteChat = null,
    ?callable $baixadorHttp = null,
    ?string $dataHora = null
): void {
    $id_telegram = $telegram->UserID();
    if ($id_telegram === null) {
        return;
    }

    // Verificar se há uma imagem na mensagem
    $mensagem_recebida = mensagemRecebidaDeMidia($telegram);
    $chatType = tipoDeChatDaMidia($mensagem_recebida);

    $imagem_recebida = imagemDaMensagem($mensagem_recebida);
    $caption_original = isset($mensagem_recebida['caption']) && is_string($mensagem_recebida['caption'])
        ? $mensagem_recebida['caption']
        : "O que você vê nesta imagem?";
    // Limitar caption a 1024 caracteres (limite do Telegram para captions)
    $caption = cortarMensagem($caption_original, 1024);

    if (!$imagem_recebida['tem_imagem']) {
        avisarUsuarioImagem($telegram, "Por favor, envie uma imagem para que eu possa analisá-la! 📸");
        return;
    }

    $chave_memoria = chaveMemoriaConversa(
        $chatType,
        $telegram->UserID(),
        $telegram->ChatID(),
        idTopicoDaMensagem($mensagem_recebida)
    );
    $photo_file_id = $imagem_recebida['file_id'];
    if ($photo_file_id === null) {
        avisarUsuarioImagem($telegram, "Erro ao obter informações da imagem. Tente novamente! ❌");
        return;
    }

    if (!admitirMidiaDoComando(
        $redis,
        $telegram,
        $chatType,
        limite_diario_sujeito($chatType, $id_telegram, $telegram->ChatID()),
        1,
        avisarUsuarioImagem(...)
    )) {
        return;
    }

    // Obter informações do arquivo somente após admitir o trabalho de mídia.
    $file_path = caminhoDoArquivoDeMidia($telegram, $photo_file_id);
    if ($file_path === null) {
        avisarUsuarioImagem($telegram, "Erro ao obter informações da imagem. Tente novamente! ❌");
        return;
    }

    // A imagem vai para a OpenAI como data: URI em base64, baixada AQUI — nunca
    // como a URL de download do Telegram. Aquela URL carrega o TOKEN_BOT COMPLETO
    // (`/file/bot<TOKEN>/<file_path>`), e mandá-la no corpo da requisição entregava
    // o token a um terceiro em toda mensagem com foto. Ver baixarImagemComoDataUri().
    $imagem_baixada = baixarImagemComoDataUri(
        "https://api.telegram.org/file/bot" . TOKEN_BOT . "/" . $file_path,
        $baixadorHttp
    );
    if ($imagem_baixada['data_uri'] === null) {
        avisarUsuarioImagem($telegram, $imagem_baixada['erro']);
        return;
    }
    // O nome segue o campo da OpenAI que ele alimenta (`image_url.url`), que
    // aceita tanto URL quanto data: URI.
    $image_url = $imagem_baixada['data_uri'];

    // Só consome depois de a mídia estar validada e pronta para a chamada paga.
    $info_limite = consumir_limite_diario(
        $redis,
        limite_diario_sujeito($chatType, $id_telegram, $telegram->ChatID()),
        100
    );

    if ($info_limite['atingiu_limite']) {
        if ($chatType === 'private') {
            avisarUsuarioImagem(
                $telegram,
                "Você atingiu o limite de {$info_limite['limite_maximo']} requisições diárias. Tente novamente amanhã! 🙂"
            );
        }
        return;
    }

    if (!iniciarRespostaIa($telegram, $redis)) {
        return;
    }

    responderMidiaImagem($telegram, $redis, $id_telegram, $chatType, $chave_memoria, $caption, $image_url, $transporteChat, $dataHora);
}

/** Processa somente a imagem validada e com orçamento reservado. */
function responderMidiaImagem(
    Telegram $telegram,
    Redis $redis,
    int $id_telegram,
    ?string $chatType,
    string $chave_memoria,
    string $caption,
    string $image_url,
    ?callable $transporteChat,
    ?string $dataHora
): void {
    // Endpoint de chat do OpenRouter
    $api_url = 'https://openrouter.ai/api/v1/chat/completions';
    // Chave exclusiva do OpenRouter
    $api_key = OPENROUTER_API_KEY;

    if ($chatType === 'group' || $chatType === 'supergroup') {
        $chatId = $telegram->ChatID();
        if ($chatId !== null) {
            $id_telegram = $chatId;
        }
    }

    // Buscar contexto anterior do Redis
    [$chave_contexto, $chave_assistente] = chavesLegadasDaConversa($redis, $chave_memoria, $id_telegram);

    $historico = lerHistoricoMemoria(
        $redis,
        $chave_memoria,
        $chave_contexto,
        $chave_assistente
    );

    // Define o fuso horário para São Paulo
    date_default_timezone_set('America/Sao_Paulo');

    $messages = montarMensagensImagemComHistorico($caption, $image_url, $historico, $dataHora);

    // Salvar no Redis
    salvarTextoComTtl($redis, $chave_contexto, $caption, ttlMemoriaConversa());

    $post_fields = montarCorpoRequisicaoImagem($messages);
    if ($post_fields === false) {
        avisarUsuarioImagem($telegram, "Erro ao analisar a imagem. Tente novamente! ❌");
        return;
    }

    // Executa a chamada à API e interpreta o corpo recebido
    $response = chamarChatCompletionsImagem($api_url, $api_key, $post_fields, $transporteChat);
    $resposta_api = interpretarRespostaImagem($response);

    // Erro do transporte, erro da API e conteúdo ausente caem na mesma mensagem
    // que o comando já enviava e encerram o fluxo.
    if ($resposta_api['tipo'] !== 'resposta') {
        avisarUsuarioImagem($telegram, $resposta_api['texto']);
        return;
    }

    $resposta_chatgpt = $resposta_api['texto'];

    \App\Support\EntregaIaDuravel::memorizar($chave_memoria, $historico, $caption, $resposta_chatgpt, $chave_assistente);
    if (!enviarRespostaIa($telegram, $redis, $resposta_chatgpt)) {
        return;
    }

    salvarTurnoMemoria($redis, $chave_memoria, $historico, $caption, $resposta_chatgpt);

    // Salvar resposta do assistente no Redis legado durante a migração
    salvarTextoComTtl($redis, $chave_assistente, $resposta_chatgpt, ttlMemoriaConversa());
}
