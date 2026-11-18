<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ComposerUnusedGateTest extends TestCase
{
    /**
     * @param list<string> $argumentos
     * @return array{status: int, saida: string, stderr: string}
     */
    private function executarForaDaRaiz(array $argumentos = []): array
    {
        $comando = [
            PHP_BINARY,
            dirname(__DIR__, 2) . '/scripts/quality/run_composer_unused.php',
            ...$argumentos,
        ];

        $processo = proc_open(
            $comando,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canos,
            sys_get_temp_dir(),
        );
        $this->assertIsResource($processo);
        fclose($canos[0]);

        $saida = stream_get_contents($canos[1]);
        $erro = stream_get_contents($canos[2]);
        fclose($canos[1]);
        fclose($canos[2]);
        $this->assertIsString($saida);
        $this->assertIsString($erro);

        return [
            'status' => proc_close($processo),
            'saida' => $saida . $erro,
            'stderr' => $erro,
        ];
    }

    public function testChecagemUsaOProjetoMesmoQuandoChamadoForaDaRaiz(): void
    {
        $resultado = $this->executarForaDaRaiz();

        $this->assertSame(0, $resultado['status'], $resultado['saida']);
        $this->assertMatchesRegularExpression('/Found \d+ used, 0 unused/', $resultado['saida']);
        $this->assertStringContainsString('ext-curl', $resultado['saida']);
        $this->assertStringContainsString('Results', $resultado['saida']);
        $this->assertStringContainsString('0 zombie', $resultado['saida']);
        $this->assertSame('', $resultado['stderr']);
    }

    public function testChecagemPreservaCodigoDeFalhaDoComandoFilho(): void
    {
        $resultado = $this->executarForaDaRaiz([
            dirname(__DIR__, 2) . '/arquivo-composer-inexistente.json',
        ]);

        $this->assertSame(1, $resultado['status']);
        $this->assertStringContainsString('does not exist or is not readable', $resultado['saida']);
    }
}
