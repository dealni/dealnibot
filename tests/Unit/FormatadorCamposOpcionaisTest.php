<?php

declare(strict_types=1);

namespace Tests;

use App\Support\FormatadorDeContrato;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class FormatadorCamposOpcionaisTest extends TestCase
{
    public function testCamposOpcionaisPreservamNullEObjetoJson(): void
    {
        $registro = new LogRecord(new \DateTimeImmutable('2026-09-13T00:00:00Z'), 'teste', Level::Error, 'Falha de teste', ['user_id' => null, 'http' => (object) ['status' => 503]]);
        $r = json_decode((new FormatadorDeContrato())->format($registro), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($r);
        self::assertArrayHasKey('user_id', $r);
        self::assertNull($r['user_id']);
        self::assertSame(['status' => 503], $r['http']);
        self::assertSame('error', $r['level']);
    }
}
