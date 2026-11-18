<?php

declare(strict_types=1);

namespace Tests\Support;

final class ExecutorCurlIsolado
{
    /**
     * @param array<string, mixed> $resposta
     * @param array<string, string|false> $ambiente
     * @param list<string> $argumentos
     * @return array{codigo: int, stdout: string, stderr: string, requisicoes: array<mixed>}
     */
    public static function executar(string $script, array $resposta, int $http = 200, array $ambiente = [], array $argumentos = []): array
    {
        if (getenv('APP_ENV') !== 'test' || getenv('CHATBOTS_TESTING') !== '1') {
            throw new \RuntimeException('Transporte simulado exige ambiente de teste.');
        }
        $runner = (string) tempnam(sys_get_temp_dir(), 'curl_runner_');
        $config = $runner . '.json';
        $registro = $runner . '.chamadas';
        file_put_contents($config, json_encode(['script' => $script, 'corpo' => json_encode($resposta, JSON_THROW_ON_ERROR), 'http' => $http, 'registro' => $registro, 'argumentos' => $argumentos], JSON_THROW_ON_ERROR));
        file_put_contents($runner, <<<'RUNNER'
<?php
$cenarioCurl = json_decode(file_get_contents($argv[1]), true);
$GLOBALS['cenarioCurl'] = $cenarioCurl;
$GLOBALS['opcoesCurl'] = [];
$GLOBALS['pedidosCurl'] = [];
if (getenv('CHATBOTS_CURL_TEST_INIT_FAIL') === '1') { function curl_init(?string $url = null): CurlHandle|false { return false; } }
function curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
{
    $GLOBALS['opcoesCurl'][spl_object_id($handle)][$option] = $value;
    return getenv('CHATBOTS_CURL_TEST_OPTION_FAIL') !== '1'
        && getenv('CHATBOTS_CURL_TEST_OPTION_FAIL_ID') !== (string) $option;
}
function curl_setopt_array(CurlHandle $handle, array $options): bool
{
    if (getenv('CHATBOTS_CURL_TEST_ARRAY_OPTION_FAIL') === '1') { return false; }
    foreach ($options as $key => $value) { curl_setopt($handle, $key, $value); }
    return true;
}
function curl_exec(CurlHandle $handle): string|bool
{
    $GLOBALS['pedidosCurl'][] = $GLOBALS['opcoesCurl'][spl_object_id($handle)] ?? [];
    if (curl_errno($handle) !== 0) { return false; }
    $trechos = getenv('CHATBOTS_CURL_TEST_DOWNLOAD_CHUNKS');
    if (is_string($trechos) && $trechos !== '') {
        $escrever = $GLOBALS['opcoesCurl'][spl_object_id($handle)][CURLOPT_WRITEFUNCTION];
        foreach (json_decode($trechos, true) as $trecho) {
            if ($escrever($handle, $trecho) !== strlen($trecho)) {
                $GLOBALS['downloadCurlErrno'] = CURLE_WRITE_ERROR;
                return false;
            }
        }
        $erroAposTrechos = getenv('CHATBOTS_CURL_TEST_ERRNO_AFTER_CHUNKS');
        if (is_string($erroAposTrechos) && ctype_digit($erroAposTrechos) && (int) $erroAposTrechos !== 0) {
            $GLOBALS['downloadCurlErrno'] = (int) $erroAposTrechos;
            return false;
        }
        return true;
    }
    $sequencia = getenv('CHATBOTS_CURL_TEST_RESPONSES');
    if (is_string($sequencia) && $sequencia !== '') {
        $respostas = json_decode($sequencia, true);
        $indice = count($GLOBALS['pedidosCurl']) - 1;
        if (!isset($respostas[$indice])) { throw new RuntimeException('Transporte simulado sem resposta para a chamada ' . $indice); }
        if (($respostas[$indice]['encerrar'] ?? false) === true) { exit(0); }
        return json_encode($respostas[$indice]);
    }
    return $GLOBALS['cenarioCurl']['corpo'];
}
function curl_getinfo(CurlHandle $handle, ?int $option = null): mixed
{
    return $option === CURLINFO_HTTP_CODE ? $GLOBALS['cenarioCurl']['http'] : [];
}
function curl_errno(CurlHandle $handle): int { if (isset($GLOBALS['downloadCurlErrno'])) { return $GLOBALS['downloadCurlErrno']; } $valor = getenv('CHATBOTS_CURL_TEST_ERRNO'); return is_string($valor) && ctype_digit($valor) ? (int) $valor : 0; }
function curl_error(CurlHandle $handle): string { return curl_errno($handle) === 0 ? '' : 'Falha sintética de transporte'; }
register_shutdown_function(static function (): void {
    register_shutdown_function(static function (): void {
        file_put_contents($GLOBALS['cenarioCurl']['registro'], json_encode($GLOBALS['pedidosCurl']));
    });
});
$corpoHttpTeste = getenv('CHATBOTS_HTTP_TEST_BODY');
if (is_string($corpoHttpTeste)) {
    class EntradaHttpCurlTeste {
        public $context;
        public static string $corpo = '';
        private int $posicao = 0;
        public function stream_open($path, $mode, $options, &$opened): bool { return $path === 'php://input'; }
        public function stream_read(int $count): string { $r = substr(self::$corpo, $this->posicao, $count); $this->posicao += strlen($r); return $r; }
        public function stream_eof(): bool { return $this->posicao >= strlen(self::$corpo); }
        public function stream_stat(): array { return []; }
    }
    EntradaHttpCurlTeste::$corpo = $corpoHttpTeste;
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', EntradaHttpCurlTeste::class);
}
$argv = [$cenarioCurl['script'], ...$cenarioCurl['argumentos']];
$_SERVER['argv'] = $argv;
include $cenarioCurl['script'];
RUNNER);
        try {
            $r = ExecutorCli::executar($runner, [$config], $ambiente, ['disable_functions' => 'curl_setopt,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error' . (($ambiente['CHATBOTS_CURL_TEST_INIT_FAIL'] ?? '') === '1' ? ',curl_init' : '')]);
            $bruto = is_file($registro) ? file_get_contents($registro) : false;
            $pedidos = is_string($bruto) ? json_decode($bruto, true) : null;
            if (!is_array($pedidos)) {
                throw new \RuntimeException('Simulador não registrou as requisições: ' . $r['stderr']);
            }
            return [...$r, 'requisicoes' => $pedidos];
        } finally {
            foreach ([$runner, $config, $registro] as $arquivo) {
                if (is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
        }
    }
}
