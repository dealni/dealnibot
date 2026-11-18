<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FormatadorDeContrato;
use App\Support\ProcessadorDeRedacao;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Os nomes do contrato sobem ao TOPO do evento; o resto fica em `context`.
 *
 * Sem isso o filtro do runbook seria `jq '.context.duration_ms'`, e o nome do
 * campo passaria a depender de onde cada chamador o pôs. A promoção mora no
 * formatador, então a regra vale para todo evento emitido neste repositório.
 */
final class PromocaoDeCamposTest extends TestCase
{
    /**
     * @param array<string, mixed> $contexto
     * @param array<string, mixed> $extra
     * @return array<array-key, mixed>
     */
    private function evento(
        array $contexto,
        string $mensagem = 'evento',
        array $extra = [],
        string $canal = 'geral'
    ): array {
        $formatador = new FormatadorDeContrato(FormatadorDeContrato::BATCH_MODE_JSON, true);
        $formatador->includeStacktraces(false);

        $linha = $formatador->format(new LogRecord(
            new DateTimeImmutable('now'),
            $canal,
            Level::Info,
            $mensagem,
            $contexto,
            $extra
        ));

        $decodificado = json_decode(trim($linha), true);
        $this->assertIsArray($decodificado);

        return $decodificado;
    }

    public function testCamposDoContratoSobemAoTopo(): void
    {
        $evento = $this->evento([
            'user_id' => 'hash-abc',
            'duration_ms' => 42,
            'http' => ['method' => 'POST', 'route' => '/webhook', 'status' => 200],
        ]);

        $this->assertSame('hash-abc', $evento['user_id'] ?? null);
        $this->assertSame(42, $evento['duration_ms'] ?? null);
        $http = $evento['http'] ?? null;
        $this->assertIsArray($http);
        $this->assertSame('/webhook', $http['route'] ?? null);
    }

    public function testCampoDoProdutoContinuaNoContexto(): void
    {
        $evento = $this->evento(['id_telegram' => 12345]);

        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(12345, $contexto['id_telegram'] ?? null);
        $this->assertArrayNotHasKey('id_telegram', $evento);
    }

    public function testRedigeMensagemContextoEExtraMasPreservaCanal(): void
    {
        $evento = $this->evento(
            ['id' => 'token=segredo-no-contexto'],
            'authorization=Bearer segredo-na-mensagem',
            ['cookie' => 'sessionid=segredo-no-extra'],
            'token=nome-do-canal'
        );

        $this->assertSame(
            'authorization=' . ProcessadorDeRedacao::SUBSTITUTO,
            $evento['msg'] ?? null
        );
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(
            'token=' . ProcessadorDeRedacao::SUBSTITUTO,
            $contexto['id'] ?? null
        );
        $extra = $evento['extra'] ?? null;
        $this->assertIsArray($extra);
        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $extra['cookie'] ?? null);
        $this->assertSame('token=nome-do-canal', $evento['channel'] ?? null);
    }

    public function testValoresPromovidosPreservamBoolFloatNullEStdClass(): void
    {
        $evento = $this->evento([
            'user_id' => true,
            'duration_ms' => 12.5,
            'http' => (object) ['status' => 200],
            'err' => null,
        ]);

        $this->assertTrue($evento['user_id'] ?? false);
        $this->assertSame(12.5, $evento['duration_ms'] ?? null);
        $http = $evento['http'] ?? null;
        $this->assertIsArray($http);
        $this->assertSame(200, $http['status'] ?? null);
        $this->assertNull($evento['err'] ?? null);
    }

    public function testExcecaoViraErrSemCaminhoDeServidor(): void
    {
        // `file`, `line` e `trace` entregariam o caminho absoluto do servidor
        // a quem lê o log; `type` e `message` já identificam a falha.
        $evento = $this->evento(['exception' => new \RuntimeException('falha do gateway')]);

        $erro = $evento['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertSame(\RuntimeException::class, $erro['type'] ?? null);
        $this->assertSame('falha do gateway', $erro['message'] ?? null);
        $this->assertArrayNotHasKey('file', $erro);
        $this->assertArrayNotHasKey('trace', $erro);
        $this->assertStringNotContainsString('vendor/', (string) json_encode($evento));
    }

    public function testSegredoNaMensagemDeExcecaoTambemNaoEscapaPeloFormatter(): void
    {
        $evento = $this->evento([
            'exception' => new \RuntimeException('authorization=Bearer SEGREDO'),
        ]);

        $erro = $evento['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertSame(
            'authorization=' . ProcessadorDeRedacao::SUBSTITUTO,
            $erro['message'] ?? null
        );
        $this->assertStringNotContainsString('SEGREDO', (string) json_encode($evento));
    }

    public function testThrowableNoCampoErrNaoLevaCaminhoNemStackAoContrato(): void
    {
        $evento = $this->evento([
            'err' => new \RuntimeException('token=SEGREDO'),
        ]);

        $erro = $evento['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertSame(\RuntimeException::class, $erro['type'] ?? null);
        $this->assertSame(
            'token=' . ProcessadorDeRedacao::SUBSTITUTO,
            $erro['message'] ?? null
        );
        $this->assertArrayNotHasKey('file', $erro);
        $this->assertArrayNotHasKey('trace', $erro);
        $this->assertStringNotContainsString('SEGREDO', (string) json_encode($evento));
    }

    public function testObjetoNoCampoErrTambemFicaSoComTipoEMensagem(): void
    {
        $evento = $this->evento([
            'err' => (object) [
                'class' => 'RuntimeException',
                'message' => 'token=SEGREDO',
                'file' => '/servidor/nao-expor.php',
                'trace' => ['token=SEGREDO'],
            ],
        ]);

        $erro = $evento['err'] ?? null;
        $this->assertSame(
            [
                'type' => 'RuntimeException',
                'message' => 'token=' . ProcessadorDeRedacao::SUBSTITUTO,
            ],
            $erro
        );
        $this->assertStringNotContainsString('SEGREDO', (string) json_encode($evento));
        $this->assertStringNotContainsString('/servidor/', (string) json_encode($evento));
    }

    public function testArrayNaChaveExceptionNaoEhTratadoComoExcecao(): void
    {
        // PSR-3 reserva `exception` para uma instância de Exception, mas o
        // contexto aceita qualquer valor. Um array com chaves parecidas não
        // pode ser promovido para `err` por mera semelhança estrutural.
        $evento = $this->evento([
            'exception' => ['class' => 'dados-do-usuario', 'message' => 'valor comum'],
        ]);

        $this->assertArrayNotHasKey('err', $evento);
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(
            ['class' => 'dados-do-usuario', 'message' => 'valor comum'],
            $contexto['exception'] ?? null
        );
    }

    public function testMarcadorPublicoForjadoNaoPromoveArrayParaErr(): void
    {
        // O nome do marcador é constante pública para coordenar processor e
        // formatter, portanto o valor enviado pelo chamador continua não sendo
        // confiável. Só a sentinela de identidade criada pelo processor vale.
        $evento = $this->evento([
            'exception' => [
                ProcessadorDeRedacao::MARCADOR_EXCECAO => true,
                'class' => 'dados-do-usuario',
                'message' => 'valor comum',
            ],
        ]);

        $this->assertArrayNotHasKey('err', $evento);
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertArrayHasKey('exception', $contexto);
        $excecao = $contexto['exception'] ?? null;
        $this->assertIsArray($excecao);
        $this->assertTrue($excecao[ProcessadorDeRedacao::MARCADOR_EXCECAO] ?? false);
    }

    public function testContextoVazioContinuaObjetoENaoViraLista(): void
    {
        // Virar `[]` mudaria o formato do evento para quem consome o JSON.
        $formatador = new FormatadorDeContrato(FormatadorDeContrato::BATCH_MODE_JSON, true);
        $linha = $formatador->format(new LogRecord(
            new DateTimeImmutable('now'),
            'geral',
            Level::Info,
            'sem contexto',
            ['duration_ms' => 1],
            []
        ));

        $this->assertStringContainsString('"context":{}', $linha);
    }

    public function testMensagemDaExcecaoEhRedigidaDepoisDaNormalizacao(): void
    {
        $evento = $this->evento([
            'exception' => new \RuntimeException('token=SEGREDO | password=SENHA'),
        ]);

        $erro = $evento['err'] ?? null;
        $this->assertIsArray($erro);
        $mensagem = $erro['message'] ?? null;
        $this->assertIsString($mensagem);
        $this->assertStringNotContainsString('SEGREDO', $mensagem);
        $this->assertStringNotContainsString('SENHA', $mensagem);
        $this->assertSame(
            'token=' . \App\Support\ProcessadorDeRedacao::SUBSTITUTO
                . ' | password=' . \App\Support\ProcessadorDeRedacao::SUBSTITUTO,
            $mensagem
        );
    }


    public function testValorNaChaveExceptionSoViraErrQuandoEhThrowable(): void
    {
        $evento = $this->evento([
            'exception' => ['class' => 'ValorDoContexto', 'message' => 'não é exceção'],
        ]);

        $this->assertArrayNotHasKey('err', $evento);
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(
            ['class' => 'ValorDoContexto', 'message' => 'não é exceção'],
            $contexto['exception'] ?? null
        );
    }

    public function testValorJsonSerializableQueFalhaNaoDerrubaOLog(): void
    {
        $valor = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('falha deliberada de serialização');
            }
        };

        $evento = $this->evento(['valor' => $valor]);

        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame('[unserializable]', $contexto['valor'] ?? null);
    }

    public function testMensagemDaExcecaoTambemPassaPelaRedacao(): void
    {
        $evento = $this->evento([
            'exception' => new \RuntimeException('authorization=Bearer segredo-da-excecao'),
        ]);

        $erro = $evento['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertStringNotContainsString('segredo-da-excecao', (string) json_encode($evento));
        $this->assertSame(
            'authorization=' . \App\Support\ProcessadorDeRedacao::SUBSTITUTO,
            $erro['message'] ?? null
        );
    }

    public function testChaveUtf8InvalidaRedigeOValorPorSeguranca(): void
    {
        // Sem a validação, "tok\xFFen" virava "tok?en" em mb_strtolower()
        // e o valor sensível passava sem casar com a regra de "token".
        $chaveInvalida = "tok\xFFen";
        $evento = $this->evento([$chaveInvalida => 'segredo-da-chave']);

        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertSame(
            [\App\Support\ProcessadorDeRedacao::SUBSTITUTO],
            array_values($contexto)
        );
        $json = json_encode($evento);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('segredo-da-chave', $json);
    }
}
