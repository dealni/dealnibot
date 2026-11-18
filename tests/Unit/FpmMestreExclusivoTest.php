<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class FpmMestreExclusivoTest extends TestCase
{
    public function testMestreIncluiSomenteOPoolEAmbienteDesteBot(): void
    {
        $bot = 'dealnibot';
        $root = dirname(__DIR__, 2);
        $master = parse_ini_file($root . '/deploy/php-fpm/' . $bot . '-master.conf', true, INI_SCANNER_RAW);
        self::assertIsArray($master);
        self::assertIsArray($master['global']);
        self::assertSame('/etc/php/8.5/fpm/pool.d/' . $bot . '.conf', $master['global']['include']);
        self::assertSame('/run/php8.5-fpm-' . $bot . '/master.pid', $master['global']['pid']);
        $unit = file_get_contents($root . '/deploy/systemd/php8.5-fpm-' . $bot . '.service');
        self::assertIsString($unit);
        preg_match_all('/^EnvironmentFile=(.*)$/m', $unit, $matches);
        self::assertSame(['/etc/' . $bot . '/fpm.environment'], $matches[1]);
        self::assertStringContainsString('--fpm-config /etc/php/8.5/fpm/' . $bot . '-master.conf', $unit);
        self::assertStringContainsString('NoNewPrivileges=true', $unit);
        self::assertStringContainsString('ProtectSystem=strict', $unit);
        self::assertStringNotContainsString('php-fpm-socket-helper', $unit);
        self::assertMatchesRegularExpression('/^MemoryMax=[1-9][0-9]*M$/m', $unit);
    }
}
