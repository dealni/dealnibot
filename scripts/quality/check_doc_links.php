<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use Throwable;

function mainLinks(): int
{
    $raiz = dirname(__DIR__, 2);
    try {
        // `--others --exclude-per-directory=.gitignore` junto de `--cached`:
        // documento novo ainda não adicionado ao índice é exatamente onde nasce
        // link quebrado, e sem isto ele só seria conferido depois de commitado.
        // Somente o `.gitignore` versionado/da árvore exclui caminhos; excludes
        // globais do usuário não podem esconder documentação que o gate deve
        // conferir.
        $resultado = executarProcesso(
            ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-per-directory=.gitignore', '--', '*.md'],
            $raiz,
        );
        if ($resultado['status'] !== 0) {
            throw new \RuntimeException('git ls-files falhou: ' . trim($resultado['stderr']));
        }
        $arquivos = array_values(array_unique(array_filter(
            explode("\0", $resultado['stdout']),
            static fn (string $arquivo): bool => $arquivo !== '' && is_file($raiz . '/' . $arquivo),
        )));
        if ($arquivos === []) {
            throw new \RuntimeException('Nenhum arquivo Markdown para validar.');
        }
        $problemas = problemasNosLinks($raiz, $arquivos);
        if ($problemas !== []) {
            fwrite(STDERR, "Links locais inválidos:\n" . implode("\n", $problemas) . "\n");

            return 1;
        }
        printf("Links locais válidos em %d arquivo(s) Markdown.\n", count($arquivos));

        return 0;
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    }
}

if (executadoDiretamente(__FILE__)) {
    exit(mainLinks());
}
