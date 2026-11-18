<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class ExecutorCliAmbienteTest extends TestCase
{
    public function testFilhoDistingueVariavelVaziaDeVariavelAusente(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'ambiente_cli_');
        self::assertIsString($script);
        file_put_contents($script, '<?php echo json_encode([getenv("CHATBOTS_FIXTURE_VAZIA"), getenv("CHATBOTS_FIXTURE_AUSENTE"), getenv("CHATBOTS_FIXTURE_TEXTO")]);');
        try {
            $r = ExecutorCli::executar($script, [], ['CHATBOTS_FIXTURE_VAZIA' => '', 'CHATBOTS_FIXTURE_AUSENTE' => false, 'CHATBOTS_FIXTURE_TEXTO' => 'texto-da-fixture']);
            self::assertSame(0, $r['codigo'], $r['stderr']);
            self::assertSame('', $r['stderr']);
            self::assertSame(['', false, 'texto-da-fixture'], json_decode($r['stdout'], true, 512, JSON_THROW_ON_ERROR));
        } finally {
            unlink($script);
        }
    }
}
