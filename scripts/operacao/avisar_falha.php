<?php

declare(strict_types=1);

/**
 * Avisa no Telegram o chat de DEALNI_ALERTA_CHAT_ID que uma rotina agendada
 * falhou, ou que voltou a funcionar depois de uma falha.
 *
 * Uso: php scripts/operacao/avisar_falha.php --job=NOME --codigo=N [--log=ARQUIVO]
 *
 * Quem chama é scripts/operacao/avisar_se_falhar.sh, depois do job. Com código
 * 0 e sem falha anterior, sai sem carregar a configuração do bot. Sem
 * DEALNI_ALERTA_CHAT_ID o aviso fica desligado: a falha só deixa uma linha no
 * STDERR. A lógica está em inclusos/funcoes_aviso_de_falha.php.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) ---------
// Nome do sistema na mensagem, variável que muda o diretório de estado (a
// mesma do monitorar_saude.sh; padrão /var/lib/<sistema>) e variável opcional
// com o chat que recebe o aviso.
$sistema = 'dealnibot';
$variavelDoEstado = 'DEALNI_MONITOR_STATE_DIR';
$variavelDoChat = 'DEALNI_ALERTA_CHAT_ID';
// --- FIM DOS DADOS; daqui para baixo o código é canônico ---------------------

$raiz = dirname(__DIR__, 2);
require_once $raiz . '/inclusos/funcoes_aviso_de_falha.php';

$brutos = $_SERVER['argv'] ?? [];
$argumentos = is_array($brutos) ? array_values(array_filter(array_slice($brutos, 1), 'is_string')) : [];
try {
    $opcoes = lerArgumentosDoAviso($argumentos);
} catch (InvalidArgumentException $erro) {
    fwrite(STDERR, $erro->getMessage() . PHP_EOL);
    exit(2);
}

$chat = getenv($variavelDoChat);
$chat = is_string($chat) ? trim($chat) : '';
if ($chat === '') {
    if ($opcoes['codigo'] !== 0) {
        fwrite(STDERR, "Aviso de {$opcoes['job']} desligado: configure {$variavelDoChat} no environment." . PHP_EOL);
    }
    exit(0);
}

$estado = getenv($variavelDoEstado);
$estado = is_string($estado) && $estado !== '' ? $estado : '/var/lib/' . $sistema;
$host = gethostname();

$enviar = static function (string $texto) use ($raiz, $chat): bool {
    try {
        // Só aqui a configuração é carregada: sucesso sem falha anterior não a lê.
        require_once $raiz . '/inclusos/dados_bot.php';

        return enviarAvisoTelegram(TOKEN_BOT, $chat, $texto);
    } catch (Throwable $erro) {
        fwrite(STDERR, 'Configuração do bot indisponível para o aviso: ' . $erro->getMessage() . PHP_EOL);

        return false;
    }
};

$resultado = processarAvisoDeFalha($opcoes, $sistema, is_string($host) ? $host : 'desconhecido', $estado, $enviar);
if ($resultado !== 0) {
    fwrite(STDERR, "Aviso de {$opcoes['job']} não foi entregue ao Telegram." . PHP_EOL);
}
exit($resultado);
