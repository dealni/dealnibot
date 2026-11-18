<?php

declare(strict_types=1);

/**
 * O passo de TRANSCRIÇÃO de comandos/ver_audio.php: metadados do multipart,
 * a chamada à Transcriptions API e a leitura do que ela devolve.
 *
 * Saiu de funcoes_ver_audio.php para aquele arquivo caber no teto de linhas
 * do `composer check:complexity`; o fluxo do comando (executarComandoVerAudio)
 * continua lá e carrega este irmão com require_once — o segundo include é
 * no-op, como exige o `require` repetido do roteador.
 *
 * Só declara funções — nenhum efeito no include —, então os testes podem
 * carregar este arquivo direto, sem o tokenizer.
 */

require_once __DIR__ . '/funcoes_ia_comuns.php';
require_once __DIR__ . '/funcoes_http_openai.php';

/**
 * Traduz a extensão devolvida pelo Telegram para metadados do multipart da
 * Transcriptions API. O arquivo temporário não tem extensão; sem este passo,
 * um MP3/M4A seria anunciado como OGG e o provedor poderia rejeitar o áudio
 * apesar de os bytes estarem íntegros.
 *
 * `.oga` é a extensão usada por notas de voz do Telegram, mas a documentação
 * da OpenAI lista `ogg`; por isso o nome enviado fica `audio.ogg` e o MIME é
 * `audio/ogg`.
 *
 * @return array{nome: string, mime: string}
 */
function metadadosDeUploadAudio(string $filePath): array
{
    $extensao = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $formatos = [
        'flac' => ['nome' => 'audio.flac', 'mime' => 'audio/flac'],
        'mp3' => ['nome' => 'audio.mp3', 'mime' => 'audio/mpeg'],
        'mp4' => ['nome' => 'audio.mp4', 'mime' => 'audio/mp4'],
        'mpeg' => ['nome' => 'audio.mpeg', 'mime' => 'audio/mpeg'],
        'mpga' => ['nome' => 'audio.mpga', 'mime' => 'audio/mpeg'],
        'm4a' => ['nome' => 'audio.m4a', 'mime' => 'audio/mp4'],
        'oga' => ['nome' => 'audio.ogg', 'mime' => 'audio/ogg'],
        'ogg' => ['nome' => 'audio.ogg', 'mime' => 'audio/ogg'],
        'wav' => ['nome' => 'audio.wav', 'mime' => 'audio/wav'],
        'webm' => ['nome' => 'audio.webm', 'mime' => 'audio/webm'],
    ];

    return $formatos[$extensao] ?? ['nome' => 'audio.ogg', 'mime' => 'audio/ogg'];
}

/**
 * Envia o arquivo temporário ao GPT Transcribe e devolve o corpo bruto da resposta
 * com o código HTTP. Falha de transporte (timeout, DNS, conexão recusada)
 * devolve corpo false e código 0.
 *
 * @param string $temp_audio_path Arquivo temporário já gravado em disco
 * @param callable|null $transporteHttp Substitui o cURL nos testes:
 *        fn(string $url, array<string, mixed> $campos, array<int, string> $cabecalhos): mixed.
 *        Nulo (padrão) é o caminho de produção, com cURL real.
 * @param string|null $nomeArquivo Nome com extensão para o multipart; fallback `audio.ogg`.
 * @param string|null $mimeType MIME correspondente ao nome; fallback `audio/ogg`.
 * @return array{corpo: string|false, http: int}
 */
function chamarTranscricaoWhisper(
    string $whisper_url,
    string $api_key,
    string $temp_audio_path,
    ?callable $transporteHttp = null,
    ?string $nomeArquivo = null,
    ?string $mimeType = null
): array {
    // O endpoint precisa de metadados suficientes para identificar o formato;
    // `audio.ogg` continua sendo o fallback para notas de voz, mas o fluxo
    // passa a preservar a extensão do arquivo de áudio recebido quando ela é
    // conhecida (por exemplo, MP3 ou M4A).
    renovarLeaseDaInboxAtiva();
    $cfile = new CURLFile(
        $temp_audio_path,
        $mimeType ?? 'audio/ogg',
        $nomeArquivo ?? 'audio.ogg'
    );

    $campos = [
        'file' => $cfile,
        'model' => 'openai/whisper-large-v3-turbo', // Modelo atual para transcrição de áudio gravado
        // Multipart exige preferências de provedor como objeto JSON em texto.
        'provider' => '{"allow_fallbacks":true,"data_collection":"deny","ignore":["openai"]}',
        'language' => 'pt', // Português
        'response_format' => 'json',
        'prompt' => 'Transcrição de áudio em português do Brasil. Mantenha a pontuação e capitalize corretamente.'
    ];

    $cabecalhos = [
        'Authorization: Bearer ' . $api_key
    ];

    if ($transporteHttp !== null) {
        $devolvido = $transporteHttp($whisper_url, $campos, $cabecalhos);
        $corpo = is_array($devolvido) ? ($devolvido['corpo'] ?? false) : false;
        $codigo = is_array($devolvido) && isset($devolvido['http']) && is_int($devolvido['http'])
            ? $devolvido['http']
            : 0;

        return ['corpo' => is_string($corpo) ? $corpo : false, 'http' => $codigo];
    }

    return executarRequisicaoOpenAi(
        $whisper_url,
        $campos,
        $cabecalhos,
        'audio.transcriptions',
        120
    );
}

/**
 * Interpreta o corpo devolvido pelo GPT Transcribe e decide o que o comando faz:
 *   - 'transcricao': texto transcrito já limitado por cortarMensagem();
 *   - 'falha': transporte falhou, HTTP diferente de 200 ou corpo sem texto
 *     utilizável (chave ausente, nula ou string vazia).
 *
 * @param string|false $whisper_response Corpo bruto ou false na falha de transporte
 * @return array{tipo: 'transcricao'|'falha', texto: string}
 */
function interpretarTranscricaoWhisper(string|false $whisper_response, int $whisper_http_code): array
{
    if (!is_string($whisper_response)) {
        return [
            'tipo' => 'falha',
            'texto' => "Erro ao transcrever o áudio. Tente novamente! ❌",
        ];
    }

    // json_decode devolve mixed; se não vier um array (JSON inválido, "null", etc.)
    // tratamos como resposta vazia, que já caía no mesmo ramo de erro do isset().
    $whisper_data_bruto = json_decode($whisper_response, true);
    $whisper_data = is_array($whisper_data_bruto) ? $whisper_data_bruto : [];

    // Só é transcrição quando vem texto de verdade. Ausente, nulo, de outro
    // tipo ou vazio (inclusive apenas espaços) conta como falha:
    //   - áudio sem fala (silêncio, ruído, microfone mudo) faz o GPT Transcribe
    //     responder 200 com texto vazio, e mandar isso ao chat/completions
    //     gastaria a chamada PAGA com uma mensagem de usuário sem nada dentro,
    //     faria o modelo responder ao contexto antigo como se o áudio tivesse
    //     dito aquilo e ainda gravaria '' como contexto, apagando a conversa;
    //   - texto de outro tipo (objeto/número) virava o aviso fixo de
    //     cortarMensagem(), e era ESSE aviso que seguia como se fosse a fala do
    //     usuário — chamada paga com lixo e contexto sujo.
    // O comando de texto já recusa entrada vazia da mesma forma
    // (comandos/chatgpt.php). Comparação com '' e não empty(): a transcrição
    // "0" (o usuário falou "zero") é conteúdo legítimo e continua passando;
    // trim() não o transforma em vazio.
    $texto_transcrito = $whisper_data['text'] ?? null;

    if ($whisper_http_code !== 200 || !is_string($texto_transcrito) || trim($texto_transcrito) === '') {
        // Mensagem de erro da OpenAI: só usa se vier escalar, senão mantém o padrão.
        $erro_msg = 'Erro desconhecido na transcrição';
        $erro_whisper = isset($whisper_data['error']) ? $whisper_data['error'] : null;
        if (is_array($erro_whisper) && isset($erro_whisper['message']) && is_scalar($erro_whisper['message'])) {
            $erro_msg = (string) $erro_whisper['message'];
        }

        registrarErroDaApiOpenAi('audio.transcriptions', $erro_msg);

        return ['tipo' => 'falha', 'texto' => "Erro ao transcrever o áudio. Tente novamente! ❌"];
    }

    return ['tipo' => 'transcricao', 'texto' => cortarMensagem($texto_transcrito)];
}
