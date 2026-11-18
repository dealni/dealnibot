<?php

declare(strict_types=1);

namespace Tests;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ComandosContextoInvalidoTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function comandos(): array
    {
        return [
            'texto' => ['chatgpt.php', 'Contexto inválido para o comando chatgpt.'],
            'áudio' => ['ver_audio.php', 'Contexto inválido para o comando de áudio.'],
            'imagem' => ['ver_imagem.php', 'Contexto inválido para o comando de imagem.'],
        ];
    }

    #[DataProvider('comandos')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRecusaContextoAusenteAntesDeExecutarOFluxo(string $arquivo, string $mensagem): void
    {
        // Nenhum objeto de Telegram/Redis, token ou banco entra no escopo.
        // Os requires iniciais só declaram funções. O sandbox de rede do runner
        // permanece ativo: a guarda deve abortar antes de qualquer operação.
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs($mensagem);

        (static function (string $caminho): void {
            require $caminho;
        })(__DIR__ . '/../../comandos/' . $arquivo);
    }
}
