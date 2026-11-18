<?php

declare(strict_types=1);

namespace Quality;

require_once __DIR__ . '/quality_gates.php';

use Throwable;

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Variável de ambiente que traz o usuário do PostgreSQL deste bot.
 */
const VARIAVEL_USUARIO_POSTGRES = 'DEALNI_DB_USER';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

/**
 * Gate de configuração de produção (`composer check:config:production`).
 *
 * Carrega dados_bot.php, onde vivem credenciais, flags e PUBLIC_BASE_URL. O
 * arquivo só declara constantes e valida configuração: não abre banco ou rede.
 */
function mainConfiguracaoProducao(): int
{
    require_once __DIR__ . '/../../inclusos/banco.php';
    putenv('APP_ENV=production');
    putenv('CHATBOTS_TESTING=0');

    try {
        \dsnBancoDoBot();
        $usuarioPostgres = getenv(VARIAVEL_USUARIO_POSTGRES);
        if (\bancoUsaPostgres() && (!is_string($usuarioPostgres) || trim($usuarioPostgres) === '')) {
            throw new \RuntimeException('PostgreSQL exige ' . VARIAVEL_USUARIO_POSTGRES . '.');
        }
        if (\bancoUsaPostgres() && !extension_loaded('pdo_pgsql')) {
            throw new \RuntimeException('PostgreSQL exige a extensão pdo_pgsql.');
        }
        validarCarregamentoDeProducao(
            static function (): void {
                require_once __DIR__ . '/../../inclusos/dados_bot.php';
                // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ----
                // Este bot não tem protocolo de pagamento para validar no carregamento.
                // --- FIM DOS DADOS; daqui para baixo o código é canônico ----------------
            },
        );
    } catch (Throwable $erro) {
        fwrite(STDERR, mensagemDeThrowable($erro) . PHP_EOL);

        return 1;
    }

    fwrite(STDOUT, "Configuração de produção válida.\n");

    return 0;
}

if (executadoDiretamente(__FILE__)) {
    exit(mainConfiguracaoProducao());
}
