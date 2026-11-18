<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use RuntimeException;
use Throwable;
use UnexpectedValueException;

/** @return array{dependencies: array<string, array<mixed>>} */
function licencasDoLockComposer(string $arquivo): array
{
    if (!is_file($arquivo)) {
        throw new RuntimeException("Árvore esperada ausente: {$arquivo}");
    }
    $conteudo = file_get_contents($arquivo);
    if (!is_string($conteudo)) {
        throw new RuntimeException("Não foi possível ler {$arquivo}");
    }
    $lock = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($lock) || !isset($lock['packages'], $lock['packages-dev'])
        || !is_array($lock['packages']) || !array_is_list($lock['packages'])
        || !is_array($lock['packages-dev']) || !array_is_list($lock['packages-dev'])
    ) {
        throw new UnexpectedValueException("Árvore inválida: {$arquivo}");
    }
    $dependencias = [];
    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $pacote) {
        if (!is_array($pacote) || !isset($pacote['name'], $pacote['version'])
            || !is_string($pacote['name']) || $pacote['name'] === ''
            || !is_string($pacote['version']) || $pacote['version'] === ''
            || isset($dependencias[$pacote['name']])
        ) {
            throw new UnexpectedValueException("Pacote inválido: {$arquivo}");
        }
        if (isset($pacote['license']) && (
            !is_array($pacote['license'])
            || !array_is_list($pacote['license'])
            || count(array_filter($pacote['license'], 'is_string')) !== count($pacote['license'])
        )) {
            throw new UnexpectedValueException("Licença inválida: {$arquivo}");
        }
        $dependencias[$pacote['name']] = $pacote;
    }
    if ($dependencias === []) {
        throw new UnexpectedValueException("Árvore esperada vazia: {$arquivo}");
    }

    return ['dependencies' => $dependencias];
}

function conferirLicencasComposer(string $composer, string $diretorio): void
{
    $arquivo = $diretorio . '/composer.lock';
    $lock = licencasDoLockComposer($arquivo);
    $reprovadas = dependenciasComLicencaReprovada($lock);
    if ($reprovadas !== []) {
        throw new RuntimeException("{$arquivo}: dependências fora da política de licenças:\n" . implode("\n", $reprovadas));
    }
    $resultado = executarProcesso([
        $composer, '--no-plugins', '--no-scripts', '--working-dir=' . $diretorio,
        'licenses', '--locked', '--format=json', '--no-interaction', '--no-ansi',
    ]);
    if ($resultado['status'] !== 0) {
        throw new RuntimeException("{$arquivo}: composer licenses falhou:\n" . $resultado['stderr']);
    }
    $relatorio = json_decode($resultado['stdout'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($relatorio) || !isset($relatorio['dependencies']) || !is_array($relatorio['dependencies'])) {
        throw new UnexpectedValueException("{$arquivo}: relatório de licenças inválido.");
    }
    $reprovadas = dependenciasComLicencaReprovada($relatorio);
    $nomes = array_keys($lock['dependencies']);
    $nomesRelatados = array_keys($relatorio['dependencies']);
    sort($nomes);
    sort($nomesRelatados);
    if ($nomes !== $nomesRelatados || $reprovadas !== []) {
        throw new UnexpectedValueException("{$arquivo}: relatório de licenças incompleto ou fora da política.");
    }
    printf("%s: licenças permissivas ou revisadas em %d pacote(s).\n", $arquivo, count($nomes));
}

function mainLicencas(): int
{
    $composer = getenv('COMPOSER_BINARY');
    if (!is_string($composer) || $composer === '') {
        $composer = 'composer';
    }
    $redeAnterior = getenv('COMPOSER_DISABLE_NETWORK');
    putenv('COMPOSER_DISABLE_NETWORK=1');
    try {
        conferirLicencasComposer($composer, '.');
        conferirLicencasComposer($composer, 'tools/phpcpd');
        $npm = executarProcesso(['node', __DIR__ . '/check_npm_licenses.mjs']);
        if ($npm['status'] !== 0) {
            throw new RuntimeException('package-lock.json: checker npm falhou: ' . $npm['stderr']);
        }
        fwrite(STDOUT, $npm['stdout']);

        return 0;
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    } finally {
        putenv($redeAnterior === false ? 'COMPOSER_DISABLE_NETWORK' : 'COMPOSER_DISABLE_NETWORK=' . $redeAnterior);
    }
}

if (executadoDiretamente(__FILE__)) {
    exit(mainLicencas());
}
