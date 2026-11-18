<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Contrato do vhost Nginx versionado.
 *
 * O arquivo é um modelo aplicado no host, por isso um erro nele não aparece
 * como falha de PHP durante o CI. Estas asserções mantêm a allowlist, o socket
 * isolado e os limites que a documentação de operação promete.
 */
final class ContratoNginxTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
    /** Nome do vhost, do socket do pool e do arquivo em deploy/nginx. */
    private const SLUG = 'dealnibot';

    private const SERVER_NAME = 'bot.example.com';

    /** Webhooks publicados além de /index.php e /bot_web.php, sempre por match exato. */
    private const ROTAS_PHP_EXTRAS = [
    ];

    /** Prefixos servidos sem PHP (desafio ACME), só onde o hook de renovação do certbot é versionado. */
    private const PREFIXOS_SEM_PHP = [
    ];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

    /**
     * Devolve a lista como está no bloco de dados: o analisador estático não
     * pode concluir "sempre vazia" ou "sempre cheia" a partir do valor deste
     * repositório, porque o valor é dado, não código.
     *
     * @param list<string> $dados
     * @return list<string>
     */
    private static function dados(array $dados): array
    {
        return $dados;
    }

    private static function fonte(): string
    {
        $fonte = file_get_contents(__DIR__ . '/../../deploy/nginx/' . self::SLUG . '.conf');
        self::assertIsString($fonte);

        return $fonte;
    }

    public function testVhostMantemAllowlistEUsaPoolIsolado(): void
    {
        $fonte = self::fonte();

        self::assertMatchesRegularExpression('/^\s*listen\s+80;\s*$/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*server_name\s+' . preg_quote(self::SERVER_NAME, '/') . ';\s*$/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*location\s*=\s*\/\s*\{\s*rewrite \^ \/index\.php last;/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*location\s*=\s*\/index\.php\s*\{/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*location\s*=\s*\/bot_web\.php\s*\{/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*location\s*=\s*\/health\s*\{/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*location\s*=\s*\/ready\s*\{/m', $fonte);
        foreach (self::dados(self::ROTAS_PHP_EXTRAS) as $rota) {
            self::assertMatchesRegularExpression('/^\s*location\s*=\s*' . preg_quote($rota, '/') . '\s*\{/m', $fonte, $rota);
        }
        $prefixosSemPhp = self::dados(self::PREFIXOS_SEM_PHP);
        foreach ($prefixosSemPhp as $prefixo) {
            self::assertMatchesRegularExpression('/^\s*location\s+\^~\s+' . preg_quote($prefixo, '/') . '/m', $fonte, $prefixo);

            // Servir arquivo estático sem PHP só é seguro com o caminho preso
            // ao webroot: sem disable_symlinks, um link plantado no diretório
            // do desafio publicaria qualquer arquivo legível pelo Nginx, e sem
            // o try_files o prefixo viraria uma listagem de caminho arbitrário.
            self::assertMatchesRegularExpression(
                '/^\s*location\s+\^~\s+' . preg_quote($prefixo, '/')
                . '(?:(?!^\s*location\b).)*?disable_symlinks\s+on;'
                . '(?:(?!^\s*location\b).)*?try_files\s+\$uri\s+=404;/ms',
                $fonte,
                $prefixo,
            );
        }
        if ($prefixosSemPhp === []) {
            self::assertStringNotContainsString('acme-challenge', $fonte);
        }
        self::assertMatchesRegularExpression('/^\s*location\s+\/\s*\{\s*$/m', $fonte);
        self::assertMatchesRegularExpression('/^\s*return\s+404;\s*$/m', $fonte);

        // Um location PHP por regex poderia sombrear o webhook conforme novos
        // locations regex fossem adicionados acima dele; o contrato é casar exato.
        self::assertDoesNotMatchRegularExpression('/^\s*location\s+~\s+.*\\\.php/m', $fonte);
        self::assertSame(
            4 + count(self::dados(self::ROTAS_PHP_EXTRAS)),
            substr_count($fonte, 'fastcgi_pass unix:/run/php/php8.5-fpm-' . self::SLUG . '.sock;'),
        );
        self::assertStringNotContainsString('proxy_pass', $fonte);
    }

    public function testRotasRestringemMetodosEProtegemSondasContraFilaDoFpm(): void
    {
        $fonte = self::fonte();

        self::assertMatchesRegularExpression(
            '/location\s*=\s*\/index\.php\s*\{(?:(?!^\s*location\b).)*?limit_except\s+GET\s*\{\s*deny all;\s*\}/ms',
            $fonte,
        );
        self::assertMatchesRegularExpression(
            '/location\s*=\s*\/bot_web\.php\s*\{(?:(?!^\s*location\b).)*?limit_except\s+POST\s*\{\s*deny all;\s*\}/ms',
            $fonte,
        );

        foreach (['/health', '/ready'] as $rota) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote("location = {$rota} {", '/')
                . '(?:(?!^\s*location\b).)*?limit_except\s+GET\s*\{\s*deny all;\s*\}/ms',
                $fonte,
            );
            self::assertMatchesRegularExpression(
                '/' . preg_quote("location = {$rota} {", '/')
                . '(?:(?!^\s*location\b).)*?fastcgi_connect_timeout\s+3s;'
                . '(?:(?!^\s*location\b).)*?fastcgi_read_timeout\s+5s;/ms',
                $fonte,
            );
        }
    }

    public function testSondasApontamParaScriptsReaisEHeadersTemAlways(): void
    {
        $fonte = self::fonte();

        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root/health.php;', $fonte);
        self::assertStringContainsString('fastcgi_param SCRIPT_FILENAME $document_root/ready.php;', $fonte);
        self::assertStringContainsString('access_log off;', $fonte);
        self::assertStringContainsString('add_header X-Content-Type-Options "nosniff" always;', $fonte);
        self::assertStringContainsString('add_header X-Frame-Options "DENY" always;', $fonte);
        self::assertStringContainsString('add_header Referrer-Policy "no-referrer" always;', $fonte);
    }

    public function testCanalCorrelacaoRegistraSomenteCamposLimitados(): void
    {
        $fonte = file_get_contents(__DIR__ . '/../../deploy/nginx/bots-correlacao.conf');
        self::assertIsString($fonte);
        self::assertSame(1, preg_match('/^log_format bots_correlacao escape=json (.+);$/m', $fonte, $formato));
        preg_match_all('/\$[a-zA-Z_][a-zA-Z0-9_]*/', $formato[1], $variaveis);
        self::assertSame([
            '$time_iso8601', '$server_name', '$status', '$body_bytes_sent',
            '$request_time', '$upstream_response_time', '$upstream_status',
            '$bots_correlacao_cf_ray', '$request_id',
        ], $variaveis[0]);
        self::assertStringContainsString('"~^[A-Fa-f0-9]{16,32}-[A-Z]{3}$" $http_cf_ray;', $fonte);
        self::assertMatchesRegularExpression('/map \$http_cf_ray \$bots_correlacao_cf_ray \{\s*default "";/s', $fonte);
        self::assertMatchesRegularExpression('/map \$server_name \$bots_correlacao_ativo \{\s*default 0;/s', $fonte);
        preg_match_all('/^\s+([a-z0-9.-]+) 1;$/m', $fonte, $nomes);
        self::assertSame([self::SERVER_NAME], $nomes[1]);
        self::assertStringContainsString(
            'access_log /var/log/nginx/bots-correlacao.json.log bots_correlacao if=$bots_correlacao_ativo;',
            $fonte,
        );
    }

    public function testCanalAdicionalPreservaLogLegadoESondas(): void
    {
        $fonte = self::fonte();
        preg_match_all('/^\s*access_log (?!off;)([^;]+);$/m', $fonte, $logs);
        self::assertSame([], $logs[1]);
        foreach (['/health', '/ready'] as $rota) {
            self::assertMatchesRegularExpression(
                '/' . preg_quote("location = {$rota} {", '/')
                . '(?:(?!^\s*location\b).)*?access_log off;/ms',
                $fonte,
            );
        }
    }
}
