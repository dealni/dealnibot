<?php

declare(strict_types=1);

namespace Tests\Support;

final class ExecutorCgi
{
    /**
     * @param array<string, string|false> $variaveis
     * @return array{codigo: int, stdout: string, stderr: string}
     */
    public static function executar(string $script, array $variaveis = []): array
    {
        $binario = dirname(PHP_BINARY) . '/php-cgi';
        if (!is_executable($binario)) {
            throw new \RuntimeException('A suíte de entradas HTTP exige php-cgi do mesmo PHP do PHPUnit.');
        }
        $comando = ExecutorCli::comando($script);
        $comando[0] = $binario;
        array_pop($comando);
        $comando[] = '-f';
        $comando[] = $script;
        $ambiente = getenv();
        foreach (['REDIRECT_STATUS' => '1', 'REQUEST_METHOD' => 'GET', 'SCRIPT_FILENAME' => $script, 'SCRIPT_NAME' => '/entrada.php', ...$variaveis] as $nome => $valor) {
            if ($valor === false) {
                unset($ambiente[$nome]);
            } else {
                $ambiente[$nome] = $valor;
            }
        }
        $processo = proc_open(ExecutorCli::preservarAmbienteVazio($comando, $ambiente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $ambiente);
        if (!is_resource($processo)) {
            throw new \RuntimeException('Não foi possível iniciar o CGI de teste.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['codigo' => proc_close($processo), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
