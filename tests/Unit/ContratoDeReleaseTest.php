<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrato de publicação, backup e recuperação — a parte que a máquina confere.
 *
 * O modo de falha destes assuntos é o silêncio. Um comando renomeado no
 * `composer.json` continua funcionando para quem digita o nome novo e some para
 * o runbook; um script movido só é descoberto no host, no pior momento; uma
 * guarda que parou de recusar fica verde em todo o resto. Este arquivo é o
 * portão que faz cada uma dessas coisas reprovar aqui.
 */
final class ContratoDeReleaseTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Raiz do checkout e grupo de runtime no host, como em registrar_publicacao.sh. */
    private const DIRETORIO_ESPERADO_NO_HOST = '/var/www/dealnibot';

    private const GRUPO_DE_RUNTIME = 'dealnibot';

    /** Prefixo dos diretórios temporários dos ensaios. */
    private const PREFIXO_TEMPORARIO = 'dealni-';

    /**
     * Entrypoints shell que trazem o próprio shim: são `#!/bin/sh`, e a
     * primeira coisa que fazem é reexecutar-se sob `env -i`, deixando de fora
     * BASH_ENV, PATH herdado e o resto do ambiente de quem chamou.
     *
     * @var list<string>
     */
    private const ENTRYPOINTS_COM_SHIM = [
        'instalar.sh',
        'scripts/deploy/deploy.sh',
        'scripts/deploy/deploy_verify.sh',
        'scripts/deploy/registrar_publicacao.sh',
        'scripts/deploy/release_check.sh',
        'scripts/deploy/rollback.sh',
        'scripts/install/instalar_linux.sh',
        'scripts/install/instalar_macos.sh',
        'scripts/deploy/db_backup.sh',
        'scripts/deploy/db_restore.sh',
        'scripts/deploy/db_restore_test.sh',
        'scripts/operacao/executar_com_ambiente.sh',
    ];

    /**
     * Instaladores de host que, além do deploy_verify.sh, validam o PHP85_BIN
     * canônico (vazio onde não há instalador de host versionado).
     *
     * @var list<string>
     */
    private const INSTALADORES_QUE_VALIDAM_PHP85_BIN = [
    ];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    /**
     * Devolve a lista como está no bloco de dados: o analisador estático não
     * pode concluir "sempre vazia" ou "sempre cheia" a partir do valor deste
     * repositório, porque o valor é dado, não código.
     *
     * @param list<string> $dados
     * @return list<string>
     */
    private static function dados(array $dados): array
    {
        return $dados;
    }

    /** Comando do contrato da frota => script que ele executa. */
    private const COMANDOS = [
        'release:check' => 'scripts/deploy/release_check.sh',
        'deploy' => 'scripts/deploy/deploy.sh',
        'deploy:verify' => 'scripts/deploy/deploy_verify.sh',
        'rollback' => 'scripts/deploy/rollback.sh',
        'db:backup' => 'scripts/deploy/db_backup.sh',
        'db:restore' => 'scripts/deploy/db_restore.sh',
        'db:restore:test' => 'scripts/deploy/db_restore_test.sh',
        'db:migrate:deploy' => 'scripts/deploy/migrate.php',
    ];

    /**
     * Portões de qualidade cujo NOME também é interface: `check:postgres`
     * absorveu os três nomes que apontavam para o mesmo script, e
     * `check:deploy-guards` juntou as duas guardas num comando só. O que se
     * exige aqui é o nome e o alvo; formato de bloco de dados e menção no
     * runbook são cobrança dos comandos de publicação, não destes.
     *
     * @var array<string, list<string>>
     */
    private const PORTOES_NOMEADOS = [
        'check:postgres' => ['@php scripts/quality/check_postgres.php'],
        'check:gitleaks' => ['bash scripts/quality/check_gitleaks.sh'],
        'check:deploy-guards' => [
            'bash scripts/quality/check_deploy_guard.sh',
            'bash scripts/quality/check_release_guards.sh',
        ],
        'check:strict-types' => ['@php scripts/quality/check_strict_types.php'],
    ];

    /**
     * Portões de qualidade que carregam lista específica deste repositório, e
     * por isso nascem em duas metades. Não são comando do contrato de release —
     * não publicam, não restauram e não tocam no host —, mas a estrutura é a
     * mesma, e é ela que mantém o dado deste bot separado do código do portão.
     *
     * @var list<string>
     */
    private const PORTOES_COM_BLOCO_DE_DADOS = [
        'scripts/quality/check_taxonomia_testes.php',
        'scripts/quality/check_tamanho.php',
        'scripts/quality/check_fpm_environment.php',
        'scripts/quality/check_strict_types.php',
    ];

    // O bloco de dados existe em shell e em PHP; o prefixo de comentário muda,
    // o contrato não. O texto é literal até o fecho de hífens: marcador
    // reescrito à mão, com outra redação, deixa de ser o mesmo contrato.
    private const MARCADOR_ABERTURA = '/^(?:#|\/\/) --- DADOS DESTE REPOSITÓRIO \(a personalização entra só neste bloco\) -+$/u';
    private const MARCADOR_FECHAMENTO = '/^(?:#|\/\/) --- FIM DOS DADOS; daqui para baixo o código é canônico -+$/u';

    /**
     * Subcomandos do próprio Composer: aparecem na documentação e não são (nem
     * podem ser) scripts do manifesto.
     */
    private const NATIVOS_DO_COMPOSER = [
        'install', 'update', 'require', 'remove', 'outdated', 'audit',
        'licenses', 'validate', 'dump-autoload', 'check-platform-reqs',
        'show', 'why', 'exec', 'run-script', 'global', 'create-project', 'init',
    ];

    /**
     * Entrypoints do contrato que trazem o próprio shim de ambiente: chamá-los
     * por `bash` desmonta isso — o shim detecta a reentrada por BASH_VERSION e
     * pula justamente a limpeza.
     *
     * @var list<string>
     */
    private const ENTRYPOINTS_SHELL_SANITIZADOS = [
        'scripts/install/instalar_macos.sh',
        'scripts/install/instalar_linux.sh',
        'scripts/deploy/release_check.sh',
        'scripts/deploy/deploy.sh',
        'scripts/deploy/deploy_verify.sh',
        'scripts/deploy/rollback.sh',
        'scripts/deploy/db_backup.sh',
        'scripts/deploy/db_restore.sh',
        'scripts/deploy/db_restore_test.sh',
    ];

    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::raiz() . '/' . $relativo);
        if (in_array($relativo, ['scripts/deploy/db_backup.sh', 'scripts/deploy/db_restore.sh', 'scripts/deploy/db_restore_test.sh'], true)) {
            $conteudo .= file_get_contents(self::raiz() . '/' . ($relativo === 'scripts/deploy/db_restore_test.sh' ? 'scripts/quality/check_restore_postgres.php' : substr($relativo, 0, -3) . '.php'));
        }
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function scriptsDoComposer(): array
    {
        $manifesto = json_decode(self::ler('composer.json'), true);
        self::assertIsArray($manifesto);
        $scripts = $manifesto['scripts'] ?? null;
        self::assertIsArray($scripts);

        /** @var array<string, mixed> $scripts */
        return $scripts;
    }

    /**
     * O comando público só delega após registrar a execução; as asserções de
     * composição continuam cobrando os passos reais do agregado privado.
     *
     * @param array<string, mixed> $scripts
     * @return list<string>
     */
    private static function passosDoAgregado(array $scripts, string $nome): array
    {
        self::assertSame(
            [
                'Composer\\Config::disableProcessTimeout',
                'node scripts/quality/validation-runner.mjs --proof check:all -- composer ' . $nome . ':run',
            ],
            $scripts[$nome] ?? null,
            "{$nome} deve passar pelo emissor completo sem timeout externo.",
        );
        $passos = $scripts[$nome . ':run'] ?? null;
        self::assertIsArray($passos);
        foreach ($passos as $passo) {
            self::assertIsString($passo);
        }

        self::assertSame('Composer\\Config::disableProcessTimeout', $passos[0] ?? null);

        /** @var list<string> $passos */
        return array_slice($passos, 1);
    }

    private static function limparArvoreTemporaria(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho)) {
            @unlink($caminho);

            return;
        }

        if (!is_dir($caminho)) {
            return;
        }

        $entradas = @scandir($caminho);
        if ($entradas !== false) {
            foreach ($entradas as $entrada) {
                if ($entrada === '.' || $entrada === '..') {
                    continue;
                }

                self::limparArvoreTemporaria($caminho . '/' . $entrada);
            }
        }

        @rmdir($caminho);
    }

    private static function diretorioTemporarioCanonico(): string
    {
        $caminho = realpath(sys_get_temp_dir());
        self::assertIsString($caminho);

        return $caminho;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function comandosDoContrato(): array
    {
        $casos = [];
        foreach (self::COMANDOS as $comando => $script) {
            $casos[$comando] = [$comando, $script];
        }

        return $casos;
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function portoesNomeados(): array
    {
        $casos = [];
        foreach (self::PORTOES_NOMEADOS as $comando => $passos) {
            $casos[$comando] = [$comando, $passos];
        }

        return $casos;
    }

    /**
     * @param list<string> $passos
     */
    #[DataProvider('portoesNomeados')]
    public function testPortaoNomeadoExisteEChamaOsScriptsReais(string $comando, array $passos): void
    {
        $scripts = self::scriptsDoComposer();

        $this->assertArrayHasKey(
            $comando,
            $scripts,
            "composer.json deixou de expor `{$comando}`, que é nome de portão da frota.",
        );

        $valor = $scripts[$comando];
        $this->assertSame(
            $passos,
            is_array($valor) ? array_values($valor) : [$valor],
            "`composer {$comando}` deveria executar exatamente esses scripts, nessa ordem.",
        );

        foreach ($passos as $passo) {
            $separador = strpos($passo, ' ');
            $this->assertIsInt($separador, "Passo sem interpretador: {$passo}");
            $this->assertFileExists(self::raiz() . '/' . substr($passo, $separador + 1));
        }
    }

    #[DataProvider('comandosDoContrato')]
    public function testComandoDoContratoExisteEApontaParaScriptReal(string $comando, string $script): void
    {
        $scripts = self::scriptsDoComposer();

        $this->assertArrayHasKey(
            $comando,
            $scripts,
            "composer.json deixou de expor `{$comando}`, que é nome de contrato da frota.",
        );
        // Entrypoints sanitizados precisam ser executados diretamente para que
        // o shim POSIX retire BASH_ENV/PATH antes de o Bash iniciar. O
        // backup/restore também têm launcher próprio: chamar `bash script`
        // diretamente pularia a limpeza de ambiente do shim.
        $invocador = str_ends_with($script, '.php')
            ? '@php '
            : (in_array($script, self::ENTRYPOINTS_SHELL_SANITIZADOS, true) ? '' : 'bash ');
        $this->assertSame(
            $invocador . $script,
            $scripts[$comando],
            "`composer {$comando}` deveria executar {$script}.",
        );
        $this->assertFileExists(self::raiz() . '/' . $script);
    }

    #[DataProvider('comandosDoContrato')]
    public function testScriptTemBlocoDeDadosNoTopoECodigoCanonicoAbaixo(string $comando, string $script): void
    {
        if (str_starts_with($comando, 'db:')) {
            $fonteNativa = self::ler($script);
            self::assertStringNotContainsString('sqlite:', $fonteNativa);
            self::assertStringNotContainsString('sqlite3', $fonteNativa);
            self::assertStringContainsString('php', $fonteNativa);
            return;
        }

        $fonte = self::ler($script);
        $ehPhp = str_ends_with($script, '.php');

        if ($ehPhp) {
            $this->assertStringStartsWith("<?php\n", $fonte, "{$comando}: abertura PHP esperada.");
            $this->assertStringContainsString('declare(strict_types=1);', $fonte, "{$comando}: sem strict_types.");
        } else {
            $shebang = in_array($script, self::ENTRYPOINTS_SHELL_SANITIZADOS, true)
                ? "#!/bin/sh\n"
                : "#!/usr/bin/env bash\n";
            $this->assertStringStartsWith($shebang, $fonte, "{$comando}: shebang esperado.");
            $this->assertStringContainsString('set -euo pipefail', $fonte, "{$comando}: sem set -euo pipefail.");
        }

        $abertura = self::posicaoDoMarcador($fonte, self::MARCADOR_ABERTURA);
        $fechamento = self::posicaoDoMarcador($fonte, self::MARCADOR_FECHAMENTO);
        $this->assertIsInt($abertura, "{$comando}: falta o bloco de dados demarcado do topo.");
        $this->assertIsInt($fechamento, "{$comando}: falta o fim do bloco de dados.");
        $this->assertLessThan($fechamento, $abertura, "{$comando}: bloco de dados invertido.");

        // O bloco tem de vir ANTES do código: é o que mantém toda a identidade
        // deste bot num trecho só, demarcado, em vez de espalhada pelo script.
        $primeiroComando = strpos($fonte, $ehPhp ? '$root = dirname(' : 'project_dir="$(cd ');
        $this->assertIsInt($primeiroComando, "{$comando}: sem resolução da raiz do projeto.");
        $this->assertLessThan($primeiroComando, $fechamento, "{$comando}: há código antes do bloco de dados.");
    }

    /** Onde o marcador começa no arquivo, ou false quando não existe. */
    private static function posicaoDoMarcador(string $fonte, string $padrao): false|int
    {
        if (preg_match(str_replace('/^', '/^', $padrao) . 'm', $fonte, $achado, PREG_OFFSET_CAPTURE) !== 1) {
            return false;
        }

        return $achado[0][1];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function portoesComBlocoDeDados(): array
    {
        $casos = [];
        foreach (self::PORTOES_COM_BLOCO_DE_DADOS as $script) {
            $casos[basename($script)] = [$script];
        }

        return $casos;
    }

    /**
     * O bloco de dados de um portão de qualidade vem depois de `declare` e
     * `namespace` — que são canônicos — e ANTES de qualquer `require`, função ou
     * constante de código. É isso que mantém a lista deste bot separada da
     * mecânica do portão, num trecho só e demarcado.
     */
    #[DataProvider('portoesComBlocoDeDados')]
    public function testPortaoDeQualidadeTemBlocoDeDadosAntesDoCodigo(string $script): void
    {
        $fonte = self::ler($script);

        $this->assertStringStartsWith("<?php\n", $fonte, "{$script}: abertura PHP esperada.");
        $this->assertStringContainsString('declare(strict_types=1);', $fonte, "{$script}: sem strict_types.");
        $this->assertStringContainsString('namespace Quality;', $fonte, "{$script}: fora do namespace Quality.");

        $abertura = self::posicaoDoMarcador($fonte, self::MARCADOR_ABERTURA);
        $fechamento = self::posicaoDoMarcador($fonte, self::MARCADOR_FECHAMENTO);
        $this->assertIsInt($abertura, "{$script}: falta o bloco de dados demarcado do topo.");
        $this->assertIsInt($fechamento, "{$script}: falta o fim do bloco de dados.");
        $this->assertLessThan($fechamento, $abertura, "{$script}: bloco de dados invertido.");

        $primeiroRequire = strpos($fonte, "require_once __DIR__");
        $this->assertIsInt($primeiroRequire, "{$script}: sem require_once do apoio de qualidade.");
        $this->assertLessThan($primeiroRequire, $fechamento, "{$script}: há código antes do bloco de dados.");

        // Antes do bloco só pode haver abertura, declare, namespace, comentário
        // e linha em branco: qualquer outra coisa é código executando antes do
        // dado que ele deveria consumir.
        foreach (explode("\n", substr($fonte, 0, $abertura)) as $linha) {
            $this->assertMatchesRegularExpression(
                '/^(<\?php|declare\(strict_types=1\);|namespace [A-Za-z\\\\]+;|\s*(\/\/|\/\*|\*).*|\s*)$/',
                $linha,
                "{$script}: linha executável antes do bloco de dados: {$linha}",
            );
        }
    }

    /**
     * O `@test` no `check` e no `check:all` roda as cinco suítes do PHPUnit.
     * No pre-push, o hook chama `check:all`, que executa a base e a suíte. Sem
     * ele tudo continuaria verde — os outros portões passam do mesmo jeito — e
     * a suíte simplesmente deixaria de rodar antes do push.
     */
    public function testCheckRodaASuiteInteiraDoPhpunit(): void
    {
        $scripts = self::scriptsDoComposer();
        $check = $scripts['check'] ?? null;
        $checkAll = self::passosDoAgregado($scripts, 'check:all');

        $this->assertIsArray($check);
        $this->assertContains('@test', $check, 'O composer check deveria rodar @test.');
        $this->assertContains('@check:base', $checkAll, 'O composer check:all deveria rodar todos os gates comuns.');
        $this->assertContains('@test', $checkAll, 'O composer check:all deveria rodar a suíte completa.');
        // Sem `--testsuite`: com filtro, o agregado rodaria uma suíte só e
        // continuaria se chamando suíte.
        $this->assertSame(
            ['Composer\\Config::disableProcessTimeout', '@php scripts/quality/run_tests.php'],
            $scripts['test'] ?? null,
            '`composer test` deveria rodar todas as suítes sem timeout do Composer.',
        );
        $this->assertStringContainsString('composer check:all', self::ler('.lefthook/pre-push/qualidade.sh'));
    }

    public function testContratoDosChecksSeparaBaseExaustivoERegistry(): void
    {
        $scripts = self::scriptsDoComposer();
        $check = $scripts['check'] ?? null;
        $checkAll = self::passosDoAgregado($scripts, 'check:all');
        $checkOnline = $scripts['check:online'] ?? null;

        $this->assertIsArray($check);
        $this->assertSame(
            ['@check:vulnerabilities', 'npm run check:vulnerabilities'],
            $checkOnline,
            '`check:online` deve concentrar apenas as auditorias que consultam registries.',
        );
        $this->assertContains('@check:base', $checkAll);
        $base = $scripts['check:base'] ?? null;
        $this->assertIsArray($base);
        $this->assertNotContains('@check:vulnerabilities', $base);
        $this->assertNotContains('npm run check:vulnerabilities', $base);
        $this->assertNotContains('@check:vulnerabilities', $check);
        $this->assertNotContains('@check:vulnerabilities', $checkAll);
        $this->assertNotContains('npm run check:vulnerabilities', $checkAll);
        $this->assertStringContainsString('composer check:all', self::ler('.lefthook/pre-push/qualidade.sh'));
    }

    /**
     * A taxonomia da frota é interface: renomear é mudança de interface, e o
     * alias antigo não pode virar o comando de verdade de novo.
     */
    public function testTaxonomiaDaFrotaExpoeSomenteOsComandosReais(): void
    {
        $scripts = self::scriptsDoComposer();

        $reais = [
            'test:unit', 'test:integration', 'test:smoke', 'check:dead-code',
            'typecheck', 'format', 'format:check', 'check:postgres',
            'check:gitleaks', 'check:deploy-guards', 'check:duplication', 'check:licenses',
            'check:vulnerabilities', 'check:deps:undeclared', 'check:shell', 'check:security',
            'check:toolchain', 'check:editorconfig', 'complexity:baseline', 'duplication:baseline',
            'deps:outdated', 'check:config', 'check:config:production', 'check:config:examples',
        ];
        foreach ($reais as $comando) {
            $this->assertArrayHasKey($comando, $scripts, "composer.json deixou de expor `{$comando}`.");
        }

        // O apagado não pode voltar como apelido: apelido que sobrevive vira o
        // nome de verdade no dedo de quem digita, e a renomeação desanda.
        $antigos = [
            'test-unit', 'test-integration', 'smoke', 'mutation', 'deps-nao-usadas',
            'analyse', 'cs', 'fix', 'coverage', 'check:fix', 'security-audit',
            'license-policy', 'duplicacao', 'database', 'test:postgres', 'db:reset:test',
            'test:deploy-guard', 'test:release-guards', 'check:secrets', 'lint-shell', 'semgrep',
            'check-versions', 'complexity-baseline', 'duplication-baseline', 'check:deps',
            'deps-nao-declaradas', 'desatualizados', 'editorconfig',
            'validate-config', 'validate-production-config', 'validate-config-examples',
        ];
        foreach ($antigos as $antigo) {
            $this->assertArrayNotHasKey($antigo, $scripts, "Alias antigo ainda exposto: `{$antigo}`.");
        }
    }

    public function testRollbackDeCodigoNaoTocaEmDado(): void
    {
        $fonte = self::ler('scripts/deploy/rollback.sh');

        // Nenhuma chamada a sqlite3, nenhum snapshot, nenhuma escrita no banco,
        // e nenhuma invocação do comando que restaura dado.
        $this->assertStringNotContainsString('sqlite3', $fonte);
        $this->assertStringNotContainsString('snapshot_banco', $fonte);
        $this->assertStringNotContainsString('VACUUM', $fonte);
        $this->assertStringNotContainsString('bash scripts/deploy/db_restore.sh', $fonte);
        $this->assertStringContainsString('O banco NÃO será tocado', $fonte);

        // E a recusa explícita quando o destino não conhece uma migration já
        // aplicada: não há reversão automática de migration incompatível.
        $this->assertStringContainsString('database/migrations/', $fonte);
        $this->assertStringContainsString('--aceito-schema-divergente', $fonte);
    }

    public function testRollbackRecusaCarimboSymlinkAntesDeLeitura(): void
    {
        $rollback = self::ler('scripts/deploy/rollback.sh');
        $guarda = strpos($rollback, 'if [[ -L "$release_file" ]]');
        $leitura = strpos($rollback, 'file_get_contents($argv[1])');

        self::assertIsInt($guarda);
        self::assertIsInt($leitura);
        self::assertLessThan($leitura, $guarda, 'O rollback deve recusar symlink antes de ler o carimbo.');
    }

    public function testRollbackPreservaRegistradorAntesDeTrocarParaCommitAntigo(): void
    {
        $rollback = self::ler('scripts/deploy/rollback.sh');
        $registrar = self::ler('scripts/deploy/registrar_publicacao.sh');

        $captura = strpos($rollback, 'cp "$publicacao_ferramentas_dir/registrar_publicacao.sh"');
        $switch = strpos($rollback, 'then git switch --detach "$destino"; fi');
        $execucao = strpos($rollback, 'RELEASE_PROJECT_DIR="$project_dir" "$registrador_temporario"');
        $this->assertIsInt($captura);
        $this->assertIsInt($switch);
        $this->assertIsInt($execucao);
        $this->assertLessThan($switch, $captura, 'O helper precisa ser capturado antes de sair do commit atual.');
        $this->assertLessThan($execucao, $switch, 'O carimbo deve ser gravado depois da troca do checkout.');
        $this->assertStringContainsString('chmod 0700 "$registrador_temporario"', $rollback);
        $this->assertStringContainsString('RELEASE_PROJECT_DIR:-', $registrar);
    }

    public function testRollbackDeCodigoEDeDadosSaoComandosDiferentes(): void
    {
        $scripts = self::scriptsDoComposer();
        $codigo = $scripts['rollback'] ?? null;
        $dados = $scripts['db:restore'] ?? null;

        $this->assertIsString($codigo);
        $this->assertIsString($dados);
        $this->assertNotSame($codigo, $dados);
        $this->assertStringNotContainsString('db_restore', $codigo);
        $this->assertStringNotContainsString('rollback', $dados);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function guardasDeConfirmacao(): array
    {
        return [
            'deploy' => ['scripts/deploy/deploy.sh', 'DEPLOY_CONFIRM', 'Deploy não iniciado'],
            'rollback' => ['scripts/deploy/rollback.sh', 'ROLLBACK_CONFIRM', 'Rollback não iniciado'],
            'db:restore' => ['scripts/deploy/db_restore.sh', 'DB_RESTORE_CONFIRM', 'Restauração não iniciada'],
        ];
    }

    /**
     * @return array{status: int, saida: string}
     */
    private function executarSemAsVariaveis(string $script, string ...$semAsVariaveis): array
    {
        $comando = ['env'];
        foreach ($semAsVariaveis as $variavel) {
            $comando[] = '-u';
            $comando[] = $variavel;
        }
        $comando[] = 'bash';
        $comando[] = self::raiz() . '/' . $script;

        $canos = [];
        $processo = proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canos,
            self::raiz(),
        );
        try {
            $this->assertIsResource($processo);
            fclose($canos[0]);
            $saida = (string) stream_get_contents($canos[1]);
            $erro = (string) stream_get_contents($canos[2]);
            fclose($canos[1]);
            fclose($canos[2]);
            $status = proc_close($processo);
            $processo = null;

            return ['status' => $status, 'saida' => $saida . $erro];
        } finally {
            foreach ($canos as $cano) {
                if (is_resource($cano)) {
                    fclose($cano);
                }
            }
            if (is_resource($processo)) {
                @proc_terminate($processo);
                @proc_close($processo);
            }
        }
    }

    #[DataProvider('guardasDeConfirmacao')]
    public function testComandoDeProducaoRecusaSemConfirmacao(string $script, string $variavel, string $trecho): void
    {
        $resultado = $this->executarSemAsVariaveis($script, $variavel);

        $this->assertSame(2, $resultado['status'], "{$script} deveria recusar com status 2 sem {$variavel}.");
        $this->assertStringContainsString($trecho, $resultado['saida']);
    }

    /**
     * Os tokens são diferentes de propósito. Quem acabou de publicar tem
     * DEPLOY_CONFIRM exportado no shell: se o rollback aceitasse esse token, um
     * comando digitado por engano nesse estado rodaria sozinho. O mesmo vale
     * para a restauração de dados, que é a operação irreversível da lista.
     */
    public function testCadaOperacaoTemTokenProprioENaoHerdaODaOutra(): void
    {
        foreach (self::guardasDeConfirmacao() as [$script, $variavel]) {
            $fonte = self::ler($script);
            $this->assertStringContainsString(
                ($variavel === 'DB_RESTORE_CONFIRM' ? "getenv('DB_RESTORE_CONFIRM')" : '"${' . $variavel . ':-}"'),
                $fonte,
                "{$script} deveria conferir {$variavel}.",
            );
            foreach (['DEPLOY_CONFIRM', 'ROLLBACK_CONFIRM', 'DB_RESTORE_CONFIRM'] as $outro) {
                if ($outro === $variavel) {
                    continue;
                }
                $this->assertStringNotContainsString(
                    '"${' . $outro . ':-}"',
                    $fonte,
                    "{$script} não pode aceitar {$outro} como confirmação.",
                );
            }
        }
    }

    public function testEnsaioDeRestauracaoUsaBancoDescartavel(): void
    {
        $fonte = self::ler('scripts/quality/check_restore_postgres.php');
        self::assertStringContainsString('BancoTeste::dsn($dsn)', $fonte);
        self::assertStringContainsString('prova_restauracao', $fonte);
        self::assertStringContainsString('with_postgres.sh', $fonte);
    }

    public function testPublicacoesRepetidasMantemLeituraDoGrupoDeRuntime(): void
    {
        $base = self::diretorioTemporarioCanonico() . '/' . self::PREFIXO_TEMPORARIO . 'carimbo-grupo-' . bin2hex(random_bytes(8));
        try {
            self::assertTrue(mkdir($base . '/scripts/deploy', 0700, true));
            self::assertNotFalse(file_put_contents($base . '/composer.lock', '{}'));
            self::assertNotFalse(file_put_contents($base . '/.gitignore', ".release.json\n"));
            $grupo = filegroup($base);
            self::assertIsInt($grupo);
            $fonte = str_replace(
                ['expected_dir="' . self::DIRETORIO_ESPERADO_NO_HOST . '"', 'runtime_group="' . self::GRUPO_DE_RUNTIME . '"'],
                ['expected_dir="' . $base . '"', 'runtime_group="' . $grupo . '"'],
                self::ler('scripts/deploy/registrar_publicacao.sh')
            );
            $script = $base . '/scripts/deploy/registrar_publicacao.sh';
            self::assertNotFalse(file_put_contents($script, $fonte));
            $this->executarProgramaDeEnsaio(['git', '-C', $base, 'init', '--quiet']);
            $this->executarProgramaDeEnsaio(['git', '-C', $base, 'add', '.']);
            $this->executarProgramaDeEnsaio([
                'git', '-C', $base, '-c', 'user.name=Teste', '-c', 'user.email=teste@example.invalid',
                '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false',
                'commit', '--quiet', '--allow-empty', '-m', 'fixture',
            ]);
            foreach (['deploy', 'rollback'] as $origem) {
                $this->executarProgramaDeEnsaio(['/bin/sh', $script, $base . '/.release.json', $origem]);
                clearstatcache(true, $base . '/.release.json');
                $modo = fileperms($base . '/.release.json');
                self::assertIsInt($modo);
                self::assertSame(0640, $modo & 0777);
                self::assertSame($grupo, filegroup($base . '/.release.json'));
                self::assertTrue(chmod($base . '/.release.json', 0600));
            }
        } finally {
            self::limparArvoreTemporaria($base);
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function alteracoesDoCheckout(): iterable
    {
        yield 'arquivo versionado modificado' => [false];
        yield 'arquivo novo não versionado' => [true];
    }

    #[DataProvider('alteracoesDoCheckout')]
    public function testCarimboRecusaArvoreSujaEPreservaReciboAnterior(bool $naoVersionado): void
    {
        $base = self::diretorioTemporarioCanonico() . '/' . self::PREFIXO_TEMPORARIO . 'carimbo-sujo-' . bin2hex(random_bytes(8));
        try {
            self::assertTrue(mkdir($base . '/scripts/deploy', 0700, true));
            self::assertNotFalse(file_put_contents($base . '/composer.lock', '{}'));
            self::assertNotFalse(file_put_contents($base . '/.gitignore', ".release.json\n"));
            $script = $base . '/scripts/deploy/registrar_publicacao.sh';
            self::assertNotFalse(file_put_contents($script, self::ler('scripts/deploy/registrar_publicacao.sh')));
            $this->executarProgramaDeEnsaio(['git', '-C', $base, 'init', '--quiet']);
            $this->executarProgramaDeEnsaio(['git', '-C', $base, 'add', '.']);
            $this->executarProgramaDeEnsaio([
                'git', '-C', $base, '-c', 'user.name=Teste', '-c', 'user.email=teste@example.invalid',
                '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'fixture',
            ]);
            $destino = $base . '/.release.json';
            $this->executarProgramaDeEnsaio(['/bin/sh', $script, $destino, 'deploy']);
            $anterior = file_get_contents($destino);
            self::assertIsString($anterior);
            self::assertNotFalse(file_put_contents($base . ($naoVersionado ? '/hotfix.php' : '/composer.lock'), 'alteração local sintética'));
            $processo = proc_open(['/bin/sh', $script, $destino, 'deploy'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canos);
            self::assertIsResource($processo);
            $saida = stream_get_contents($canos[1]);
            $erro = stream_get_contents($canos[2]);
            fclose($canos[1]);
            fclose($canos[2]);
            self::assertSame(1, proc_close($processo), (string) $saida . (string) $erro);
            self::assertStringContainsString('Árvore suja', (string) $erro);
            self::assertSame($anterior, file_get_contents($destino));
            self::assertSame([], glob($destino . '.tmp.*'));
        } finally {
            self::limparArvoreTemporaria($base);
        }
    }

    /** @param list<string> $comando */
    private function executarProgramaDeEnsaio(array $comando): void
    {
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canos);
        self::assertIsResource($processo);
        $saida = stream_get_contents($canos[1]);
        $erro = stream_get_contents($canos[2]);
        fclose($canos[1]);
        fclose($canos[2]);
        self::assertSame(0, proc_close($processo), (string) $saida . (string) $erro);
    }

    public function testBackupPostgresUsaDumpEPublicacaoValidada(): void
    {
        $fonte = self::ler('inclusos/funcoes_snapshot_banco.php');
        self::assertStringContainsString("'pg_dump'", $fonte);
        self::assertStringContainsString("'--format=custom'", $fonte);
        self::assertStringContainsString("'pg_restore'", $fonte);
        self::assertStringContainsString('snapshotBancoMetadadosConferem', $fonte);
        self::assertStringContainsString('rotacionarSnapshotsBanco', $fonte);
    }

    public function testRestoreExigeBackupValidoEBancoVazio(): void
    {
        $fonte = self::ler('scripts/deploy/db_restore.php');
        self::assertStringContainsString('snapshotBancoIntegro', $fonte);
        self::assertStringContainsString('Restauração exige banco vazio', $fonte);
        self::assertStringContainsString('--single-transaction', $fonte);
        self::assertStringContainsString('--exit-on-error', $fonte);
    }

    public function testSmokeConfiguraPostgresSomenteLeitura(): void
    {
        $fonte = self::ler('scripts/quality/run_smoke.php');
        self::assertStringContainsString('SET default_transaction_read_only = on', $fonte);
        self::assertStringContainsString('SELECT 1 FROM usuarios', $fonte);
    }
    public function testDeployVerifyCanonizaShaEsperadoAntesDeComparar(): void
    {
        $fonte = self::ler('scripts/deploy/deploy_verify.sh');

        $this->assertStringContainsString('esperado_resolvido=', $fonte);
        $this->assertStringContainsString('rev-parse --verify --end-of-options "$esperado^{commit}"', $fonte);
        $this->assertStringContainsString('[[ "$commit_atual" == "$esperado_resolvido" ]]', $fonte);
        $this->assertStringNotContainsString('[[ "$commit_atual" == "$esperado"* ]]', $fonte);
    }
    public function testGuardasGitUsamLeituraSemLockOpcional(): void
    {
        foreach (['scripts/deploy/release_check.sh', 'scripts/deploy/deploy.sh', 'scripts/deploy/deploy_verify.sh', 'scripts/deploy/rollback.sh'] as $script) {
            $fonte = self::ler($script);
            // Em duas asserções porque o deploy.sh intercala `-C "$project_dir"`
            // entre a flag e o subcomando; o que importa é que as duas partes
            // estejam lá, não que apareçam coladas.
            $this->assertStringContainsString(
                '--no-optional-locks',
                $fonte,
                "{$script} precisa evitar o lock opcional do status.",
            );
            $this->assertStringContainsString(
                'status --porcelain',
                $fonte,
                "{$script} precisa ler o status em formato estável.",
            );
        }
    }
    public function testReleaseCheckValidaTodasAsTagsApontadasPeloHead(): void
    {
        $fonte = self::ler('scripts/deploy/release_check.sh');

        $this->assertStringContainsString('git tag --points-at HEAD', $fonte);
        $this->assertStringContainsString('tags_invalidas=()', $fonte);
        $this->assertStringNotContainsString('git tag --points-at HEAD | head -n 1', $fonte);
    }
    public function testRollbackResolveRefExternaComFimDeOpcoesEFalhaFechadoNoDiff(): void
    {
        $fonte = self::ler('scripts/deploy/rollback.sh');

        $this->assertStringContainsString('rev-parse --verify --end-of-options "$alvo^{commit}"', $fonte);
        $this->assertStringContainsString('git diff --no-ext-diff --no-renames --name-only --diff-filter=AM', $fonte);
        $this->assertStringContainsString('rollback recusado.', $fonte);
        $this->assertStringNotContainsString('git diff --name-only --diff-filter=A "$destino" "$atual" -- database/migrations/ 2>/dev/null || true', $fonte);
    }
    public function testLefthookInstalaResetandoHooksPathHerdado(): void
    {
        $manifesto = json_decode(self::ler('package.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifesto);

        $scripts = $manifesto['scripts'] ?? null;
        self::assertIsArray($scripts);
        self::assertSame('lefthook install --reset-hooks-path', $scripts['prepare'] ?? null);

        // Lefthook 2.1.12 instala com --force no postinstall do pacote. O deny
        // name-only impede que esse script rode; o prepare do projeto é a única
        // instalação automática, e o --reset-hooks-path limpa um core.hooksPath
        // deixado por outra ferramenta, que faria o hook ser gravado e nunca
        // executado.
        self::assertSame(['lefthook' => false], $manifesto['allowScripts'] ?? null);

        $lefthook = self::ler('lefthook.yml');
        self::assertStringContainsString('min_version: 2.1.12', $lefthook);
        self::assertStringContainsString('--edit "{1}"', $lefthook);
        self::assertStringNotContainsString('--edit {1}', $lefthook);
        self::assertStringNotContainsString('--reset-hooks-path', $lefthook);
        self::assertStringNotContainsString('--force', $lefthook);
    }
    public function testEntrypointsFixamShellEExpurgamAmbienteAntesDoBash(): void
    {
        foreach (self::dados(self::ENTRYPOINTS_COM_SHIM) as $entrypoint) {
            $fonte = self::ler($entrypoint);
            self::assertStringStartsWith("#!/bin/sh\n", $fonte, $entrypoint);
            self::assertStringContainsString(
                'if [ -n "${BASH_VERSION:-}" ] && ! shopt -q -o posix 2>/dev/null; then',
                $fonte,
                $entrypoint,
            );
            self::assertStringContainsString('exec /usr/bin/env -i', $fonte, $entrypoint);
            self::assertStringContainsString('/bin/bash -p "$0" "$@"', $fonte, $entrypoint);
            self::assertStringContainsString("PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'", $fonte, $entrypoint);
            self::assertStringNotContainsString('_shell_sanitizado', $fonte, $entrypoint);

            foreach (['_API_KEY=', '_PASSWORD=', '_SECRET=', 'RCLONE_CONFIG_PASS='] as $segredo) {
                self::assertStringNotContainsString($segredo, $fonte, "Segredo não pode ser argumento do env -i: {$entrypoint}.");
            }
        }
    }
    public function testInstaladorRaizPreservaArgumentosEEncadeiaExecucaoDireta(): void
    {
        $instalador = self::ler('instalar.sh');

        self::assertStringContainsString(
            'exec "$script_dir/scripts/install/instalar_macos.sh" "$@"',
            $instalador,
        );
        self::assertStringContainsString(
            'exec "$script_dir/scripts/install/instalar_linux.sh" "$@"',
            $instalador,
        );
        self::assertStringNotContainsString('bash "$(dirname', $instalador);
        self::assertStringContainsString('/bin/bash -p "$0" "$@"', $instalador);
    }
    public function testInstaladorRaizDespachaOsPreservaArgumentosEStatus(): void
    {
        $diretorio = sys_get_temp_dir() . '/' . self::PREFIXO_TEMPORARIO . 'launcher-' . bin2hex(random_bytes(6));
        $processo = null;
        $pipes = [];
        $variaveisAmbiente = [
            'BASH_ENV' => getenv('BASH_ENV'),
            'ENV' => getenv('ENV'),
        ];

        try {
            self::assertTrue(mkdir($diretorio . '/bin', 0700, true));
            self::assertTrue(mkdir($diretorio . '/scripts/install', 0700, true));
            putenv('BASH_ENV=' . $diretorio . '/bash-env-sentinela');
            putenv('ENV=' . $diretorio . '/env-sentinela');

            $fonte = self::ler('instalar.sh');
            $pathOriginal = "PATH='/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'";
            $pathFixture = 'PATH=' . escapeshellarg($diretorio . '/bin:/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
            self::assertSame(1, substr_count($fonte, $pathOriginal));
            $fonte = str_replace($pathOriginal, $pathFixture, $fonte);

            $launcher = $diretorio . '/instalar.sh';
            self::assertSame(strlen($fonte), file_put_contents($launcher, $fonte));
            self::assertTrue(chmod($launcher, 0700));

            $argumentos = ['--flag', 'valor com espaços', '$(printf não-execute)', 'a*b'];
            foreach ([
                ['Darwin', 'instalar_macos.sh', 17],
                ['Linux', 'instalar_linux.sh', 23],
            ] as [$sistema, $instalador, $status]) {
                $saida = $diretorio . '/' . $sistema . '.txt';
                $uname = "#!/bin/sh\nprintf '%s\\n' " . escapeshellarg($sistema) . "\n";
                self::assertSame(strlen($uname), file_put_contents($diretorio . '/bin/uname', $uname));
                self::assertTrue(chmod($diretorio . '/bin/uname', 0700));

                $filho = "#!/bin/sh\n";
                $filho .= "{\n  printf 'argc=%s\\n' \"\$#\"\n";
                $filho .= "  printf 'BASH_ENV=<%s> ENV=<%s>\\n' \"\${BASH_ENV-}\" \"\${ENV-}\"\n";
                $filho .= "  for argumento do printf '<%s>\\n' \"\$argumento\"; done\n";
                $filho .= "} > " . escapeshellarg($saida) . "\n";
                $filho .= "exit {$status}\n";
                self::assertSame(
                    strlen($filho),
                    file_put_contents($diretorio . '/scripts/install/' . $instalador, $filho),
                );
                self::assertTrue(chmod($diretorio . '/scripts/install/' . $instalador, 0700));

                $processo = proc_open(
                    array_merge([$launcher], $argumentos),
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    $diretorio,
                );
                if (!is_resource($processo)) {
                    self::fail("Não foi possível iniciar o launcher para {$sistema}.");
                }
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                self::assertIsString($stdout);
                self::assertIsString($stderr);
                $codigo = proc_close($processo);
                $processo = null;

                self::assertSame($status, $codigo, "O status do instalador {$sistema} não foi preservado.");
                self::assertSame(
                    "argc=4\nBASH_ENV=<> ENV=<>\n<--flag>\n<valor com espaços>\n<\$(printf não-execute)>\n<a*b>\n",
                    file_get_contents($saida),
                );
                self::assertFileDoesNotExist($diretorio . '/bash-env-sentinela');
                self::assertFileDoesNotExist($diretorio . '/env-sentinela');
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($processo)) {
                proc_close($processo);
            }
            foreach ($variaveisAmbiente as $variavel => $valor) {
                if ($valor === false) {
                    putenv($variavel);
                } else {
                    putenv($variavel . '=' . $valor);
                }
            }
            self::limparArvoreTemporaria($diretorio);
        }
    }

    public function testDeployGravaOCarimboQueODeployVerifyConfronta(): void
    {
        $deploy = self::ler('scripts/deploy/deploy.sh');
        $verify = self::ler('scripts/deploy/deploy_verify.sh');
        $registrar = self::ler('scripts/deploy/registrar_publicacao.sh');
        $ignorados = self::ler('.gitignore');

        $this->assertStringContainsString('registrar_publicacao.sh', $deploy);
        $this->assertStringContainsString('.release.json', $deploy);
        $this->assertStringContainsString('.release.json', $verify);

        foreach (['commit', 'commit_anterior', 'checkout', 'publicado_em', 'composer_lock_sha256'] as $campo) {
            $this->assertStringContainsString($campo, $registrar, "O carimbo deveria registrar {$campo}.");
        }

        // Os três fatos que a verificação confronta.
        $this->assertStringContainsString('rev-parse --verify HEAD', $verify, 'Falta o HEAD do checkout.');
        $this->assertStringContainsString(
            'git --no-optional-locks status --porcelain=v1',
            $verify,
            'Falta a exigência de árvore limpa.',
        );
        $this->assertStringContainsString(
            'ExecMainStartTimestamp',
            $verify,
            'Sem o instante em que o serviço subiu, o opcache serve o código antigo e a verificação aprova.',
        );
        $this->assertStringContainsString('não iniciou um novo processo desde a publicação', $verify);

        // Um verificador que aprova quando não tem o que comparar é pior do que
        // não existir.
        $this->assertStringContainsString('carimbo de publicação ausente', $verify);

        // O carimbo é estado do host. Versioná-lo faria cada publicação sujar a
        // árvore que o próprio deploy exige limpa.
        $this->assertMatchesRegularExpression('#^/?\.release\.json$#m', $ignorados);
    }

    public function testVerifyExigeNovoProcessoEMantemFalhaQuandoTimestampAusente(): void
    {
        $fonte = self::ler('scripts/deploy/deploy_verify.sh');
        $inicio = strpos($fonte, 'if command -v systemctl >/dev/null 2>&1; then');
        self::assertIsInt($inicio);
        $fim = strpos($fonte, 'if [[ -n "$worker_process_pattern" ]]', $inicio);
        self::assertIsInt($fim);
        $bloco = substr($fonte, $inicio, $fim - $inicio);

        // Executa o bloco real com systemd e relógio isolados. Um reload tem
        // mudança de estado nova, mas mantém o início do mestre anterior.
        $ambiente = <<<'BASH'
set -euo pipefail
servicos=(fpm-teste)
publicado_epoch_ns=200
marca_data=publicado
falhas=0
aprovar() { :; }
reprovar() { falhas=$((falhas + 1)); }
systemctl() {
    if [[ "$1" == is-active ]]; then return 0; fi
    if [[ " $* " == *" ExecMainStartTimestamp "* ]]; then
        case "$cenario" in
            restart|carimbo_ausente) echo novo ;;
            reload) echo antigo ;;
            ausente) return 1 ;;
        esac
    else
        echo novo
    fi
}
date() {
    case "$3" in
        antigo) echo 100 ;;
        novo) echo 300 ;;
        '') echo 300 ;; # GNU date interpreta string vazia como a data atual.
        *) return 1 ;;
    esac
}
BASH;
        foreach (['restart' => 0, 'reload' => 1, 'ausente' => 1, 'carimbo_ausente' => 1] as $cenario => $falhasEsperadas) {
            $script = $ambiente . "\ncenario=" . $cenario
                . "\nmarca_data=" . ($cenario === 'carimbo_ausente' ? "''" : 'publicado')
                . "\n" . $bloco . "\nprintf '%s' \"\$falhas\"\n";
            $processo = proc_open(['/bin/bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $canos);
            self::assertIsResource($processo);
            $saida = stream_get_contents($canos[1]);
            $erro = stream_get_contents($canos[2]);
            fclose($canos[1]);
            fclose($canos[2]);
            self::assertSame(0, proc_close($processo), (string) $erro);
            self::assertSame((string) $falhasEsperadas, $saida, $cenario);
        }
    }

    public function testDeployValidaCarimboAntesDoBackupOuDeQualquerMutacao(): void
    {
        $deploy = self::ler('scripts/deploy/deploy.sh');

        $guarda = strpos($deploy, 'if [[ -L "$release_file"');
        $backup = strpos($deploy, 'scripts/deploy/db_backup.sh');

        self::assertIsInt($guarda);
        self::assertIsInt($backup);
        self::assertLessThan(
            $backup,
            $guarda,
            'O marcador precisa ser validado antes do backup e das mutações do deploy.',
        );
        self::assertStringContainsString('&& ! -f "$release_file"', $deploy);
        self::assertStringContainsString('nada foi alterado', $deploy);
    }

    public function testDeployEVerifyValidamEnvironmentFileAntesDoParser(): void
    {
        foreach (['scripts/deploy/deploy.sh', 'scripts/deploy/deploy_verify.sh'] as $script) {
            $fonte = self::ler($script);
            $guarda = strpos($fonte, 'if ! environment_file_seguro; then');
            $parser = strpos($fonte, 'source scripts/operacao/carregar_ambiente_seguro.sh');

            self::assertIsInt($guarda, $script);
            self::assertIsInt($parser, $script);
            self::assertLessThan($parser, $guarda, "{$script} deve validar o EnvironmentFile antes do parser.");
            self::assertStringContainsString('[[ -f "$environment_file" && ! -L "$environment_file" ]]', $fonte);
            self::assertStringContainsString("stat -c '%U:%G:%a'", $fonte);
            self::assertStringContainsString('runtime_group=', $fonte);
            self::assertStringContainsString('"root:${runtime_group}:640"', $fonte);
        }
    }

    public function testDeployVerifyEInstaladorValidamPHP85BinCanonico(): void
    {
        $verify = self::ler('scripts/deploy/deploy_verify.sh');
        $fontes = [$verify];
        foreach (self::dados(self::INSTALADORES_QUE_VALIDAM_PHP85_BIN) as $instalador) {
            $fonteDoInstalador = self::ler($instalador);
            self::assertStringContainsString('conferir php85_bin_suportado', $fonteDoInstalador, $instalador);
            $fontes[] = $fonteDoInstalador;
        }

        foreach ($fontes as $fonte) {
            self::assertStringContainsString('php85_bin_suportado()', $fonte);
            self::assertStringContainsString('realpath -e -- "$php_bin"', $fonte);
            self::assertStringContainsString('PHP_VERSION_ID >= 80512', $fonte);
        }
        self::assertStringContainsString('reprovar "PHP85_BIN ausente', $verify);
    }

    public function testDeployVerifyExigeRespostaHttpDoProdutoEReinicioReal(): void
    {
        $verify = self::ler('scripts/deploy/deploy_verify.sh');

        $this->assertStringContainsString('public_status=', $verify);
        $this->assertStringContainsString('public_location=', $verify);
        $this->assertStringContainsString('public_path=', $verify);
        $this->assertStringContainsString('[[ "$codigo" != "$public_status" ]]', $verify);
        $this->assertStringContainsString('[[ "$location" != "$public_location" ]]', $verify);
        $this->assertStringContainsString('public_url=', $verify);
        $this->assertStringContainsString('origem HTTPS sem caminho', $verify);
        $this->assertStringContainsString('if [[ -L "$release_file" ]]', $verify);
        $this->assertStringContainsString('health_status', $verify);
        $this->assertStringContainsString('[[ "$health_status" != "200" ]]', $verify);
        $this->assertStringContainsString('-p ExecMainStartTimestamp', $verify);
        $this->assertStringNotContainsString("|| echo '000'", $verify);
    }

    public function testDeployVerifyCarregaParserUmaVezNoShellAtual(): void
    {
        $verify = self::ler('scripts/deploy/deploy_verify.sh');
        $guarda = strpos($verify, 'if ! carregar_ambiente_seguro "$environment_file"; then');
        $arvore = strpos($verify, 'aprovar "árvore limpa: nada foi editado no host"');
        $publicUrl = strpos($verify, 'public_url="${!public_url_variable:-}"');
        $smoke = strrpos($verify, 'php scripts/quality/run_smoke.php --confirm-production');

        $this->assertIsInt($guarda);
        $this->assertIsInt($arvore);
        $this->assertIsInt($publicUrl);
        $this->assertIsInt($smoke);
        $this->assertSame(
            1,
            substr_count($verify, 'carregar_ambiente_seguro "$environment_file"'),
            'O EnvironmentFile deve ser interpretado uma única vez pelo shell atual.',
        );
        $this->assertLessThan($guarda, $arvore, 'O parser deve ficar depois da prova da árvore limpa.');
        $this->assertLessThan($publicUrl, $guarda, 'A URL deve vir do ambiente já carregado.');
        $this->assertLessThan($smoke, $publicUrl, 'O smoke deve executar após o ambiente ser carregado.');
        $this->assertStringContainsString('exit 1', substr($verify, $guarda, $publicUrl - $guarda));
        $this->assertStringNotContainsString('public_url="$(', $verify);
        $this->assertStringNotContainsString('printf \'%s\' "${!public_url_variable:-}"', $verify);
    }

    public function testDeployVerifyNaoCarregaParserComCheckoutSujo(): void
    {
        $verify = self::ler('scripts/deploy/deploy_verify.sh');
        $sujo = strpos($verify, 'árvore suja:');
        $aborto = strpos($verify, 'exit 1', $sujo === false ? 0 : $sujo);
        $source = strpos($verify, 'source scripts/operacao/carregar_ambiente_seguro.sh');

        self::assertIsInt($sujo);
        self::assertIsInt($aborto);
        self::assertIsInt($source);
        self::assertLessThan($source, $aborto, 'Checkout sujo deve abortar antes de carregar o parser.');
    }

    public function testRegistradorProtegeSymlinkEPublicaCarimboAtomico(): void
    {
        $registrar = self::ler('scripts/deploy/registrar_publicacao.sh');
        $php = strpos($registrar, 'php -r');
        $rename = strpos($registrar, 'mv -f -- "$temporario" "$destino"');

        $this->assertStringContainsString('[[ -L "$destino" ]]', $registrar);
        $this->assertStringContainsString('mktemp "${destino}.tmp.XXXXXX"', $registrar);
        $this->assertStringContainsString('chmod 0600 "$temporario"', $registrar);
        $this->assertIsInt($php);
        $this->assertIsInt($rename);
        $this->assertLessThan($rename, $php, 'O JSON deve ser escrito antes do rename atômico.');
    }

    public function testCiExecutaOsPortoesOperacionaisDoCheck(): void
    {
        $workflow = self::ler('.github/workflows/ci.yml');

        // Um comando só: o ensaio de restauração sobre banco descartável é
        // alcançado DENTRO de check:deploy-guards. Exigir aqui o passo avulso
        // seria exigir que o CI repetisse o mesmo ensaio duas vezes.
        $this->assertStringContainsString('composer check:deploy-guards', $workflow);
    }

    public function testRunbookUsaSomenteArgumentosOperacionaisExistentes(): void
    {
        $documento = self::ler('docs/OPERACAO.md');

        $this->assertStringNotContainsString('--sem-servico', $documento);
        $this->assertStringNotContainsString('composer rollback -- <commit>', $documento);
        $this->assertStringNotContainsString('--arquivo=/caminho/evidencia.json', $documento);
    }

    /**
     * No host o operador é root e chama o script, não o alias do Composer, que
     * recusa rodar como root; por isso o runbook tem de citar o script real.
     */
    #[DataProvider('comandosDoContrato')]
    public function testComandoDoContratoEstaDocumentadoEmOperacao(string $comando, string $script): void
    {
        $this->assertStringContainsString(
            $script,
            self::ler('docs/OPERACAO.md'),
            "docs/OPERACAO.md não menciona {$script} (composer {$comando}).",
        );
    }

    /**
     * Runbook que manda rodar comando inexistente é pior que runbook ausente:
     * manda o operador para o vazio no pior momento possível.
     */
    public function testTodoComandoComposerCitadoNaDocumentacaoExiste(): void
    {
        $raiz = self::raiz();
        $arquivos = array_merge(
            (array) glob($raiz . '/docs/*.md'),
            [$raiz . '/README.md'],
        );

        $scripts = self::scriptsDoComposer();
        $citados = [];
        foreach ($arquivos as $arquivo) {
            if (!is_string($arquivo) || !is_file($arquivo)) {
                continue;
            }
            $conteudo = file_get_contents($arquivo);
            if (!is_string($conteudo)) {
                continue;
            }
            if (preg_match_all('/\bcomposer ([a-z][a-z0-9:._-]*)/', $conteudo, $casamentos) === false) {
                continue;
            }
            foreach ($casamentos[1] as $nome) {
                $citados[$nome][] = basename($arquivo);
            }
        }

        // Guarda do próprio extrator: uma regex que deixasse de casar
        // transformaria este teste em "nada a verificar, tudo verde".
        $this->assertGreaterThan(20, count($citados), 'A documentação deixou de citar comandos reconhecíveis.');

        $inexistentes = [];
        foreach ($citados as $nome => $onde) {
            if (in_array($nome, self::NATIVOS_DO_COMPOSER, true) || array_key_exists($nome, $scripts)) {
                continue;
            }
            $inexistentes[] = $nome . ' (' . implode(', ', array_unique($onde)) . ')';
        }

        $this->assertSame(
            [],
            $inexistentes,
            'Documentação cita comando que não existe no composer.json: ' . implode('; ', $inexistentes),
        );
    }

    /**
     * Script operacional que chama um alias removido só falha no host, quando o
     * operador mais precisa dele. Varremos linhas de invocação em shell e PHP;
     * o início de linha evita confundir exemplos e prosa com execução real.
     */
    public function testTodoComandoComposerCitadoNosScriptsExiste(): void
    {
        $raiz = self::raiz();
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz . '/scripts', \FilesystemIterator::SKIP_DOTS),
        );
        $scripts = self::scriptsDoComposer();
        $inexistentes = [];
        $citados = [];

        foreach ($iterador as $arquivo) {
            if (!$arquivo instanceof \SplFileInfo || !$arquivo->isFile()) {
                continue;
            }
            if (!in_array($arquivo->getExtension(), ['php', 'sh'], true)) {
                continue;
            }

            $conteudo = file_get_contents($arquivo->getPathname());
            if (!is_string($conteudo)
                || preg_match_all(
                    '/^[ \t]*(?:(?:if|elif|then|exec)\s+)?(?:[A-Z_][A-Z0-9_]*=\S*\s+)*composer\s+([a-z][a-z0-9:._-]*)/m',
                    $conteudo,
                    $casamentos,
                ) === false
            ) {
                continue;
            }
            foreach ($casamentos[1] as $nome) {
                $relativo = substr($arquivo->getPathname(), strlen($raiz) + 1);
                $citados[$nome][] = $relativo;
            }
        }

        // Conta invocações, não nomes: os scripts de host só chamam comandos
        // nativos, então poucos nomes distintos aparecem várias vezes.
        $this->assertGreaterThan(
            5,
            array_sum(array_map('count', $citados)),
            'A varredura dos scripts deixou de encontrar comandos Composer.',
        );
        foreach ($citados as $nome => $onde) {
            if (in_array($nome, self::NATIVOS_DO_COMPOSER, true) || array_key_exists($nome, $scripts)) {
                continue;
            }
            $inexistentes[] = $nome . ' (' . implode(', ', array_unique($onde)) . ')';
        }

        $this->assertSame(
            [],
            $inexistentes,
            'Script cita comando que não existe no composer.json: ' . implode('; ', $inexistentes),
        );
    }
}
