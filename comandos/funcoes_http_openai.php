<?php

declare(strict_types=1);

/**
 * Transporte HTTP comum das três modalidades de IA.
 *
 * Nunca registra cabeçalhos, corpo ou chave da API. O log contém apenas o
 * contexto fixo da operação, status HTTP e diagnóstico de transporte.
 */

/**
 * @param string|array<string, mixed> $campos
 * @param array<int, string> $cabecalhos
 * @return array{corpo: string|false, http: int}
 */
function executarRequisicaoOpenAi(
    string $url,
    string|array $campos,
    array $cabecalhos,
    string $contexto,
    int $timeout = 90
): array {
    $ch = curl_init($url);
    if ($ch === false) {
        registrarFalhaHttpOpenAi($contexto, 0, 0, 'curl_init falhou');

        return ['corpo' => false, 'http' => 0];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $campos,
        CURLOPT_HTTPHEADER => $cabecalhos,
        // A chave da OpenAI viaja nesta conexão; não aceite downgrade de TLS
        // nem certificado de outro host mesmo que os defaults do cURL mudem.
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => max(1, $timeout),
    ]);

    $corpo = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $erro = $errno !== 0 ? curl_error($ch) : '';
    unset($ch);

    if ($errno !== 0 || !is_string($corpo)) {
        registrarFalhaHttpOpenAi($contexto, $http, $errno, $erro);

        return ['corpo' => false, 'http' => $http];
    }

    if ($http < 200 || $http >= 300) {
        registrarFalhaHttpOpenAi($contexto, $http, 0, 'resposta HTTP fora da faixa 2xx');
    }

    return ['corpo' => $corpo, 'http' => $http];
}

/**
 * Classifica o diagnóstico do provedor sem persistir error.message: esse campo
 * pode conter identificadores da conta, credenciais ou conteúdo do usuário.
 * A resposta pública continua sendo o aviso fixo de cada modalidade.
 */
function registrarErroDaApiOpenAi(string $contexto, string $mensagem): void
{
    // Esse diagnóstico identifica bloqueio persistente do usuário da API,
    // diferente da moderação do conteúdo de uma mensagem individual.
    $bloqueioDeConta = stripos($mensagem, 'this user has been blocked for a previous policy violation') !== false;
    $categoria = $bloqueioDeConta ? 'account_blocked' : 'provider_error';
    if (!$bloqueioDeConta && stripos($mensagem, 'content policy violation') !== false) {
        $categoria = 'content_moderation';
    }
    registrarFalhaHttpOpenAi($contexto, 200, 0, 'erro no corpo da resposta: ' . $categoria, $bloqueioDeConta);
}

/**
 * Status em que o provedor recusa a CONTA do bot, não a mensagem de alguém:
 * 401 (chave inválida ou revogada) e 402 (sem crédito). Em 24/09/2026 foram
 * 65 × 401 gravados como `info`, e a IA ficou muda sem ninguém saber. O 403
 * fica de fora: no OpenRouter ele é também a moderação barrando o conteúdo de
 * quem escreveu, e avisar o dono por isso seria ruído.
 */
const HTTP_OPENAI_FALHA_DE_CONTA = [401, 402];

/**
 * Nível do log de uma falha do provedor: conta recusada é `error` (e aviso ao
 * dono); 403 (permissão ou moderação), limite e instabilidade do provedor
 * (429, 5xx) são `warning`, sem aviso; transporte e corpo com erro usam
 * `error`, e outros 4xx usam `warning`.
 */
function nivelDaFalhaHttpOpenAi(int $http): string
{
    if (in_array($http, HTTP_OPENAI_FALHA_DE_CONTA, true)) {
        return 'error';
    }

    return in_array($http, [403, 429], true) || ($http >= 400 && $http <= 599) ? 'warning' : 'error';
}

function registrarFalhaHttpOpenAi(string $contexto, int $http, int $errno, string $erro, bool $bloqueioDeConta = false): void
{
    $contexto = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $contexto) ?? 'openai';
    $erro = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $erro) ?? 'erro indisponível';
    $mensagem = "OpenAI {$contexto} falhou | HTTP {$http} | cURL {$errno} | " . substr($erro, 0, 300);
    $nivel = nivelDaFalhaHttpOpenAi($http);

    // Sem funcoes.php carregado (transporte incluído direto), o destino continua
    // sendo o app.log JSON — não o error_log do PHP, que sairia da família.
    if (!class_exists(\App\Support\LoggerFactory::class)) {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    if (class_exists(\App\Support\LoggerFactory::class)) {
        // `info` respeita LOGS_ATIVO; aviso e erro gravam sempre, como toda
        // infraestrutura fora do ar.
        \App\Support\LoggerFactory::channel('erros_openai', $nivel !== 'info')->log($nivel, $mensagem, ['http' => $http, 'provider' => 'openrouter', 'operation' => $contexto, 'curl_errno' => $errno, 'account_blocked' => $bloqueioDeConta]);
    }

    if ($bloqueioDeConta || in_array($http, HTTP_OPENAI_FALHA_DE_CONTA, true)) {
        avisarDonoFalhaDeContaOpenAi($contexto, $http);
    }
}

/**
 * Leva a falha de conta ao chat de DEALNI_ALERTA_CHAT_ID pelo mesmo caminho
 * dos avisos de rotina (inclusos/funcoes_aviso_de_falha.php), no máximo uma
 * vez por AVISO_JANELA_DE_REPETICAO. A marca da janela fica no diretório de
 * logs, o único que o FPM pode gravar. Sem a variável (ou sem TOKEN_BOT), o
 * aviso fica desligado e sobra a linha `error` do log.
 *
 * @param (callable(string): bool)|null $enviar transporte do aviso; null usa o Telegram
 * @return bool verdadeiro quando o aviso foi entregue agora
 */
function avisarDonoFalhaDeContaOpenAi(string $contexto, int $http, ?callable $enviar = null, ?int $agora = null): bool
{
    $chat = getenv('DEALNI_ALERTA_CHAT_ID');
    $chat = is_string($chat) ? trim($chat) : '';
    if ($chat === '' || !class_exists(\App\Support\LoggerFactory::class)) {
        return false;
    }
    require_once __DIR__ . '/../inclusos/funcoes_aviso_de_falha.php';

    $enviar ??= static fn (string $texto): bool => avisarDonoPeloTelegram($chat, $texto);
    $texto = montarMensagemDeFalhaDoProvedor(\App\Support\ContextoDeExecucao::servico(), hostDoAviso(), $contexto, $http);
    $marca = \App\Support\LoggerFactory::diretorioLogs() . '/aviso-openrouter.falhou';

    $lock = @fopen($marca . '.lock', 'c');
    if ($lock === false) {
        return false;
    }
    try {
        // A janela só é gravada após o envio: serialize FPM concorrentes.
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }
        return enviarAvisoComJanela($marca, $texto, $enviar, $agora ?? time()) === true;
    } finally {
        fclose($lock);
    }
}
