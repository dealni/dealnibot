<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato da instância Redis versionada.
 *
 * A unit, o drop-in e a configuração em deploy/ são cópias do que roda no host
 * (o repositório segue o host). Estas asserções fixam as linhas que definem
 * usuário, caminhos, porta, memória e isolamento, e conferem que o instalador
 * grava nos mesmos caminhos que a unit lê: uma troca feita só num lado quebra
 * aqui, não na próxima instalação.
 */
final class ContratoDoRedisTest extends TestCase
{
    private const RAIZ = __DIR__ . '/../..';

    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    private const SLUG = 'dealnibot';
    private const PORTA = '6380';

    /** Linhas da unit que vêm do host, na forma em que estão versionadas. */
    private const LINHAS_DA_UNIT = [
        'Description=Redis exclusivo do DealniBot',
        'User=dealnibot',
        'Group=dealnibot',
        'ExecStart=/usr/bin/redis-server /etc/dealnibot/redis.conf',
        'Restart=on-failure',
        'TimeoutStopSec=60',
        'UMask=0077',
        'NoNewPrivileges=true',
        'PrivateTmp=true',
        'ProtectHome=true',
        'ProtectSystem=strict',
        'ReadWritePaths=/var/lib/dealnibot/redis',
    ];

    /** Linhas do redis.conf do host que o bot e a operação pressupõem. */
    private const LINHAS_DA_CONFIGURACAO = [
        'bind 127.0.0.1',
        'port 6380',
        'protected-mode yes',
        'dir /var/lib/dealnibot/redis',
        'appendonly yes',
        'appendfsync everysec',
        'save 900 1',
        'maxmemory 256mb',
        'maxmemory-policy noeviction',
    ];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::RAIZ . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /** @return list<string> */
    private static function linhas(string $conteudo): array
    {
        $linhas = array_map('trim', explode("\n", $conteudo));

        return array_values(array_filter(
            $linhas,
            static fn (string $linha): bool => $linha !== '' && !str_starts_with($linha, '#'),
        ));
    }

    /** Valor único de uma chave `Chave=valor` da unit. */
    private static function valorDaUnit(string $unit, string $chave): string
    {
        self::assertSame(1, preg_match_all('/^' . $chave . '=(.+)$/m', $unit, $matches), "Unit sem {$chave} único.");

        return trim($matches[1][0]);
    }

    /** Valor de uma atribuição `nome="valor"` do instalador. */
    private static function variavelDoInstalador(string $instalador, string $nome): string
    {
        self::assertSame(1, preg_match('/^' . $nome . '="([^"]*)"$/m', $instalador, $matches), "Instalador sem {$nome}.");

        return $matches[1];
    }

    public function testUnitVersionadaTemAsLinhasDoHost(): void
    {
        $unit = self::linhas(self::ler('deploy/systemd/redis-' . self::SLUG . '.service'));

        foreach (self::LINHAS_DA_UNIT as $linha) {
            self::assertContains($linha, $unit, "Unit sem a linha do host: {$linha}");
        }
    }

    public function testDropInExigeOIsolamentoPorUid(): void
    {
        $dropIn = self::linhas(self::ler('deploy/systemd/redis-' . self::SLUG . '.service.d/60-isolamento.conf'));

        self::assertSame(
            ['[Unit]', 'Requires=bots-redis-isolation.service', 'After=bots-redis-isolation.service'],
            $dropIn,
        );
    }

    public function testConfiguracaoVersionadaTemAsLinhasDoHostESemSenha(): void
    {
        $conteudo = self::ler('deploy/redis/' . self::SLUG . '.conf');
        $configuracao = self::linhas($conteudo);

        foreach (self::LINHAS_DA_CONFIGURACAO as $linha) {
            self::assertContains($linha, $configuracao, "Configuração sem a linha do host: {$linha}");
        }
        self::assertContains('port ' . self::PORTA, $configuracao);
        self::assertDoesNotMatchRegularExpression('/^\s*requirepass\b/m', $conteudo, 'Segredo não se versiona.');
    }

    public function testInstaladorUnitEConfiguracaoApontamParaOsMesmosCaminhos(): void
    {
        $unit = self::ler('deploy/systemd/redis-' . self::SLUG . '.service');
        $configuracao = self::linhas(self::ler('deploy/redis/' . self::SLUG . '.conf'));
        $instalador = self::ler('scripts/install/instalar_redis.sh');

        $caminhoDaConfiguracao = self::variavelDoInstalador($instalador, 'config');
        $dados = self::variavelDoInstalador($instalador, 'data_dir');

        self::assertSame(self::SLUG, self::variavelDoInstalador($instalador, 'bot_slug'));
        self::assertSame(self::PORTA, self::variavelDoInstalador($instalador, 'redis_port'));
        self::assertSame(self::valorDaUnit($unit, 'User'), self::variavelDoInstalador($instalador, 'redis_user'));
        self::assertSame(self::valorDaUnit($unit, 'Group'), self::variavelDoInstalador($instalador, 'redis_group'));
        self::assertSame('/usr/bin/redis-server ' . $caminhoDaConfiguracao, self::valorDaUnit($unit, 'ExecStart'));
        self::assertStringStartsWith(self::variavelDoInstalador($instalador, 'config_dir') . '/', $caminhoDaConfiguracao);
        self::assertSame($dados, self::valorDaUnit($unit, 'ReadWritePaths'));
        self::assertContains('dir ' . $dados, $configuracao);
        self::assertStringContainsString('deploy/systemd/${service}.d/60-isolamento.conf', $instalador);
    }
}
