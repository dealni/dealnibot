<?php

declare(strict_types=1);

namespace Tests\Support;

final class ExecutorFalhaDeArquivo
{
    /**
     * @param array<string, string|int> $cenario
     * @return array{codigo: int, stdout: string, stderr: string}
     */
    public static function executar(array $cenario): array
    {
        $substitutos = [
            'is_file' => 'function is_file(string $path): bool { return falhaDeArquivo($path) ? false : (new SplFileInfo($path))->isFile(); }',
            'realpath' => 'function realpath(string $path): string|false { return falhaDeArquivo($path) ? false : (new SplFileInfo($path))->getRealPath(); }',
            'scandir' => 'function scandir(string $path, int $sorting = SCANDIR_SORT_ASCENDING, mixed $context = null): array|false { if (falhaDeArquivo($path)) { return false; } $r = []; foreach (new DirectoryIterator($path) as $f) { $r[] = $f->getFilename(); } sort($r); return $r; }',
            'file_get_contents' => 'function file_get_contents(string $path, bool $include = false, mixed $context = null, int $offset = 0, ?int $length = null): string|false { if (falhaDeArquivo($path)) { return false; } $h = fopen($path, "rb"); if ($h === false) { return false; } $r = stream_get_contents($h, $length, $offset); fclose($h); return $r; }',
            'fileperms' => 'function fileperms(string $path): int|false { if (falhaDeArquivo($path)) { return ($GLOBALS["cenarioFalhaIo"]["retorno"] ?? "false") === "modo" ? 0777 : false; } $s = stat($path); return $s === false ? false : $s["mode"]; }',
            'filesize' => 'function filesize(string $path): int|false { if (falhaDeArquivo($path)) { return false; } $s = stat($path); return $s === false ? false : $s["size"]; }',
            'fopen' => 'function fopen(string $path, string $mode, bool $include = false, mixed $context = null): mixed { falhaDeArquivo($path); return false; }',
            'fwrite' => 'function fwrite(mixed $stream, string $data, ?int $length = null): int|false { if (falhaDeArquivo(stream_get_meta_data($stream)["uri"])) { return 0; } return fputs($stream, $data, $length); }',
            'fclose' => 'function fclose(mixed $stream): bool { return !falhaDeArquivo(stream_get_meta_data($stream)["uri"]); }',
            'link' => 'function link(string $source, string $target): bool { if (falhaDeArquivo($target)) { return false; } throw new RuntimeException("link não previsto nesta fixture"); }',
            'chmod' => 'function chmod(string $path, int $mode): bool { if (falhaDeArquivo($path)) { return false; } return binarioDaFixture(["/bin/chmod", decoct($mode), $path]); }',
            'unlink' => 'function unlink(string $path, mixed $context = null): bool { if (falhaDeArquivo($path)) { return false; } return (is_file($path) || is_link($path)) && binarioDaFixture(["/bin/rm", "--", $path]); }',
        ];
        $funcao = $cenario['funcao'];
        if (!is_string($funcao) || !isset($substitutos[$funcao])) {
            throw new \InvalidArgumentException('Falha de arquivo não permitida.');
        }
        $script = (string) tempnam(sys_get_temp_dir(), 'falha_arquivo_');
        $codigo = <<<'RUNNER'
<?php
$GLOBALS['cenarioFalhaIo'] = json_decode($argv[1], true);
$GLOBALS['chamadasFalhaIo'] = 0;
function falhaDeArquivo(string $path): bool {
    if (!isset($GLOBALS['cenarioFalhaIo'])) { return false; }
    $c = $GLOBALS['cenarioFalhaIo'];
    if (!str_starts_with($path, $c['diretorio']) || !str_contains($path, $c['alvo'])) { return false; }
    $GLOBALS['chamadasFalhaIo']++;
    return $GLOBALS['chamadasFalhaIo'] === $c['chamada'];
}
function binarioDaFixture(array $cmd): bool {
    $ultimo = $cmd[count($cmd) - 1];
    if (!str_starts_with($ultimo, $GLOBALS['cenarioFalhaIo']['diretorio'])) { throw new RuntimeException('Operação fora da fixture.'); }
    $p = proc_open($cmd, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    return is_resource($p) && proc_close($p) === 0;
}
__SUBSTITUTO__
$c = $GLOBALS['cenarioFalhaIo'];
require $c['raiz'] . '/inclusos/funcoes_snapshot_banco.php';
try {
    $resultado = match ($c['acao']) {
        'caminho' => caminhoSnapshotResolvido($c['arquivo']),
        'listar' => listarCandidatosSnapshotsBanco($c['diretorio']),
        'rotacionar' => rotacionarSnapshotsBanco($c['diretorio'], 1),
        'ler' => lerMetadadosSnapshotBanco($c['arquivo']),
        'metadados' => gravarMetadadosSnapshotBanco('postgresql', $c['arquivo']),
        'snapshot' => gravarSnapshotBanco($c['dsn'], $c['arquivo']),
        'executar' => executarSnapshotBanco($c['dsn'], $c['diretorio'], 1, static fn (): int => 1700000000),
        default => throw new RuntimeException('Ação não permitida.'),
    };
    echo json_encode(['resultado' => $resultado, 'falhas' => $GLOBALS['chamadasFalhaIo']]);
} catch (RuntimeException $e) {
    echo json_encode(['erro' => $e->getMessage(), 'falhas' => $GLOBALS['chamadasFalhaIo']]);
}
RUNNER;
        try {
            file_put_contents($script, str_replace('__SUBSTITUTO__', $substitutos[$funcao], $codigo));
            return ExecutorCli::executar($script, [json_encode($cenario, JSON_THROW_ON_ERROR)], [], ['disable_functions' => $funcao]);
        } finally {
            unlink($script);
        }
    }
}
