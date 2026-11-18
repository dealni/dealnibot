<?php

declare(strict_types=1);

namespace Tests;

use App\Support\FormatadorDeContrato;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class FormatadorContextoFronteirasTest extends TestCase
{
    /** @param array<string, mixed> $contexto
     * @return array<string, mixed>
     */
    private function formatar(array $contexto): array
    {
        $registro = new LogRecord(new \DateTimeImmutable('2026-09-13T00:00:00Z'), 'teste', Level::Error, 'Falha de teste', $contexto);
        $resultado = json_decode((new FormatadorDeContrato())->format($registro), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($resultado);
        $evento = [];
        foreach ($resultado as $chave => $valor) {
            self::assertIsString($chave, 'Os campos do evento devem ter nomes textuais.');
            $evento[$chave] = $valor;
        }
        return $evento;
    }

    public function testExcecaoNaoEstruturadaPermaneceNoContexto(): void
    {
        $r = $this->formatar(['exception' => 'mensagem legada']);
        self::assertArrayNotHasKey('err', $r);
        self::assertSame(['exception' => 'mensagem legada'], $r['context']);
    }

    public function testArrayNaChaveExceptionSemTipoNemMensagemContinuaNoContexto(): void
    {
        // Um array com a forma de exceção normalizada, mas sem a origem em um
        // Throwable, não sobe a `err`: a promoção depende da identidade da
        // exceção, não da semelhança estrutural (ver PromocaoDeCamposTest).
        $r = $this->formatar(['exception' => ['class' => 123, 'message' => []]]);
        self::assertArrayNotHasKey('err', $r);
        self::assertSame(['exception' => ['class' => 123, 'message' => []]], $r['context']);
    }

    public function testCamposOpcionaisPreservamNullEObjetoJson(): void
    {
        $r = $this->formatar(['user_id' => null, 'http' => (object) ['status' => 503]]);
        self::assertArrayHasKey('user_id', $r);
        self::assertNull($r['user_id']);
        self::assertSame(['status' => 503], $r['http']);
    }
}
