<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class RuntimeEnvironmentPlataformasTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function plataformas(): array
    {
        return [
            'sistema não suportado' => [['plataforma' => 'Windows'], false],
            'Linux namespace distinto só loopback' => [['plataforma' => 'Linux', 'atual' => 'net:[2]', 'inicial' => 'net:[1]', 'interfaces' => ['lo' => []]], true],
            'Linux namespace distinto com rede' => [['plataforma' => 'Linux', 'atual' => 'net:[2]', 'inicial' => 'net:[1]', 'interfaces' => ['lo' => [], 'eth0' => []]], false],
            'Linux namespace igual só loopback' => [['plataforma' => 'Linux', 'atual' => 'net:[1]', 'inicial' => 'net:[1]', 'interfaces' => ['lo' => []]], true],
            'Linux namespace igual com rede' => [['plataforma' => 'Linux', 'atual' => 'net:[1]', 'inicial' => 'net:[1]', 'interfaces' => ['lo' => [], 'eth0' => []]], false],
            'Linux namespace atual ilegível' => [['plataforma' => 'Linux', 'atual' => false, 'inicial' => 'net:[1]', 'interfaces' => ['lo' => []]], true],
            'Linux sem acesso ao PID 1 e sysfs do host' => [['plataforma' => 'Linux', 'atual' => 'net:[2]', 'inicial' => false, 'sysfs' => ['.', '..', 'lo', 'eth0'], 'interfaces' => ['lo' => []]], true],
            'Linux rede externa desativada ainda reprova' => [['plataforma' => 'Linux', 'atual' => 'net:[2]', 'inicial' => false, 'interfaces' => ['lo' => [], 'eth0' => ['up' => false]]], false],
            'Linux sem leitura das interfaces' => [['plataforma' => 'Linux', 'atual' => false, 'interfaces' => false], false],
            'Linux nenhuma interface' => [['plataforma' => 'Linux', 'atual' => false, 'interfaces' => []], false],
            'Darwin sem sinalização' => [['plataforma' => 'Darwin', 'sandbox' => '0'], false],
            'Darwin recusa de permissão nas duas sondas' => [['plataforma' => 'Darwin', 'sandbox' => '1', 'sockets' => [1, 1]], true],
            'Darwin primeira sonda abre' => [['plataforma' => 'Darwin', 'sandbox' => '1', 'sockets' => ['abre']], false],
            'Darwin segunda sonda abre' => [['plataforma' => 'Darwin', 'sandbox' => '1', 'sockets' => [1, 'abre']], false],
            'Darwin conexão recusada não prova isolamento' => [['plataforma' => 'Darwin', 'sandbox' => '1', 'sockets' => [61]], false],
            'Darwin segunda sonda com outro erro' => [['plataforma' => 'Darwin', 'sandbox' => '1', 'sockets' => [1, 60]], false],
        ];
    }

    /** @param array<string, mixed> $cenario */
    #[DataProvider('plataformas')]
    public function testDecisaoDeIsolamentoUsaEvidenciasDaPlataforma(array $cenario, bool $esperado): void
    {
        $script = (string) tempnam(sys_get_temp_dir(), 'runtime_plataformas_');
        $config = $script . '.json';
        file_put_contents($config, json_encode($cenario, JSON_THROW_ON_ERROR));
        // A constante namespaced e os dublês existem só neste subprocesso.
        // O sandbox real do runner continua negando rede em todas as plataformas.
        file_put_contents($script, <<<'RUNNER'
<?php
namespace App\Support;
$GLOBALS['cenarioPlataforma'] = json_decode(file_get_contents($argv[1]), true);
define(__NAMESPACE__ . '\PHP_OS_FAMILY', $GLOBALS['cenarioPlataforma']['plataforma']);
putenv('CHATBOTS_OS_NETWORK_SANDBOX=' . ($GLOBALS['cenarioPlataforma']['sandbox'] ?? '0'));
function readlink(string $path): string|false {
    return $GLOBALS['cenarioPlataforma'][$path === '/proc/self/ns/net' ? 'atual' : 'inicial'] ?? false;
}
function scandir(string $path): array|false {
    return $GLOBALS['cenarioPlataforma']['sysfs'] ?? false;
}
function net_get_interfaces(): array|false {
    return $GLOBALS['cenarioPlataforma']['interfaces'] ?? false;
}
function stream_socket_client(string $address, &$errno, &$error, float $timeout, int $flags): mixed {
    $retorno = array_shift($GLOBALS['cenarioPlataforma']['sockets']);
    $errno = is_int($retorno) ? $retorno : 0;
    return $retorno === 'abre' ? fopen('php://memory', 'w+') : false;
}
require $argv[2] . '/src/Support/RuntimeEnvironment.php';
echo json_encode(RuntimeEnvironment::hasNetworkIsolation());
RUNNER);
        try {
            $r = ExecutorCli::executar($script, [$config, dirname(__DIR__, 2)]);
            self::assertSame(0, $r['codigo'], $r['stderr']);
            self::assertSame('', $r['stderr']);
            self::assertSame($esperado, json_decode($r['stdout'], true));
        } finally {
            unlink($script);
            unlink($config);
        }
    }
}
