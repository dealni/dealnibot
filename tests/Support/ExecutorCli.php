<?php

declare(strict_types=1);

namespace Tests\Support;

final class ExecutorCli
{
    /**
     * proc_open descarta valores vazios do mapa de ambiente. env os reinstala
     * antes do PHP iniciar, preservando a distinção entre ausente e vazio.
     * @param list<string> $comando
     * @param array<string, string> $ambiente
     * @return list<string>
     */
    public static function preservarAmbienteVazio(array $comando, array $ambiente): array
    {
        $vazios = [];
        foreach ($ambiente as $nome => $valor) {
            if ($valor === '') {
                $vazios[] = $nome . '=';
            }
        }
        return $vazios === [] ? $comando : ['/usr/bin/env', '--', ...$vazios, ...$comando];
    }

    /**
     * @param list<string> $argumentos
     * @param array<string, string> $opcoesIni
     * @return list<string>
     */
    public static function comando(string $script, array $argumentos = [], array $opcoesIni = []): array
    {
        $comando = [PHP_BINARY];
        foreach (['opcache.enable', 'opcache.enable_cli', 'error_reporting', 'display_errors'] as $nome) {
            $valor = ini_get($nome);
            if (is_string($valor)) {
                $comando[] = '-d';
                $comando[] = $nome . '=' . $valor;
            }
        }
        foreach ($opcoesIni as $nome => $valor) {
            $comando[] = '-d';
            $comando[] = $nome . '=' . $valor;
        }
        return [...$comando, $script, ...$argumentos];
    }

    /**
     * @param list<string> $argumentos
     * @param array<string, string|false> $variaveis false remove uma variável herdada.
     * @param array<string, string> $opcoesIni
     * @return array{codigo: int, stdout: string, stderr: string}
     */
    public static function executar(string $script, array $argumentos = [], array $variaveis = [], array $opcoesIni = []): array
    {
        $ambiente = getenv();
        foreach ($variaveis as $nome => $valor) {
            if ($valor === false) {
                unset($ambiente[$nome]);
            } else {
                $ambiente[$nome] = $valor;
            }
        }
        $processo = proc_open(self::preservarAmbienteVazio(self::comando($script, $argumentos, $opcoesIni), $ambiente), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $ambiente);
        if (!is_resource($processo)) {
            throw new \RuntimeException('Não foi possível iniciar o CLI de teste.');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['codigo' => proc_close($processo), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
