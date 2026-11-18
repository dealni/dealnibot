<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Support\ExecutorCli;

final class BancoTesteCicloDeVidaTest extends TestCase
{
    public function testSubprocessoRemoveSuaFixtureEPreservaSchemaEmprestado(): void
    {
        $id = bin2hex(random_bytes(16));
        $emprestado = \BancoTeste::dsn($id);
        $pdo = \BancoTeste::conectar($emprestado);
        $pdo->exec('CREATE TABLE preservada (id integer)');
        $script = tempnam(sys_get_temp_dir(), 'fixture-ciclo-');
        self::assertIsString($script);
        try {
            $codigo = '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true)
                . '; BancoTeste::dsn(' . var_export($id, true) . ');'
                . '$dsn = BancoTeste::dsn(); echo substr($dsn, strrpos($dsn, "=") + 1);';
            self::assertNotFalse(file_put_contents($script, $codigo));
            $resultado = ExecutorCli::executar($script);
            self::assertSame(0, $resultado['codigo'], $resultado['stderr']);
            self::assertMatchesRegularExpression('/^teste_[a-f0-9]{32}$/D', $resultado['stdout']);
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM pg_namespace WHERE nspname = ?');
            self::assertNotFalse($stmt);
            $stmt->execute([$resultado['stdout']]);
            self::assertSame(0, $stmt->fetchColumn());
            self::assertSame(0, \ConsultaBanco::coluna($pdo, 'SELECT COUNT(*) FROM preservada'));
        } finally {
            unlink($script);
        }
    }
}
