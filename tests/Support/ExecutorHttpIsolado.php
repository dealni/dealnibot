<?php

declare(strict_types=1);

namespace Tests\Support;

final class ExecutorHttpIsolado
{
    /**
     * @param array<string, mixed> $servidor Inclui campos de cabeçalho malformados para validar a fronteira HTTP.
     * @param array<string, string|false> $ambiente
     * @return array{codigo: int, stdout: string, stderr: string, status: int}
     */
    public static function executar(string $script, string $corpo, array $servidor, array $ambiente): array
    {
        $runner = (string) tempnam(sys_get_temp_dir(), 'http_runner_');
        $config = $runner . '.json';
        $resultado = $runner . '.status';
        file_put_contents($config, json_encode(['script' => $script, 'corpo' => $corpo, 'servidor' => $servidor, 'resultado' => $resultado], JSON_THROW_ON_ERROR));
        file_put_contents($runner, <<<'RUNNER'
<?php
$config = json_decode(file_get_contents($argv[1]), true);
$_SERVER = array_replace($_SERVER, $config['servidor']);
http_response_code(200);
register_shutdown_function(static function () use ($config): void { file_put_contents($config['resultado'], (string) http_response_code()); });
class CorpoHttpSimulado
{
    public $context;
    private int $posicao = 0;
    public function stream_open($path, $mode, $options, &$opened_path): bool { return $path === 'php://input'; }
    public function stream_read(int $count): string
    {
        $r = substr($GLOBALS['config']['corpo'], $this->posicao, $count);
        $this->posicao += strlen($r);
        return $r;
    }
    public function stream_eof(): bool { return $this->posicao >= strlen($GLOBALS['config']['corpo']); }
    public function stream_stat(): array { return []; }
    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($whence !== SEEK_SET) { return false; }
        $this->posicao = $offset;
        return true;
    }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', CorpoHttpSimulado::class);
include $config['script'];
RUNNER);
        try {
            $r = ExecutorCli::executar($runner, [$config], $ambiente);
            $status = is_file($resultado) ? file_get_contents($resultado) : false;
            if (!is_string($status) || !ctype_digit($status)) {
                throw new \RuntimeException('Entrypoint não informou status HTTP: ' . $r['stderr']);
            }
            return [...$r, 'status' => (int) $status];
        } finally {
            unlink($runner);
            unlink($config);
            if (is_file($resultado)) {
                unlink($resultado);
            }
        }
    }
}
