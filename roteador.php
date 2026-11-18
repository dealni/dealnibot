<?php

declare(strict_types=1);

/**
 * Roteador compartilhado entre bot.php (polling/dev) e bot_web.php (webhook/produção).
 * Processa UMA atualização do Telegram, garantindo comportamento idêntico nos dois modos.
 *
 * A DECISÃO de roteamento (o que extrair da atualização e qual comando cada
 * combinação dispara) vive em funções PURAS de inclusos/roteamento.php. Este
 * script mantém apenas os EFEITOS: leituras via $telegram, o registro do
 * usuário em $bd, o require do comando escolhido, os terminadores (`return`)
 * e o sorteio rand() dos grupos — chamado no mesmo ponto de antes para
 * preservar o consumo do gerador.
 *
 * Todo fim de fluxo usa `return`, nunca `exit`: quem inclui este arquivo é
 * bot.php, DENTRO do callback que executarLongPollingTelegram()
 * (inclusos/funcoes_polling.php) chama uma vez por update do lote. O offset de
 * confirmação só é enviado ao Telegram DEPOIS que o lote inteiro passou por
 * aqui, então um `exit` no meio derruba o processo com o lote ainda por
 * confirmar: as mensagens já atendidas seriam reentregues e respondidas de
 * novo (chamada paga à OpenAI cobrada duas vezes), e o laço de polling morre
 * até o bot.sh reiniciá-lo. A regra vale ainda mais do que quando o lote era
 * confirmado antecipadamente. Para o bot_web.php nada muda: o require do
 * roteador é a última linha do arquivo.
 * Contrato travado em InfraRoteadorPollingTest.
 *
 * Espera no escopo de quem inclui apenas as dependências já inicializadas:
 *   $telegram, $bd e $redis.
 */

require_once __DIR__ . '/inclusos/config_loader.php';
require_once __DIR__ . '/inclusos/roteamento.php';
require_once __DIR__ . '/inclusos/funcoes_recusa_de_escrita.php';

if (
    !isset($telegram, $bd, $redis)
    || !$telegram instanceof Telegram
    || !$bd instanceof bdTelegram
    || !$redis instanceof Redis
) {
    throw new LogicException('Contexto inválido para o roteador.');
}

$dados_recebidos = $telegram->getData();
$telegram->acompanharContatos($bd);
$telegram->observarInteracaoContato($dados_recebidos);
$id_telegram = $telegram->UserID();
$texto_recebido = $telegram->Text();
$tipo_de_update = $telegram->getUpdateType();

$mensagem_recebida = roteamento_extrairMensagem($dados_recebidos);
$callbackMessage = roteamento_extrairMensagemDoCallback($dados_recebidos);
$chatRecebido = roteamento_extrairChat($mensagem_recebida, $callbackMessage);
$chatType = roteamento_tipoChat($chatRecebido);

// O bot RESPONDE somente em grupos e supergrupos: nenhuma cota é consumida e
// nenhum comando é carregado fora deles.
//
// O bot registra contatos privados e orienta a conversar em grupos.
if (!roteamento_chatEhGrupo($chatType)) {
    if ($chatType === 'private' && $id_telegram !== null && $id_telegram > 0) {
        if (!$bd->registrarContato($id_telegram)) {
            throw new RuntimeException('Não foi possível registrar o contato.');
        }
        $telegram->sendMessage([
            'chat_id' => $telegram->ChatID(),
            'text' => 'O assistente responde em grupos. Adicione o bot ao seu grupo para conversar.',
        ]);
    }

    return;
}

$temFoto = roteamento_temFoto($mensagem_recebida);
$temVoz = roteamento_temVoz($mensagem_recebida);
$temAudio = roteamento_temAudio($mensagem_recebida);

if (!roteamento_updateAtendivelForaDoPrivado($tipo_de_update)) {
    return;
}

// Grupo que tirou do bot o direito de escrever: a recusa fica lembrada no
// Redis (inclusos/funcoes_recusa_de_escrita.php) e, até a chave vencer, nenhum
// comando roda nem a Bot API é chamada para esse chat.
if (chatSemPermissaoDeEscrita($redis, $telegram->ChatID())) {
    return;
}

// Resposta direta: só é atendida quando responde a uma mensagem do próprio bot
$reply_to_message = roteamento_extrairReply($mensagem_recebida);
if ($reply_to_message !== null) {
    if (!roteamento_replyEhDoBot($reply_to_message, defined('TOKEN_BOT') ? TOKEN_BOT : '')) {
        return;
    }
    $rota = roteamento_rotaPorMidia($temFoto, $temVoz, $temAudio);
    require __DIR__ . '/' . roteamento_arquivoDaRota($rota);
    return;
}

// Menção pelo nome: fotos usam o caption; as demais mensagens usam o texto
$texto_para_verificar = roteamento_textoParaVerificar($mensagem_recebida, $texto_recebido, $temFoto);
if (roteamento_contemNome($texto_para_verificar, telegramUsernameConfigurado())) {
    $rota = roteamento_rotaPorMidiaFoto($temFoto);
    require __DIR__ . '/' . roteamento_arquivoDaRota($rota);
    return;
}

// Mensagens aleatórias em grupos e supergrupos com 10% de chance.
if (rand(1, 10) === 1) {
    $rota = roteamento_rotaPorMidia($temFoto, $temVoz, $temAudio);
    require __DIR__ . '/' . roteamento_arquivoDaRota($rota);
}

return;
