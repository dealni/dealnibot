<?php

declare(strict_types=1);

// ============================================================================
// bot.php — long polling (desenvolvimento). Esqueleto canônico da família.
//
// PONTOS CANÔNICOS OBRIGATÓRIOS:
//   1. ordem dos require, com vendor/autoload.php PRIMEIRO;
//   2. executarLongPollingTelegram() em vez do laço `for` inline — confirmação
//      do offset SÓ depois do lote inteiro, reentrega após restart;
//   3. `new bdTelegram()` POR UPDATE, dentro do callback e dentro de
//      try/catch (o construtor canônico lança em falha de conexão);
//   4. $redis/$telegram e as instâncias caras criados UMA VEZ, fora do laço;
//   5. as variáveis de contexto ($dados_recebidos, $texto_recebido,
//      $tipo_de_update, $id_do_chat_atual) NÃO são preenchidas aqui: o
//      roteador.php as atribui ele mesmo no topo.
// ============================================================================

// Inclui os arquivos necessários que contêm as classes e funções usadas no código
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/inclusos/Telegram.php';
require_once __DIR__ . '/inclusos/dados_bot.php';
require_once __DIR__ . '/inclusos/bdTelegram.php';
require_once __DIR__ . '/inclusos/funcoes.php';
require_once __DIR__ . '/inclusos/funcoes_polling.php';

// Processo de terminal: STDERR JSON ligado independente de LOGS_ATIVO.
iniciarLoggerCli();

$telegram = new Telegram(TOKEN_BOT);
$redisConectado = conectarRedis();

// Desenvolvimento sem webhook: aguarda continuamente por novos lotes. O
// helper só envia ao Telegram o offset de confirmação depois que o lote
// anterior terminou; uma falha no roteador permite a reentrega após o restart.
//
// A falha de banco NÃO chama ecoarEEncerrar() dentro do callback: o `exit`
// dele faria o shutdown do helper entender a saída como fluxo normal e
// confirmar o offset, e o update se perderia. Ela atravessa o helper, que não
// confirma nada em exceção, e só então encerra com a mensagem. Qualquer outra
// exceção segue sem tratamento, com o rastro completo, como sempre seguiu.
$falhaDoBanco = new class () {
    public ?Throwable $excecao = null;
};
try {
    executarLongPollingTelegram(
        $telegram,
        static function (Telegram $telegram) use (
            $redisConectado,
            $falhaDoBanco,
        ): void {
            // Um `request_id` por UPDATE, e não por execução do bot: aqui o
            // processo é reaproveitado entre updates (é o ponto do polling), então
            // um id só marcaria a sessão inteira e o campo deixaria de separar um
            // atendimento do seguinte — que é a única coisa que ele serve para
            // fazer. `reset()` devolve o ciclo ao estado de "ainda não resolvido";
            // o próximo evento logado gera o id novo sozinho.
            App\Support\ContextoDeExecucao::reset();

            // Nomes de variável esperados pelo roteador e pelos scripts incluídos.
            $redis = $redisConectado;

            // Uma conexão de banco por update.
            try {
                $bd = new bdTelegram();
                $telegram->acompanharContatos($bd);
            } catch (Throwable $e) {
                $falhaDoBanco->excecao = $e;
                throw $e;
            }

            // Processa a atualização (mesmo roteamento do bot_web.php)
            require __DIR__ . '/roteador.php';
        }
    );
} catch (Throwable $e) {
    if ($e !== $falhaDoBanco->excecao) {
        throw $e;
    }
    // O helper já encerrou o seu estado no `finally`; só agora é seguro
    // formatar a mensagem e sair sem confirmar o offset pendente.
    ecoarEEncerrar($e->getMessage());
}
