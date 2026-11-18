<?php

declare(strict_types=1);

/**
 * Aviso no Telegram quando uma rotina agendada falha (snapshot, replay da
 * inbox e monitor de saúde) ou quando o provedor de IA recusa a conta.
 *
 * O cron entrega o STDERR ao `MAILTO=root`, mas o e-mail não sai da VPS
 * (docs/OPERACAO.md#monitoramento-e-alertas): o canal real é o Telegram, pelo
 * próprio bot, para o chat de `DEALNI_ALERTA_CHAT_ID` (o bot não tem admins;
 * vazia, o aviso fica desligado). Este arquivo só declara funções; a
 * entrada é scripts/operacao/avisar_falha.php, chamada pelo
 * scripts/operacao/avisar_se_falhar.sh que envolve o job no crontab.
 *
 * A mensagem leva sistema, job, host, código de saída e o fim do log, com
 * credenciais redigidas. Sucesso não avisa; o primeiro sucesso depois de uma
 * falha avisa uma única vez que a rotina voltou a funcionar. A mesma falha
 * volta ao Telegram no máximo uma vez por AVISO_JANELA_DE_REPETICAO: o replay
 * roda a cada minuto e o monitor a cada cinco, e um aviso por rodada enterraria
 * o chat do dono.
 */

/** Quantas linhas do fim do log entram na mensagem. */
const AVISO_LINHAS_DO_LOG = 15;
/** Teto do trecho de log na mensagem (o Telegram aceita 4096 caracteres). */
const AVISO_BYTES_DO_LOG = 1500;
/** Quanto do fim do arquivo de log é lido: o resto nunca entraria na mensagem. */
const AVISO_BYTES_LIDOS = 65536;
/** Segundos em que a mesma falha não é reenviada (a janela do monitorar_saude.sh). */
const AVISO_JANELA_DE_REPETICAO = 3600;

/**
 * @param list<string> $argumentos argv sem o nome do script
 * @return array{job: string, codigo: int, log: string}
 */
function lerArgumentosDoAviso(array $argumentos): array
{
    $uso = 'Uso: avisar_falha.php --job=NOME --codigo=N [--log=ARQUIVO]';
    $valores = [];
    foreach ($argumentos as $argumento) {
        if (preg_match('/^--(job|codigo|log)=(.*)$/s', $argumento, $partes) !== 1 || isset($valores[$partes[1]])) {
            throw new InvalidArgumentException($uso);
        }
        $valores[$partes[1]] = $partes[2];
    }
    $job = $valores['job'] ?? '';
    $codigo = $valores['codigo'] ?? '';
    if (preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $job) !== 1 || preg_match('/^(0|[1-9][0-9]{0,2})$/', $codigo) !== 1) {
        throw new InvalidArgumentException($uso);
    }

    return ['job' => $job, 'codigo' => (int) $codigo, 'log' => $valores['log'] ?? ''];
}

/** Troca por `<redigido>` o que parece credencial: token de bot, senha em URL ou em `chave=valor`. */
function redigirSegredosDoAviso(string $texto): string
{
    $padroes = [
        '/bot\d+:[A-Za-z0-9_-]+/' => 'bot<redigido>',
        '#([a-z][a-z0-9+.-]*://)[^/\s:@]+:[^@\s]+@#i' => '$1<redigido>@',
        '/\bBearer\s+\S+/i' => 'Bearer <redigido>',
        '/\b(password|passwd|senha|secret|token|api[_-]?key)(\s*[=:]\s*)("[^"]*"|\'[^\']*\'|[^\s,;]+)/i' => '$1$2<redigido>',
    ];

    return (string) preg_replace(array_keys($padroes), array_values($padroes), $texto);
}

/** Últimas linhas do log, redigidas, em UTF-8 válido e dentro do teto da mensagem. */
function fimDoLogDoAviso(string $conteudo): string
{
    $conteudo = mb_scrub(str_replace("\r", '', $conteudo), 'UTF-8');
    $conteudo = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $conteudo);
    $linhas = explode("\n", trim($conteudo));
    $fim = redigirSegredosDoAviso(implode("\n", array_slice($linhas, -AVISO_LINHAS_DO_LOG)));
    if (strlen($fim) > AVISO_BYTES_DO_LOG) {
        // O corte por bytes pode cair no meio de um caractere: os bytes de
        // continuação soltos no começo saem, em vez de virar "?".
        $fim = '...' . (string) preg_replace('/^[\x80-\xBF]{1,3}/', '', substr($fim, -AVISO_BYTES_DO_LOG));
    }

    return $fim === '' ? '(log vazio)' : $fim;
}

/** Lê só o fim do arquivo; ausente, ilegível ou symlink vira texto vazio. */
function lerFimDoArquivoDoAviso(string $caminho): string
{
    if ($caminho === '' || is_link($caminho) || !is_file($caminho) || !is_readable($caminho)) {
        return '';
    }
    $arquivo = fopen($caminho, 'rb');
    if ($arquivo === false) {
        return '';
    }
    $tamanho = filesize($caminho);
    if (is_int($tamanho) && $tamanho > AVISO_BYTES_LIDOS) {
        fseek($arquivo, -AVISO_BYTES_LIDOS, SEEK_END);
    }
    $conteudo = stream_get_contents($arquivo);
    fclose($arquivo);

    return is_string($conteudo) ? $conteudo : '';
}

function montarMensagemDeFalha(string $sistema, string $job, string $host, int $codigo, string $fimDoLog): string
{
    return "[FALHA] Rotina agendada falhou\n"
        . "Sistema: {$sistema}\nJob: {$job}\nHost: {$host}\nCódigo de saída: {$codigo}\n\n"
        . "Fim do log:\n{$fimDoLog}";
}

function montarMensagemDeRecuperacao(string $sistema, string $job, string $host): string
{
    return "[RECUPERADO] Rotina voltou a funcionar\nSistema: {$sistema}\nJob: {$job}\nHost: {$host}";
}

/** Nome da máquina na mensagem; sem ele, o aviso sai assim mesmo. */
function hostDoAviso(): string
{
    $host = gethostname();

    return is_string($host) ? $host : 'desconhecido';
}

/** Transporte padrão do aviso fora do cron: sem TOKEN_BOT carregado, não envia. */
function avisarDonoPeloTelegram(string $chat, string $texto): bool
{
    return defined('TOKEN_BOT') && enviarAvisoTelegram(TOKEN_BOT, $chat, $texto);
}

/** Aviso de que o provedor de IA recusou a conta do bot (401 ou 402). */
function montarMensagemDeFalhaDoProvedor(string $sistema, string $host, string $chamada, int $http): string
{
    $motivo = match ($http) {
        200 => 'bloqueio persistente do usuário da API por política',
        401 => 'chave inválida ou revogada',
        402 => 'conta sem crédito',
        default => 'recusa da conta',
    };

    return "[FALHA] OpenRouter recusou a chamada da IA\n"
        . "Sistema: {$sistema}\nHost: {$host}\nChamada: {$chamada}\nHTTP: {$http} ({$motivo})\n\n"
        . 'Enquanto durar, os grupos recebem o aviso genérico de erro. Detalhe no canal erros_openai; '
        . 'este aviso se repete no máximo uma vez por hora.';
}

/**
 * Manda texto puro (sem parse_mode: o log não precisa de escape) e só devolve
 * verdadeiro quando o Telegram confirma `ok: true`.
 *
 * @param (callable(string, string): (string|false))|null $transporte recebe URL e corpo, devolve a resposta
 */
function enviarAvisoTelegram(string $token, string $chatId, string $texto, ?callable $transporte = null): bool
{
    if ($token === '' || preg_match('/^-?[1-9][0-9]{0,19}$/', $chatId) !== 1) {
        return false;
    }
    $transporte ??= 'transporteHttpDoAviso';
    $corpo = http_build_query(['chat_id' => $chatId, 'text' => $texto, 'disable_web_page_preview' => 'true']);
    $resposta = $transporte('https://api.telegram.org/bot' . $token . '/sendMessage', $corpo);
    $dados = is_string($resposta) ? json_decode($resposta, true) : null;

    return is_array($dados) && ($dados['ok'] ?? false) === true;
}

/**
 * Contexto do POST do aviso. `bindto` força IPv4, como o resto das chamadas à
 * Bot API: a rota IPv6 da VPS expira no handshake TLS
 * (docs/OPERACAO.md#incidente-de-conectividade-com-o-telegram). O `timeout`
 * do wrapper http vale para a conexão e para a leitura.
 *
 * @return array{socket: array{bindto: string}, http: array{method: string, header: string, content: string, timeout: int, ignore_errors: bool}}
 */
function opcoesDoTransporteDoAviso(string $corpo): array
{
    return [
        'socket' => ['bindto' => '0.0.0.0:0'],
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $corpo,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ];
}

function transporteHttpDoAviso(string $url, string $corpo): string|false
{
    error_clear_last();
    http_clear_last_response_headers();
    $resposta = @file_get_contents($url, false, stream_context_create(opcoesDoTransporteDoAviso($corpo)));
    $erro = error_get_last();
    require_once __DIR__ . '/../src/Support/DiagnosticoAvisoTelegram.php';
    $diagnostico = \App\Support\DiagnosticoAvisoTelegram::resumir(
        $resposta,
        http_get_last_response_headers() ?? [],
        $erro['message'] ?? ''
    );
    if ($diagnostico !== null) {
        // error_log também funciona no PHP-FPM, onde STDERR não é definido.
        error_log((string) json_encode($diagnostico));
        return false;
    }
    return $resposta;
}

/**
 * A marca guarda o instante do último aviso entregue; `0` quando o envio
 * falhou, para que a próxima falha tente de novo. Marca ausente, ilegível,
 * symlink ou com conteúdo estranho não segura aviso nenhum.
 */
function avisoRecenteNaMarca(string $marca, int $agora): bool
{
    if (is_link($marca) || !is_file($marca)) {
        return false;
    }
    $conteudo = trim((string) @file_get_contents($marca));
    if (preg_match('/^[1-9][0-9]{0,18}$/', $conteudo) !== 1) {
        return false;
    }

    // Relógio que voltou não prende o aviso por tempo indeterminado.
    $decorrido = $agora - (int) $conteudo;

    return $decorrido >= 0 && $decorrido < AVISO_JANELA_DE_REPETICAO;
}

function gravarMarcaDoAviso(string $marca, int $instante): void
{
    if (!is_link($marca) && @file_put_contents($marca, (string) $instante) !== false) {
        @chmod($marca, 0600);
    }
}

/**
 * Manda o texto, a menos que a mesma marca tenha avisado dentro da janela.
 *
 * @param callable(string): bool $enviar
 * @return bool|null null quando a janela segurou o aviso
 */
function enviarAvisoComJanela(string $marca, string $texto, callable $enviar, int $agora): ?bool
{
    if (avisoRecenteNaMarca($marca, $agora)) {
        return null;
    }
    $entregue = $enviar($texto);
    gravarMarcaDoAviso($marca, $entregue ? $agora : 0);

    return $entregue;
}

/**
 * Falha avisa e deixa a marca `aviso-<job>.falhou` com o instante do aviso;
 * a mesma falha dentro da janela não volta ao Telegram. Sucesso só avisa se a
 * marca existir, e a apaga depois de avisar.
 *
 * @param array{job: string, codigo: int, log: string} $argumentos
 * @param callable(string): bool $enviar
 * @return int 0 com aviso entregue, segurado pela janela ou desnecessário; 1 quando o envio falhou
 */
function processarAvisoDeFalha(
    array $argumentos,
    string $sistema,
    string $host,
    string $diretorioDeEstado,
    callable $enviar,
    ?int $agora = null
): int {
    $marca = rtrim($diretorioDeEstado, '/') . '/aviso-' . $argumentos['job'] . '.falhou';
    if ($argumentos['codigo'] === 0) {
        if (!is_file($marca)) {
            return 0;
        }
        if (!$enviar(montarMensagemDeRecuperacao($sistema, $argumentos['job'], $host))) {
            return 1;
        }
        @unlink($marca);

        return 0;
    }

    $fimDoLog = fimDoLogDoAviso(lerFimDoArquivoDoAviso($argumentos['log']));
    $mensagem = montarMensagemDeFalha($sistema, $argumentos['job'], $host, $argumentos['codigo'], $fimDoLog);

    return enviarAvisoComJanela($marca, $mensagem, $enviar, $agora ?? time()) === false ? 1 : 0;
}
