<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class AmbienteFpmEntradasTest extends TestCase
{
    /** @return array<string, array{bool, int, string}> */
    public static function ambientes(): array
    {
        return ['checkout' => [false, 0, 'contrato do host isolado por bot'], 'host sem artefatos' => [true, 1, 'Artefatos de ambiente do PHP-FPM ausentes']];
    }

    #[DataProvider('ambientes')]
    public function testComandoValidaCheckoutEDistingueHostSemConfiguracao(bool $host, int $codigo, string $mensagem): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'fpm_entrada_');
        file_put_contents($script, <<<'RUNNER'
<?php
function is_file(string $arquivo): bool {
    return str_starts_with($arquivo, '/etc/') ? false : (new SplFileInfo($arquivo))->isFile();
}
require $argv[1] . '/scripts/quality/check_fpm_environment.php';
exit(Quality\mainAmbienteFpm($argv[2] === '1' ? ['--host'] : []));
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [dirname(__DIR__, 2), $host ? '1' : '0'], [], ['disable_functions' => 'is_file']);
            self::assertSame($codigo, $r['codigo'], $r['stdout'] . $r['stderr']);
            self::assertStringContainsString($mensagem, $r['stdout']);
            self::assertSame('', $r['stderr']);
        } finally {
            unlink($script);
        }
    }
}
