<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrato do agendador de produção.
 *
 * O arquivo é um /etc/cron.d, não um crontab de usuário: além dos cinco campos
 * de tempo ele carrega o usuário. O teste fica estático de propósito — macOS
 * traz um parser Vixie sem `crontab -T` e não entende o sexto campo do modelo;
 * a validação real acontece no host Debian durante a instalação.
 */
final class ContratoDeCronTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Usuário dos jobs, raiz do checkout no host e diretório dos locks. */
    private const SLUG = 'dealnibot';
    private const ROTINAS_SUPERVISIONADAS = ['/processos/snapshot_banco.php'];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /** @return list<string> */
    private static function linhasAtivas(string $crontab): array
    {
        $linhas = preg_split('/\R/', $crontab);
        self::assertIsArray($linhas);

        $ativas = [];
        foreach ($linhas as $linha) {
            $linha = trim($linha);
            if ($linha === '' || str_starts_with($linha, '#') || preg_match('/^[A-Z_]+=/', $linha) === 1) {
                continue;
            }
            // Só os backups passam pelo coordenador externo; modo e prazo
            // são exatos. O restante do contrato continua validando o wrapper.
            $supervisionado = array_any(
                self::ROTINAS_SUPERVISIONADAS,
                static fn (string $alvo): bool => str_contains($linha, '/var/www/' . self::SLUG . $alvo . ' '),
            );
            if ($supervisionado) {
                $prefixo = '#^(\S+ \S+ \S+ \S+ \S+ ' . self::SLUG . ' )'
                    . preg_quote('/usr/local/libexec/vps-backup-maintenance.py shared 2700 ', '#') . '#';
                self::assertSame(1, preg_match($prefixo, $linha), "Backup sem supervisor canônico: {$linha}");
                $linha = preg_replace($prefixo, '$1', $linha);
                self::assertIsString($linha);
            } else {
                self::assertStringNotContainsString('vps-backup-maintenance.py', $linha, 'Supervisor restrito aos backups.');
            }
            $ativas[] = $linha;
        }

        return $ativas;
    }

    /** @return array<string, array{string}> */
    public static function prefixosDeBackupInvalidos(): array
    {
        return [
            'ausente' => [''],
            'caminho diferente' => ['/tmp/vps-backup-maintenance.py shared 2700 '],
            'modo diferente' => ['/usr/local/libexec/vps-backup-maintenance.py exclusive 2700 '],
            'prazo diferente' => ['/usr/local/libexec/vps-backup-maintenance.py shared 2701 '],
        ];
    }

    #[DataProvider('prefixosDeBackupInvalidos')]
    public function testBackupRecusaSupervisorAusenteOuDivergente(string $prefixo): void
    {
        $linha = '10 5 * * * ' . self::SLUG . ' ' . $prefixo . '/var/www/' . self::SLUG
            . '/scripts/operacao/executar_com_ambiente.sh /var/www/' . self::SLUG
            . self::ROTINAS_SUPERVISIONADAS[0] . ' >/dev/null';
        $this->expectException(AssertionFailedError::class);
        self::linhasAtivas($linha);
    }

    public function testSupervisorNaoPodeEnvolverJobForaDosBackups(): void
    {
        $this->expectException(AssertionFailedError::class);
        self::linhasAtivas('10 5 * * * ' . self::SLUG
            . ' /usr/local/libexec/vps-backup-maintenance.py shared 2700 /var/www/' . self::SLUG
            . '/scripts/operacao/executar_com_ambiente.sh --php /var/www/' . self::SLUG
            . '/scripts/operacao/monitorar_saude.sh >/dev/null');
    }

    public function testArgumentoComIgualNaoOcultaJobDoContrato(): void
    {
        $linha = '10 5 * * * ' . self::SLUG . ' /var/www/' . self::SLUG
            . '/scripts/operacao/executar_com_ambiente.sh --limite=10 >/dev/null';
        self::assertSame([$linha], self::linhasAtivas("SHELL=/bin/bash\n" . $linha));
    }

    public function testArquivoTemFormatoEtamanhoDeEtcCronD(): void
    {
        $crontab = self::ler('config/crontab.txt');
        self::assertStringEndsWith("\n", $crontab, 'cron exige newline no fim de cada entrada.');
        self::assertStringContainsString('SHELL=/bin/bash', $crontab);
        self::assertStringContainsString('MAILTO=root', $crontab);

        foreach (self::linhasAtivas($crontab) as $linha) {
            $campos = preg_split('/\s+/', $linha, 7);
            self::assertIsArray($campos);
            self::assertCount(7, $campos, "Entrada sem 5 campos de tempo, usuário e comando: {$linha}");
            self::assertSame(self::SLUG, $campos[5]);
            self::assertStringStartsWith('/var/www/' . self::SLUG . '/scripts/operacao/executar_com_ambiente.sh', $campos[6]);
            self::assertStringEndsWith('>/dev/null', $campos[6]);
            self::assertStringNotContainsString('2>&1', $campos[6], 'STDERR deve continuar sendo alerta por e-mail.');
        }
    }

    public function testTabelaNaoFixaBinarioNemSegredo(): void
    {
        $crontab = self::ler('config/crontab.txt');

        self::assertStringNotContainsString('/usr/bin/php8.5', $crontab, 'O binário vem de PHP85_BIN.');
        foreach (['_API_KEY=', '_PASSWORD=', '_SECRET='] as $segredo) {
            self::assertStringNotContainsString($segredo, $crontab, 'Segredo não entra na tabela.');
        }
    }

    public function testJobsNaoPassamPeloComposerNemPeloGit(): void
    {
        // O checkout é do root e o job roda como o usuário do bot: o Composer
        // consulta o git para adivinhar a versão do projeto, e o git recusa o
        // repositório ("dubious ownership"), mandando um erro por e-mail a cada
        // execução. Os jobs chamam o script de destino direto.
        foreach (self::linhasAtivas(self::ler('config/crontab.txt')) as $linha) {
            self::assertDoesNotMatchRegularExpression('/(?:^|[\s\/])(?:composer|git)(?:\s|$)/', $linha, $linha);
        }
    }

    public function testSnapshotAvisaNoTelegramQuandoFalha(): void
    {
        // O MAILTO não sai da VPS: sem o aviso, snapshot quebrado só aparece
        // quando alguém precisa do arquivo.
        $snapshots = array_values(array_filter(
            self::linhasAtivas(self::ler('config/crontab.txt')),
            static fn (string $linha): bool => str_contains($linha, '/cron/snapshot.lock '),
        ));

        self::assertCount(1, $snapshots);
        self::assertStringContainsString(
            '/cron/snapshot.lock /var/www/' . self::SLUG . '/scripts/operacao/avisar_se_falhar.sh snapshot --php /var/www/'
            . self::SLUG . '/processos/snapshot_banco.php >/dev/null',
            $snapshots[0],
        );
        self::assertFileExists(self::RAIZ . '/scripts/operacao/avisar_falha.php');
        self::assertTrue(is_executable(self::RAIZ . '/scripts/operacao/avisar_se_falhar.sh'));
        // O chat do aviso é opcional no schema: vazio desliga o aviso.
        self::assertStringContainsString(
            "'DEALNI_ALERTA_CHAT_ID' => ['type' => 'string', 'optional' => true, 'allow_empty' => true],",
            self::ler('config/env.schema.php'),
        );
    }

    public function testTodoJobAvisaNoTelegramQuandoFalha(): void
    {
        // O monitor passou de 21 a 26/09/2026 quebrado falando só com
        // /var/mail/root. Cada job, com o nome que vira a marca do aviso, passa
        // pelo avisar_se_falhar.sh logo depois do wrapper de ambiente (e do lock).
        $esperados = [
            '/webhook-inbox.lock ' => 'avisar_se_falhar.sh webhook-inbox --php /var/www/' . self::SLUG . '/processos/processar_webhook_inbox.php',
            '/snapshot.lock ' => 'avisar_se_falhar.sh snapshot --php /var/www/' . self::SLUG . '/processos/snapshot_banco.php',
            'monitorar_saude.sh' => 'executar_com_ambiente.sh /var/www/' . self::SLUG . '/scripts/operacao/avisar_se_falhar.sh monitor-saude /var/www/'
                . self::SLUG . '/scripts/operacao/monitorar_saude.sh',
        ];
        $linhas = self::linhasAtivas(self::ler('config/crontab.txt'));
        self::assertCount(count($esperados), $linhas, 'Job novo também precisa do aviso de falha.');
        foreach ($esperados as $marca => $trecho) {
            $achadas = array_values(array_filter($linhas, static fn (string $linha): bool => str_contains($linha, $marca)));
            self::assertCount(1, $achadas, $marca);
            self::assertStringContainsString($trecho, $achadas[0]);
        }
    }

    public function testSnapshotRodaAs5h10(): void
    {
        $snapshots = array_values(array_filter(
            self::linhasAtivas(self::ler('config/crontab.txt')),
            static fn (string $linha): bool => str_contains($linha, '/cron/snapshot.lock '),
        ));
        self::assertCount(1, $snapshots);
        self::assertStringStartsWith('10 5 * * * ' . self::SLUG . ' ', $snapshots[0]);
    }

    public function testWrapperCarregaAmbienteEResolvePhpELock(): void
    {
        $wrapper = self::ler('scripts/operacao/executar_com_ambiente.sh');

        self::assertStringContainsString('carregar_ambiente_seguro "$environment_file"', $wrapper);
        self::assertStringNotContainsString('source "$environment_file"', $wrapper);
        self::assertStringContainsString('environment_file_seguro()', $wrapper);
        self::assertStringContainsString('! -L "$environment_file"', $wrapper);
        self::assertStringContainsString("'0:root:root:600'", $wrapper);
        // O grupo permitido é o usuário de runtime, que o wrapper lê do próprio
        // bloco de dados: o contrato prende a forma da comparação E o valor de
        // `bot`, em vez de um literal repetido no meio do código canônico.
        self::assertStringContainsString('"0:root:$bot:640"', $wrapper);
        self::assertStringContainsString('bot="' . self::SLUG . '"', $wrapper);
        self::assertStringContainsString("stat -f '%Sg'", $wrapper);
        $guard = strpos($wrapper, 'if ! environment_file_seguro; then');
        $parser = strpos($wrapper, 'source "$script_dir/carregar_ambiente_seguro.sh"');
        self::assertIsInt($guard);
        self::assertIsInt($parser);
        self::assertLessThan($parser, $guard, 'EnvironmentFile deve ser validado antes de carregar o parser.');
        self::assertStringContainsString(
            'scripts/operacao/carregar_ambiente_seguro.sh',
            self::ler('scripts/deploy/deploy.sh'),
        );
        self::assertStringNotContainsString('source "$environment_file"', self::ler('scripts/deploy/deploy_verify.sh'));
        self::assertStringContainsString('php_bin="${PHP85_BIN:-}"', $wrapper);
        self::assertStringContainsString('canonicalizar_caminho_existente "$php_bin"', $wrapper);
        self::assertStringContainsString('php_bin_canonico', $wrapper);
        self::assertStringContainsString('[[ ! -f "$lock_file" || -L "$lock_file" ]]', $wrapper);
        self::assertStringContainsString('canonicalizar_caminho_existente "$lock_file"', $wrapper);
        self::assertStringContainsString('realpath -e -- "$1"', $wrapper);
        self::assertStringContainsString('exec {lock_fd}<>"$lock_file"', $wrapper);
        self::assertStringContainsString('chmod 0600 "$lock_fd_path"', $wrapper);
        self::assertStringContainsString('lock_fd_tem_modo_0600', $wrapper);
        self::assertStringContainsString(
            "stat -L -c '%a' -- \"\$lock_fd_path\"",
            $wrapper,
            'No Linux, stat precisa seguir /proc/<pid>/fd/N até o lock real.',
        );
        self::assertStringContainsString(
            "stat -L -f '%Lp' \"\$lock_fd_path\"",
            $wrapper,
            'No macOS, stat precisa seguir /dev/fd/N até o lock real.',
        );
        self::assertStringContainsString('"$flock_bin" --nonblock "$lock_fd"', $wrapper);
        self::assertStringContainsString('"$flock_bin" --exclusive "$lock_fd"', $wrapper);
    }

    public function testPathSeguroMantemPrefixosHomebrewFixos(): void
    {
        $parser = self::ler('scripts/operacao/carregar_ambiente_seguro.sh');
        $wrapper = self::ler('scripts/operacao/executar_com_ambiente.sh');
        $pathFixo = "'/usr/local/sbin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'";

        self::assertStringContainsString("PATH={$pathFixo}", $parser);
        self::assertStringContainsString("export PATH={$pathFixo}", $wrapper);
        self::assertStringNotContainsString('PATH="${PATH', $parser);
        self::assertStringNotContainsString('PATH="${PATH', $wrapper);
    }

    public function testIndiceApontaParaManuaisDeCronEDeFlock(): void
    {
        $documentos = self::ler('docs/REFERENCIAS.md');

        self::assertStringContainsString('https://man7.org/linux/man-pages/man5/crontab.5.html', $documentos);
        self::assertStringContainsString('https://man7.org/linux/man-pages/man1/flock.1.html', $documentos);
        self::assertStringContainsString('https://sources.debian.org/src/cron/', $documentos);
    }

    public function testRunbookExplicaDaemonAmbienteLocksEStdout(): void
    {
        $operacao = self::ler('docs/OPERACAO.md');

        self::assertStringContainsString('systemctl is-active --quiet cron', $operacao);
        self::assertStringContainsString('/var/lib/' . self::SLUG . '/cron', $operacao);
        self::assertStringContainsString('PHP85_BIN', $operacao);
        self::assertStringContainsString('/usr/sbin/sendmail', $operacao);
        self::assertStringContainsString('STDOUT de rotina vai para `/dev/null`', $operacao);
        self::assertStringContainsString('flock', $operacao);
    }
}
