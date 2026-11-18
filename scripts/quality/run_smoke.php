<?php

declare(strict_types=1);

/**
 * Smoke test manual e somente leitura do ambiente implantado.
 *
 * Não entra no CI: precisa do config.php, do banco e do Redis reais. A
 * confirmação explícita evita chamar a Bot API por engano durante um check.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Arquivo que define conectarRedisConfigurado() neste repositório. O smoke não
 * carrega a biblioteca grande de funções — ela puxa logger, contexto e alertas,
 * que não têm o que fazer aqui —, então o helper de conexão é incluído direto.
 */
const ARQUIVO_DO_HELPER_REDIS = 'inclusos/redis_conexao.php';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

$arguments = $_SERVER['argv'] ?? [];
if (!is_array($arguments) || ($arguments[1] ?? null) !== '--confirm-production') {
    fwrite(STDERR, "Uso: composer test:smoke -- --confirm-production\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
putenv('APP_ENV=production');
putenv('CHATBOTS_TESTING=0');

try {
    require_once $root . '/vendor/autoload.php';
    require_once $root . '/inclusos/dados_bot.php';
    require_once $root . '/inclusos/caminhos.php';
    require_once $root . '/' . ARQUIVO_DO_HELPER_REDIS;

    require_once $root . '/inclusos/banco.php';
    $pdo = conectarBancoDoBot();
    $pdo->exec('SET default_transaction_read_only = on');
    ConsultaBanco::query($pdo, 'SELECT 1 FROM usuarios LIMIT 1');
    echo "[ok] PostgreSQL respondeu com a tabela de usuários acessível.\n";

    if (!class_exists(Redis::class)) {
        throw new RuntimeException('Extensão Redis não carregada.');
    }

    // A conexão sai do helper do próprio bot, e não de um connect() escrito
    // aqui: endereço, senha, timeout de conexão e timeout de LEITURA (o sexto
    // argumento; 0, que é o padrão do phpredis, deixa operações penduradas
    // quando o socket fica mudo) são decisões que o smoke precisa herdar, não
    // reescrever — inclusive a autenticação, que um smoke sem AUTH esconde ao
    // aprovar um Redis que o bot não conseguiria usar.
    $redis = new Redis();
    $conectado = false;
    try {
        $conectado = conectarRedisConfigurado($redis);
        if ($conectado !== true) {
            throw new RuntimeException('Redis recusou a conexão.');
        }
        // phpredis devolve true; um servidor falado direto no protocolo
        // devolveria 'PONG' ou '+PONG'. Os três são resposta boa.
        $pong = $redis->ping();
        if ($pong !== true && $pong !== 'PONG' && $pong !== '+PONG') {
            throw new RuntimeException('Redis respondeu ao ping com valor inesperado.');
        }
    } finally {
        if ($conectado === true) {
            $redis->close();
        }
    }
    echo "[ok] Redis respondeu ao ping.\n";

    if (!extension_loaded('curl')) {
        throw new RuntimeException('Extensão cURL não carregada.');
    }

    $telegramCall = static function (string $method): array {
        $curl = curl_init('https://api.telegram.org/bot' . TOKEN_BOT . '/' . $method);
        if ($curl === false) {
            throw new RuntimeException('Não foi possível iniciar cURL.');
        }
        if (curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            // Redirecionamento seguido às cegas manda o token do bot para o
            // destino que a resposta escolher; a Bot API não redireciona.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPGET => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
        ]) !== true) {
            $codigoErro = curl_errno($curl);
            $mensagemErro = curl_error($curl);
            throw new RuntimeException(
                "Telegram {$method}: falha ao configurar cURL ({$codigoErro}: "
                . ($mensagemErro !== '' ? $mensagemErro : 'erro desconhecido') . ')'
            );
        }
        $body = curl_exec($curl);
        $curlError = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if (!is_string($body)) {
            throw new RuntimeException("Telegram {$method}: {$curlError}");
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded) && is_string($decoded['description'] ?? null)
                ? $decoded['description']
                : "HTTP {$httpCode}";
            throw new RuntimeException("Telegram {$method}: {$description}");
        }

        return $decoded;
    };

    $me = $telegramCall('getMe');
    $resultadoMe = $me['result'] ?? null;
    $username = is_array($resultadoMe) ? ($resultadoMe['username'] ?? null) : null;
    if (!is_string($username) || $username === '') {
        throw new RuntimeException('getMe não devolveu o username do bot.');
    }
    echo "[ok] Telegram autenticou @{$username}.\n";

    $webhook = $telegramCall('getWebhookInfo');
    $resultadoWebhook = $webhook['result'] ?? null;
    $configuredUrl = is_array($resultadoWebhook) ? ($resultadoWebhook['url'] ?? null) : null;
    $publicBaseUrl = defined('PUBLIC_BASE_URL') ? constant('PUBLIC_BASE_URL') : null;
    if (!is_string($publicBaseUrl) || $publicBaseUrl === '') {
        throw new RuntimeException('PUBLIC_BASE_URL não foi carregada.');
    }
    $expectedUrl = rtrim($publicBaseUrl, '/') . '/bot_web.php';
    if (!is_string($configuredUrl) || !hash_equals($expectedUrl, $configuredUrl)) {
        throw new RuntimeException('Webhook registrado não corresponde a PUBLIC_BASE_URL.');
    }
    echo "[ok] Webhook registrado na URL esperada.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[falha] ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo "Smoke test de produção concluído.\n";
