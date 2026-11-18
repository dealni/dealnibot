<?php

declare(strict_types=1);

namespace Tests;

use ConsultaBanco;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../inclusos/banco.php';

final class BancoConfiguracaoTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $ambiente = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'CHATBOTS_TESTING', 'TEST_DATABASE_DSN', 'DEALNI_DB_DSN', 'DEALNI_DB_USER'] as $nome) {
            $this->ambiente[$nome] = getenv($nome);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->ambiente as $nome => $valor) {
            putenv($valor === false ? $nome : $nome . '=' . $valor);
        }
    }

    public function testProducaoSelecionaDsnDoBotSemHerdarDsnDeTeste(): void
    {
        putenv('APP_ENV=production');
        putenv('CHATBOTS_TESTING=0');
        putenv('TEST_DATABASE_DSN=pgsql:dbname=errado');
        putenv('DEALNI_DB_DSN=pgsql:host=127.0.0.1;dbname=teste');
        $this->assertSame('pgsql:host=127.0.0.1;dbname=teste', \dsnBancoDoBot());
        $this->assertTrue(\bancoUsaPostgres());
    }

    public function testDsnComSenhaEmbutidaERecusado(): void
    {
        putenv('APP_ENV=production');
        putenv('DEALNI_DB_DSN=pgsql:dbname=teste;password=test-only');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/credenciais separadas/');
        \dsnBancoDoBot();
    }

    public function testDsnDeOutroDriverNaoCaiSilenciosamenteParaSqlite(): void
    {
        putenv('APP_ENV=production');
        putenv('DEALNI_DB_DSN=mysql:dbname=teste');
        $this->expectException(RuntimeException::class);
        \dsnBancoDoBot();
    }

    public function testArgumentosCliSaoValidadosSemConversaoSilenciosa(): void
    {
        $antes = $_SERVER['argv'] ?? null;
        try {
            $_SERVER['argv'] = ['migrate.php', '--status'];
            $this->assertSame(['migrate.php', '--status'], ConsultaBanco::argumentos());
            $_SERVER['argv'] = ['migrate.php', new \stdClass()];
            $this->expectException(RuntimeException::class);
            ConsultaBanco::argumentos();
        } finally {
            $_SERVER['argv'] = $antes;
        }
    }

    public function testArgumentosCliExigemLista(): void
    {
        $antes = $_SERVER['argv'] ?? null;
        try {
            $_SERVER['argv'] = 'migrate.php';
            $this->expectException(RuntimeException::class);
            ConsultaBanco::argumentos();
        } finally {
            $_SERVER['argv'] = $antes;
        }
    }

    public function testConsultaSilenciosaFalhaExplicitamente(): void
    {
        $pdo = \BancoTeste::conectar(null);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->expectException(RuntimeException::class);
        ConsultaBanco::query($pdo, 'SELECT * FROM tabela_ausente');
    }

    public function testListaDeNomesRecusaValorNumerico(): void
    {
        $pdo = \BancoTeste::conectar(null);
        $this->expectException(RuntimeException::class);
        ConsultaBanco::nomes($pdo, 'SELECT 123');
    }

    public function testMapaRecusaQuantidadeErradaDeColunas(): void
    {
        $pdo = \BancoTeste::conectar(null);
        $this->expectException(RuntimeException::class);
        ConsultaBanco::mapa($pdo, "SELECT 'a' AS nome, 'b' AS valor, 'c' AS extra");
    }
}
