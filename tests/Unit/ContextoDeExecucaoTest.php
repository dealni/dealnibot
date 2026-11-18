<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ContextoDeExecucao;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * `request_id`, `env` e `version`: a identidade que todo evento carrega.
 */
final class ContextoDeExecucaoTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    private const NOME_DO_SERVICO = 'dealnibot';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /** @var list<string> */
    private array $diretoriosTemporarios = [];

    private bool $requestIdHeaderExistia = false;

    private mixed $requestIdHeaderAnterior = null;

    private bool $arquivoReleaseExistia = false;

    private string $arquivoReleaseAnterior = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestIdHeaderExistia = array_key_exists('HTTP_X_REQUEST_ID', $_SERVER);
        $this->requestIdHeaderAnterior = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        $arquivoRelease = getenv('CHATBOTS_RELEASE_FILE');
        $this->arquivoReleaseExistia = is_string($arquivoRelease);
        $this->arquivoReleaseAnterior = is_string($arquivoRelease) ? $arquivoRelease : '';
    }

    protected function tearDown(): void
    {
        if ($this->requestIdHeaderExistia) {
            $_SERVER['HTTP_X_REQUEST_ID'] = $this->requestIdHeaderAnterior;
        } else {
            unset($_SERVER['HTTP_X_REQUEST_ID']);
        }
        if ($this->arquivoReleaseExistia) {
            putenv('CHATBOTS_RELEASE_FILE=' . $this->arquivoReleaseAnterior);
        } else {
            putenv('CHATBOTS_RELEASE_FILE');
        }
        foreach ($this->diretoriosTemporarios as $diretorio) {
            $this->removerArvoreTemporaria($diretorio);
        }
        $this->diretoriosTemporarios = [];
        ContextoDeExecucao::reset();
        parent::tearDown();
    }

    public function testRequestIdEhOMesmoDuranteTodoOCiclo(): void
    {
        ContextoDeExecucao::reset();

        $primeiro = ContextoDeExecucao::requestId();

        $this->assertSame($primeiro, ContextoDeExecucao::requestId());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{8,64}$/', $primeiro);
    }

    public function testRequestIdGeradoEhUuidV4(): void
    {
        ContextoDeExecucao::reset();
        $primeiro = ContextoDeExecucao::requestId();

        ContextoDeExecucao::reset();
        $segundo = ContextoDeExecucao::requestId();

        // Versão 4 e variante RFC 4122 nos lugares certos; e dois ciclos
        // diferentes nunca compartilham id — colisão aqui é um pagamento
        // atribuído ao ciclo errado no log.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $primeiro
        );
        $this->assertNotSame($primeiro, $segundo);
    }

    public function testRequestIdRecebidoNoHeaderEhAceitoQuandoCasaOPadrao(): void
    {
        ContextoDeExecucao::reset();
        $_SERVER['HTTP_X_REQUEST_ID'] = 'ciclo-do-gateway_123';

        $this->assertSame('ciclo-do-gateway_123', ContextoDeExecucao::requestId());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function requestIdRecusadoProvider(): array
    {
        return [
            'ausente' => [null],
            'vazio' => [''],
            'curto demais' => ['abc1234'],
            'longo demais' => [str_repeat('a', 65)],
            'com quebra de linha' => ["abc12345\nlinha forjada"],
            'com espaço' => ['abc 12345'],
            'com caractere de controle' => ["abc12345\x00"],
            'não textual' => [['abc12345']],
        ];
    }

    #[DataProvider('requestIdRecusadoProvider')]
    public function testRequestIdForaDoPadraoEhSubstituidoPorUmGerado(mixed $recebido): void
    {
        // O valor vem do cliente. Sem esta recusa ele injeta quebra de linha
        // (uma linha de log forjada), payload gigante ou controle de terminal
        // em TODO evento do ciclo — o log deixa de ser evidência.
        ContextoDeExecucao::reset();
        $_SERVER['HTTP_X_REQUEST_ID'] = $recebido;

        $gerado = ContextoDeExecucao::requestId();

        $this->assertNotSame($recebido, $gerado);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $gerado);
    }

    public function testServicoMantemONomeEmUmCheckoutRenomeado(): void
    {
        $this->assertSame(self::NOME_DO_SERVICO, ContextoDeExecucao::servico());

        $raiz = sys_get_temp_dir() . '/checkout-renomeado-' . bin2hex(random_bytes(6));
        $suporte = $raiz . '/src/Support';
        self::assertTrue(mkdir($suporte, 0700, true));
        $this->diretoriosTemporarios[] = $raiz;
        $arquivo = $suporte . '/ContextoDeExecucao.php';
        self::assertTrue(copy(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', $arquivo));

        $codigo = 'require $argv[1]; echo App\Support\ContextoDeExecucao::servico();';
        $resultado = $this->executarNoDiretorio([PHP_BINARY, '-r', $codigo, $arquivo], $raiz);
        self::assertSame(0, $resultado['status'], $resultado['saida']);
        self::assertSame(self::NOME_DO_SERVICO, $resultado['saida']);
    }

    public function testAmbienteEhSempreUmDosQuatroNomesDoContrato(): void
    {
        $this->assertContains(
            ContextoDeExecucao::ambiente(),
            ['development', 'test', 'staging', 'production']
        );
        // A suíte roda com APP_ENV=test (phpunit.xml), e é o valor do contrato.
        $this->assertSame('test', ContextoDeExecucao::ambiente());
        $this->assertFalse(ContextoDeExecucao::producao());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAmbienteNormalizaCaixaEEspacosAntesDeAplicarOApelido(): void
    {
        putenv('APP_ENV=  HoMoLoG  ');

        $this->assertSame('staging', ContextoDeExecucao::ambiente());
        $this->assertFalse(ContextoDeExecucao::producao());
    }

    public function testVersaoEhUmaStringNaoVaziaECacheadaNoProcesso(): void
    {
        ContextoDeExecucao::reset();

        $versao = ContextoDeExecucao::versao();

        $this->assertNotSame('', $versao);
        $this->assertSame($versao, ContextoDeExecucao::versao());
        // Sem carimbo e sem checkout a resposta honesta é `desconhecida`; num
        // checkout, o commit curto. Nunca um número inventado.
        $this->assertMatchesRegularExpression('/^(desconhecida|[0-9a-f]{7}|v?\d+\.\d+\.\d+.*)$/', $versao);
    }

    public function testVersaoSaiDoCarimboDePublicacaoQuandoEleExiste(): void
    {
        // É o carimbo que o `scripts/deploy/deploy.sh` grava — a única identidade de
        // publicação que existe numa VPS, onde o artefato é o diretório.
        $carimbo = tempnam(sys_get_temp_dir(), 'release');
        $this->assertIsString($carimbo);

        try {
            file_put_contents($carimbo, json_encode([
                'commit' => '0123456789abcdef0123456789abcdef01234567',
                'tag' => '',
            ]));
            putenv('CHATBOTS_RELEASE_FILE=' . $carimbo);
            ContextoDeExecucao::reset();

            $this->assertSame('0123456', ContextoDeExecucao::versao());

            // Quando os sete primeiros dígitos não bastam para serem únicos,
            // o marcador preserva a abreviação maior devolvida pelo Git.
            file_put_contents($carimbo, json_encode([
                'commit' => '0123456789abcdef0123456789abcdef01234567',
                'commit_curto' => '012345678',
                'tag' => '',
            ]));
            ContextoDeExecucao::reset();

            $this->assertSame('012345678', ContextoDeExecucao::versao());

            // Publicação com nome usa a tag; é ela que o operador reconhece.
            file_put_contents($carimbo, json_encode([
                'commit' => '0123456789abcdef0123456789abcdef01234567',
                'tag' => 'v3.1.0',
            ]));
            ContextoDeExecucao::reset();

            $this->assertSame('v3.1.0', ContextoDeExecucao::versao());
        } finally {
            putenv('CHATBOTS_RELEASE_FILE');
            unlink($carimbo);
            ContextoDeExecucao::reset();
        }
    }

    public function testVersaoMantemOValorCacheadoQuandoOCarimboMuda(): void
    {
        $carimbo = tempnam(sys_get_temp_dir(), 'release-cache');
        $this->assertIsString($carimbo);

        try {
            $this->assertNotFalse(file_put_contents($carimbo, '{"tag":"v1.0.0"}'));
            putenv('CHATBOTS_RELEASE_FILE=' . $carimbo);
            ContextoDeExecucao::reset();

            $this->assertSame('v1.0.0', ContextoDeExecucao::versao());

            $this->assertNotFalse(file_put_contents($carimbo, '{"tag":"v2.0.0"}'));
            $this->assertSame('v1.0.0', ContextoDeExecucao::versao());
        } finally {
            putenv('CHATBOTS_RELEASE_FILE');
            ContextoDeExecucao::reset();
            if (is_file($carimbo)) {
                unlink($carimbo);
            }
        }
    }

    public function testVersaoLeLinkedWorktreeComGitfileERefEmpacotada(): void
    {
        // `git worktree add` cria um `.git` que é um gitfile, enquanto HEAD
        // fica no gitdir privado e refs/heads + packed-refs ficam no diretório
        // comum. O processo separado faz a raiz de ContextoDeExecucao ser a
        // fixture, sem tocar no checkout desta suíte.
        $raiz = sys_get_temp_dir() . '/contexto gitfile-' . bin2hex(random_bytes(6));
        $comum = $raiz . '/repo.git';
        $privado = $comum . '/worktrees/feature';
        $suporte = $raiz . '/src/Support';
        $processo = null;
        $canos = [];
        $processoFechado = false;
        $limpar = static function (string $diretorio): void {
            if (!is_dir($diretorio)) {
                return;
            }
            $iterador = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterador as $entrada) {
                if (!$entrada instanceof \SplFileInfo) {
                    continue;
                }
                $caminho = $entrada->getPathname();
                if ($entrada->isDir()) {
                    rmdir($caminho);
                } else {
                    unlink($caminho);
                }
            }
            rmdir($diretorio);
        };

        try {
            mkdir($suporte, 0777, true);
            mkdir($privado, 0777, true);
            copy(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', $suporte . '/ContextoDeExecucao.php');
            copy(__DIR__ . '/../../src/Support/VersaoDoGit.php', $suporte . '/VersaoDoGit.php');
            copy(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', $suporte . '/RuntimeEnvironment.php');
            file_put_contents($raiz . '/.git', "gitdir: repo.git/worktrees/feature\n");
            file_put_contents($privado . '/HEAD', "ref: refs/heads/feature\n");
            file_put_contents($privado . '/commondir', "../..\n");
            file_put_contents(
                $comum . '/packed-refs',
                "# pack-refs with: peeled fully-peeled\n" . str_repeat('a', 40) . " refs/heads/feature\n",
            );
            $runner = $raiz . '/versao.php';
            file_put_contents($runner, <<<'PHP'
<?php
require $argv[1] . '/RuntimeEnvironment.php';
require $argv[1] . '/VersaoDoGit.php';
require $argv[1] . '/ContextoDeExecucao.php';
putenv('CHATBOTS_RELEASE_FILE=' . $argv[2]);
echo App\Support\ContextoDeExecucao::versao();
PHP);

            try {
                $processo = proc_open(
                    [PHP_BINARY, $runner, $suporte, $raiz . '/release-ausente.json'],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $canos,
                    $raiz,
                );
                $this->assertIsResource($processo);
                $saida = stream_get_contents($canos[1]);
                $erro = stream_get_contents($canos[2]);
                fclose($canos[1]);
                fclose($canos[2]);
                $status = proc_close($processo);
                $processoFechado = true;
                $processo = null;
                $this->assertSame(
                    0,
                    $status,
                    $erro !== '' ? $erro : 'fixture de linked worktree falhou.',
                );
                $this->assertSame('', $erro);
                $this->assertSame('aaaaaaa', $saida);
            } finally {
                foreach ($canos as $cano) {
                    if (is_resource($cano)) {
                        fclose($cano);
                    }
                }
                if (is_resource($processo) && !$processoFechado) {
                    $status = proc_get_status($processo);
                    if ($status['running']) {
                        proc_terminate($processo);
                    }
                    @proc_close($processo);
                }
            }
        } finally {
            $limpar($raiz);
        }
    }

    public function testVersaoLeHeadDeLinkedWorktreeSemSubprocessoEmRuntime(): void
    {
        $base = sys_get_temp_dir() . '/contexto-linked-worktree-' . bin2hex(random_bytes(6));
        $repositorio = $base . '/repo';
        $worktree = $base . '/linked';
        self::assertTrue(mkdir($repositorio, 0700, true));
        $this->diretoriosTemporarios[] = $base;

        foreach ([
            ['git', '-C', $repositorio, 'init', '-q'],
            ['git', '-C', $repositorio, 'config', 'user.name', 'Contexto Teste'],
            ['git', '-C', $repositorio, 'config', 'user.email', 'contexto@example.invalid'],
            ['git', '-C', $repositorio, 'commit', '--allow-empty', '-m', 'inicial', '-q'],
            ['git', '-C', $repositorio, 'branch', '-M', 'main'],
            ['git', '-C', $repositorio, 'worktree', 'add', '-q', '-b', 'linked', $worktree],
        ] as $comando) {
            $resultado = $this->executarNoDiretorio($comando, $base);
            self::assertSame(0, $resultado['status'], implode(' ', $comando) . ': ' . $resultado['saida']);
        }

        $commit = $this->executarNoDiretorio(['git', '-C', $repositorio, 'rev-parse', '--verify', 'HEAD'], $base);
        self::assertSame(0, $commit['status'], $commit['saida']);
        $sha = trim($commit['saida']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);

        self::assertTrue(mkdir($worktree . '/src/Support', 0700, true));
        self::assertTrue(copy(
            dirname(__DIR__, 2) . '/src/Support/RuntimeEnvironment.php',
            $worktree . '/src/Support/RuntimeEnvironment.php',
        ));
        self::assertTrue(copy(
            dirname(__DIR__, 2) . '/src/Support/ContextoDeExecucao.php',
            $worktree . '/src/Support/ContextoDeExecucao.php',
        ));
        self::assertTrue(copy(
            dirname(__DIR__, 2) . '/src/Support/VersaoDoGit.php',
            $worktree . '/src/Support/VersaoDoGit.php',
        ));

        $codigo = 'require $argv[1]; require dirname($argv[2]) . "/VersaoDoGit.php"; require $argv[2]; putenv("CHATBOTS_RELEASE_FILE=" . $argv[3]); echo \\App\\Support\\ContextoDeExecucao::versao();';
        $versao = $this->executarNoDiretorio([
            PHP_BINARY,
            '-r',
            $codigo,
            $worktree . '/src/Support/RuntimeEnvironment.php',
            $worktree . '/src/Support/ContextoDeExecucao.php',
            $base . '/release-inexistente.json',
        ], $worktree);

        self::assertSame(0, $versao['status'], $versao['saida']);
        self::assertSame(substr($sha, 0, 7), trim($versao['saida']));
    }

    /**
     * Executa um binário com argv, sem passar por shell ou pelo código de
     * produção. Isto deixa o fixture de worktree independente de quoting.
     *
     * @param list<string> $comando
     * @return array{status: int, saida: string}
     */
    private function executarNoDiretorio(array $comando, string $diretorio): array
    {
        $processo = proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canos,
            $diretorio,
        );
        self::assertIsResource($processo);
        fclose($canos[0]);
        $saida = (string) stream_get_contents($canos[1]);
        $erro = (string) stream_get_contents($canos[2]);
        fclose($canos[1]);
        fclose($canos[2]);

        return ['status' => proc_close($processo), 'saida' => $saida . $erro];
    }

    public function testVersaoUsaOCaminhoPadraoDoCarimboQuandoNaoHaVariavel(): void
    {
        $versao = $this->versaoEmCheckoutTemporario([
            '.release.json' => '{"tag":"v-padrao"}',
        ]);

        $this->assertSame('v-padrao', $versao);
    }

    public function testCarimboAceitaAProfundidadeMaximaDoJson(): void
    {
        $valor = 'folha';
        for ($i = 0; $i < 510; $i++) {
            $valor = ['x' => $valor];
        }
        $json = json_encode(
            ['tag' => 'v-profundidade', 'ruido' => $valor],
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('v-profundidade', $this->versaoComCarimbo($json));
    }

    public function testCarimboRejeitaJsonAcimaDaProfundidadeMaxima(): void
    {
        $valor = 'folha';
        for ($i = 0; $i < 511; $i++) {
            $valor = ['x' => $valor];
        }
        $json = json_encode(
            ['tag' => 'v-profundo-demais', 'ruido' => $valor],
            JSON_THROW_ON_ERROR,
        );

        $versao = $this->versaoComCarimbo($json);

        $this->assertNotSame('v-profundo-demais', $versao);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $versao);
    }

    public function testCarimboIgnoraTagSomenteComEspacos(): void
    {
        $this->assertSame(
            '0123456',
            $this->versaoComCarimbo('{"tag":"   ","commit":"0123456789abcdef0123456789abcdef01234567"}'),
        );
    }

    public function testCarimboNormalizaTagComEspacos(): void
    {
        $this->assertSame(
            'v3.2.0',
            $this->versaoComCarimbo('{"tag":"  v3.2.0  ","commit":"fedcba9876543210fedcba9876543210fedcba98"}'),
        );
    }

    public function testCarimboNormalizaCommitCurtoComEspacos(): void
    {
        $this->assertSame(
            '012345678',
            $this->versaoComCarimbo('{"commit_curto":" 012345678 ","commit":"fedcba9876543210fedcba9876543210fedcba98"}'),
        );
    }

    public function testCarimboRejeitaCommitCurtoComSufixo(): void
    {
        $this->assertSame(
            'fedcba9',
            $this->versaoComCarimbo('{"commit_curto":"012345678-extra","commit":"fedcba9876543210fedcba9876543210fedcba98"}'),
        );
    }

    public function testCarimboExigeCommitCurtoDesdeOInicio(): void
    {
        $this->assertSame(
            'fedcba9',
            $this->versaoComCarimbo('{"commit_curto":"bad-012345678","commit":"fedcba9876543210fedcba9876543210fedcba98"}'),
        );
    }

    public function testCarimboAceitaCommitCurtoComLetrasMaiusculas(): void
    {
        $this->assertSame(
            'ABCDEF012345678',
            $this->versaoComCarimbo('{"commit_curto":"ABCDEF012345678","commit":"fedcba9876543210fedcba9876543210fedcba98"}'),
        );
    }

    public function testCarimboIgnoraCommitSomenteComEspacos(): void
    {
        $versao = $this->versaoComCarimbo('{"commit":"   "}');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{7}$/', $versao);
    }

    public function testCarimboNormalizaCommitCompletoComEspacos(): void
    {
        $this->assertSame(
            '0123456',
            $this->versaoComCarimbo('{"commit":" 0123456789abcdef0123456789abcdef01234567 "}'),
        );
    }

    public function testGitfileLeRefSoltaComCabecalhoComEspacos(): void
    {
        $hash = str_repeat('b', 40);
        $versao = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "  ref: refs/heads/feature  \n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/heads/feature' => $hash . "\n",
        ], sys_get_temp_dir() . '/release-ausente-' . bin2hex(random_bytes(4)) . '.json');

        $this->assertSame('bbbbbbb', $versao);
    }

    public function testGitfileRejeitaRefComPrefixoOuSufixoEspurios(): void
    {
        $hash = str_repeat('c', 40);
        $prefixo = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "junk ref: refs/heads/feature\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/heads/feature' => $hash . "\n",
        ], sys_get_temp_dir() . '/release-ausente-' . bin2hex(random_bytes(4)) . '.json');
        $sufixo = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "ref: refs/heads/feature trailing\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/heads/feature' => $hash . "\n",
        ], sys_get_temp_dir() . '/release-ausente-' . bin2hex(random_bytes(4)) . '.json');

        $this->assertSame('desconhecida', $prefixo);
        $this->assertSame('desconhecida', $sufixo);
    }

    public function testCheckoutComGitDiretorioNormalizaHeadDestacadoEExigeHashInteiro(): void
    {
        $hash = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
        $espacos = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "  {$hash}  \n",
        ]);
        $prefixo = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "prefixo{$hash}\n",
        ]);
        $sufixo = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "{$hash}sufixo\n",
        ]);
        $minusculo = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "abcdef0123456789abcdef0123456789abcdef01\n",
        ]);

        $this->assertSame('ABCDEF0', $espacos);
        $this->assertSame('desconhecida', $prefixo);
        $this->assertSame('desconhecida', $sufixo);
        $this->assertSame('abcdef0', $minusculo);
    }

    public function testRefSoltaNormalizaEExigeHashInteiroComCaixaPreservada(): void
    {
        $hash = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
        $espacos = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "ref: refs/heads/main\n",
            '.git/refs/heads/main' => "  {$hash}  \n",
        ]);
        $prefixo = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "ref: refs/heads/main\n",
            '.git/refs/heads/main' => "prefixo{$hash}\n",
        ]);
        $sufixo = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "ref: refs/heads/main\n",
            '.git/refs/heads/main' => "{$hash}sufixo\n",
        ]);
        $deslocado = $this->versaoEmCheckoutTemporario([
            '.git/HEAD' => "ref: refs/heads/main\n",
            '.git/refs/heads/main' => '0123456789abcdef0123456789abcdef01234567' . "\n",
        ]);

        $this->assertSame('ABCDEF0', $espacos);
        $this->assertSame('desconhecida', $prefixo);
        $this->assertSame('desconhecida', $sufixo);
        $this->assertSame('0123456', $deslocado);
    }

    public function testGitfileRecusaRefForaDeRefsETravessiaMesmoComArquivoAlvo(): void
    {
        $foraDaRaiz = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "ref: heads/feature\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/heads/feature' => str_repeat('d', 40) . "\n",
        ]);
        $travessia = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "ref: refs/heads/../escape\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/escape' => str_repeat('e', 40) . "\n",
        ]);

        $this->assertSame('desconhecida', $foraDaRaiz);
        $this->assertSame('desconhecida', $travessia);
    }

    public function testGitfileEscolheDiretorioComumEPrivadoConformeORef(): void
    {
        $comum = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "ref: refs/heads/refs/worktree/current\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/heads/refs/worktree/current' => str_repeat('c', 40) . "\n",
            'repo.git/worktrees/feature/refs/heads/refs/worktree/current' => str_repeat('d', 40) . "\n",
        ]);
        $privado = $this->versaoEmCheckoutTemporario([
            '.git' => "gitdir: repo.git/worktrees/feature\n",
            'repo.git/worktrees/feature/HEAD' => "ref: refs/worktree/current\n",
            'repo.git/worktrees/feature/commondir' => "../..\n",
            'repo.git/refs/worktree/current' => str_repeat('c', 40) . "\n",
            'repo.git/worktrees/feature/refs/worktree/current' => str_repeat('d', 40) . "\n",
        ]);

        $this->assertSame('ccccccc', $comum);
        $this->assertSame('ddddddd', $privado);
    }

    public function testCarimboIlegivelNaoDerrubaOLogENaoInventaVersao(): void
    {
        putenv('CHATBOTS_RELEASE_FILE=' . sys_get_temp_dir() . '/nao-existe-' . uniqid() . '.json');
        ContextoDeExecucao::reset();

        try {
            $this->assertNotSame('', ContextoDeExecucao::versao());
        } finally {
            putenv('CHATBOTS_RELEASE_FILE');
            ContextoDeExecucao::reset();
        }
    }

    public function testCicloWebEmSapiHttpEnviaORequestIdNoHeader(): void
    {
        $contexto = var_export(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', true);
        $versaoGit = var_export(__DIR__ . '/../../src/Support/VersaoDoGit.php', true);
        $ambiente = var_export(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', true);
        $script = "<?php\n"
            . "namespace App\\Support;\n"
            . "\$headersSent = false; \$headers = [];\n"
            . "function headers_sent(): bool { global \$headersSent; return \$headersSent; }\n"
            . "function header(string \$line, bool \$replace = true, int \$code = 0): void { global \$headers; \$headers[] = \$line; }\n"
            . "require {$ambiente};\n"
            . "require {$versaoGit};\n"
            . "require {$contexto};\n"
            . "\$_SERVER['HTTP_X_REQUEST_ID'] = 'cgi_header_1234';\n"
            . "ContextoDeExecucao::reset();\n"
            . "\$primeiro = [ContextoDeExecucao::iniciarCicloWeb(), \$headers];\n"
            . "\$headersSent = true; \$headers = [];\n"
            . "\$_SERVER['HTTP_X_REQUEST_ID'] = 'cgi_sent_1234';\n"
            . "ContextoDeExecucao::reset();\n"
            . "\$segundo = [ContextoDeExecucao::iniciarCicloWeb(), \$headers];\n"
            . "echo json_encode(['sapi' => PHP_SAPI, 'primeiro' => \$primeiro, 'segundo' => \$segundo], JSON_THROW_ON_ERROR);";

        $resultado = $this->executarPhpDbg($script);
        $this->assertSame(0, $resultado['status'], $resultado['stderr']);
        $dados = json_decode(trim($resultado['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($dados);
        $this->assertSame('phpdbg', $dados['sapi'] ?? null);
        $primeiro = $dados['primeiro'] ?? null;
        $this->assertIsArray($primeiro);
        $this->assertSame('cgi_header_1234', $primeiro[0] ?? null);
        $this->assertIsArray($primeiro[1] ?? null);
        $this->assertContains('X-Request-Id: cgi_header_1234', $primeiro[1]);
        $segundo = $dados['segundo'] ?? null;
        $this->assertIsArray($segundo);
        $this->assertSame('cgi_sent_1234', $segundo[0] ?? null);
        $this->assertSame([], $segundo[1] ?? null);
    }

    public function testCicloWebNaoTentaAdicionarHeaderDepoisDeARespostaComecar(): void
    {
        $contexto = var_export(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', true);
        $versaoGit = var_export(__DIR__ . '/../../src/Support/VersaoDoGit.php', true);
        $ambiente = var_export(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', true);
        $script = "<?php\n"
            . "namespace App\\Support;\n"
            . "\$headersSent = true; \$headers = [];\n"
            . "function headers_sent(): bool { global \$headersSent; return \$headersSent; }\n"
            . "function header(string \$line, bool \$replace = true, int \$code = 0): void { global \$headers; \$headers[] = \$line; }\n"
            . "require {$ambiente};\n"
            . "require {$versaoGit};\n"
            . "require {$contexto};\n"
            . "\$_SERVER['HTTP_X_REQUEST_ID'] = 'cgi_sent_1234';\n"
            . "ContextoDeExecucao::reset();\n"
            . "ContextoDeExecucao::iniciarCicloWeb();\n"
            . "echo json_encode(\$headers, JSON_THROW_ON_ERROR);";

        $resultado = $this->executarPhpDbg($script);
        $this->assertSame(0, $resultado['status'], $resultado['stderr']);
        $headers = json_decode(trim($resultado['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($headers);
        $this->assertNotContains('X-Request-Id: cgi_sent_1234', $headers);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCicloWebNaoEmiteHeaderNoCliMesmoComHeadersDisponiveis(): void
    {
        $runtime = var_export(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', true);
        $contexto = var_export(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', true);
        $versaoGit = var_export(__DIR__ . '/../../src/Support/VersaoDoGit.php', true);
        $script = "namespace App\\Support;\n"
            . "\$headers = [];\n"
            . "function headers_sent(): bool { return false; }\n"
            . "function header(string \$line, bool \$replace = true, int \$code = 0): void { global \$headers; \$headers[] = \$line; }\n"
            . "require {$runtime}; require {$versaoGit}; require {$contexto};\n"
            . "\$_SERVER['HTTP_X_REQUEST_ID'] = 'cli_header_1234';\n"
            . "ContextoDeExecucao::reset(); ContextoDeExecucao::iniciarCicloWeb();\n"
            . "echo json_encode(\$headers, JSON_THROW_ON_ERROR);";

        $resultado = $this->executarProcesso([PHP_BINARY, '-r', $script]);
        $this->assertSame(0, $resultado['status'], $resultado['stderr']);
        $this->assertSame([], json_decode(trim($resultado['stdout']), true, 512, JSON_THROW_ON_ERROR));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCicloWebEmiteHeaderNoPhpDbgAntesDeHeadersSeremEnviados(): void
    {
        $runtime = var_export(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', true);
        $contexto = var_export(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', true);
        $versaoGit = var_export(__DIR__ . '/../../src/Support/VersaoDoGit.php', true);
        $script = "<?php\nnamespace App\\Support;\n"
            . "\$headers = [];\n"
            . "function headers_sent(): bool { return false; }\n"
            . "function header(string \$line, bool \$replace = true, int \$code = 0): void { global \$headers; \$headers[] = \$line; }\n"
            . "require {$runtime}; require {$versaoGit}; require {$contexto};\n"
            . "\$_SERVER['HTTP_X_REQUEST_ID'] = 'phpdbg_header_1234';\n"
            . "ContextoDeExecucao::reset(); ContextoDeExecucao::iniciarCicloWeb();\n"
            . "echo json_encode(['sapi' => PHP_SAPI, 'headers' => \$headers], JSON_THROW_ON_ERROR);";

        $resultado = $this->executarPhpDbg($script);
        $this->assertSame(0, $resultado['status'], $resultado['stderr']);
        $dados = json_decode(trim($resultado['stdout']), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($dados);
        $this->assertSame('phpdbg', $dados['sapi'] ?? null);
        $this->assertSame(['X-Request-Id: phpdbg_header_1234'], $dados['headers'] ?? null);
    }

    public function testUuidV4AplicaOsBitsEmBytesDeterminados(): void
    {
        $primeiros = '';
        for ($i = 0; $i < 16; $i++) {
            $primeiros .= chr($i);
        }
        $primeiros[5] = chr(5);
        $primeiros[6] = chr(6);
        $primeiros[7] = chr(7);
        $primeiros[8] = chr(8);
        $segundos = $primeiros;
        $segundos[6] = chr(7);

        $runtime = var_export(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', true);
        $contexto = var_export(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', true);
        $versaoGit = var_export(__DIR__ . '/../../src/Support/VersaoDoGit.php', true);
        $script = ""
            . "namespace App\\Support;\n"
            . "\$fixtures = [" . var_export($primeiros, true) . ', ' . var_export($segundos, true) . "];\n"
            . "function random_bytes(int \$length): string { global \$fixtures; return array_shift(\$fixtures); }\n"
            . "require {$runtime};\n"
            . "require {$versaoGit};\n"
            . "require {$contexto};\n"
            . "ContextoDeExecucao::reset();\n"
            . "\$primeiro = ContextoDeExecucao::requestId();\n"
            . "ContextoDeExecucao::reset();\n"
            . "\$segundo = ContextoDeExecucao::requestId();\n"
            . "echo json_encode([\$primeiro, \$segundo], JSON_THROW_ON_ERROR);";

        $resultado = $this->executarProcesso([PHP_BINARY, '-r', $script]);
        $this->assertSame(0, $resultado['status'], $resultado['stderr']);
        $this->assertSame(
            [
                '00010203-0405-4607-8809-0a0b0c0d0e0f',
                '00010203-0405-4707-8809-0a0b0c0d0e0f',
            ],
            json_decode(trim($resultado['stdout']), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUuidV4Solicita16BytesEFormataTodosOsOctetos(): void
    {
        $GLOBALS['contextoUuidFixtures'] = [
            "\x00\x01\x02\x03\x04\x05\xF7\x01\x03\x09\x0A\x0B\x0C\x0D\x0E\x0F",
            "\x10\x11\x12\x13\x14\x15\xF0\x02\x00\x19\x1A\x1B\x1C\x1D\x1E\x1F",
            "\x20\x21\x22\x23\x24\x25\xF2\x03\xFF\x29\x2A\x2B\x2C\x2D\x2E\x2F",
        ];
        eval(<<<'PHP'
namespace App\Support;
function random_bytes(int $length): string
{
    if ($length !== 16) {
        throw new \RuntimeException('uuidV4 deve solicitar exatamente 16 bytes.');
    }

    $fixture = array_shift($GLOBALS['contextoUuidFixtures']);
    if (!is_string($fixture)) {
        throw new \RuntimeException('uuidV4 solicitou bytes além das fixtures.');
    }

    return $fixture;
}
PHP);

        unset($_SERVER['HTTP_X_REQUEST_ID']);
        ContextoDeExecucao::reset();
        $this->assertSame(
            '00010203-0405-4701-8309-0a0b0c0d0e0f',
            ContextoDeExecucao::requestId(),
        );

        unset($_SERVER['HTTP_X_REQUEST_ID']);
        ContextoDeExecucao::reset();
        $this->assertSame(
            '10111213-1415-4002-8019-1a1b1c1d1e1f',
            ContextoDeExecucao::requestId(),
        );

        unset($_SERVER['HTTP_X_REQUEST_ID']);
        ContextoDeExecucao::reset();
        $this->assertSame(
            '20212223-2425-4203-bf29-2a2b2c2d2e2f',
            ContextoDeExecucao::requestId(),
        );
    }

    /**
     * @param array<string, string> $arquivos
     */
    private function versaoEmCheckoutTemporario(array $arquivos, ?string $carimboConfigurado = null): string
    {
        $raiz = sys_get_temp_dir() . '/contexto fixture-' . bin2hex(random_bytes(6));

        try {
            $suporte = $raiz . '/src/Support';
            mkdir($suporte, 0777, true);
            copy(__DIR__ . '/../../src/Support/ContextoDeExecucao.php', $suporte . '/ContextoDeExecucao.php');
            copy(__DIR__ . '/../../src/Support/VersaoDoGit.php', $suporte . '/VersaoDoGit.php');
            copy(__DIR__ . '/../../src/Support/RuntimeEnvironment.php', $suporte . '/RuntimeEnvironment.php');

            foreach ($arquivos as $relativo => $conteudo) {
                $caminho = $raiz . '/' . $relativo;
                $diretorio = dirname($caminho);
                if (!is_dir($diretorio)) {
                    mkdir($diretorio, 0777, true);
                }
                file_put_contents($caminho, $conteudo);
            }

            $runtime = var_export($suporte . '/RuntimeEnvironment.php', true);
            $contexto = var_export($suporte . '/ContextoDeExecucao.php', true);
            $versaoGit = var_export($suporte . '/VersaoDoGit.php', true);
            $configuracao = $carimboConfigurado === null
                ? "putenv('CHATBOTS_RELEASE_FILE');"
                : 'putenv(' . var_export('CHATBOTS_RELEASE_FILE=' . $carimboConfigurado, true) . ');';
            $script = "require {$runtime}; require {$versaoGit}; require {$contexto}; {$configuracao} echo \\App\\Support\\ContextoDeExecucao::versao();";

            $resultado = $this->executarProcesso([PHP_BINARY, '-r', $script]);
            $this->assertSame(0, $resultado['status'], $resultado['stderr']);
            $this->assertSame('', $resultado['stderr']);

            return $resultado['stdout'];
        } finally {
            $this->removerArvoreTemporaria($raiz);
        }
    }

    private function versaoComCarimbo(string $conteudo): string
    {
        $carimbo = tempnam(sys_get_temp_dir(), 'contexto-release');
        if ($carimbo === false) {
            $this->fail('Não foi possível criar o carimbo temporário.');
        }
        try {
            if (file_put_contents($carimbo, $conteudo) === false) {
                $this->fail('Não foi possível escrever o carimbo temporário.');
            }
            putenv('CHATBOTS_RELEASE_FILE=' . $carimbo);
            ContextoDeExecucao::reset();

            return ContextoDeExecucao::versao();
        } finally {
            putenv('CHATBOTS_RELEASE_FILE');
            ContextoDeExecucao::reset();
            if (is_file($carimbo)) {
                unlink($carimbo);
            }
        }
    }

    /**
     * @param list<string> $comando
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function executarProcesso(array $comando, string $entrada = ''): array
    {
        $canos = [];
        $processo = null;
        $processoFechado = false;

        try {
            $processo = proc_open(
                $comando,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $canos,
            );
            $this->assertIsResource($processo);
            if ($entrada !== '') {
                fwrite($canos[0], $entrada);
            }
            fclose($canos[0]);
            $stdout = stream_get_contents($canos[1]);
            $stderr = stream_get_contents($canos[2]);
            fclose($canos[1]);
            fclose($canos[2]);
            $status = proc_close($processo);
            $processoFechado = true;
            $processo = null;

            return [
                'status' => $status,
                'stdout' => is_string($stdout) ? $stdout : '',
                'stderr' => is_string($stderr) ? $stderr : '',
            ];
        } finally {
            foreach ($canos as $cano) {
                if (is_resource($cano)) {
                    fclose($cano);
                }
            }
            if (is_resource($processo) && !$processoFechado) {
                $status = proc_get_status($processo);
                if ($status['running']) {
                    proc_terminate($processo);
                }
                @proc_close($processo);
            }
        }
    }

    /**
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function executarPhpDbg(string $script): array
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'contexto-phpdbg');
        if ($arquivo === false) {
            $this->fail('Não foi possível criar o script phpdbg temporário.');
        }
        try {
            if (file_put_contents($arquivo, $script) === false) {
                $this->fail('Não foi possível escrever o script phpdbg temporário.');
            }
            return $this->executarProcesso([$this->phpDbgBinary(), '-qrr', $arquivo]);
        } finally {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
    }

    private function phpDbgBinary(): string
    {
        $candidatos = [];
        $configurado = getenv('PHPDBG_BINARY');
        if (is_string($configurado)) {
            $configurado = trim($configurado);
            if ($configurado !== '') {
                $candidatos[] = $configurado;
            }
        }
        $candidatos[] = dirname(PHP_BINARY) . '/phpdbg';
        $candidatos[] = '/usr/bin/phpdbg';
        $candidatos[] = '/opt/homebrew/opt/php/bin/phpdbg';

        foreach (array_unique($candidatos) as $candidato) {
            if (is_file($candidato) && is_executable($candidato)) {
                return $candidato;
            }
        }

        $this->fail('phpdbg é necessário para verificar o contrato do header web.');
    }

    private function removerArvoreTemporaria(string $diretorio): void
    {
        if (!is_dir($diretorio)) {
            return;
        }
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterador as $entrada) {
            if (!$entrada instanceof \SplFileInfo) {
                continue;
            }
            $caminho = $entrada->getPathname();
            if ($entrada->isDir() && !$entrada->isLink()) {
                rmdir($caminho);
            } else {
                unlink($caminho);
            }
        }
        rmdir($diretorio);
    }
}
