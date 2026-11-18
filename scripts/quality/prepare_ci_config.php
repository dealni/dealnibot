<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use RuntimeException;
use Throwable;

/**
 * Cria apenas no CI um config.php inofensivo para exercitar o mesmo ramo de
 * produção que será usado no servidor. O arquivo é ignorado pelo Git.
 */
/**
 * O carregador valida o formato de alguns segredos antes de qualquer uso; o
 * placeholder do CI precisa passar por essa validação sem ser um segredo.
 * TELEGRAM_WEBHOOK_SECRET exige 32 a 256 caracteres de [A-Za-z0-9_-].
 */
function valorDePlaceholderCi(string $nome): string
{
    if ($nome === 'TELEGRAM_WEBHOOK_SECRET') {
        return str_repeat('ci-placeholder-', 3) . 'webhook';
    }

    return 'ci-placeholder';
}

function mainPrepararConfiguracaoCi(): int
{
    if (getenv('CI') !== 'true') {
        fwrite(STDERR, "Este gerador só pode rodar com CI=true.\n");

        return 1;
    }

    $raiz = dirname(__DIR__, 2);
    $carregador = $raiz . '/inclusos/config_loader.php';
    $destino = $raiz . '/inclusos/config.php';

    try {
        $codigo = file_get_contents($carregador);
        if (!is_string($codigo)) {
            throw new RuntimeException('Não foi possível ler o carregador de configuração.');
        }
        if (preg_match('/\\$requiredConstants\\s*=\\s*\\[(?<lista>.*?)\\];/s', $codigo, $partes) !== 1) {
            throw new RuntimeException('Não foi possível localizar requiredConstants no carregador.');
        }
        preg_match_all("/'(?<nome>[A-Z][A-Z0-9_]*)'/", $partes['lista'], $nomes);
        $constantes = $nomes['nome'];
        if ($constantes === []) {
            throw new RuntimeException('O carregador não declarou constantes obrigatórias.');
        }

        $conteudo = "<?php\n\n// Gerado pelo CI; nunca versionar este arquivo.\n";
        foreach (array_values(array_unique($constantes)) as $nome) {
            $conteudo .= sprintf("define(%s, %s);\n", var_export($nome, true), var_export(valorDePlaceholderCi($nome), true));
        }
        $temporario = $destino . '.ci.tmp';
        if (file_put_contents($temporario, $conteudo, LOCK_EX) === false
            || !rename($temporario, $destino)
        ) {
            @unlink($temporario);
            throw new RuntimeException('Não foi possível criar inclusos/config.php para o CI.');
        }

        fwrite(STDOUT, sprintf("Configuração descartável criada com %d constante(s).\n", count($constantes)));

        return 0;
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    }
}

if (executadoDiretamente(__FILE__)) {
    exit(mainPrepararConfiguracaoCi());
}
