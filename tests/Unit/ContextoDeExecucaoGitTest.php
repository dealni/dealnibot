<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ContextoDeExecucao;
use App\Support\VersaoDoGit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * As peças da leitura do `.git` COMO ARQUIVO que dá a `version` fora de uma
 * publicação: ponteiro de linked worktree, `commondir`, `packed-refs` e a
 * validação da ref apontada pelo HEAD.
 *
 * ContextoDeExecucaoTest prova o caminho inteiro num subprocesso montado com
 * `git worktree add`; a cobertura não atravessa essa fronteira, e cada peça
 * tem ramos de recusa (ponteiro malformado, ref com `..`, packed-refs
 * ausente) que o repositório real nunca exercita. Aqui cada uma é chamada
 * direto, sobre diretórios temporários, sem `git` e sem subprocesso.
 */
final class ContextoDeExecucaoGitTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/contexto-git-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->base, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->apagarDiretorio($this->base);
    }

    // ---------------------------------------------------------------------
    // diretorioDoGit: `.git` como diretório ou como ponteiro `gitdir:`
    // ---------------------------------------------------------------------

    public function testDiretorioDoGitDevolveOCaminhoRealDeUmDiretorio(): void
    {
        $gitDir = $this->base . '/.git';
        self::assertTrue(mkdir($gitDir));

        $this->assertSame(realpath($gitDir), self::chamar('diretorioDoGit', $gitDir));
    }

    public function testDiretorioDoGitDevolveNuloQuandoNaoHaCheckout(): void
    {
        $this->assertNull(self::chamar('diretorioDoGit', $this->base . '/.git'));
    }

    public function testPonteiroSemGitdirEIgnorado(): void
    {
        // Um `.git` que é arquivo mas não segue o formato documentado pelo
        // Git não pode virar um caminho: melhor `desconhecida` que um chute.
        $ponteiro = $this->base . '/.git';
        file_put_contents($ponteiro, "qualquer coisa\n");

        $this->assertNull(self::chamar('diretorioDoGit', $ponteiro));
    }

    public function testPonteiroRelativoEResolvidoAPartirDoProprioArquivo(): void
    {
        // Layout de `git worktree add`: o `.git` do worktree aponta ao gitdir
        // privado dentro do repositório principal, por caminho relativo.
        $privado = $this->base . '/repo/.git/worktrees/linked';
        self::assertTrue(mkdir($privado, 0700, true));
        self::assertTrue(mkdir($this->base . '/linked', 0700, true));
        $ponteiro = $this->base . '/linked/.git';
        file_put_contents($ponteiro, "gitdir: ../repo/.git/worktrees/linked\n");

        $this->assertSame(realpath($privado), self::chamar('diretorioDoGit', $ponteiro));
    }

    public function testPonteiroAbsolutoEUsadoComoEsta(): void
    {
        $privado = $this->base . '/gitdir';
        self::assertTrue(mkdir($privado));
        $ponteiro = $this->base . '/.git';
        file_put_contents($ponteiro, 'gitdir: ' . $privado);

        $this->assertSame(realpath($privado), self::chamar('diretorioDoGit', $ponteiro));
    }

    public function testPonteiroParaDiretorioInexistenteDevolveNulo(): void
    {
        $ponteiro = $this->base . '/.git';
        file_put_contents($ponteiro, 'gitdir: ' . $this->base . '/sumiu');

        $this->assertNull(self::chamar('diretorioDoGit', $ponteiro));
    }

    // ---------------------------------------------------------------------
    // diretorioComumDoGit: refs de um worktree vivem no gitdir comum
    // ---------------------------------------------------------------------

    public function testSemCommondirOGitdirComumEOProprio(): void
    {
        $gitDir = $this->base . '/.git';
        self::assertTrue(mkdir($gitDir));

        $this->assertSame($gitDir, self::chamar('diretorioComumDoGit', $gitDir));
    }

    public function testCommondirVazioNaoMudaOGitdir(): void
    {
        $gitDir = $this->base . '/.git';
        self::assertTrue(mkdir($gitDir));
        file_put_contents($gitDir . '/commondir', "  \n");

        $this->assertSame($gitDir, self::chamar('diretorioComumDoGit', $gitDir));
    }

    public function testCommondirRelativoEResolvidoAPartirDoGitdirPrivado(): void
    {
        // É o que o Git grava: `../..` do gitdir privado leva ao `.git` comum.
        $comum = $this->base . '/repo/.git';
        $privado = $comum . '/worktrees/linked';
        self::assertTrue(mkdir($privado, 0700, true));
        file_put_contents($privado . '/commondir', "../..\n");

        $this->assertSame(realpath($comum), self::chamar('diretorioComumDoGit', $privado));
    }

    public function testCommondirAbsolutoEUsadoComoEsta(): void
    {
        $comum = $this->base . '/comum';
        $privado = $this->base . '/privado';
        self::assertTrue(mkdir($comum));
        self::assertTrue(mkdir($privado));
        file_put_contents($privado . '/commondir', $comum);

        $this->assertSame(realpath($comum), self::chamar('diretorioComumDoGit', $privado));
    }

    public function testCommondirQueApontaParaLugarNenhumMantemOGitdirPrivado(): void
    {
        $privado = $this->base . '/privado';
        self::assertTrue(mkdir($privado));
        file_put_contents($privado . '/commondir', '../nao-existe');

        $this->assertSame($privado, self::chamar('diretorioComumDoGit', $privado));
    }

    // ---------------------------------------------------------------------
    // caminhoAbsoluto e refGitValida
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool}>
     */
    public static function caminhosProvider(): array
    {
        return [
            'posix absoluto' => ['/var/www/bot/.git', true],
            'windows com barra invertida' => ['C:\\repo\\.git', true],
            'windows com barra' => ['D:/repo/.git', true],
            'relativo' => ['../repo/.git', false],
            'vazio' => ['', false],
        ];
    }

    #[DataProvider('caminhosProvider')]
    public function testCaminhoAbsolutoReconheceAsDuasFamilias(string $caminho, bool $esperado): void
    {
        $this->assertSame($esperado, self::chamar('caminhoAbsoluto', $caminho));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function refsProvider(): array
    {
        return [
            'branch' => ['refs/heads/main', true],
            'tag' => ['refs/tags/v1.2.3', true],
            'fora de refs' => ['HEAD', false],
            'sobe diretorio' => ['refs/heads/../../etc/passwd', false],
            'barra dupla' => ['refs//heads/main', false],
            'barra invertida' => ['refs\\heads\\main', false],
            'com espaco' => ['refs/heads/ma in', false],
            'com controle' => ["refs/heads/main\x7f", false],
        ];
    }

    /**
     * O HEAD é lido como arquivo e a ref vira caminho concatenado ao gitdir:
     * sem esta guarda, um `.git` adulterado faria o processo ler um arquivo
     * qualquer do host só para logar a versão.
     */
    #[DataProvider('refsProvider')]
    public function testRefGitValidaSoAceitaCaminhosDentroDeRefs(string $ref, bool $esperado): void
    {
        $this->assertSame($esperado, self::chamar('refGitValida', $ref));
    }

    // ---------------------------------------------------------------------
    // doPackedRefs e doCarimboDecodificado
    // ---------------------------------------------------------------------

    public function testSemPackedRefsNaoHaVersao(): void
    {
        $this->assertNull(self::chamar('doPackedRefs', $this->base, 'refs/heads/main'));
    }

    public function testPackedRefsDevolveOCommitCurtoDaRefPedida(): void
    {
        // Depois de um `git gc` o arquivo solto some e o SHA passa a viver só
        // aqui; o formato é o do Git, com cabeçalho e uma ref por linha.
        file_put_contents(
            $this->base . '/packed-refs',
            "# pack-refs with: peeled fully-peeled sorted\n"
            . "fedcba9876543210fedcba9876543210fedcba98 refs/heads/outra\n"
            . self::SHA . " refs/heads/main\n"
        );

        $this->assertSame('0123456', self::chamar('doPackedRefs', $this->base, 'refs/heads/main'));
        $this->assertNull(self::chamar('doPackedRefs', $this->base, 'refs/heads/inexistente'));
    }

    public function testCarimboSemTagNemCommitTextuaisNaoInventaVersao(): void
    {
        // Um `.release.json` corrompido (tipos errados) não pode virar
        // `version: Array` no log; a resposta honesta continua sendo null.
        $this->assertNull(self::chamar('doCarimboDecodificado', ['tag' => 5, 'commit' => ['x']]));
        $this->assertNull(self::chamar('doCarimboDecodificado', []));
        // Tag só de espaços cai no commit curto, como uma tag ausente.
        $this->assertSame('0123456', self::chamar('doCarimboDecodificado', ['tag' => '   ', 'commit' => self::SHA]));
    }

    private static function chamar(string $metodo, mixed ...$argumentos): mixed
    {
        $classe = $metodo === 'doCarimboDecodificado' ? ContextoDeExecucao::class : VersaoDoGit::class;
        $reflexao = new ReflectionMethod($classe, $metodo);

        return $reflexao->invoke(null, ...$argumentos);
    }

    private function apagarDiretorio(string $diretorio): void
    {
        if (!is_dir($diretorio)) {
            return;
        }

        $itens = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($itens as $item) {
            self::assertInstanceOf(\SplFileInfo::class, $item);
            if ($item->isLink() || !$item->isDir()) {
                unlink($item->getPathname());
            } else {
                rmdir($item->getPathname());
            }
        }
        rmdir($diretorio);
    }
}
