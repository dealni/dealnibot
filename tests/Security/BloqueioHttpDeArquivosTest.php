<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/** Garante que o virtual host Nginx não transforme o checkout em DocumentRoot. */
final class BloqueioHttpDeArquivosTest extends TestCase
{
    private function nginx(): string
    {
        $conteudo = file_get_contents(__DIR__ . '/../../deploy/nginx/dealnibot.conf');
        $this->assertIsString($conteudo);

        return $conteudo;
    }

    private function blocoDaLocation(string $conteudo, string $caminho): string
    {
        $inicio = strpos($conteudo, 'location = ' . $caminho . ' {');
        $this->assertIsInt($inicio);
        $proxima = strpos($conteudo, "\n    location ", $inicio + 1);

        return substr($conteudo, $inicio, $proxima === false ? null : $proxima - $inicio);
    }

    public function testNaoMantemArtefatosDoServidorAnterior(): void
    {
        $this->assertDirectoryDoesNotExist(__DIR__ . '/../../deploy/apache');
        $this->assertFileDoesNotExist(__DIR__ . '/../../.htaccess');
    }

    public function testSomenteOsQuatroEntrypointsSaoExecutaveis(): void
    {
        $conteudo = $this->nginx();
        $this->assertStringContainsString('server_name bot.example.com;', $conteudo);
        preg_match_all('/location = \/([^\s{]+)\s*\{/', $conteudo, $locais);

        $this->assertSame(['index.php', 'bot_web.php', 'health', 'ready'], $locais[1]);
        $this->assertStringContainsString('location /', $conteudo);
        $this->assertStringContainsString('return 404;', $conteudo);
        $this->assertStringNotContainsString('location ~', $conteudo);
    }

    public function testSondasNaoSaoAlcancaveisPeloNomeDoArquivo(): void
    {
        $conteudo = $this->nginx();

        $this->assertStringNotContainsString('location = /health.php', $conteudo);
        $this->assertStringNotContainsString('location = /ready.php', $conteudo);
    }

    public function testSondasSaoSilenciosasNoAcessoEComTimeoutCurto(): void
    {
        $conteudo = $this->nginx();

        $this->assertSame(2, substr_count($conteudo, 'access_log off;'));
        $this->assertSame(2, substr_count($conteudo, 'fastcgi_connect_timeout 3s;'));
        $this->assertSame(2, substr_count($conteudo, 'fastcgi_read_timeout 5s;'));
    }

    public function testEntrypointsUsamPoolIsoladoECaminhoExplicito(): void
    {
        $conteudo = $this->nginx();

        $this->assertSame(4, substr_count(
            $conteudo,
            'fastcgi_pass unix:/run/php/php8.5-fpm-dealnibot.sock;'
        ));
        $this->assertStringContainsString('root /var/www/dealnibot;', $conteudo);
        foreach (['index.php', 'bot_web.php', 'health.php', 'ready.php'] as $arquivo) {
            $this->assertStringContainsString(
                'fastcgi_param SCRIPT_FILENAME $document_root/' . $arquivo . ';',
                $conteudo
            );
        }
        $this->assertSame(2, substr_count($conteudo, 'fastcgi_read_timeout 330s;'));
    }

    public function testNaoDuplicaScriptNameNemAceitaPathInfo(): void
    {
        $conteudo = $this->nginx();

        // O fastcgi_params oficial já define SCRIPT_NAME com
        // $fastcgi_script_name. Como estas locations são exatas, o valor já
        // é o caminho público e não há PATH_INFO a separar.
        $this->assertSame(4, substr_count($conteudo, 'include fastcgi_params;'));
        $this->assertSame(0, preg_match_all('/^\s*fastcgi_param SCRIPT_NAME\b/m', $conteudo));
        $this->assertSame(0, preg_match_all('/^\s*fastcgi_split_path_info\b/m', $conteudo));
        $this->assertSame(0, preg_match_all('/^\s*fastcgi_param PATH_INFO\b/m', $conteudo));
    }

    public function testCadaRotaAceitaSomenteOMetodoDaSuaInterface(): void
    {
        $conteudo = $this->nginx();

        $this->assertSame(4, substr_count($conteudo, 'limit_except '));
        foreach (['/index.php', '/health', '/ready'] as $rota) {
            $bloco = $this->blocoDaLocation($conteudo, $rota);
            $this->assertStringContainsString("limit_except GET {\n            deny all;", $bloco, $rota);
            $this->assertStringNotContainsString('limit_except POST', $bloco, $rota);
        }

        $webhook = $this->blocoDaLocation($conteudo, '/bot_web.php');
        $this->assertStringContainsString("limit_except POST {\n            deny all;", $webhook);
        $this->assertStringNotContainsString('limit_except GET', $webhook);
    }
}
