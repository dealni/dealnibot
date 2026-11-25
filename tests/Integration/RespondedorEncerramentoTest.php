<?php

declare(strict_types=1);

namespace Tests;

use DealniBot\Encerramento\Estado;
use DealniBot\Encerramento\Falha;
use DealniBot\Encerramento\Telegram;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCgi;
use Tests\Support\ExecutorCli;

use function DealniBot\Encerramento\processarLote;

require_once __DIR__ . '/../../processos/responder_encerramento.php';

final class RespondedorEncerramentoTest extends TestCase
{
    private const SCRIPT = __DIR__ . '/../../processos/responder_encerramento.php';
    private string $diretorio;

    protected function setUp(): void
    {
        $this->diretorio = realpath(sys_get_temp_dir()) . '/respondedor-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->diretorio, 0700));
    }

    protected function tearDown(): void
    {
        $arquivos = glob($this->diretorio . '/*');
        self::assertIsArray($arquivos);
        foreach ($arquivos as $arquivo) {
            unlink($arquivo);
        }
        rmdir($this->diretorio);
    }

    public function testFiltraChatsEEtapasAnterioresAPartirDoMesmoLote(): void
    {
        $estado = new Estado($this->diretorio, 100);
        $pedidos = [];
        $pessoa = ['is_bot' => false];
        $lote = [
            ['update_id' => 10, 'message' => ['date' => 101, 'from' => $pessoa, 'chat' => ['type' => 'private', 'id' => 321], 'text' => 'canario-privado']],
            ['update_id' => 1, 'message' => ['date' => 99, 'from' => $pessoa, 'text' => '/start', 'chat' => ['type' => 'private', 'id' => 456]]],
            ['update_id' => 2, 'message' => ['date' => 100, 'from' => $pessoa, 'text' => 'antiga', 'chat' => ['type' => 'private', 'id' => 456]]],
            ['update_id' => 3, 'message' => ['date' => 101, 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'group', 'id' => 456]]],
            ['update_id' => 4, 'message' => ['date' => 101, 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => -1]]],
            ['update_id' => 5, 'edited_message' => ['date' => 101, 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => 456]]],
            ['update_id' => 6, 'callback_query' => ['message' => ['date' => 101, 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => 456]]]],
            ['update_id' => 7, 'message' => ['date' => '101', 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => 456]]],
            ['update_id' => 8, 'message' => ['date' => 101, 'from' => $pessoa, 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => '456']]],
            ['update_id' => 9, 'message' => ['date' => 101, 'from' => $pessoa, 'pinned_message' => ['text' => 'oi'], 'chat' => ['type' => 'private', 'id' => 456]]],
            ['update_id' => 11, 'channel_post' => ['date' => 101, 'chat' => ['type' => 'channel', 'id' => -1]]],
            ['update_id' => 12, 'message' => ['date' => 101, 'from' => $pessoa, 'photo' => [['file_id' => 'foto-sintetica']], 'chat' => ['type' => 'private', 'id' => 789]]],
            ['update_id' => 13, 'message' => ['date' => 101, 'from' => ['is_bot' => true], 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => 456]]],
        ];
        $telegram = new Telegram(
            function (string $metodo, array $dados) use (&$pedidos, $lote): array {
                $pedidos[] = [$metodo, $dados];
                if ($metodo === 'getUpdates') {
                    return self::ok($lote);
                }
                // O checkpoint precede qualquer chamada que possa responder.
                $offset = $dados['chat_id'] === 321 ? 11 : 13;
                self::assertSame(['ativado_em' => 100, 'offset' => $offset], $this->estadoEmDisco());
                return self::ok();
            },
            static function (int $segundos): void {
                self::fail('O lote saudável não precisa aguardar.');
            },
            static function (string $mensagem): void {
                self::fail($mensagem);
            },
        );

        processarLote($estado, $telegram, $telegram->buscar($estado->offset));

        self::assertSame([
            ['getUpdates', ['offset' => 0, 'timeout' => 30, 'limit' => 20, 'allowed_updates' => ['message']]],
            ['sendMessage', ['chat_id' => 321, 'text' => 'O DealniBot está temporariamente pausado. Durante a pausa, os comandos e as funções do bot estão indisponíveis.']],
            ['sendMessage', ['chat_id' => 789, 'text' => 'O DealniBot está temporariamente pausado. Durante a pausa, os comandos e as funções do bot estão indisponíveis.']],
        ], $pedidos);
        self::assertSame(['ativado_em' => 100, 'offset' => 14], $this->estadoEmDisco());
        processarLote($estado, $telegram, []);
        self::assertSame(['ativado_em' => 100, 'offset' => 0], $this->estadoEmDisco());
    }

    public function testTimeoutDeEnvioNaoProvocaRespostaDuplicadaAposReinicio(): void
    {
        $estado = new Estado($this->diretorio, 100);
        $tentativas = 0;
        $registros = [];
        $telegram = new Telegram(
            static function (string $metodo, array $dados) use (&$tentativas): array {
                self::assertSame('sendMessage', $metodo);
                ++$tentativas;
                return ['http' => 0, 'erro' => CURLE_OPERATION_TIMEDOUT, 'corpo' => false];
            },
            static function (int $segundos): void {
                self::fail('Envio indeterminado não pode ser repetido.');
            },
            static function (string $mensagem) use (&$registros): void {
                $registros[] = $mensagem;
            },
        );
        $lote = [77 => ['message' => ['date' => 101, 'from' => ['is_bot' => false], 'text' => 'oi', 'chat' => ['type' => 'private', 'id' => 321]]]];
        processarLote($estado, $telegram, $lote);
        unset($estado);

        $estado = new Estado($this->diretorio, 102);
        self::assertSame(100, $estado->ativadoEm);
        self::assertSame(78, $estado->offset);
        processarLote($estado, $telegram, $lote);
        self::assertSame(1, $tentativas);
        self::assertSame(['Resposta do Telegram recusada ou indeterminada; envio não repetido.'], $registros);
    }

    public function testAguardaRetryAfterERepeteFalhasSemConexao(): void
    {
        $limite = ['http' => 429, 'erro' => 0, 'corpo' => '{"parameters":{"retry_after":9},"description":"canario-privado"}'];
        $semConexao = ['http' => 0, 'erro' => CURLE_COULDNT_CONNECT, 'corpo' => false];
        $respostas = [$semConexao, $limite, self::ok([]), $limite, $semConexao, self::ok()];
        $pausas = [];
        $telegram = new Telegram(
            static function (string $metodo, array $dados) use (&$respostas): array {
                return array_shift($respostas) ?? throw new \LogicException('Chamada além da fixture.');
            },
            static function (int $segundos) use (&$pausas): void {
                $pausas[] = $segundos;
            },
            static function (string $mensagem): void {
                self::assertStringNotContainsString('canario-privado', $mensagem);
            },
        );
        self::assertSame([], $telegram->buscar(0));
        $telegram->responder(321);
        self::assertSame([1, 9, 9, 2], $pausas);
        self::assertSame([], $respostas);
    }

    public function testCursorAntigoExpiraSemMudarOInstanteDaAtivacao(): void
    {
        $estado = new Estado($this->diretorio, 100);
        $estado->salvar(9000);
        unset($estado);
        self::assertTrue(touch($this->diretorio . '/estado.json', 200));
        clearstatcache();

        $estado = new Estado($this->diretorio, 200 + 86400);
        self::assertSame(100, $estado->ativadoEm);
        self::assertSame(0, $estado->offset);
        self::assertSame(['ativado_em' => 100, 'offset' => 0], $this->estadoEmDisco());
        $this->expectException(Falha::class);
        $this->expectExceptionMessageIs('Não foi possível obter a trava exclusiva do respondedor.');
        new Estado($this->diretorio, 300 + 86400);
    }

    public function testExecutaSoComArquivoECredencialAcessiveisApenasAoServico(): void
    {
        $respondedor = $this->diretorio . '/responder_encerramento.php';
        self::assertTrue(copy(self::SCRIPT, $respondedor));
        $token = '123456:' . str_repeat('A', 35);
        file_put_contents($this->diretorio . '/telegram.token', $token . "\n");
        $principal = $this->diretorio . '/executar.php';
        file_put_contents($principal, <<<'PHP'
<?php
namespace DealniBot\Encerramento;
function curl_init(string $url): \CurlHandle|false
{
    $GLOBALS['requisicao'] = $url;
    return \curl_init();
}
function curl_setopt_array(\CurlHandle $curl, array $opcoes): bool
{
    if ($opcoes[CURLOPT_PROTOCOLS_STR] !== 'https' || $opcoes[CURLOPT_SSL_VERIFYPEER] !== true
        || $opcoes[CURLOPT_SSL_VERIFYHOST] !== 2 || $opcoes[CURLOPT_FOLLOWLOCATION] !== false) {
        exit(2);
    }
    $GLOBALS['corpo'] = $opcoes[CURLOPT_POSTFIELDS];
    return true;
}
function curl_exec(\CurlHandle $curl): string
{
    if (str_ends_with($GLOBALS['requisicao'], '/getMe')) {
        return '{"ok":true,"result":{"username":"DealniBot"}}';
    }
    if (!str_ends_with($GLOBALS['requisicao'], '/getUpdates')) {
        exit(3);
    }
    $dados = json_decode($GLOBALS['corpo'], true, 512, JSON_THROW_ON_ERROR);
    if ($dados['offset'] === 126) {
        exit(0);
    }
    if ($dados['offset'] !== 0) {
        exit(4);
    }
    return '{"ok":true,"result":[{"update_id":125,"message":{"date":1,"from":{"is_bot":false},"chat":{"type":"private","id":321},"text":"/start"}}]}';
}
function curl_getinfo(\CurlHandle $curl, int $opcao): int { return 200; }
$_SERVER['SCRIPT_FILENAME'] = $argv[1];
require $argv[1];
PHP);
        $ambiente = ['CREDENTIALS_DIRECTORY' => $this->diretorio, 'STATE_DIRECTORY' => $this->diretorio, 'PATH' => '/inexistente'];
        $http = ExecutorCgi::executar($respondedor, $ambiente);
        self::assertSame(1, $http['codigo']);
        self::assertStringContainsString('Status: 404', $http['stdout']);
        self::assertSame('', $http['stderr']);
        self::assertFileDoesNotExist($this->diretorio . '/estado.json');

        $cli = ExecutorCli::executar($principal, [$respondedor], $ambiente);
        self::assertSame(0, $cli['codigo'], $cli['stderr']);
        self::assertSame("Respondedor do @DealniBot conectado ao Telegram.\n", $cli['stderr']);
        self::assertSame('', $cli['stdout']);
        $estado = $this->estadoEmDisco();
        self::assertSame(126, $estado['offset']);
        self::assertIsInt($estado['ativado_em']);
        self::assertGreaterThan(1, $estado['ativado_em']);
        self::assertSame(0600, (int) fileperms($this->diretorio . '/estado.json') & 0777);
    }

    /** @return array<string, mixed> */
    private function estadoEmDisco(): array
    {
        $conteudo = file_get_contents($this->diretorio . '/estado.json');
        self::assertIsString($conteudo);
        $estado = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($estado);
        self::assertSame(['ativado_em', 'offset'], array_keys($estado));
        return ['ativado_em' => $estado['ativado_em'], 'offset' => $estado['offset']];
    }

    /** @return array{http: 200, erro: 0, corpo: string} */
    private static function ok(mixed $resultado = null): array
    {
        return ['http' => 200, 'erro' => 0, 'corpo' => json_encode(['ok' => true, 'result' => $resultado], JSON_THROW_ON_ERROR)];
    }
}
