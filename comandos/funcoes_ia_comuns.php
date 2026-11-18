<?php

declare(strict_types=1);

/**
 * Helpers compartilhados pelos três comandos de IA (chatgpt, ver_audio e
 * ver_imagem).
 *
 * Vivem aqui, e não no topo de cada comando, porque roteador.php inclui o
 * comando escolhido com `require` puro DENTRO do laço de bot.php sobre o lote
 * de updates. Com as declarações no comando, duas mensagens de IA no mesmo
 * lote — o mesmo comando duas vezes ou dois comandos diferentes — abortavam o
 * processo com "Cannot redeclare function", e o lote já tinha sido confirmado
 * no getUpdates(): as mensagens restantes sumiam sem resposta. Carregado com
 * require_once, o segundo include é no-op.
 *
 * A memória conversacional (chave, histórico e gravação CAS) mora no irmão
 * funcoes_memoria_conversa.php, carregado aqui para nenhum comando precisar
 * conhecer a divisão.
 */

require_once __DIR__ . '/funcoes_memoria_conversa.php';
require_once __DIR__ . '/funcoes_entrega_ia.php';

/** Includes avulsos dos helpers não abrem uma reserva; a inbox carrega a classe. */
function renovarLeaseDaInboxAtiva(): void
{
    if (class_exists(\App\Support\LeaseWebhook::class)) {
        \App\Support\LeaseWebhook::renovar();
    }
}

/**
 * Envia chat/completions com o mesmo contrato para texto, áudio e imagem.
 *
 * @param callable(string, string, array<int, string>): mixed|null $transporteHttp
 */
function executarChatCompletionsOpenAi(
    string $url,
    string $chaveApi,
    string $corpo,
    string $contexto,
    ?callable $transporteHttp = null
): string|false {
    renovarLeaseDaInboxAtiva();
    $cabecalhos = ['Content-Type: application/json', 'Authorization: Bearer ' . $chaveApi];
    if ($transporteHttp !== null) {
        $resposta = $transporteHttp($url, $corpo, $cabecalhos);

        return is_string($resposta) ? $resposta : false;
    }

    return executarRequisicaoOpenAi($url, $corpo, $cabecalhos, $contexto)['corpo'];
}

/**
 * A ação é transitória; apenas recusa definitiva impede a chamada paga, e ela
 * fica lembrada no Redis para o roteador nem chamar a API desse chat de novo
 * (inclusos/funcoes_recusa_de_escrita.php).
 */
function iniciarRespostaIa(Telegram $telegram, Redis $redis): bool
{
    return !lembrarRecusaDeEscrita($redis, $telegram->ChatID(), $telegram->sendChatAction([
        'chat_id' => $telegram->ChatID(),
        'action' => 'typing',
    ]));
}

/**
 * Detecta delimitadores simples e código sem fechamento, inclusive após
 * cortarMensagem(). Links e escapes mantêm a decisão da Bot API com fallback.
 */
function respostaIaTemMarcadorAberto(string $texto): bool
{
    if (strpbrk($texto, "[\\") !== false) {
        return false;
    }

    // Blocos completos podem conter marcadores literais. A alternativa de
    // código inline exige conteúdo para não consumir partes de uma cerca ```.
    $semCodigo = preg_replace('/```[\\s\\S]*?```|`[^`]+`/u', '', $texto) ?? $texto;

    return str_contains($semCodigo, '`')
        || substr_count($semCodigo, '*') % 2 !== 0
        || substr_count($semCodigo, '_') % 2 !== 0;
}

/** @param array<mixed> $resultado */
function respostaIaRecusaFormatacao(array $resultado): bool
{
    return ($resultado['error_code'] ?? null) === 400
        && is_string($resultado['description'] ?? null)
        && str_contains($resultado['description'], "can't parse entities");
}

/** @param array<mixed> $resultado */
function segundosDeRetentativaRespostaIa(array $resultado): ?int
{
    if (($resultado['ok'] ?? null) !== false || ($resultado['error_code'] ?? null) !== 429) {
        return null;
    }
    $parametros = $resultado['parameters'] ?? null;
    $atraso = is_array($parametros) ? ($parametros['retry_after'] ?? null) : null;

    return is_int($atraso) && $atraso >= 1 && $atraso <= 60 ? $atraso : null;
}

/**
 * Limita a mensagem ao tamanho aceito pelo Telegram, cortando no último espaço
 * quando houver um depois do início.
 *
 * O parâmetro é `mixed` de propósito, e não `string`: o conteúdo chega de
 * choices[0].message.content da OpenAI, que é JSON arbitrário. Qualquer valor
 * não textual (nulo, número, objeto) vira o aviso fixo em vez de quebrar o
 * sendMessage — comportamento fixado em ComandosChatgptHelpersTest.
 *
 * @param mixed $mensagem Conteúdo devolvido pela API (string no uso normal)
 * @param int $limite Máximo de caracteres UTF-8
 */
function cortarMensagem(mixed $mensagem, int $limite = 4096): string
{
    // A API deveria devolver UTF-8 válido, mas a string também pode vir de um
    // dublê, de memória corrompida ou de uma resposta malformada. As funções
    // mb_* substituem bytes inválidos silenciosamente (e a função retornava a
    // string intacta no ramo curto), fazendo a validade do JSON depender do
    // tamanho. Rejeitar antes de contar/cortar mantém a saída textual válida e
    // evita a classe de "Invalid Encoding Attack" descrita na documentação do
    // mbstring.
    if (!is_string($mensagem) || !mb_check_encoding($mensagem, 'UTF-8')) {
        return "Erro ao gerar mensagem.";
    }

    // mb_substr() aceita comprimento negativo como "até o fim menos N"; isso
    // não representa um teto de caracteres. Limite negativo é argumento
    // inválido para este helper, assim como um conteúdo não textual.
    if ($limite < 0) {
        return "Erro ao gerar mensagem.";
    }
    // Se a mensagem for menor ou igual ao limite, retorna ela mesma
    if (mb_strlen($mensagem, 'UTF-8') <= $limite) {
        return $mensagem;
    }
    // Corta a mensagem para respeitar o limite de caracteres UTF-8
    $mensagemCortada = mb_substr($mensagem, 0, $limite, 'UTF-8');
    // Encontra a última ocorrência de um espaço na mensagem cortada
    $ultimoEspaco = mb_strrpos($mensagemCortada, ' ', 0, 'UTF-8');
    // Se um espaço foi encontrado depois do início, corta a mensagem até lá;
    // com o único espaço na posição 0 o corte devolveria string vazia, então
    // mantém o corte duro no limite para nunca retornar mensagem vazia
    if ($ultimoEspaco !== false && $ultimoEspaco > 0) {
        return mb_substr($mensagemCortada, 0, $ultimoEspaco, 'UTF-8');
    }
    return $mensagemCortada;
}

/**
 * Segundos que o download de um arquivo do Telegram (o áudio de ver_audio, a
 * imagem de ver_imagem) pode consumir.
 *
 * O timeout do antigo stream limitava inatividade, permitindo que um servidor
 * enviasse bytes lentamente por tempo ilimitado. cURL aplica este teto ao
 * download inteiro. O trabalho roda depois do ACK: track_finished=no desliga
 * o timeout do pool nessa fase, e o Telegram já não reentrega. O prazo total
 * evita ocupar o filho do FPM indefinidamente e ultrapassar a lease da inbox,
 * o que permitiria outro worker cobrar e responder ao mesmo update de novo.
 *
 * 20 s é folgado para o que o Telegram entrega aqui: foto comprimida (dezenas
 * ou poucas centenas de KB) e nota de voz (raramente mais de 1 MB).
 */
function segundosDeDownloadDoTelegram(): int
{
    return 20;
}

/**
 * Opções de cURL do download do Telegram. Separada de
 * baixarArquivoDoTelegram() para o teto ser verificável sem rede.
 *
 * CURLOPT_TIMEOUT limita a transferência inteira, mesmo se o servidor
 * continuar enviando bytes lentamente. O timeout do stream era de inatividade.
 * @return array<int, int|bool>
 */
function opcoesDeDownloadDoTelegram(int $timeout = 20): array
{
    return [
        CURLOPT_HTTPGET => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => min(10, max(1, $timeout)),
        CURLOPT_TIMEOUT => max(1, $timeout),
    ];
}

/**
 * Baixa um arquivo do Telegram com teto de tempo explícito e, opcionalmente,
 * de tamanho.
 *
 * Lê no máximo `$maxBytes` bytes. Os fluxos passam seu limite de mídia MAIS
 * um byte: assim distinguem arquivo dentro do limite de arquivo maior,
 * recusando o excesso em vez de mandar uma mídia truncada para a chamada paga.
 *
 * @param string $url URL do arquivo em api.telegram.org
 * @param int|null $maxBytes Máximo de bytes lidos; nulo lê o arquivo inteiro
 * @return string|false Conteúdo, ou false em qualquer falha de transporte
 */
function baixarArquivoDoTelegram(string $url, ?int $maxBytes = null): string|false
{
    return baixarArquivoTelegramComPrazoTotal($url, $maxBytes, segundosDeDownloadDoTelegram());
}

/** Prazo injetável para validar o download sem esperar os 20 s de produção. */
function baixarArquivoTelegramComPrazoTotal(string $url, ?int $maxBytes, int $timeout): string|false
{
    if ($maxBytes !== null && $maxBytes < 1) {
        return false;
    }
    renovarLeaseDaInboxAtiva();
    $curl = curl_init($url);
    if ($curl === false) {
        return false;
    }
    $corpo = '';
    $interrompidoPeloLimite = false;
    $opcoes = opcoesDeDownloadDoTelegram($timeout);
    $opcoes[CURLOPT_WRITEFUNCTION] = static function (CurlHandle $handle, string $trecho) use (&$corpo, &$interrompidoPeloLimite, $maxBytes): int {
        return acumularTrechoDownloadTelegram($trecho, $maxBytes, $corpo, $interrompidoPeloLimite);
    };
    if (!curl_setopt_array($curl, $opcoes)) {
        return false;
    }
    $resultado = curl_exec($curl);
    $errno = curl_errno($curl);
    $http = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    // file:// é a fixture local usada pela suíte; na Bot API exigimos 2xx.
    $httpValido = ($http >= 200 && $http < 300) || ($http === 0 && str_starts_with($url, 'file://'));
    $limiteAtingido = $interrompidoPeloLimite && $errno === CURLE_WRITE_ERROR;

    return $httpValido && ($resultado !== false || $limiteAtingido) ? $corpo : false;
}

/** Acumula apenas os bytes admitidos e distingue corte deliberado de falha. */
function acumularTrechoDownloadTelegram(string $trecho, ?int $maxBytes, string &$corpo, bool &$interrompidoPeloLimite): int
{
    $comprimento = strlen($trecho);
    $restante = $maxBytes === null ? $comprimento : max(0, $maxBytes - strlen($corpo));
    $corpo .= substr($trecho, 0, $restante);
    if ($maxBytes !== null && strlen($corpo) >= $maxBytes) {
        $interrompidoPeloLimite = true;
        return 0;
    }

    return $comprimento;
}

function escapeMarkdown(string $text): string
{
    // Caracteres que precisam ser escapados em Markdown
    $characters = ['_', '*', '`'];
    // Adicionando a barra invertida antes de cada caractere especial
    foreach ($characters as $char) {
        $text = str_replace($char, '\\' . $char, $text);
    }
    return $text;
}

/**
 * Lê um texto guardado no Redis (contexto do usuário ou última resposta do
 * assistente). Chave ausente — ou valor de outro tipo, como o false que o
 * phpredis devolve quando a leitura falha — vira string vazia, exatamente
 * como o fluxo já tratava.
 */
function lerTextoDoRedis(Redis $redis, string $chave): string
{
    try {
        if (!$redis->exists($chave)) {
            return "";
        }

        $bruto = $redis->get($chave);

        return is_string($bruto) ? $bruto : "";
    } catch (Throwable $erro) {
        // Memória é estado efêmero e opcional depois do ACK: uma falha de
        // leitura não pode abortar o comando nem vazar a exceção para o
        // roteador. A escrita CAS (salvarTurnoMemoria, em
        // funcoes_memoria_conversa.php) continua fail-closed.
        if (function_exists('escreverLog')) {
            escreverLog(
                'Falha ao ler memória de conversa: ' . $erro->getMessage(),
                'erros_redis',
                true,
                'error'
            );
        }

        return "";
    }
}

/**
 * Grava texto e TTL em uma única operação Redis.
 *
 * `SET` seguido de `EXPIRE` deixava conteúdo sem expiração quando o segundo
 * comando falhava. Para memória de conversa isso viola diretamente a retenção
 * de 24 horas; a opção EX torna valor e validade indivisíveis.
 */
function salvarTextoComTtl(Redis $redis, string $chave, string $texto, int $ttl): bool
{
    if ($ttl < 1) {
        return false;
    }

    try {
        return $redis->set($chave, $texto, ['ex' => $ttl]);
    } catch (Throwable $erro) {
        if (function_exists('escreverLog')) {
            escreverLog(
                'Falha ao persistir memória com TTL: ' . $erro->getMessage(),
                'erros_redis',
                true,
                'error'
            );
        }

        return false;
    }
}
