<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ContextoDeExecucao;
use App\Support\LoggerFactory;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/bdTelegram.php';

/**
 * Caracteriza a FALHA de conexão do construtor de bdTelegram
 * (inclusos/bdTelegram.php), a única lacuna real da classe que BdTelegramTest
 * não cobre: o caminho `catch (PDOException)`.
 *
 * Contrato canônico da família: o construtor LANÇA RuntimeException em vez de
 * chamar die(). Quem instancia decide como encerrar: bot.php e bot_web.php envolvem o `new`
 * em try/catch e chamam ecoarEEncerrar(). O que não muda: se o construtor
 * RETORNOU, a conexão interna é válida, e a PDOException nunca escapa crua.
 *
 * A falha também precisa ficar registrada no canal `bd` do app.log com
 * $forcar = true — infraestrutura fora do ar não pode depender de LOGS_ATIVO.
 *
 * Nenhum arquivo de produção é tocado: os caminhos usados são temporários e
 * inutilizáveis de propósito.
 */
final class BdTelegramConexaoTest extends TestCase
{
    private int $tamanhoLogAntes = 0;

    /** @var list<string> */
    private array $arquivosTemporarios = [];

    protected function setUp(): void
    {
        LoggerFactory::reset();
        $this->tamanhoLogAntes = AppLogReader::tamanhoAtual();
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivosTemporarios as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            } elseif (is_dir($arquivo)) {
                rmdir($arquivo);
            }
        }
        $this->arquivosTemporarios = [];
    }







    private function caminhoInutilizavel(): string
    {
        return 'pgsql:host=/caminho-inexistente-chatbots;dbname=chatbots_test_ausente';
    }

    public function testConstrutorComCaminhoInutilizavelLancaRuntimeExceptionEmPortugues(): void
    {
        $caminho = $this->caminhoInutilizavel();

        try {
            new \bdTelegram($caminho);
            $this->fail('O construtor precisa lançar quando o PDO não abre o banco.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('Erro ao conectar ao banco de dados: ', $e->getMessage());
            // A causa original vai encadeada: quem depurar não perde o erro do
            // driver, mas quem chama trata um tipo só.
            $anterior = $e->getPrevious();
            $this->assertInstanceOf(RuntimeException::class, $anterior);

            // O prefixo sozinho não diz nada: a mensagem do driver ("unable to
            // open database file", "attempt to write a readonly database") é o
            // que distingue um incidente do outro no ecoarEEncerrar() e no log.
            $this->assertNotSame('', $anterior->getMessage());
            $this->assertSame(
                'Erro ao conectar ao banco de dados: ' . $anterior->getMessage(),
                $e->getMessage()
            );

            // Código 0: quem instancia usa o TIPO para decidir, nunca o código.
            // Um código diferente de zero vira status de saída em quem repassa
            // getCode() ao exit(), e o cron passaria a ver falhas trocadas.
            $this->assertSame(0, $e->getCode());
        }
    }

    /**
     * A PDOException nunca escapa crua: quem instancia bdTelegram trata
     * RuntimeException e não precisa conhecer o driver.
     */
    public function testPdoExceptionNaoEscapaCrua(): void
    {
        $this->expectException(RuntimeException::class);

        new \bdTelegram($this->caminhoInutilizavel());
    }

    /**
     * $forcar = true no log: uma queda de banco precisa ficar registrada mesmo
     * num processo que não carregou dados_bot.php (e portanto não tem
     * LOGS_ATIVO definida).
     */
    public function testFalhaDeConexaoFicaRegistradaNoCanalBdDoAppLog(): void
    {
        try {
            new \bdTelegram($this->caminhoInutilizavel());
        } catch (RuntimeException $e) {
            // Esperado: o que interessa aqui é o rastro deixado no log.
            $this->assertNotSame('', $e->getMessage());
        }

        $entradas = AppLogReader::linhasJson(AppLogReader::conteudoDesde($this->tamanhoLogAntes));
        $this->assertNotEmpty($entradas, 'A falha de conexão precisa ficar registrada no app.log.');
        $ultima = $entradas[count($entradas) - 1];
        $this->assertSame('bd', $ultima['channel']);
        $this->assertSame('error', $ultima['level']);
        $this->assertIsString($ultima['msg']);
        $this->assertStringStartsWith('Erro ao conectar ao banco de dados: ', $ultima['msg']);

        // O erro do driver é o que diz se o banco sumiu, ficou somente leitura
        // ou travou — sem ele a linha guarda só a frase em português. O
        // contrato da frota o expõe como `err` no TOPO do evento, e não como
        // `context.exception`: o nome do campo vem do contrato de observabilidade.
        $erro = $ultima['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertSame(RuntimeException::class, $erro['type'] ?? null);
        $this->assertNotSame('', $erro['message'] ?? '');

        // Decisão da frota (2026-08-30): o stack NUNCA sai, nem fora de
        // produção. `file` e `line` também não — os três revelariam caminho
        // absoluto e forma interna do código a quem tiver acesso ao arquivo,
        // e `type` + `message` já identificam a falha. A suíte roda em `test`,
        // que é justamente o ambiente onde a regra antiga os deixava passar.
        $this->assertFalse(ContextoDeExecucao::producao());
        $this->assertArrayNotHasKey('stack', $erro);
        $this->assertArrayNotHasKey('trace', $erro);
        $this->assertArrayNotHasKey('file', $erro);
        $this->assertStringNotContainsString(
            'vendor/',
            (string) json_encode($ultima),
            'caminho de arquivo do servidor vazou no evento'
        );
    }

    /**
     * Controle: com um caminho gravável o construtor NÃO lança e a conexão fica
     * utilizável (prova que o teste acima acusa a falha real, e não o simples
     * fato de instanciar a classe).
     */
    public function testConstrutorComCaminhoValidoProssegueSemLancar(): void
    {
        $caminhoValido = tempnam(sys_get_temp_dir(), 'dealnibot_bd_ok_');
        $this->assertIsString($caminhoValido, 'tempnam precisa criar o arquivo temporário do controle.');

        try {
            $bd = new \bdTelegram(\BancoTeste::dsn($caminhoValido));

            $this->assertTrue($bd->queryExec(
                'CREATE TABLE usuarios (id INTEGER PRIMARY KEY, id_telegram INTEGER NOT NULL)',
                [],
                true
            ));
            $this->assertSame([], $bd->todosUsuarios());
        } finally {
            if (file_exists($caminhoValido)) {
                @unlink($caminhoValido);
            }
        }
    }

    /**
     * O bloco de carga sob demanda do autoload do Composer, dentro do catch.
     *
     * O construtor é chamado por entrypoints que podem nunca ter passado pelo
     * autoload (bot_web.php dos bots legados, script solto de manutenção).
     * Nesse processo `App\Support\LoggerFactory` não existe, e sem carregar o
     * `vendor/autoload.php` na mão a queda do banco — o incidente MAIS grave
     * que este código conhece — não deixaria rastro nenhum: a RuntimeException
     * subiria igual, e o app.log ficaria mudo justamente na hora em que é lido.
     *
     * O cenário NÃO é reproduzível dentro da suíte: o bootstrap registra o
     * autoload, e `class_exists()` devolve true para classe já declarada mesmo
     * sem autoloader nenhum. Por isso ele é montado num subprocesso limpo, que
     * imprime a sentinela ANTES/DEPOIS — a prova de que o processo começou sem
     * a classe e terminou com ela.
     */
    public function testSemOAutoloadDoComposerAQuedaDoBancoAindaVaiParaOAppLog(): void
    {
        $diretorio = sys_get_temp_dir() . '/dealnibot-bd-sem-autoload-' . uniqid();
        $classe = var_export(LoggerFactory::class, true);
        $script = '<?php' . "\n"
            . 'putenv(' . var_export('DEALNI_LOGS_DIR=' . $diretorio, true) . ');' . "\n"
            . 'echo class_exists(' . $classe . ') ? "ANTES:sim\n" : "ANTES:nao\n";' . "\n"
            . 'require ' . var_export(dirname(__DIR__, 2) . '/inclusos/bdTelegram.php', true) . ';' . "\n"
            . 'try {' . "\n"
            . '    new bdTelegram(' . var_export($this->caminhoInutilizavel(), true) . ');' . "\n"
            . '    echo "EXCECAO:nao\n";' . "\n"
            . '} catch (RuntimeException $e) {' . "\n"
            . '    echo "EXCECAO:sim\n";' . "\n"
            . '}' . "\n"
            . 'echo class_exists(' . $classe . ') ? "DEPOIS:sim\n" : "DEPOIS:nao\n";' . "\n";

        try {
            $saida = $this->rodarEmSubprocesso($script);

            // O processo realmente começou sem o autoload registrado, a
            // conexão falhou como esperado, e a classe passou a existir só
            // depois de o catch a carregar.
            $this->assertStringContainsString('ANTES:nao', $saida);
            $this->assertStringContainsString('EXCECAO:sim', $saida);
            $this->assertStringContainsString('DEPOIS:sim', $saida);

            $entrada = $this->unicaEntradaJsonDe($diretorio);
            $this->assertSame('bd', $entrada['channel'] ?? null);
            $this->assertSame('error', $entrada['level'] ?? null);
            $mensagem = $entrada['msg'] ?? null;
            $this->assertIsString($mensagem);
            $this->assertStringStartsWith('Erro ao conectar ao banco de dados: ', $mensagem);
        } finally {
            $restos = glob($diretorio . '/*');
            foreach ($restos === false ? [] : $restos as $resto) {
                @unlink($resto);
            }
            @rmdir($diretorio);
        }
    }

    /**
     * Executa um script PHP em subprocesso e devolve a saída combinada.
     *
     * O subprocesso herda o isolamento de rede da suíte: ele só tenta uma
     * conexão inutilizável e escreve num diretório temporário do próprio teste.
     */
    private function rodarEmSubprocesso(string $script): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'dealnibot_bd_sem_autoload_');
        $this->assertIsString($caminho, 'tempnam precisa criar o script do subprocesso.');

        try {
            $this->assertNotFalse(file_put_contents($caminho, $script));

            $linhas = [];
            $codigo = -1;
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($caminho) . ' 2>&1', $linhas, $codigo);
            $saida = implode("\n", $linhas);
            $this->assertSame(0, $codigo, "O subprocesso precisa terminar sem erro. Saída:\n" . $saida);

            return $saida;
        } finally {
            @unlink($caminho);
        }
    }

    /**
     * Única linha JSON gravada no app.log de um diretório de logs qualquer.
     *
     * O arquivo é localizado por glob, e não por date(): o nome carrega a data
     * do rotativo, e um teste que a calcule por conta própria quebra sozinho na
     * virada do dia.
     *
     * @return array<string, mixed>
     */
    private function unicaEntradaJsonDe(string $diretorio): array
    {
        $arquivos = glob($diretorio . '/app-*.log');
        $this->assertIsArray($arquivos);
        $this->assertCount(1, $arquivos, 'A falha precisa ter criado exatamente um app.log em ' . $diretorio . '.');

        $conteudo = file_get_contents($arquivos[0]);
        $this->assertIsString($conteudo);
        $linhas = AppLogReader::linhasJson($conteudo);
        $this->assertCount(1, $linhas);

        return $linhas[0];
    }

    /**
     * O `throw` só é seguro porque TODO ponto que instancia bdTelegram fora de
     * teste o envolve em try/catch. Sem esta varredura, uma rota nova voltaria
     * a morrer com "Uncaught RuntimeException" e stack trace na saída — que sob
     * FPM vira HTTP 500 e, no cron, um e-mail de erro sem mensagem útil.
     */
    public function testTodoPontoDeProducaoQueInstanciaOBancoTrataAExcecao(): void
    {
        $raiz = dirname(__DIR__, 2);
        $arquivos = [
            'bot.php',
            'bot_web.php',
        ];

        foreach ($arquivos as $relativo) {
            $fonte = file_get_contents($raiz . '/' . $relativo);
            $this->assertIsString($fonte, "{$relativo} precisa ser legível.");

            $posicaoTry = strpos($fonte, 'try {');
            $posicaoNew = strpos($fonte, '$bd = new bdTelegram();');
            $posicaoCatch = strpos($fonte, '} catch (Throwable $e) {');
            $this->assertIsInt($posicaoNew, "{$relativo} precisa continuar instanciando o banco.");
            $this->assertIsInt($posicaoTry, "{$relativo} precisa envolver o `new bdTelegram()` em try/catch.");
            $this->assertIsInt($posicaoCatch, "{$relativo} precisa tratar Throwable no catch.");
            $this->assertLessThan($posicaoNew, $posicaoTry);
            $this->assertGreaterThan($posicaoNew, $posicaoCatch);
            $this->assertStringContainsString(
                'ecoarEEncerrar($e->getMessage());',
                $fonte,
                "{$relativo} precisa encerrar com a mensagem legível, sem stack trace."
            );
        }
    }

    /**
     * Nenhum arquivo de produção pode voltar a usar die() no caminho de
     * conexão: die("texto") sai com status 0 e o cron/monitoramento veria
     * sucesso.
     */
    public function testConstrutorNaoUsaMaisDieNemExit(): void
    {
        $caminho = dirname(__DIR__, 2) . '/inclusos/bdTelegram.php';
        $fonte = file_get_contents($caminho);
        $this->assertIsString($fonte, 'inclusos/bdTelegram.php precisa ser legível.');

        // Tokenização, e não busca textual: o comentário do próprio arquivo
        // explica por que o die() saiu, e uma busca por string casaria com ele.
        // T_EXIT cobre tanto `exit` quanto `die`.
        $linhas = [];
        foreach (token_get_all($fonte) as $token) {
            if (is_array($token) && $token[0] === T_EXIT) {
                $linhas[] = $token[2];
            }
        }
        $this->assertSame(
            [],
            $linhas,
            'inclusos/bdTelegram.php não pode encerrar o processo: quem instancia é que decide. Linhas: '
                . implode(', ', $linhas)
        );

        $this->assertStringContainsString('throw new RuntimeException($mensagem, 0, $e);', $fonte);
    }
}
