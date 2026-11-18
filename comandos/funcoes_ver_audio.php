<?php

declare(strict_types=1);

/**
 * Helpers de comandos/ver_audio.php.
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
require_once __DIR__ . '/funcoes_transcricao_audio.php';

require_once __DIR__ . '/funcoes_arquivo_audio.php';

/**
 * Monta o payload de áudio usando a memória rolante compartilhada pelos
 * comandos de texto, áudio e imagem.
 *
 * @param array<int, array{role: 'user'|'assistant', content: string}> $historico
 * @return array<int, array{role: string, content: string}>
 */
function montarMensagensAudioComHistorico(
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

CONTEXTO: O usuário enviou um ÁUDIO que foi transcrito automaticamente. Você está escutando o usuário.

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
function montarCorpoRequisicaoAudio(array $messages): string|false
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
function chamarChatCompletionsAudio(
    string $api_url,
    string $api_key,
    string $post_fields,
    ?callable $transporteHttp = null
): string|false {
    return executarChatCompletionsOpenAi($api_url, $api_key, $post_fields, 'chat.completions.audio', $transporteHttp);
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
 * define o contrato, fixado em testRespostaInvalidaDoAudioViraMensagemGenericaDeErro.
 *
 * @param mixed $response Corpo bruto da API (string) ou false na falha de transporte
 * @return array{tipo: 'resposta'|'erro_api'|'falha', texto: string}
 */
function interpretarRespostaAudio(mixed $response): array
{
    return interpretarRespostaDeChat($response, 'chat.completions.audio', "Erro ao gerar resposta. Tente novamente! ❌");
}

/**
 * Executa o comando /ver_audio (transcreve com Whisper e responde com o modelo de chat) de ponta a ponta.
 *
 * Mora aqui, e não no corpo de `comandos/ver_audio.php`, pelo mesmo motivo que o resto
 * deste arquivo: script de topo executa no include e nenhum teste pode incluí-lo,
 * então tudo que ficasse lá seria código de produção sem cobertura possível.
 * Como função, a suíte carrega este arquivo com `require_once` de verdade — a
 * cobertura é atribuída — e injeta os pontos que falariam com a rede.
 *
 * @param (callable(int): void)|null $esperar
 *
 * Os `?callable` têm o mesmo contrato das funções que os recebem: `null` usa o
 * cURL de produção.
 */
function executarComandoVerAudio(
    Telegram $telegram,
    Redis $redis,
    ?callable $transporteWhisper = null,
    ?callable $transporteChat = null,
    ?callable $baixadorHttp = null,
    ?string $dataHora = null,
    ?string $diretorioTemporario = null,
    ?callable $esperar = null
): void {
    $id_telegram = $telegram->UserID();
    if ($id_telegram === null) {
        return;
    }

    // Verificar se há um áudio/voice na mensagem
    $mensagem_recebida = mensagemRecebidaDeMidia($telegram);
    $chatType = tipoDeChatDaMidia($mensagem_recebida);

    $audio_recebido = audioDaMensagem($mensagem_recebida);
    if (!validarAudioDoComando($telegram, $mensagem_recebida, $audio_recebido['tem_audio'])) {
        return;
    }
    $id_telegram = idDoContextoDeMidia($telegram, $chatType, $id_telegram);

    $chave_memoria = chaveMemoriaConversa(
        $chatType,
        $telegram->UserID(),
        $telegram->ChatID(),
        idTopicoDaMensagem($mensagem_recebida)
    );
    // Obter o file_id do áudio (voice tem prioridade)
    $audio_file_id = $audio_recebido['file_id'];
    if ($audio_file_id === null) {
        avisarUsuarioAudio($telegram, "Erro ao obter informações do áudio. Tente novamente! ❌");
        return;
    }

    if (!admitirMidiaDoComando(
        $redis,
        $telegram,
        $chatType,
        sujeitoDaCotaDeAudio($telegram, $chatType, $id_telegram),
        2,
        avisarUsuarioAudio(...)
    )) {
        return;
    }

    // Obter informações do arquivo somente após admitir o trabalho de mídia.
    $file_path = caminhoDoArquivoDeMidia($telegram, $audio_file_id);
    if ($file_path === null) {
        avisarUsuarioAudio($telegram, "Erro ao obter informações do áudio. Tente novamente! ❌");
        return;
    }
    $metadados_upload = metadadosDeUploadAudio($file_path);

    // Construir URL do arquivo
    $audio_url = "https://api.telegram.org/file/bot" . TOKEN_BOT . "/" . $file_path;

    // Baixar o arquivo de áudio temporariamente
    $audio_temporario = baixarAudioParaArquivoTemporario($audio_url, $baixadorHttp, $diretorioTemporario);
    if ($audio_temporario['caminho'] === null) {
        avisarUsuarioAudio($telegram, $audio_temporario['erro']);
        return;
    }
    $temp_audio_path = $audio_temporario['caminho'];

    // Áudio faz DUAS chamadas pagas (transcrição + chat), portanto reserva duas
    // unidades atomicamente. A reserva fica depois de validar e baixar o arquivo:
    // falha do Telegram ou mídia inválida não pode consumir orçamento OpenAI.
    try {
        $info_limite = consumir_limite_diario(
            $redis,
            sujeitoDaCotaDeAudio($telegram, $chatType, $id_telegram),
            100,
            2
        );
    } catch (Throwable $erro) {
        removerAudioTemporario($temp_audio_path);
        throw $erro;
    }

    if ($info_limite['atingiu_limite']) {
        removerAudioTemporario($temp_audio_path);
        if ($chatType === 'private') {
            avisarUsuarioAudio(
                $telegram,
                "Você atingiu o limite de {$info_limite['limite_maximo']} requisições diárias. Tente novamente amanhã! 🙂"
            );
        }
        return;
    }

    try {
        if (!iniciarRespostaIa($telegram, $redis)) {
            return;
        }

        responderMidiaAudio($telegram, $redis, $id_telegram, $chave_memoria, $temp_audio_path, $metadados_upload, $transporteWhisper, $transporteChat, $dataHora, $esperar);
    } finally {
        // A perda da lease também pode interromper o typing, antes do Whisper.
        removerAudioTemporario($temp_audio_path);
    }
}

/**
 * @param array{nome: string, mime: string} $metadados_upload
 * @param (callable(int): void)|null $esperar
 */
function responderMidiaAudio(
    Telegram $telegram,
    Redis $redis,
    int $id_telegram,
    string $chave_memoria,
    string $temp_audio_path,
    array $metadados_upload,
    ?callable $transporteWhisper,
    ?callable $transporteChat,
    ?string $dataHora,
    ?callable $esperar = null
): void {
    // Chave exclusiva do OpenRouter
    $api_key = OPENROUTER_API_KEY;

    // === PASSO 1: Transcrever o áudio usando GPT Transcribe ===
    $whisper_url = 'https://openrouter.ai/api/v1/audio/transcriptions';

    try {
        $transcricao = chamarTranscricaoWhisper(
            $whisper_url,
            $api_key,
            $temp_audio_path,
            $transporteWhisper,
            $metadados_upload['nome'],
            $metadados_upload['mime']
        );
    } finally {
        // Remover arquivo temporário — inclusive quando a transcrição falha, para
        // que nenhum áudio fique esquecido no disco do servidor.
        removerAudioTemporario($temp_audio_path);
    }

    $resposta_whisper = interpretarTranscricaoWhisper($transcricao['corpo'], $transcricao['http']);
    if ($resposta_whisper['tipo'] !== 'transcricao') {
        avisarUsuarioAudio($telegram, $resposta_whisper['texto']);
        return;
    }

    $textoCortado = $resposta_whisper['texto'];

    // === PASSO 2: Processar o texto com GPT ===
    $api_url = 'https://openrouter.ai/api/v1/chat/completions';

    // Buscar o histórico novo e, se necessário, uma rodada das chaves legadas.
    [$chave_contexto, $chave_assistente] = chavesLegadasDaConversa($redis, $chave_memoria, $id_telegram);

    $historico = lerHistoricoMemoria(
        $redis,
        $chave_memoria,
        $chave_contexto,
        $chave_assistente
    );

    // Define o fuso horário para São Paulo
    date_default_timezone_set('America/Sao_Paulo');

    $messages = montarMensagensAudioComHistorico($textoCortado, $historico, $dataHora);

    // Espelho legado durante a migração; o histórico novo só recebe o turno
    // quando houver uma resposta válida.
    salvarTextoComTtl($redis, $chave_contexto, $textoCortado, ttlMemoriaConversa());

    $post_fields = montarCorpoRequisicaoAudio($messages);
    if ($post_fields === false) {
        avisarUsuarioAudio($telegram, "Erro ao gerar resposta. Tente novamente! ❌");
        return;
    }

    // Executa a chamada à API e interpreta o corpo recebido
    $response = chamarChatCompletionsAudio($api_url, $api_key, $post_fields, $transporteChat);
    $resposta_api = interpretarRespostaAudio($response);

    // Erro do transporte, erro da API e conteúdo ausente caem na mesma mensagem
    // que o comando já enviava e encerram o fluxo.
    if ($resposta_api['tipo'] !== 'resposta') {
        avisarUsuarioAudio($telegram, $resposta_api['texto']);
        return;
    }

    $resposta_chatgpt = $resposta_api['texto'];

    // Enviar apenas a resposta (como faz com texto e imagem)
    \App\Support\EntregaIaDuravel::memorizar($chave_memoria, $historico, $textoCortado, $resposta_chatgpt, $chave_assistente);
    if (!enviarRespostaIa($telegram, $redis, $resposta_chatgpt, $esperar)) {
        return;
    }

    salvarTurnoMemoria($redis, $chave_memoria, $historico, $textoCortado, $resposta_chatgpt);

    // Espelho legado da resposta para concluir a migração sem quebrar versões
    // antigas que ainda possam ler essas chaves.
    salvarTextoComTtl($redis, $chave_assistente, $resposta_chatgpt, ttlMemoriaConversa());
}

function sujeitoDaCotaDeAudio(Telegram $telegram, ?string $chatType, int $id_telegram): int
{
    return limite_diario_sujeito($chatType, $telegram->UserID() ?? $id_telegram, $telegram->ChatID());
}
