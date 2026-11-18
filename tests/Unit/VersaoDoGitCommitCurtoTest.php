<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\VersaoDoGit;
use PHPUnit\Framework\TestCase;

/**
 * `App\Support\VersaoDoGit::commitCurto()` do começo ao fim, sobre checkouts
 * de mentira montados em diretório temporário.
 *
 * ContextoDeExecucaoGitTest chama cada peça privada por reflexão e
 * ContextoDeExecucaoTest monta um worktree de verdade num subprocesso; entre os
 * dois sobrava o caminho INTEIRO — ler o `.git`, decidir se o HEAD é um SHA ou
 * uma ref, escolher entre o gitdir privado e o comum, ler a ref solta e cortar
 * o commit curto —, e é nele que moram as decisões que um teste de peça isolada
 * não observa: quantos dígitos o commit curto tem, se os espaços em volta de um
 * arquivo do Git são ignorados, e se um arquivo com lixo antes ou depois do
 * formato documentado é recusado em vez de aproveitado pela metade.
 *
 * Nada aqui chama o binário `git`: o formato dos arquivos é o documentado, e a
 * leitura é de arquivo puro — é justamente essa a razão de a classe existir.
 */
final class VersaoDoGitCommitCurtoTest extends TestCase
{
    private const SHA = '0123456789abcdef0123456789abcdef01234567';

    /** SHA diferente, para provar QUAL dos dois arquivos foi lido. */
    private const OUTRO_SHA = 'fedcba9876543210fedcba9876543210fedcba98';

    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/versao-git-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->base, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->apagarDiretorio($this->base);
    }

    // ---------------------------------------------------------------------
    // HEAD destacado
    // ---------------------------------------------------------------------

    public function testHeadDestacadoDevolveOsSetePrimeirosDigitos(): void
    {
        $this->escrever('.git/HEAD', self::SHA . "\n");

        // Sete dígitos: é o tamanho que o Git usa por padrão em `--short`, e o
        // que o campo `version` do log promete. Seis ou oito não são "quase
        // certo", são outro identificador.
        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testHeadDestacadoIgnoraEspacosEmVolta(): void
    {
        // O Git escreve HEAD com quebra de linha no fim, e um checkout mexido à
        // mão pode ganhar espaço ou tabulação; nenhum dos dois faz parte do SHA.
        $this->escrever('.git/HEAD', "  \t" . self::SHA . " \n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testHeadComLixoAntesDeRefNaoApontaRefAlguma(): void
    {
        // O formato é a linha INTEIRA começando em `ref:`. Aceitar o `ref:` do
        // meio de outra palavra faria um HEAD adulterado escolher o arquivo que
        // este processo vai abrir.
        $this->escrever('.git/HEAD', "xref: refs/heads/main\n");
        $this->escrever('.git/refs/heads/main', self::SHA . "\n");

        $this->assertNull(VersaoDoGit::commitCurto($this->base));
    }

    public function testHeadComSujeiraDepoisDaRefNaoApontaRefAlguma(): void
    {
        // Idem no outro extremo: a ref vai até o fim da linha, e não até o
        // primeiro espaço.
        $this->escrever('.git/HEAD', "ref: refs/heads/main sujeira\n");
        $this->escrever('.git/refs/heads/main', self::SHA . "\n");

        $this->assertNull(VersaoDoGit::commitCurto($this->base));
    }

    // ---------------------------------------------------------------------
    // Ref solta
    // ---------------------------------------------------------------------

    public function testRefSoltaDevolveOsSetePrimeirosDigitos(): void
    {
        $this->escrever('.git/HEAD', "ref: refs/heads/main\n");
        $this->escrever('.git/refs/heads/main', self::SHA . "\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testRefSoltaIgnoraEspacosEmVolta(): void
    {
        $this->escrever('.git/HEAD', "ref: refs/heads/main\n");
        $this->escrever('.git/refs/heads/main', " " . self::SHA . "  \n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testRefSoltaQueNaoEShaCaiEmPackedRefs(): void
    {
        // Arquivo solto existe mas não contém um SHA (ref simbólica, conteúdo
        // truncado): a leitura precisa continuar em packed-refs, e não desistir.
        $this->escrever('.git/HEAD', "ref: refs/heads/main\n");
        $this->escrever('.git/refs/heads/main', "ref: refs/heads/outra\n");
        $this->escrever('.git/packed-refs', self::SHA . " refs/heads/main\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    // ---------------------------------------------------------------------
    // `.git` como ponteiro de linked worktree
    // ---------------------------------------------------------------------

    public function testPonteiroGitdirEAceitoEmMaiusculas(): void
    {
        $this->escrever('.git', 'GITDIR: ' . $this->base . "/real\n");
        $this->escrever('real/HEAD', self::SHA . "\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testPonteiroGitdirEAceitoComEspacoAntes(): void
    {
        $this->escrever('.git', '   gitdir: ' . $this->base . "/real\n");
        $this->escrever('real/HEAD', self::SHA . "\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base));
    }

    public function testPonteiroComLixoAntesDeGitdirNaoVale(): void
    {
        $this->escrever('.git', 'xgitdir: ' . $this->base . "/real\n");
        $this->escrever('real/HEAD', self::SHA . "\n");

        $this->assertNull(VersaoDoGit::commitCurto($this->base));
    }

    public function testPonteiroComSegundaLinhaNaoVale(): void
    {
        // O formato documentado é de UMA linha. Um arquivo com mais de uma
        // linha não é um ponteiro do Git, e adivinhar qual delas vale seria
        // escolher um caminho do sistema de arquivos por conta própria.
        $this->escrever('.git', 'gitdir: ' . $this->base . "/real\nsujeira\n");
        $this->escrever('real/HEAD', self::SHA . "\n");

        $this->assertNull(VersaoDoGit::commitCurto($this->base));
    }

    // ---------------------------------------------------------------------
    // Worktree: refs comuns × refs privadas
    // ---------------------------------------------------------------------

    public function testRefComumDoWorktreeVemDoGitdirComum(): void
    {
        // Layout de `git worktree add`: HEAD é privado, `refs/heads/` é comum.
        // Procurar `refs/heads/main` dentro do gitdir privado não acha nada.
        $this->montarWorktree('refs/heads/main');
        $this->escrever('repo/.git/refs/heads/main', self::SHA . "\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base . '/linked'));
    }

    public function testRefPrivadaDoWorktreeVemDoGitdirPrivado(): void
    {
        // `refs/worktree/` é a exceção: essa família vive no gitdir privado.
        // Os dois arquivos existem com SHAs diferentes, então o teste diz QUAL
        // foi lido, e não apenas que algum foi.
        $this->montarWorktree('refs/worktree/atual');
        $this->escrever('repo/.git/worktrees/linked/refs/worktree/atual', self::SHA . "\n");
        $this->escrever('repo/.git/refs/worktree/atual', self::OUTRO_SHA . "\n");

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base . '/linked'));
    }

    public function testRefComumComNomeQueImitaRefDeWorktreeVemDoComum(): void
    {
        // `refs/heads/refs/worktree/x` é um branch de nome infeliz, não uma ref
        // por worktree: a família privada se reconhece pelo INÍCIO do nome.
        $this->montarWorktree('refs/heads/refs/worktree/x');
        $this->escrever('repo/.git/refs/heads/refs/worktree/x', self::SHA . "\n");
        $this->escrever(
            'repo/.git/worktrees/linked/refs/heads/refs/worktree/x',
            self::OUTRO_SHA . "\n"
        );

        $this->assertSame('0123456', VersaoDoGit::commitCurto($this->base . '/linked'));
    }

    /**
     * Monta o par gitdir privado + gitdir comum de um linked worktree, com o
     * HEAD privado apontando para a ref pedida.
     */
    private function montarWorktree(string $ref): void
    {
        $this->escrever('linked/.git', 'gitdir: ' . $this->base . "/repo/.git/worktrees/linked\n");
        $this->escrever('repo/.git/worktrees/linked/commondir', "../..\n");
        $this->escrever('repo/.git/worktrees/linked/HEAD', 'ref: ' . $ref . "\n");
    }

    private function escrever(string $caminhoRelativo, string $conteudo): void
    {
        $caminho = $this->base . '/' . $caminhoRelativo;
        $diretorio = \dirname($caminho);
        if (!is_dir($diretorio)) {
            self::assertTrue(mkdir($diretorio, 0700, true));
        }
        self::assertIsInt(file_put_contents($caminho, $conteudo));
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
