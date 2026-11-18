<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use Throwable;

/**
 * @param list<int> $atrasos
 * @param callable(list<string>): array{status: int, stdout: string, stderr: string} $executor
 * @param callable(int): void $esperar
 * @param null|callable('stdout'|'stderr', string): void $relatar
 */
function executarAuditoriaComRetry(
    string $composer,
    array $atrasos,
    callable $executor,
    callable $esperar,
    ?callable $relatar = null,
): int {
    $relatar ??= static function (string $canal, string $texto): void {
        fwrite($canal === 'stdout' ? STDOUT : STDERR, $texto);
    };
    $ultimoStatus = 1;
    foreach ($atrasos as $tentativa => $atraso) {
        if ($atraso > 0) {
            $relatar('stderr', "Composer audit indisponível; nova tentativa em {$atraso}s.\n");
            $esperar($atraso);
        }
        $resultado = $executor([
            $composer,
            'audit',
            '--locked',
            '--no-interaction',
            '--abandoned=fail',
        ]);
        $relatar('stdout', $resultado['stdout']);
        $relatar('stderr', $resultado['stderr']);
        $ultimoStatus = $resultado['status'];
        if ($ultimoStatus === 0) {
            return 0;
        }
        if (!falhaTransitoriaDoAudit($resultado['stdout'] . "\n" . $resultado['stderr'])) {
            return $ultimoStatus;
        }
        if ($tentativa === array_key_last($atrasos)) {
            break;
        }
    }

    $relatar('stderr', "Composer audit continuou indisponível após três tentativas.\n");

    return $ultimoStatus;
}

function mainAudit(): int
{
    $composer = getenv('COMPOSER_BINARY');
    if (!is_string($composer) || $composer === '') {
        $composer = 'composer';
    }

    try {
        $raiz = dirname(__DIR__, 2);
        foreach ([$raiz, $raiz . '/tools/phpcpd'] as $diretorio) {
            $status = executarAuditoriaComRetry(
                $composer,
                [0, 2, 5],
                static fn (array $comando): array => executarProcesso($comando, $diretorio),
                static function (int $segundos): void {
                    sleep($segundos);
                },
            );
            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    }
}

if (executadoDiretamente(__FILE__)) {
    exit(mainAudit());
}
