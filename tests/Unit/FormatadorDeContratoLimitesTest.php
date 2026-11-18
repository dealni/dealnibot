<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\FormatadorDeContrato;
use App\Support\ProcessadorDeRedacao;
use DateTimeImmutable;
use JsonSerializable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * A garantia de leniência do PSR-3 na fronteira de serialização: um valor de
 * contexto que o json_encode não sabe (ou não pode) serializar vira um
 * marcador neutro em vez de derrubar a linha inteira — e `extra` passa pela
 * mesma redação que `context`.
 *
 * O Monolog entrega ao formatador, sem tocar, dois tipos de objeto: os
 * JsonSerializable (que ele reserva para o json_encode) e qualquer objeto sem
 * `__toString`. É com esses dois que o formatador precisa se defender.
 */
final class FormatadorDeContratoLimitesTest extends TestCase
{
    /**
     * @param array<string, mixed> $contexto
     * @param array<string, mixed> $extra
     * @return array<array-key, mixed>
     */
    private function evento(array $contexto, array $extra = []): array
    {
        $formatador = new FormatadorDeContrato(FormatadorDeContrato::BATCH_MODE_JSON, true);
        $formatador->includeStacktraces(false);

        $linha = $formatador->format(new LogRecord(
            new DateTimeImmutable('now'),
            'geral',
            Level::Info,
            'evento',
            $contexto,
            $extra
        ));

        $decodificado = json_decode(trim($linha), true);
        $this->assertIsArray($decodificado);

        return $decodificado;
    }

    /**
     * @param array<array-key, mixed> $evento
     * @return array<array-key, mixed>
     */
    private function contextoDo(array $evento): array
    {
        $contexto = $evento['context'] ?? null;
        $this->assertIsArray($contexto);

        return $contexto;
    }

    public function testExtraTambemPassaPelaRedacao(): void
    {
        // Processor escreve em `extra` tanto quanto quem loga escreve em
        // `context`; um segredo que entrasse por ali sairia em claro.
        $evento = $this->evento([], ['token' => 'segredo-que-nao-pode-sair', 'ciclo' => 'ok']);

        $extra = $evento['extra'] ?? null;
        $this->assertIsArray($extra);
        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $extra['token'] ?? null);
        $this->assertSame('ok', $extra['ciclo'] ?? null);
    }

    public function testObjetoSemSerializacaoConhecidaViraMarcadorNeutro(): void
    {
        $objeto = new class () {
            public string $interno = 'nao deve aparecer';
        };

        $contexto = $this->contextoDo($this->evento(['objeto' => $objeto]));

        $this->assertSame('[unserializable]', $contexto['objeto'] ?? null);
    }

    public function testJsonSerializableQueDevolveASiMesmoNaoEntraEmLaco(): void
    {
        // `jsonSerialize()` devolvendo o próprio objeto faria o json_encode
        // recursar até o limite de profundidade; aqui a recursão para na
        // primeira volta.
        $objeto = new class () implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return $this;
            }
        };

        $contexto = $this->contextoDo($this->evento(['objeto' => $objeto]));

        $this->assertSame('[unserializable]', $contexto['objeto'] ?? null);
    }

    public function testJsonSerializableValidoMantemAFormaSerializada(): void
    {
        $objeto = new class () implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['id' => 7, 'nome' => 'valido'];
            }
        };

        $contexto = $this->contextoDo($this->evento(['objeto' => $objeto]));

        $this->assertSame(['id' => 7, 'nome' => 'valido'], $contexto['objeto'] ?? null);
    }

    public function testCadeiaDeSerializacaoSemFimEInterrompidaPelaProfundidade(): void
    {
        // Cada jsonSerialize() devolve um objeto novo que serializa em outro:
        // sem o teto de profundidade o formatador nunca terminaria.
        $objeto = new class () implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return new self();
            }
        };

        $contexto = $this->contextoDo($this->evento(['objeto' => $objeto]));

        $this->assertSame('[unserializable]', $contexto['objeto'] ?? null);
    }
}
