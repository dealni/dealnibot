<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use Throwable;

/**
 * @param list<string> $comando
 * @return array{status: int, stdout: string, stderr: string}
 */
function executarComandoDeWorkflow(array $comando): array
{
    return executarProcesso($comando, dirname(__DIR__, 2));
}

function mainWorkflows(): int
{
    $raiz = dirname(__DIR__, 2);
    try {
        $workflows = [];
        $arquivos = glob($raiz . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE);
        if ($arquivos === false) {
            $arquivos = [];
        }
        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            if (!is_string($conteudo)) {
                throw new \RuntimeException('Não foi possível ler ' . $arquivo . '.');
            }
            $workflows[basename($arquivo)] = $conteudo;
        }
        $mensagem = validarWorkflows(
            executarComandoDeWorkflow(...),
            is_dir($raiz . '/.github/workflows'),
            $workflows,
        );
        fwrite(STDOUT, $mensagem . PHP_EOL);

        return 0;
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    }
}

if (executadoDiretamente(__FILE__)) {
    exit(mainWorkflows());
}
