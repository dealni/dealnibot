<?php

declare(strict_types=1);

namespace Tests;

use App\Support\LoggerFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\AppLogReader;

/**
 * Contrato do adaptador PSR-3 usado pela aplicação.
 *
 * A especificação define oito métodos de nível, `log()` para o nível
 * arbitrário, mensagens string/Stringable e contexto array de dados mistos.
 * Estes testes mantêm essa superfície explícita para que uma troca de
 * implementação não reduza silenciosamente a compatibilidade da fábrica.
 */
final class Psr3LoggerContratoTest extends TestCase
{
    protected function setUp(): void
    {
        LoggerFactory::reset();
    }

    public function testFactoryRetornaLoggerInterfacePsr3Completa(): void
    {
        $retorno = (new ReflectionMethod(LoggerFactory::class, 'channel'))->getReturnType();
        $this->assertInstanceOf(ReflectionNamedType::class, $retorno);
        $this->assertSame(LoggerInterface::class, $retorno->getName());
        $this->assertSame(
            [
                'emergency', 'alert', 'critical', 'error', 'warning', 'notice',
                'info', 'debug', 'log',
            ],
            array_map(
                static fn (\ReflectionMethod $metodo): string => $metodo->getName(),
                (new ReflectionClass(LoggerInterface::class))->getMethods()
            )
        );

        $info = new ReflectionMethod(LoggerInterface::class, 'info');
        $parametros = $info->getParameters();
        $this->assertSame(['message', 'context'], array_map(
            static fn (\ReflectionParameter $parametro): string => $parametro->getName(),
            $parametros
        ));
        $this->assertSame('Stringable|string', (string) $parametros[0]->getType());
        $this->assertSame('array', (string) $parametros[1]->getType());
        $this->assertSame('void', (string) $info->getReturnType());

        $log = new ReflectionMethod(LoggerInterface::class, 'log');
        $this->assertNull($log->getParameters()[0]->getType());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAceitaStringableContextoMistoTodosOsNiveisELog(): void
    {
        define('LOGS_ATIVO', true);
        $antes = AppLogReader::tamanhoAtual();
        $logger = LoggerFactory::channel('psr3_niveis');
        $mensagem = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'mensagem Stringable';
            }
        };
        $stringableQueFalha = new class () implements \Stringable {
            public function __toString(): string
            {
                throw new \RuntimeException('Stringable inválida');
            }
        };
        $contexto = [
            'id' => 42,
            'nulo' => null,
            'objeto' => new \stdClass(),
            'mensagem' => $mensagem,
            'stringable_que_falha' => $stringableQueFalha,
        ];

        $logger->emergency($mensagem, $contexto);
        $logger->alert('alerta', $contexto);
        $logger->critical('critico', $contexto);
        $logger->error('erro', $contexto);
        $logger->warning('aviso', $contexto);
        $logger->notice('aviso normal', $contexto);
        $logger->info('info', $contexto);
        $logger->debug('debug', $contexto);
        $logger->log(LogLevel::INFO, 'log generico {id}', $contexto);

        $linhas = array_values(array_filter(
            AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes)),
            static fn (array $linha): bool => ($linha['channel'] ?? null) === 'psr3_niveis'
        ));

        $this->assertCount(9, $linhas);
        $this->assertSame(
            ['fatal', 'fatal', 'fatal', 'error', 'warn', 'info', 'info', 'debug', 'info'],
            array_map(static fn (array $linha): mixed => $linha['level'] ?? null, $linhas)
        );
        $this->assertSame('mensagem Stringable', $linhas[0]['msg'] ?? null);
        $this->assertSame('log generico {id}', $linhas[8]['msg'] ?? null);
        $contextoDoLog = $linhas[8]['context'] ?? null;
        $this->assertIsArray($contextoDoLog);
        $this->assertSame(42, $contextoDoLog['id'] ?? null);
        $this->assertIsString($contextoDoLog['stringable_que_falha'] ?? null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testExcecaoNoContextoEReconhecidaSomenteQuandoForThrowable(): void
    {
        define('LOGS_ATIVO', true);
        $antes = AppLogReader::tamanhoAtual();
        LoggerFactory::channel('psr3_exception')->error(
            'falha no gateway',
            ['exception' => new \RuntimeException('falha do gateway')]
        );

        $linhas = array_values(array_filter(
            AppLogReader::linhasJson(AppLogReader::conteudoDesde($antes)),
            static fn (array $linha): bool => ($linha['channel'] ?? null) === 'psr3_exception'
        ));
        $this->assertCount(1, $linhas);
        $erro = $linhas[0]['err'] ?? null;
        $this->assertIsArray($erro);
        $this->assertSame(\RuntimeException::class, $erro['type'] ?? null);
        $this->assertSame('falha do gateway', $erro['message'] ?? null);
        $contexto = $linhas[0]['context'] ?? null;
        $this->assertIsArray($contexto);
        $this->assertArrayNotHasKey('exception', $contexto);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNivelDesconhecidoLancaExcecaoPsr3(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LoggerFactory::channel('psr3_nivel_invalido')->log('nao-existe', 'mensagem');
    }
}
