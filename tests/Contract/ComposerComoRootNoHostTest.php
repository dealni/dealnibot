<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * No host o checkout é do root e o runtime só lê, então deploy, verificação e
 * rollback rodam como root. O Composer recusa rodar script como root, e um
 * plugin ou script de pacote rodando como root é exatamente o que não se quer.
 *
 * O contrato: os scripts de host chamam o Composer só para `install` e
 * `check-platform-reqs`, sempre com COMPOSER_ALLOW_SUPERUSER=1 e --no-plugins
 * (e o install também com --no-scripts); o que era `composer <script>` vira a
 * chamada direta do PHP; o que o install deixaria de rodar por --no-scripts
 * roda explícito; e o runbook manda chamar os scripts, não o Composer.
 */
final class ComposerComoRootNoHostTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    /** Scripts que o runbook manda rodar no host, como root. */
    private const SCRIPTS_DE_HOST = [
        'scripts/deploy/deploy.sh',
        'scripts/deploy/rollback.sh',
        'scripts/deploy/deploy_verify.sh',
    ];

    /** Subcomandos nativos que cada script de host precisa chamar. */
    private const SUBCOMANDOS_EXIGIDOS = [
        'scripts/deploy/deploy.sh' => ['install', 'check-platform-reqs'],
        'scripts/deploy/rollback.sh' => ['install'],
        'scripts/deploy/deploy_verify.sh' => ['check-platform-reqs'],
    ];

    /** Script do composer.json => script de host que roda o mesmo comando direto. */
    private const SCRIPTS_CHAMADOS_DIRETO = [
        'check:env' => 'scripts/deploy/deploy.sh',
        'check:config:production' => 'scripts/deploy/deploy.sh',
        'test:smoke' => 'scripts/deploy/deploy_verify.sh',
    ];

    /** Eventos que um `composer install` dispara; com --no-scripts, nenhum roda. */
    private const EVENTOS_DO_INSTALL = [
        'pre-install-cmd',
        'post-install-cmd',
        'pre-autoload-dump',
        'post-autoload-dump',
        'pre-operations-exec',
        'pre-package-install',
        'post-package-install',
        'pre-package-update',
        'post-package-update',
        'pre-package-uninstall',
        'post-package-uninstall',
    ];

    /** Scripts que recebem a lista explícita de passos pós-install. */
    private const SCRIPTS_COM_PASSOS_POS_INSTALL = [
        'scripts/deploy/deploy.sh',
        'scripts/deploy/rollback.sh',
    ];

    /**
     * Formas de mandar o root chamar o Composer, que ele recusa: token de
     * confirmação seguido de `composer`, o wrapper de ambiente chamando
     * `composer` e os comandos que só existem no host.
     */
    private const FORMAS_PROIBIDAS = [
        '/\b[A-Z][A-Z_]*_CONFIRM=\S+\s+composer\s/',
        '/executar_com_ambiente\.sh\s+composer\s/',
        '/\bcomposer (?:deploy|deploy:verify|rollback)(?![a-z0-9:_-])/',
    ];

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
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
     * Linhas de shell que executam o Composer, com as continuações juntadas.
     * Comentário e `echo` não casam: começam por outra palavra.
     *
     * @return list<string>
     */
    private static function invocacoesDoComposer(string $fonte): array
    {
        $unido = (string) preg_replace('/\\\\\n[ \t]*/', ' ', $fonte);
        preg_match_all(
            '/^[ \t]*(?:(?:if|elif|then|exec|!)\s+)*(?:[A-Z_][A-Z0-9_]*=\S*\s+)*composer\s.*$/m',
            $unido,
            $casamentos,
        );

        return $casamentos[0];
    }

    public function testScriptsDeHostSoChamamOComposerComoRootSemPluginsNemScripts(): void
    {
        foreach (self::SCRIPTS_DE_HOST as $script) {
            $fonte = self::ler($script);
            $vistos = [];

            foreach (self::invocacoesDoComposer($fonte) as $linha) {
                self::assertSame(
                    1,
                    preg_match('/^\s*(?:(?:if|elif|then|exec|!)\s+)*((?:[A-Z_][A-Z0-9_]*=\S*\s+)*)composer\s+(\S+)/', $linha, $partes),
                    "{$script}: invocação irreconhecível: {$linha}",
                );
                [, $ambiente, $subcomando] = $partes;
                $vistos[] = $subcomando;

                self::assertContains(
                    $subcomando,
                    ['install', 'check-platform-reqs'],
                    "{$script}: `composer {$subcomando}` como root é recusado; chame o PHP direto: {$linha}",
                );
                self::assertStringContainsString('COMPOSER_ALLOW_SUPERUSER=1', $ambiente, "{$script}: {$linha}");
                self::assertMatchesRegularExpression('/\s--no-plugins(?:\s|$)/', $linha, "{$script}: {$linha}");
                if ($subcomando === 'install') {
                    self::assertMatchesRegularExpression('/\s--no-scripts(?:\s|$)/', $linha, "{$script}: {$linha}");
                    self::assertMatchesRegularExpression('/\s--no-dev(?:\s|$)/', $linha, "{$script}: {$linha}");
                }
            }

            foreach (self::SUBCOMANDOS_EXIGIDOS[$script] as $exigido) {
                self::assertContains($exigido, $vistos, "{$script} deveria chamar composer {$exigido}.");
            }

            // A permissão vale por comando. Repassada pelo shim, ela chegaria
            // a qualquer Composer que um passo de pacote chamasse por dentro.
            self::assertStringNotContainsString('COMPOSER_ALLOW_SUPERUSER="${', $fonte, $script);
        }
    }

    public function testScriptsDoComposerViramChamadaDiretaDoMesmoComando(): void
    {
        $scripts = self::scriptsDoComposer();

        foreach (self::SCRIPTS_CHAMADOS_DIRETO as $nome => $script) {
            $comando = $scripts[$nome] ?? null;
            self::assertIsString($comando, "composer.json deveria ter o script {$nome}.");
            self::assertSame(1, preg_match('/^@php (\S+)$/', $comando, $partes), "{$nome}: esperado `@php <arquivo>`.");
            self::assertStringContainsString(
                'php ' . $partes[1],
                self::ler($script),
                "{$script} deveria rodar o mesmo comando de composer {$nome}, direto pelo PHP.",
            );
        }
    }

    /**
     * @param array<string, mixed> $scripts
     * @param array<string, true> $visitados
     * @return list<string>
     */
    private static function comandosDoScript(array $scripts, string $nome, array $visitados = []): array
    {
        self::assertArrayNotHasKey($nome, $visitados, "Ciclo nos scripts do composer.json em {$nome}.");
        $valor = $scripts[$nome] ?? [];
        $lista = is_array($valor) ? $valor : [$valor];
        $comandos = [];
        foreach ($lista as $item) {
            self::assertIsString($item, "{$nome}: item de script não textual.");
            if (preg_match('/^@(?!php |composer |putenv )(\S+)$/', $item, $referencia) === 1) {
                $comandos = [...$comandos, ...self::comandosDoScript($scripts, $referencia[1], $visitados + [$nome => true])];
                continue;
            }
            $comandos[] = $item;
        }

        return $comandos;
    }

    /** @return list<string> */
    private static function passosDeclarados(string $script): array
    {
        self::assertSame(
            1,
            preg_match('/^passos_pos_install=\(([^)]*)\)$/m', self::ler($script), $partes),
            "{$script}: falta passos_pos_install=(...) no bloco de dados.",
        );
        $itens = preg_split('/\s+/', trim($partes[1]), -1, PREG_SPLIT_NO_EMPTY);
        self::assertIsArray($itens);

        return array_map(static fn (string $item): string => trim($item, '"\''), $itens);
    }

    public function testPassosQueONoScriptsPulariaRodamExplicitos(): void
    {
        $scripts = self::scriptsDoComposer();
        $esperados = [];
        foreach (self::EVENTOS_DO_INSTALL as $evento) {
            foreach (self::comandosDoScript($scripts, $evento) as $comando) {
                self::assertSame(
                    1,
                    preg_match('/^@php (\S+)$/', $comando, $partes),
                    "{$evento} roda `{$comando}`, que o deploy com --no-scripts não sabe repetir.",
                );
                if (!in_array($partes[1], $esperados, true)) {
                    $esperados[] = $partes[1];
                }
            }
        }

        foreach (self::SCRIPTS_COM_PASSOS_POS_INSTALL as $script) {
            self::assertSame(
                $esperados,
                self::passosDeclarados($script),
                "{$script}: passos_pos_install precisa listar o que os eventos do install rodariam.",
            );
            self::assertStringContainsString('php "$passo"', self::ler($script), $script);
        }
    }

    /** @return list<string> */
    private static function textosVersionados(): array
    {
        $arquivos = array_merge(
            (array) glob(self::RAIZ . '/docs/*.md'),
            (array) glob(self::RAIZ . '/*.md'),
            (array) glob(self::RAIZ . '/suporte/*.md'),
        );
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::RAIZ . '/scripts', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterador as $arquivo) {
            if ($arquivo instanceof \SplFileInfo && in_array($arquivo->getExtension(), ['sh', 'php'], true)) {
                $arquivos[] = $arquivo->getPathname();
            }
        }

        return array_values(array_filter($arquivos, 'is_string'));
    }

    public function testRunbookEScriptsMandamORootChamarOsScriptsDireto(): void
    {
        $achados = [];
        foreach (self::textosVersionados() as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            self::assertIsString($conteudo);
            foreach (self::FORMAS_PROIBIDAS as $forma) {
                if (preg_match($forma, $conteudo, $achado) === 1) {
                    $achados[] = substr($arquivo, strlen(self::RAIZ) + 1) . ': ' . $achado[0];
                }
            }
        }
        self::assertSame([], $achados, 'Chamada ao Composer que o root não consegue rodar no host.');

        self::assertSame(
            1,
            preg_match('/^expected_dir="\/var\/www\/([a-z0-9]+)"$/m', self::ler('scripts/deploy/deploy.sh'), $partes),
        );
        $bot = $partes[1];
        $operacao = self::ler('docs/OPERACAO.md');
        foreach ([
            "DEPLOY_CONFIRM={$bot} scripts/deploy/deploy.sh",
            'scripts/deploy/deploy_verify.sh --esperado=',
            "ROLLBACK_CONFIRM={$bot} scripts/deploy/rollback.sh",
        ] as $comando) {
            self::assertStringContainsString($comando, $operacao, "docs/OPERACAO.md deveria mandar rodar {$comando}.");
        }
    }
}
