<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class ContextoGitIsoladoTest extends TestCase
{
    /** @return array<string, array{array<string, string>, string, bool}> */
    public static function publicacoes(): array
    {
        $sha = str_repeat('abcdef0123', 4);
        return [
            'gitfile inválido' => [['.git' => 'sem ponteiro'], 'desconhecida', false],
            'gitfile vazio' => [['.git' => 'gitdir: '], 'desconhecida', false],
            'gitdir inexistente' => [['.git' => 'gitdir: {{BASE}}/ausente'], 'desconhecida', false],
            'gitdir aponta para arquivo' => [['.git' => 'gitdir: {{BASE}}/arquivo', 'arquivo' => 'texto'], 'desconhecida', false],
            'worktree destacado' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => $sha], 'abcdef0', false],
            'worktree referência fora de refs' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => 'ref: config'], 'desconhecida', false],
            'worktree referência transversal' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => 'ref: refs/../config'], 'desconhecida', false],
            'commondir absoluto' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => 'ref: refs/heads/main', 'metadados/commondir' => '{{BASE}}/comum', 'comum/refs/heads/main' => $sha], 'abcdef0', false],
            'commondir vazio' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => 'ref: refs/heads/main', 'metadados/commondir' => ' ', 'metadados/refs/heads/main' => $sha], 'abcdef0', false],
            'commondir ausente no disco' => [['.git' => 'gitdir: {{BASE}}/metadados', 'metadados/HEAD' => 'ref: refs/heads/main', 'metadados/commondir' => '../ausente', 'metadados/refs/heads/main' => $sha], 'abcdef0', false],
            'fora de checkout' => [[], 'desconhecida', false],
            'HEAD ausente' => [['.git/config' => ''], 'desconhecida', false],
            'HEAD inválido' => [['.git/HEAD' => 'inválido'], 'desconhecida', false],
            'referência empacotada' => [['.git/HEAD' => 'ref: refs/heads/main', '.git/packed-refs' => $sha . ' refs/heads/main'], 'abcdef0', false],
            'outra referência empacotada' => [['.git/HEAD' => 'ref: refs/heads/main', '.git/packed-refs' => $sha . ' refs/heads/outra'], 'desconhecida', false],
            'referência ausente' => [['.git/HEAD' => 'ref: refs/heads/main'], 'desconhecida', false],
            'carimbo escalar' => [['release.json' => '12'], 'desconhecida', false],
            'carimbo deixa de poder ser lido' => [['release.json' => '{"tag":"v1"}'], 'desconhecida', true],
        ];
    }

    /** @param array<string, string> $arquivos */
    #[DataProvider('publicacoes')]
    public function testVersaoRefleteArquivosDoAmbienteSemAlterarCheckout(array $arquivos, string $esperado, bool $falhaDeLeitura): void
    {
        $base = sys_get_temp_dir() . '/contexto_git_' . bin2hex(random_bytes(8));
        mkdir($base, 0700);
        foreach ($arquivos as $nome => $conteudo) {
            $pai = dirname($base . '/' . $nome);
            if (!is_dir($pai)) {
                mkdir($pai, 0700, true);
            }
            file_put_contents($base . '/' . $nome, str_replace('{{BASE}}', $base, $conteudo));
        }
        $script = $base . '/executar.php';
        file_put_contents($script, <<<'RUNNER'
<?php
namespace App\Support;
$GLOBALS['gitRaizReal'] = $argv[1];
$GLOBALS['gitRaizFixture'] = $argv[2];
function arquivoDoAmbienteDeTeste(string $caminho): string {
    $git = $GLOBALS['gitRaizReal'] . '/.git';
    return $caminho === $git || \str_starts_with($caminho, $git . '/')
        ? $GLOBALS['gitRaizFixture'] . '/.git' . \substr($caminho, \strlen($git)) : $caminho;
}
function is_dir(string $p): bool { return \is_dir(arquivoDoAmbienteDeTeste($p)); }
function is_file(string $p): bool { return \is_file(arquivoDoAmbienteDeTeste($p)); }
function is_readable(string $p): bool { return \is_readable(arquivoDoAmbienteDeTeste($p)); }
function realpath(string $p): string|false { return \realpath(arquivoDoAmbienteDeTeste($p)); }
function file_get_contents(string $p): string|false {
    if ($GLOBALS['argv'][3] === '1' && $p === $GLOBALS['gitRaizFixture'] . '/release.json') { return false; }
    return \file_get_contents(arquivoDoAmbienteDeTeste($p));
}
require $argv[1] . '/vendor/autoload.php';
putenv('CHATBOTS_RELEASE_FILE=' . $argv[2] . '/release.json');
echo ContextoDeExecucao::versao();
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2), $base, $falhaDeLeitura ? '1' : '0']);
            self::assertSame(0, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertSame($esperado, $r['stdout']);
            self::assertSame('', $r['stderr']);
        } finally {
            $itens = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($itens as $item) {
                if ($item instanceof \SplFileInfo) {
                    if ($item->isDir()) {
                        rmdir($item->getPathname());
                    } else {
                        unlink($item->getPathname());
                    }
                }
            }
            rmdir($base);
        }
    }
}
