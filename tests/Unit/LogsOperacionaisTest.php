<?php

declare(strict_types=1);

namespace Tests;

use App\Support\ContextoDeExecucao;
use App\Support\LoggerFactory;
use App\Support\ResumoTelegram;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

final class LogsOperacionaisTest extends TestCase
{
    protected function tearDown(): void
    {
        ContextoDeExecucao::reset();
        LoggerFactory::reset();
    }

    public function testAllowlistExcluiTextoMidiaObjetosESegredos(): void
    {
        $objeto = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                throw new \RuntimeException('Objeto não pode ser serializado');
            }
        };
        $resumo = ResumoTelegram::resposta([
            'ok' => true, 'result' => ['message_id' => 42, 'text' => 'texto privado', 'photo' => [$objeto]],
            'token' => 'token privado', 'parameters' => ['retry_after' => 12, 'payload' => $objeto],
        ]);
        $this->assertSame(['ok' => true, 'message_id' => 42, 'retry_after' => 12], $resumo);
        $this->assertSame(['chat_id' => 10], ResumoTelegram::contexto(['chat_id' => 10, 'text' => 'privado', 'payload' => $objeto]));
    }

    public function testCadaReplayTemCicloProprioLigadoAoJobERestauraContexto(): void
    {
        $job = ContextoDeExecucao::requestId();
        $anterior = ContextoDeExecucao::iniciarItem(21, 2, true);
        $primeiro = ContextoDeExecucao::requestId();
        $this->assertNotSame($job, $primeiro);
        $this->assertSame(['update_id' => 21, 'attempt' => 2, 'job_id' => $job], ContextoDeExecucao::item());
        ContextoDeExecucao::restaurarItem($anterior);
        $this->assertSame([], ContextoDeExecucao::item());
        $this->assertSame($job, ContextoDeExecucao::requestId());
        $anterior = ContextoDeExecucao::iniciarItem(22, 1, true);
        $this->assertNotSame($primeiro, ContextoDeExecucao::requestId());
        ContextoDeExecucao::restaurarItem($anterior);
    }

    public function testCodigoTecnicoNaoAceitaConteudoArbitrario(): void
    {
        $handler = $this->espionar('telegram', true);
        foreach (['conteudo privado', true, 4.5, '400 conteudo privado'] as $codigo) {
            \TelegramErrorLogger::log(['ok' => false, 'error_code' => $codigo]);
        }
        $this->assertCount(4, $handler->getRecords());
        foreach ($handler->getRecords() as $registro) {
            $this->assertSame('0', $registro->context['error_code']);
            $this->assertIsArray($registro->context['api']);
            $this->assertArrayNotHasKey('error_code', $registro->context['api']);
        }
        $this->assertSame(7, ResumoTelegram::codigo(7));
        $this->assertSame('429', ResumoTelegram::codigo('429'));
    }

    public function testHelperTransmiteNivelContextoECausaRedigida(): void
    {
        $handler = $this->espionar('regressao_logs', true);
        escreverLog('Falha Redis', 'regressao_logs', true, 'error', ['phase' => 'auth', 'token' => 'segredo']);
        $this->assertTrue($handler->hasErrorRecords());
        $registro = $handler->getRecords()[0];
        $this->assertSame('auth', $registro->context['phase']);
        $this->assertNotSame('segredo', $registro->context['token']);
    }

    public function testMessageNotModifiedNaoGeraError(): void
    {
        $handler = $this->espionar('telegram');
        \TelegramErrorLogger::log(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: message is not modified'], ['metodo' => 'editMessageText']);
        $this->assertTrue($handler->hasDebugRecords());
        $this->assertFalse($handler->hasErrorRecords());
    }

    private function espionar(string $canal, bool $forcar = false): TestHandler
    {
        $logger = LoggerFactory::channel($canal, $forcar);
        $this->assertInstanceOf(Logger::class, $logger);
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        return $handler;
    }
}
