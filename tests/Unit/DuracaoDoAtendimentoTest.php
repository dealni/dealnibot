<?php

declare(strict_types=1);

namespace Tests;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\AppLogReader;

require_once __DIR__ . '/../../inclusos/funcoes_webhook_inbox.php';

/**
 * `duration_ms` do evento que fecha o atendimento de um update do webhook.
 *
 * O relógio entra como parâmetro (leituras de hrtime(true) em nanossegundos):
 * nada aqui depende da hora real nem da velocidade da máquina.
 */
final class DuracaoDoAtendimentoTest extends TestCase
{
    public function testDuracaoEmMilissegundosCortaAFracaoENuncaFicaNegativa(): void
    {
        $this->assertSame(250, duracaoEmMilissegundos(1_000_000_000, 1_250_999_999));
        $this->assertSame(0, duracaoEmMilissegundos(1_000_000_000, 1_000_999_999));
        $this->assertSame(1, duracaoEmMilissegundos(1_000_000_000, 1_001_000_000));
        // Leituras trocadas por quem chama não viram duração negativa.
        $this->assertSame(0, duracaoEmMilissegundos(2_000_000_000, 1_000_000_000));
        // hrtime(true) devolve float em plataforma de 32 bits.
        $this->assertSame(1500, duracaoEmMilissegundos(5.0e9, 6.5e9));
    }

    public function testFimDoAtendimentoRegistraUpdateDesfechoEDuracao(): void
    {
        $registros = new TestHandler();
        $log = new Logger('webhook', [$registros]);

        registrarFimDoAtendimentoWebhook(77, 'processado', 3_000_000_000, 3_042_500_000, $log);
        registrarFimDoAtendimentoWebhook(78, 'falha', 3_000_000_000, 4_000_000_000, $log);

        $eventos = $registros->getRecords();
        $this->assertCount(2, $eventos);
        $this->assertSame(Level::Info, $eventos[0]->level);
        $this->assertSame('Atendimento do update concluído', $eventos[0]->message);
        $this->assertSame(
            ['update_id' => 77, 'desfecho' => 'processado', 'duration_ms' => 42],
            $eventos[0]->context
        );
        $this->assertSame(
            ['update_id' => 78, 'desfecho' => 'falha', 'duration_ms' => 1000],
            $eventos[1]->context
        );
    }

    /**
     * Sem logger nem fim explícitos, o evento vai ao canal `webhook` do app.log
     * com `duration_ms` no TOPO (o formatador promove o campo do contrato) e
     * medido até o próprio hrtime(true) da chamada.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSemLoggerOEventoVaiAoCanalWebhookComDuracaoNoTopo(): void
    {
        define('LOGS_ATIVO', true);
        $antes = AppLogReader::tamanhoAtual();
        $inicio = hrtime(true);

        registrarFimDoAtendimentoWebhook(91, 'vazio', $inicio);

        $eventos = array_values(array_filter(
            AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes)),
            static fn (array $linha): bool => ($linha['channel'] ?? null) === 'webhook'
        ));
        $this->assertCount(1, $eventos);
        $this->assertSame('info', $eventos[0]['level'] ?? null);
        $this->assertSame('Atendimento do update concluído', $eventos[0]['msg'] ?? null);
        $this->assertIsInt($eventos[0]['duration_ms'] ?? null);
        $this->assertGreaterThanOrEqual(0, $eventos[0]['duration_ms']);
        $this->assertSame(['update_id' => 91, 'desfecho' => 'vazio'], $eventos[0]['context'] ?? null);
    }

    /**
     * O relógio começa antes de tudo no bot_web.php e o evento fecha depois do
     * processamento: mover qualquer um dos dois encolhe a duração medida.
     */
    public function testBotWebMedeDoTopoAteODesfechoDoUpdate(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../bot_web.php');
        $this->assertIsString($fonte);

        $inicio = strpos($fonte, '$inicioDoAtendimento = hrtime(true);');
        $primeiroRequire = strpos($fonte, 'require_once');
        $processamento = strpos($fonte, '$desfechoDoUpdate = processarProximoUpdateWebhook(');
        $fim = strpos(
            $fonte,
            'registrarFimDoAtendimentoWebhook($updateIdWebhook, $desfechoDoUpdate, $inicioDoAtendimento);'
        );

        $this->assertIsInt($inicio);
        $this->assertIsInt($primeiroRequire);
        $this->assertIsInt($processamento);
        $this->assertIsInt($fim);
        $this->assertLessThan($primeiroRequire, $inicio);
        $this->assertLessThan($fim, $processamento);
    }
}
