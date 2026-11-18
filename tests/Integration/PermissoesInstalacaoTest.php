<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Executa o bloco REAL de permissões do instalador numa árvore descartável.
 *
 * Só duas coisas mudam no recorte: `chown` vira uma função que registra a
 * chamada (o teste não roda como root) e a condição de produção, quando o
 * bloco tem uma, vira `true`, para o ramo da VPS rodar fora de /var/www. O
 * `chmod`, o `install -d` e o `find` atuam de verdade, e é sobre os bits
 * resultantes que as asserções falam.
 */
final class PermissoesInstalacaoTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Instalador que a VPS executa e as duas pontas do bloco de permissões dele. */
    private const INSTALADOR = 'scripts/install/instalar_linux.sh';

    private const INICIO_DO_BLOCO = '# Na VPS o runtime lê o código';

    private const FIM_DO_BLOCO = '# Teste final das funcionalidades';

    /** Condição do ramo de produção, trocada por `true`; null quando o bloco não tem ramo. */
    private const RAMO_DE_PRODUCAO = '[[ "$project_dir" == /var/www/* ]]';

    /**
     * Variáveis que o bloco lê. `$1` é a raiz descartável e `$2` um diretório
     * fora dela, para o estado que o instalador guarda fora do checkout.
     */
    private const PRELUDIO = 'project_dir="$1"; runtime_user="$(id -un)"; runtime_group="$(id -gn)"; sudo_cmd=();';

    /** Pastas da árvore descartável, todas criadas 0777. */
    private const PASTAS = ['inclusos', 'comandos', 'vendor', 'vendor/bin', '.git', 'scripts', 'scripts/install', 'logs'];

    /**
     * Arquivos da árvore => modo inicial. `@externo/` aponta para `$2`.
     *
     * @var array<string, int>
     */
    private const ARQUIVOS = [
        'index.php' => 0777,
        'composer.json' => 0777,
        'comandos/chatgpt.php' => 0777,
        'vendor/autoload.php' => 0777,
        'vendor/bin/phpunit' => 0777,
        '.git/HEAD' => 0777,
        'scripts/install/instalar_linux.sh' => 0777,
        'inclusos/config.php' => 0600,
        'logs/existente.log' => 0777,
    ];

    /**
     * Pastas de estado do runtime => modo final. São as únicas graváveis por
     * ele, e as que o instalador precisa recusar quando viram symlink.
     *
     * @var array<string, int>
     */
    private const PASTAS_DE_ESTADO = ['logs' => 0700, 'snapshots' => 0700];

    /**
     * Modo final de caminhos com regra própria, além das pastas de estado.
     *
     * @var array<string, int>
     */
    private const MODOS_ESPERADOS = [
        'inclusos/config.php' => 0640,
        'logs/existente.log' => 0600,
    ];

    /** Trecho da mensagem com que o instalador recusa o symlink. */
    private const MENSAGEM_DE_RECUSA = 'Recusando symlink no estado do runtime';
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private string $base = '';

    private string $externo = '';

    private string $registro = '';

    protected function setUp(): void
    {
        $this->base = self::temporario('permissoes_codigo_');
        $this->externo = self::temporario('permissoes_externo_');
        $this->registro = self::temporario('permissoes_chown_');
        self::assertTrue(mkdir($this->base, 0700));
        chmod($this->base, 0777);
        self::assertTrue(mkdir($this->externo, 0700));
        self::assertTrue(touch($this->registro));
        foreach (self::PASTAS as $pasta) {
            self::assertTrue(mkdir($this->base . '/' . $pasta, 0777));
            chmod($this->base . '/' . $pasta, 0777);
        }
        foreach (self::ARQUIVOS as $arquivo => $modo) {
            self::assertSame(7, file_put_contents($this->caminho($arquivo), 'fixture'));
            chmod($this->caminho($arquivo), $modo);
        }
    }

    protected function tearDown(): void
    {
        self::remover($this->base);
        self::remover($this->externo);
        self::remover($this->registro);
    }

    public function testBlocoRealRetiraEscritaDoRuntimeSemImpedirLeituraEEstado(): void
    {
        $resultado = $this->executarBloco(true);
        self::assertSame(0, $resultado['status'], $resultado['saida']);

        foreach (['', ...self::PASTAS, ...array_keys(self::ARQUIVOS)] as $caminho) {
            if (str_starts_with($caminho, '@externo/') || $this->ehEstado($caminho)) {
                continue;
            }
            $modo = $this->modo($caminho);
            self::assertSame(0, $modo & 0022, "{$caminho} permite substituição pelo runtime");
            self::assertNotSame(0, $modo & 0040, "{$caminho} perdeu leitura do grupo");
        }
        self::assertSame(0751, $this->modo(''), 'a raiz precisa ser só atravessável por outros');
        foreach ([...self::PASTAS_DE_ESTADO, ...self::MODOS_ESPERADOS] as $caminho => $modo) {
            self::assertSame($modo, $this->modo($caminho), "{$caminho} com modo inesperado");
        }
        self::assertSame('fixture', file_get_contents($this->caminho('inclusos/config.php')));

        $chamadas = $this->chamadasDeChown();
        $base = preg_quote($this->base, '/');
        self::assertNotSame([], preg_grep('/^-R root:\S+ ' . $base . '$/', $chamadas), 'a árvore inteira precisa passar para o root');
        self::assertSame([], preg_grep('/^(?!-R )(?!root:).* ' . $base . '$/', $chamadas), 'a raiz não pode voltar ao runtime');
    }

    public function testForaDeProducaoOCheckoutNaoMudaDeDono(): void
    {
        $ramo = self::ramoDeProducao();
        if ($ramo === null) {
            // Instalador sem ramo de produção aplica as permissões da VPS
            // sempre: não pode haver um caminho /var/www escondido no bloco.
            self::assertStringNotContainsString('/var/www/', $this->bloco());

            return;
        }
        file_put_contents($this->caminho('inclusos/config.php'), 'segredo');
        chmod($this->caminho('inclusos/config.php'), 0644);

        $resultado = $this->executarBloco(false);

        self::assertSame(0, $resultado['status'], $resultado['saida']);
        self::assertSame(0600, $this->modo('inclusos/config.php'), 'fora da VPS config.php é privado do usuário local');
        foreach (self::PASTAS_DE_ESTADO as $pasta => $modo) {
            self::assertSame($modo, $this->modo($pasta), "{$pasta} com modo inesperado");
        }
        self::assertSame([], preg_grep('/root:/', $this->chamadasDeChown()), 'fora de /var/www o checkout não muda de dono');
    }

    /** @return array<string, array{0: string}> */
    public static function pastasDeEstado(): array
    {
        $casos = [];
        foreach (array_keys(self::PASTAS_DE_ESTADO) as $pasta) {
            $casos[$pasta] = [$pasta];
        }

        return $casos;
    }

    #[DataProvider('pastasDeEstado')]
    public function testSymlinkNoEstadoDoRuntimeERecusadoSemTocarNoAlvo(string $pasta): void
    {
        // O alvo fica FORA da árvore: dentro dela o chmod -R legítimo o alcançaria.
        $alvo = $this->externo . '/alvo-do-symlink';
        self::assertTrue(mkdir($alvo, 0755));
        chmod($alvo, 0755);
        self::assertSame(7, file_put_contents($alvo . '/intocado', 'fixture'));
        chmod($alvo . '/intocado', 0644);
        self::remover($this->caminho($pasta));
        self::assertTrue(symlink($alvo, $this->caminho($pasta)));

        $resultado = $this->executarBloco(true);

        self::assertNotSame(0, $resultado['status'], $resultado['saida']);
        self::assertStringContainsString(self::MENSAGEM_DE_RECUSA, $resultado['saida']);
        clearstatcache();
        self::assertSame(0755, fileperms($alvo) & 0777, 'o alvo do symlink não pode receber chmod');
        self::assertSame(0644, fileperms($alvo . '/intocado') & 0777, 'o conteúdo do alvo não pode ser tocado');
    }

    private function bloco(): string
    {
        $fonte = (string) file_get_contents(dirname(__DIR__, 2) . '/' . self::INSTALADOR);
        $inicio = strpos($fonte, self::INICIO_DO_BLOCO);
        self::assertIsInt($inicio, 'o bloco de permissões perdeu o marcador de início');
        $fim = strpos($fonte, self::FIM_DO_BLOCO, $inicio);
        self::assertIsInt($fim, 'o bloco de permissões perdeu o marcador de fim');

        return substr($fonte, $inicio, $fim - $inicio);
    }

    /** @return array{status: int, saida: string} */
    private function executarBloco(bool $producao): array
    {
        $bloco = $this->bloco();
        $ramo = self::ramoDeProducao();
        if ($ramo !== null) {
            self::assertSame(1, substr_count($bloco, $ramo), 'o bloco deixou de ter exatamente um ramo de produção');
            if ($producao) {
                $bloco = str_replace($ramo, 'true', $bloco);
            }
        }

        // `set -u` fica de fora: o bash 3.2 do macOS trata o `sudo_cmd=()` vazio
        // como variável não definida.
        $script = 'set -eo pipefail; ' . self::PRELUDIO
            . ' registro="$3"; chown() { printf "%s\n" "$*" >> "$registro"; }; cd "$project_dir";'
            . "\n" . $bloco;
        // Ambiente mínimo: uma variável de instalação herdada mandaria o
        // install -d para fora da árvore descartável.
        $processo = proc_open(
            ['bash', '-c', $script, 'permissoes', $this->base, $this->externo, $this->registro],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->base,
            ['PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($processo);
        $saida = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($processo), 'saida' => $saida];
    }

    private static function ramoDeProducao(): ?string
    {
        return self::comoDado(self::RAMO_DE_PRODUCAO);
    }

    /**
     * Devolve o valor como está no bloco de dados: o analisador estático não
     * pode concluir "sempre nulo" ou "sempre texto" a partir do valor deste
     * repositório, porque o valor é dado, não código.
     */
    private static function comoDado(?string $valor): ?string
    {
        return $valor;
    }

    private function ehEstado(string $caminho): bool
    {
        foreach (array_keys(self::PASTAS_DE_ESTADO) as $pasta) {
            if ($caminho === $pasta || str_starts_with($caminho, $pasta . '/')) {
                return true;
            }
        }

        return false;
    }

    private function caminho(string $relativo): string
    {
        if (str_starts_with($relativo, '@externo/')) {
            return $this->externo . '/' . substr($relativo, strlen('@externo/'));
        }

        return rtrim($this->base . '/' . $relativo, '/');
    }

    private function modo(string $relativo): int
    {
        clearstatcache();
        $modo = fileperms($this->caminho($relativo));
        self::assertIsInt($modo, "{$relativo} não existe");

        return $modo & 0777;
    }

    /** @return list<string> */
    private function chamadasDeChown(): array
    {
        $linhas = file($this->registro, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($linhas);

        return $linhas;
    }

    private static function temporario(string $prefixo): string
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), $prefixo);
        unlink($caminho);

        return $caminho;
    }

    private static function remover(string $caminho): void
    {
        if ($caminho === '' || (!file_exists($caminho) && !is_link($caminho))) {
            return;
        }
        if (is_link($caminho) || !is_dir($caminho)) {
            unlink($caminho);

            return;
        }
        chmod($caminho, 0700);
        foreach ((array) scandir($caminho) as $nome) {
            if ($nome !== '.' && $nome !== '..' && is_string($nome)) {
                self::remover($caminho . '/' . $nome);
            }
        }
        rmdir($caminho);
    }
}
