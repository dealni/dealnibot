<?php

declare(strict_types=1);

namespace Quality;

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/** Nome do bot: dá o nome do pool, do drop-in e do diretório /etc. */
const FPM_BOT = 'dealnibot';
/** Prefixo das variáveis próprias do bot no pool isolado. */
const FPM_PREFIX = 'DEALNI';
/**
 * Teto de execução do pool. O valor não é uniforme na família: cada bot tem o
 * pior caso do próprio fluxo documentado no .conf, e encurtar esse teto mata
 * trabalho de fundo DEPOIS do ACK ao Telegram, quando já não há reentrega.
 */
const FPM_TIMEOUT = '330s';
/** Variáveis que o pool precisa repassar isoladas, na ordem em que nasceram. */
const FPM_VARIAVEIS = ['DEALNI_DB_DSN', 'DEALNI_DB_USER', 'DEALNI_DB_PASSWORD', 'DEALNI_PUBLIC_BASE_URL', 'DEALNI_BOT_USERNAME', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_PASSWORD', 'DEALNI_ALERTA_CHAT_ID'];
// --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

require_once __DIR__ . '/quality_gates.php';

/**
 * @param list<string> $arguments
 * @param resource|null $output
 */
function mainAmbienteFpm(array $arguments, ?string $root = null, $output = null): int
{
    $root ??= dirname(__DIR__, 2);
    $output ??= STDOUT;
    $bot = FPM_BOT;
    $prefix = FPM_PREFIX;
    $required = FPM_VARIAVEIS;
    $host = in_array('--host', $arguments, true);
    $poolPath = $host ? '/etc/php/8.5/fpm/pool.d/' . $bot . '.conf' : $root . '/deploy/php-fpm/' . $bot . '.conf';
    $dropPath = $host ? '/etc/systemd/system/php8.5-fpm.service.d/' . $bot . '-environment.conf'
        : $root . '/deploy/systemd/php8.5-fpm-' . $bot . '-environment.conf';
    $dedicatedUnit = '/etc/systemd/system/php8.5-fpm-' . $bot . '.service';
    if ($host && is_file($dedicatedUnit)) {
        $dropPath = $dedicatedUnit;
    }
    $pool = is_file($poolPath) ? file_get_contents($poolPath) : false;
    $dropIn = is_file($dropPath) ? file_get_contents($dropPath) : false;
    if (!is_string($pool) || !is_string($dropIn)) {
        fwrite($output, "Artefatos de ambiente do PHP-FPM ausentes.\n");
        return 1;
    }
    $parsed = @parse_ini_string($pool, true, INI_SCANNER_RAW);
    $settings = is_array($parsed) ? ($parsed[$bot] ?? null) : null;
    $environment = is_array($settings) ? ($settings['env'] ?? null) : null;
    if (!is_array($settings) || !is_array($environment)) {
        fwrite($output, "Pool do PHP-FPM inválido.\n");
        return 1;
    }
    foreach ($required as $name) {
        $suffix = str_starts_with($name, $prefix . '_') ? substr($name, strlen($prefix) + 1) : $name;
        if (($environment[$name] ?? null) !== '$' . $prefix . '_FPM_' . $suffix) {
            fwrite($output, "Pool do PHP-FPM não isola {$name}.\n");
            return 1;
        }
    }
    preg_match_all('/^EnvironmentFile=([^\r\n]*)$/m', $dropIn, $matches);
    if (($settings['clear_env'] ?? null) !== 'yes'
        || $matches[1] !== ['/etc/' . $bot . '/fpm.environment']
    ) {
        fwrite($output, "Pool/drop-in do FPM fora do contrato de isolamento.\n");
        return 1;
    }
    foreach (['request_terminate_timeout' => FPM_TIMEOUT, 'request_terminate_timeout_track_finished' => 'no', 'security.limit_extensions' => '.php'] as $directive => $value) {
        if (($settings[$directive] ?? null) !== $value) {
            fwrite($output, "Pool do PHP-FPM não fixa {$directive}.\n");
            return 1;
        }
    }

    fwrite($output, "Ambiente do PHP-FPM: contrato do host isolado por bot.\n");
    return 0;
}

if (executadoDiretamente(__FILE__)) {
    $arguments = $_SERVER['argv'] ?? [];
    exit(mainAmbienteFpm(is_array($arguments) ? array_values(array_filter($arguments, is_string(...))) : []));
}
