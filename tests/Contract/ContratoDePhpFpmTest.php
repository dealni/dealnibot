<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato do pool PHP-FPM versionado.
 *
 * O pool é um artefato de deploy, não é carregado pela suíte: estas asserções
 * impedem que uma troca de socket, ambiente ou diretiva de segurança só seja
 * descoberta quando o serviço entrar em produção.
 */
final class ContratoDePhpFpmTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Usuário/grupo do pool, nome do socket e do drop-in do systemd. */
    private const SLUG = 'dealnibot';

    /** Teto de uma requisição no pool (request_terminate_timeout). */
    private const TIMEOUT_DA_REQUISICAO = '330s';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    public function testPoolFixaDiretivasDoContrato(): void
    {
        $pool = self::ler('deploy/php-fpm/' . self::SLUG . '.conf');

        foreach ([
            'user = ' . self::SLUG,
            'group = ' . self::SLUG,
            'listen = /run/php/php8.5-fpm-' . self::SLUG . '.sock',
            'clear_env = yes',
            'request_terminate_timeout = ' . self::TIMEOUT_DA_REQUISICAO,
            'request_terminate_timeout_track_finished = no',
            'security.limit_extensions = .php',
        ] as $diretiva) {
            self::assertStringContainsString($diretiva, $pool, "Pool sem {$diretiva}.");
        }

        self::assertStringNotContainsString(
            'security.limit_extensions = .php .phar',
            $pool,
            'O pool não deve permitir Phar como script FastCGI.',
        );
    }

    public function testSocketDoPoolEdoVhostSaoOMesmo(): void
    {
        $pool = self::ler('deploy/php-fpm/' . self::SLUG . '.conf');
        $vhost = self::ler('deploy/nginx/' . self::SLUG . '.conf');
        $socket = '/run/php/php8.5-fpm-' . self::SLUG . '.sock';

        self::assertSame(
            [$socket],
            $this->linhasComSocket($pool, '/^listen\s*=\s*(.+)$/m'),
        );
        self::assertSame(
            [$socket],
            $this->linhasComSocket($vhost, '/fastcgi_pass\s+unix:(.+);/'),
        );
    }

    /** @return list<string> */
    private function linhasComSocket(string $conteudo, string $padrao): array
    {
        preg_match_all($padrao, $conteudo, $matches);
        $valores = $matches[1] ?? [];

        return array_values(array_unique(array_map('trim', array_filter($valores, 'is_string'))));
    }

    public function testRunbookExigeReinicioParaNovoEnvironmentFile(): void
    {
        $operacao = self::ler('docs/OPERACAO.md');

        self::assertStringContainsString(
            'systemctl daemon-reload && systemctl restart php8.5-fpm',
            $operacao,
        );
        self::assertStringContainsString('`reload` sozinho', $operacao);
        self::assertStringNotContainsString(
            'systemctl daemon-reload && systemctl reload php8.5-fpm',
            $operacao,
            'Um reload não importa o EnvironmentFile atualizado no mestre FPM.',
        );
    }

    public function testNotaDoDeployMandaReiniciarAUnitDoFpm(): void
    {
        $deploy = self::ler('scripts/deploy/deploy.sh');

        // O deploy sempre refaz o fpm.environment; um `reload` não reimporta o
        // EnvironmentFile e o deploy:verify exige o FPM reiniciado depois do
        // carimbo. A nota final precisa pedir restart da unit deste bot.
        self::assertStringContainsString('bash scripts/deploy/preparar_fpm.sh', $deploy);
        self::assertSame(1, preg_match('/^nota_final="([^"]*)"$/m', $deploy, $nota));
        self::assertStringContainsString('systemctl restart php8.5-fpm-' . self::SLUG, $nota[1]);
        self::assertDoesNotMatchRegularExpression('/Recarregue|(?<!daemon-)\breload\b/i', $nota[1]);
        self::assertStringNotContainsString('reload/restart', $deploy);
    }

    public function testIndiceApontaParaConfiguracaoOficialDoFpm(): void
    {
        $documentos = self::ler('docs/REFERENCIAS.md');

        self::assertStringContainsString(
            'https://www.php.net/manual/en/install.fpm.configuration.php',
            $documentos,
        );
    }

    public function testDocumentacaoNaoTrataPoolComoFronteiraCompleta(): void
    {
        $operacao = self::ler('docs/OPERACAO.md');

        self::assertStringContainsString('não é uma fronteira completa de segurança', $operacao);
        self::assertStringContainsString('OPcache', $operacao);
    }

    public function testRunbookRegistraRiscoAceitoDoEnvironmentFileComSegredos(): void
    {
        $operacao = self::ler('docs/OPERACAO.md');
        $dropIn = self::ler('deploy/systemd/php8.5-fpm-' . self::SLUG . '-environment.conf');

        self::assertStringContainsString('EnvironmentFile', $operacao);
        self::assertStringContainsString('root:' . self::SLUG . ' 0640', $operacao);
        self::assertStringContainsString('0600 root:root', $operacao);
        self::assertStringContainsString('risco aceito', $operacao);
        self::assertStringContainsString('risco aceito', $dropIn);
    }
}
