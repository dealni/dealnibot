<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class DeployAssetsTest extends TestCase
{
    /** @var list<string> */
    private array $diretorios = [];

    protected function tearDown(): void
    {
        foreach ($this->diretorios as $diretorio) {
            $itens = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($diretorio, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($itens as $item) {
                $this->assertInstanceOf(\SplFileInfo::class, $item);
                if ($item->isLink() || !$item->isDir()) {
                    unlink($item->getPathname());
                } else {
                    rmdir($item->getPathname());
                }
            }
            rmdir($diretorio);
        }
    }

    private function configuracaoIsolada(): string
    {
        $diretorio = sys_get_temp_dir() . '/dealnibot-config-' . bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($diretorio, 0700));
        $this->diretorios[] = $diretorio;
        $this->assertTrue(mkdir($diretorio . '/inclusos'));
        $this->assertTrue(mkdir($diretorio . '/scripts/install', 0700, true));
        file_put_contents($diretorio . '/inclusos/config.example.php', 'exemplo-sem-segredos');
        chmod($diretorio . '/inclusos/config.example.php', 0644);
        $this->assertTrue(copy(
            __DIR__ . '/../../scripts/install/configurar_credenciais.sh',
            $diretorio . '/scripts/install/configurar_credenciais.sh'
        ));

        return $diretorio;
    }

    /** @return array{status: int, saida: string} */
    private function executarShell(string $diretorio, string $codigo): array
    {
        $processo = proc_open(['/bin/bash', '-c', $codigo], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, $diretorio);
        $this->assertIsResource($processo);
        fclose($pipes[0]);
        $saida = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $erros = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertIsString($saida);
        $this->assertIsString($erros);

        return ['status' => proc_close($processo), 'saida' => $saida . $erros];
    }

    public function testQuickstartCriaConfiguracaoPrivadaEPreservaCredenciaisAoReexecutar(): void
    {
        $diretorio = $this->configuracaoIsolada();
        $readme = file_get_contents(__DIR__ . '/../../README.md');
        $this->assertIsString($readme);
        $this->assertSame(1, preg_match('/^bash scripts\/install\/configurar_credenciais\.sh[^\n]*/m', $readme, $comando));
        $resultado = $this->executarShell($diretorio, "umask 022\n" . $comando[0]);
        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        $configuracao = $diretorio . '/inclusos/config.php';
        clearstatcache(true, $configuracao);
        $this->assertSame(0600, fileperms($configuracao) & 0777);
        $this->assertSame('exemplo-sem-segredos', file_get_contents($configuracao));

        file_put_contents($configuracao, 'credencial-ficticia-a-preservar');
        chmod($configuracao, 0644);
        $resultado = $this->executarShell($diretorio, $comando[0]);
        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        clearstatcache(true, $configuracao);
        $this->assertSame(0600, fileperms($configuracao) & 0777);
        $this->assertSame('credencial-ficticia-a-preservar', file_get_contents($configuracao));
    }

    public function testReexecucaoNoHostMantemLeituraDoPoolPeloGrupo(): void
    {
        $diretorio = $this->configuracaoIsolada();
        $script = $diretorio . '/scripts/install/configurar_credenciais.sh';
        $fonte = file_get_contents($script);
        $this->assertIsString($fonte);
        // Só a guarda do caminho muda: o checkout temporário faz papel de /var/www.
        $this->assertSame(1, substr_count($fonte, '/var/www/*)'));
        file_put_contents($script, str_replace('/var/www/*)', '*)', $fonte));
        $configuracao = $diretorio . '/inclusos/config.php';
        file_put_contents($configuracao, 'credencial-ficticia-a-preservar');
        foreach ([0644, 0600] as $modo) {
            chmod($configuracao, $modo);
            $resultado = $this->executarShell($diretorio, 'bash scripts/install/configurar_credenciais.sh');
            $this->assertSame(0, $resultado['status'], $resultado['saida']);
            $this->assertStringContainsString('permissão 0640', $resultado['saida']);
            clearstatcache(true, $configuracao);
            $this->assertSame(0640, fileperms($configuracao) & 0777);
        }
        $this->assertSame('credencial-ficticia-a-preservar', file_get_contents($configuracao));
    }

    public function testConfiguracaoRecusaLinksInclusiveQuebradosEDiretorios(): void
    {
        foreach (['link', 'quebrado', 'diretorio'] as $tipo) {
            $diretorio = $this->configuracaoIsolada();
            $configuracao = $diretorio . '/inclusos/config.php';
            $alvo = $diretorio . '/alvo';
            if ($tipo === 'diretorio') {
                mkdir($configuracao);
            } else {
                if ($tipo === 'link') {
                    file_put_contents($alvo, 'nao-alterar');
                    chmod($alvo, 0644);
                }
                symlink($alvo, $configuracao);
            }
            $resultado = $this->executarShell($diretorio, 'bash scripts/install/configurar_credenciais.sh');
            $this->assertSame(1, $resultado['status']);
            if ($tipo === 'link') {
                $this->assertSame('nao-alterar', file_get_contents($alvo));
                $this->assertSame(0644, fileperms($alvo) & 0777);
            } elseif ($tipo === 'quebrado') {
                $this->assertFileDoesNotExist($alvo);
            }
        }
    }

    public function testTodosInstaladoresPreservamPermissaoDaConfiguracaoDuranteReexecucao(): void
    {
        // instalar_antigo.sh é só uma shim que delega ao instalar_linux.sh.
        foreach (['linux', 'macos'] as $plataforma) {
            $diretorio = $this->configuracaoIsolada();
            file_put_contents($diretorio . '/inclusos/config.php', 'credencial-ficticia');
            chmod($diretorio . '/inclusos/config.php', 0600);
            $fonte = file_get_contents(__DIR__ . '/../../scripts/install/instalar_' . $plataforma . '.sh');
            $this->assertIsString($fonte);
            $this->assertSame(1, preg_match(
                '/# Configurando (?:permissões dos arquivos|arquivos)\n(.*?)# (?:Teste final|Mensagem final)/s',
                $fonte,
                $bloco
            ));
            // Executa somente o bloco de permissões, sem instalar nem contactar serviços.
            $espiao = <<<'BASH'
sudo_cmd=()
project_dir="$PWD"
runtime_user="$(id -un)"
runtime_group="$(id -gn)"
sudo() { "$@"; }
chmod() {
    for argumento in "$@"; do
        if [ "$argumento" = "inclusos/config.php" ]; then
            case "$1" in
                600|0600) ;;
                *) printf '%s\n' "$*" >> permissoes-ampliadas ;;
            esac
        fi
    done
    command chmod "$@"
}
BASH;
            $resultado = $this->executarShell($diretorio, $espiao . "\n" . $bloco[1]);
            $this->assertSame(0, $resultado['status'], $resultado['saida']);
            $this->assertFileDoesNotExist($diretorio . '/permissoes-ampliadas');
            $this->assertSame('credencial-ficticia', file_get_contents($diretorio . '/inclusos/config.php'));
            clearstatcache();
            $this->assertSame(0600, fileperms($diretorio . '/inclusos/config.php') & 0777);
            $this->assertSame(0644, fileperms($diretorio . '/inclusos/config.example.php') & 0777);
        }
    }

    public function testPoolUsaUsuarioDedicadoETempoCompativelComAudio(): void
    {
        $pool = file_get_contents(__DIR__ . '/../../deploy/php-fpm/dealnibot.conf');
        $nginx = file_get_contents(__DIR__ . '/../../deploy/nginx/dealnibot.conf');
        $cron = file_get_contents(__DIR__ . '/../../config/crontab.txt');
        $this->assertIsString($pool);
        $this->assertIsString($nginx);
        $this->assertIsString($cron);

        $this->assertStringContainsString("\nuser = dealnibot\n", $pool);
        $this->assertStringContainsString("\ngroup = dealnibot\n", $pool);
        $this->assertStringNotContainsString("\nuser = www-data\n", $pool);
        $this->assertStringContainsString("\nlisten = /run/php/php8.5-fpm-dealnibot.sock\n", $pool);
        $this->assertStringContainsString("\nlisten.owner = www-data\n", $pool);
        $this->assertStringContainsString("\nlisten.group = www-data\n", $pool);
        $this->assertStringContainsString("\nlisten.mode = 0660\n", $pool);
        $this->assertStringContainsString("\npm = dynamic\n", $pool);
        $this->assertStringContainsString("\npm.max_children = 8\n", $pool);
        $this->assertStringContainsString("\nclear_env = yes\n", $pool);
        $this->assertStringContainsString("\nenv[DEALNI_DB_DSN] = \$DEALNI_FPM_DB_DSN\n", $pool);
        $this->assertStringContainsString("\nenv[DEALNI_PUBLIC_BASE_URL] = \$DEALNI_FPM_PUBLIC_BASE_URL\n", $pool);
        // O FPM também avisa o dono quando o OpenRouter recusa a conta.
        $this->assertStringContainsString("\nenv[DEALNI_ALERTA_CHAT_ID] = \$DEALNI_FPM_ALERTA_CHAT_ID\n", $pool);
        $this->assertStringContainsString('request_terminate_timeout = 330s', $pool);
        $this->assertStringContainsString('request_terminate_timeout_track_finished = no', $pool);
        $this->assertStringContainsString('security.limit_extensions = .php', $pool);
        $this->assertStringNotContainsString("\npm.status_path =", $pool);
        $this->assertStringNotContainsString("\npm.status_listen =", $pool);
        $this->assertStringNotContainsString("\nping.path =", $pool);
        $this->assertStringContainsString('fastcgi_read_timeout 330s', $nginx);
        $this->assertMatchesRegularExpression('/^10 5 \* \* \* dealnibot /m', $cron);
    }

    public function testCronFixaFusoSerializaExecucaoEEvitaEmailEmSucesso(): void
    {
        $cron = file_get_contents(__DIR__ . '/../../config/crontab.txt');
        $this->assertIsString($cron);
        $this->assertSame("\n", substr($cron, -1), 'crontab precisa terminar com newline');
        $this->assertStringContainsString("\nSHELL=/bin/bash\n", $cron);
        $this->assertStringContainsString(
            "\nPATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n",
            $cron
        );
        $this->assertStringContainsString("\nCRON_TZ=America/Sao_Paulo\n", $cron);
        $this->assertStringNotContainsString("\nDEALNI_DB_PATH=", $cron);
        $this->assertStringNotContainsString("\nDEALNI_SNAPSHOTS_DIR=", $cron);
        $this->assertStringContainsString('scripts/operacao/executar_com_ambiente.sh', $cron);

        $this->assertSame(
            1,
            preg_match_all('/^10 5 \* \* \* dealnibot (.+)$/m', $cron, $linhas),
            'deve haver exatamente um job diário com usuário explícito'
        );
        $comando = $linhas[1][0];
        $this->assertStringStartsWith('/usr/local/libexec/vps-backup-maintenance.py shared 2700 /var/www/dealnibot/scripts/operacao/executar_com_ambiente.sh', $comando);
        $this->assertStringContainsString(
            '--lock-nonblocking /var/lib/dealnibot/cron/snapshot.lock'
            . ' /var/www/dealnibot/scripts/operacao/avisar_se_falhar.sh snapshot --php',
            $comando,
        );
        $this->assertStringContainsString('/var/www/dealnibot/processos/snapshot_banco.php', $comando);

        // Silêncio em sucesso vem de `MAILTO` declarado + `>/dev/null`: o
        // script só escreve no STDERR quando falha, e é o STDERR que o cron
        // entrega por e-mail. A captura `output="$(... 2>&1)"` de antes fazia o
        // mesmo trabalho com um `printf` cujo `%` precisava de escape em
        // crontab(5) — uma linha a menos para errar.
        $this->assertStringContainsString("\nMAILTO=root\n", $cron);
        $this->assertStringEndsWith('>/dev/null', $comando);
        $this->assertStringNotContainsString('2>&1', $comando);

        // crontab(5): um `%` sem escape vira quebra de linha e o resto da linha
        // vai para o stdin do comando. O `printf '%s\n'` de antes chegava ao
        // bash como `printf '` com aspas abertas, e o cron mandava e-mail de
        // erro de sintaxe todo dia. Só `\%` é aceito, em TODA linha de comando.
        foreach ($this->linhasDeComandoDoCrontab($cron) as $numero => $linha) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!\\\\)%/',
                $linha,
                "crontab linha {$numero}: `%` sem escape vira quebra de linha para o cron",
            );
        }
    }

    /**
     * Linhas de comando do crontab (formato /etc/cron.d): tudo que não é
     * comentário, linha em branco nem atribuição de variável.
     *
     * @return array<int, string> número da linha (1-based) => conteúdo
     */
    private function linhasDeComandoDoCrontab(string $cron): array
    {
        $comandos = [];
        foreach (explode("\n", $cron) as $indice => $linha) {
            if (trim($linha) === '' || str_starts_with(ltrim($linha), '#')) {
                continue;
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $linha) === 1) {
                continue;
            }
            $comandos[$indice + 1] = $linha;
        }
        $this->assertNotSame([], $comandos, 'o crontab precisa ter ao menos uma linha de comando');

        return $comandos;
    }

    public function testInstaladorEntregaEstadoDoMonitorELocksAoUsuarioDosJobs(): void
    {
        $instalador = file_get_contents(__DIR__ . '/../../scripts/install/instalar_linux.sh');
        $cron = file_get_contents(__DIR__ . '/../../config/crontab.txt');
        $monitor = file_get_contents(__DIR__ . '/../../scripts/operacao/monitorar_saude.sh');
        $this->assertIsString($instalador);
        $this->assertIsString($cron);
        $this->assertIsString($monitor);

        // O monitor roda como `dealnibot` e grava em /var/lib/dealnibot (padrão
        // de DEALNI_MONITOR_STATE_DIR). Com a raiz criada só por
        // `install -d .../cron`, ela nascia root:root 0755 e o job falhava a
        // cada cinco minutos.
        $this->assertMatchesRegularExpression('/^\*\/5 \* \* \* \* dealnibot .*monitorar_saude\.sh/m', $cron);
        $this->assertStringContainsString('state_dir="${MONITOR_STATE_DIR:-${!nome:-/var/lib/$bot}}"', $monitor);
        $this->assertStringContainsString('estado_dir="/var/lib/dealnibot"', $instalador);
        $this->assertStringContainsString(
            "install -d -o \"\$runtime_user\" -g \"\$runtime_group\" -m 0700 \\\n        \"\$estado_dir\" \"\$estado_dir/cron\"",
            $instalador,
        );
        $this->assertStringContainsString('chown "$runtime_user:$runtime_group" "$estado_dir" "$estado_dir/cron"', $instalador);
        $this->assertStringContainsString('chmod 0700 "$estado_dir" "$estado_dir/cron"', $instalador);
        $this->assertStringContainsString('Estado do host precisa ser diretório regular, sem symlink', $instalador);

        // Todo lock que o cron usa nasce arquivo regular 0600 do usuário do job.
        preg_match_all('#/var/lib/dealnibot/cron/([a-z-]+\.lock)#', $cron, $locks);
        $this->assertNotEmpty($locks[1]);
        $this->assertSame(1, preg_match('/for nome_lock in ([^;]+); do/', $instalador, $criados));
        $this->assertEqualsCanonicalizing(array_values(array_unique($locks[1])), preg_split('/\s+/', trim($criados[1])));
        $this->assertStringContainsString(
            'install -o "$runtime_user" -g "$runtime_group" -m 0600 /dev/null "$caminho_lock"',
            $instalador,
        );
    }

    public function testFallbackLocalDoCiExecutaCheckSemAtualizarBaseline(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../.lefthook/pre-push/qualidade.sh');
        $this->assertIsString($fonte);
        $this->assertStringContainsString(
            'bash scripts/quality/with_local_config.sh composer check:all',
            $fonte,
        );

        // O `CI=1` saiu do hook e passou a morar no invólucro, que também monta
        // o banco descartável: sem ele o agregado reprovaria por falta de DSN na
        // máquina de quem empurra, e um baseline poderia ser regravado.
        $involucro = file_get_contents(__DIR__ . '/../../scripts/quality/with_local_config.sh');
        $this->assertIsString($involucro);
        $this->assertStringContainsString('export CI=1', $involucro);
        $this->assertStringContainsString('scripts/quality/with_postgres.sh', $involucro);
    }

    public function testLefthookFixaBinarioLocalEExigeFalhaQuandoEleSome(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../lefthook.yml');
        $this->assertIsString($fonte);

        $this->assertMatchesRegularExpression(
            '/^lefthook: \.\/node_modules\/\.bin\/lefthook$/m',
            $fonte,
        );
        $this->assertMatchesRegularExpression(
            '/^assert_lefthook_installed: true$/m',
            $fonte,
        );
        $this->assertMatchesRegularExpression(
            '/^min_version: 2\.1\.12$/m',
            $fonte,
        );
    }

    public function testHookDeMensagemPreservaOArquivoOficialDoGit(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../lefthook.yml');
        $this->assertIsString($fonte);
        $this->assertStringContainsString(
            'run: npm exec --offline -- commitlint --edit "{1}"',
            $fonte,
        );
    }
}
