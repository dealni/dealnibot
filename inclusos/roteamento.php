<?php

declare(strict_types=1);

/**
 * Decisão de roteamento do roteador.php, isolada em funções PURAS.
 *
 * Nenhuma função aqui produz efeito colateral: elas apenas leem a atualização
 * (já extraída de $telegram->getData()) e devolvem DESCRITORES — o texto a
 * verificar, flags de mídia, a rota escolhida e o caminho relativo do comando
 * a incluir. Quem orquestra os efeitos (require do comando, exit/return,
 * registro do usuário e o sorteio rand() dos grupos) é o próprio roteador.php.
 *
 * Arquivo de DECLARAÇÕES apenas (sem código top-level): é seguro
 * require_once — como inclusos/funcoes.php — em bot.php, bot_web.php,
 * roteador.php e no bootstrap dos testes, e também é compatível com
 * tests/ExtratorDeFuncoesDeScript.
 */

/**
 * Extrai o objeto message da atualização.
 *
 * @param array<string, mixed> $dados Resultado de Telegram::getData()
 * @return array<array-key, mixed> A mensagem ou [] quando ausente/inválida
 */
function roteamento_extrairMensagem(array $dados): array
{
    return isset($dados['message']) && is_array($dados['message']) ? $dados['message'] : [];
}

/**
 * Extrai o objeto message aninhado em callback_query.
 *
 * @param array<string, mixed> $dados Resultado de Telegram::getData()
 * @return array<array-key, mixed> A mensagem do callback ou [] quando ausente
 */
function roteamento_extrairMensagemDoCallback(array $dados): array
{
    $callback = isset($dados['callback_query']) && is_array($dados['callback_query'])
        ? $dados['callback_query']
        : [];

    return isset($callback['message']) && is_array($callback['message']) ? $callback['message'] : [];
}

/**
 * Determina o chat da atualização: prioriza message.chat e cai para
 * callback_query.message.chat, exatamente como o roteador fazia.
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 * @param array<array-key, mixed> $callbackMessage Objeto callback_query.message
 * @return array<array-key, mixed> O chat ou [] quando ausente
 */
function roteamento_extrairChat(array $mensagem, array $callbackMessage): array
{
    if (isset($mensagem['chat']) && is_array($mensagem['chat'])) {
        return $mensagem['chat'];
    }
    if (isset($callbackMessage['chat']) && is_array($callbackMessage['chat'])) {
        return $callbackMessage['chat'];
    }

    return [];
}

/**
 * Tipo do chat ('private', 'group', 'supergroup', 'channel'...).
 *
 * @param array<array-key, mixed> $chat Objeto chat
 * @return string|null O tipo, ou null quando ausente/não textual
 */
function roteamento_tipoChat(array $chat): ?string
{
    return isset($chat['type']) && is_string($chat['type']) ? $chat['type'] : null;
}

/**
 * Indica se o bot está autorizado a atender o chat.
 *
 * O produto funciona somente em grupos e supergrupos. Chats privados,
 * canais e updates sem chat identificável são ignorados antes de qualquer
 * registro no banco ou chamada a comando.
 *
 * @param string|null $tipoChat Tipo devolvido pelo Telegram
 */
function roteamento_chatEhGrupo(?string $tipoChat): bool
{
    return $tipoChat === 'group' || $tipoChat === 'supergroup';
}

/**
 * Indica se a mensagem contém foto.
 *
 * O `empty()` que estava aqui juntava três coisas: campo ausente, `null` e
 * lista vazia. São exatamente essas três que o Telegram produz quando não há
 * foto, e só elas continuam valendo como "sem foto".
 *
 * A truthiness dos demais valores não se reproduz de propósito, e o custo é o
 * que decide: "com foto" termina em imagemDaMensagem(), que exige array e
 * apenas responde pedindo a imagem; "sem foto" cai no comando de texto, que é
 * chamada PAGA à OpenAI. Na dúvida sobre um campo malformado, o ramo barato.
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 */
function roteamento_temFoto(array $mensagem): bool
{
    return isset($mensagem['photo']) && $mensagem['photo'] !== [];
}

/**
 * Indica se a mensagem contém uma mensagem de voz.
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 */
function roteamento_temVoz(array $mensagem): bool
{
    // Mesma decisão de roteamento_temFoto.
    return isset($mensagem['voice']) && $mensagem['voice'] !== [];
}

/**
 * Indica se a mensagem contém um arquivo de áudio.
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 */
function roteamento_temAudio(array $mensagem): bool
{
    // Mesma decisão de roteamento_temFoto.
    return isset($mensagem['audio']) && $mensagem['audio'] !== [];
}

/**
 * Texto usado para detectar a menção pelo nome: para fotos usa o caption,
 * para as demais mensagens usa o próprio texto — sempre em minúsculas (UTF-8).
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 * @param string|null $textoRecebido Telegram::Text() da atualização
 * @param bool $temFoto Se a mensagem contém foto
 * @return string Texto normalizado em minúsculas ('' quando não há texto)
 */
function roteamento_textoParaVerificar(array $mensagem, ?string $textoRecebido, bool $temFoto): string
{
    if ($temFoto) {
        $caption = isset($mensagem['caption']) && is_string($mensagem['caption'])
            ? $mensagem['caption']
            : '';

        // mb_strtolower() substitui bytes UTF-8 inválidos silenciosamente.
        // Não normalize entrada malformada: fazê-lo pode transformar dados
        // arbitrários em texto de roteamento e é a classe de ataque que
        // mb_check_encoding() existe para impedir.
        return mb_check_encoding($caption, 'UTF-8')
            ? mb_strtolower($caption, 'UTF-8')
            : '';
    }

    $texto = $textoRecebido ?? '';

    return mb_check_encoding($texto, 'UTF-8')
        ? mb_strtolower($texto, 'UTF-8')
        : '';
}

/**
 * Indica se o texto (já normalizado por roteamento_textoParaVerificar) menciona
 * o bot pelo nome. Comparação sensível a maiúsculas: espera texto minúsculo.
 *
 * @param string $texto Texto já em minúsculas
 */
function roteamento_contemNome(string $texto, string $username = ''): bool
{
    return str_contains($texto, 'dealni') || ($username !== '' && str_contains($texto, strtolower($username)));
}

/**
 * Extrai reply_to_message da mensagem.
 *
 * @param array<array-key, mixed> $mensagem Objeto message
 * @return array<array-key, mixed>|null A resposta, ou null quando ausente
 */
function roteamento_extrairReply(array $mensagem): ?array
{
    return isset($mensagem['reply_to_message']) && is_array($mensagem['reply_to_message'])
        ? $mensagem['reply_to_message']
        : null;
}

/**
 * Indica se a mensagem respondida pertence ao ID do bot no token configurado.
 *
 * @param array<array-key, mixed> $reply Objeto reply_to_message
 */
function roteamento_replyEhDoBot(array $reply, string $token): bool
{
    $from = isset($reply['from']) && is_array($reply['from']) ? $reply['from'] : [];
    $id = $from['id'] ?? null;

    return is_int($id) && $id > 0 && (string) $id === strstr($token, ':', true);
}

/**
 * Indica se um update de fora do chat privado pode ser atendido.
 *
 * Fora do privado o roteador só olha para MENSAGEM — nunca para callback_query,
 * inline_query, edited_message ou channel_post. A checagem era
 * `$tipo != 'message'`, e Telegram::getUpdateType() só devolve 'message' quando
 * há `message.text`: mensagem com foto vira 'photo', com voz 'voice', com áudio
 * 'audio'. Em grupo o bot então só reagia a TEXTO — responder a ele com foto,
 * voz ou áudio, ou mandar foto com "dealni" na legenda, não disparava nada, e as
 * chamadas a roteamento_rotaPorMidia() dos ramos de reply e do sorteio eram
 * ramos mortos.
 *
 * Os quatro tipos aqui são exatamente os que os comandos sabem atender. Vídeo,
 * documento, sticker e companhia continuam de fora de propósito: cairiam no
 * chatgpt com texto vazio, virando chamada paga por um sticker.
 *
 * Os valores são os de Telegram::MESSAGE/PHOTO/VOICE/AUDIO — escritos como
 * literal para este arquivo seguir sem depender de classe nenhuma;
 * RoteadorRotasTest passa as constantes de verdade e trava a igualdade.
 *
 * @param string|null $tipoDeUpdate Telegram::getUpdateType()
 */
function roteamento_updateAtendivelForaDoPrivado(?string $tipoDeUpdate): bool
{
    return in_array($tipoDeUpdate, ['message', 'photo', 'voice', 'audio'], true);
}

/**
 * Rota do comando conforme a mídia da mensagem: foto > voz/áudio > texto.
 *
 * @param bool $temFoto Se há foto
 * @param bool $temVoz Se há voz
 * @param bool $temAudio Se há áudio
 * @return string 'imagem', 'audio' ou 'chatgpt'
 */
function roteamento_rotaPorMidia(bool $temFoto, bool $temVoz, bool $temAudio): string
{
    if ($temFoto) {
        return 'imagem';
    }
    if ($temVoz || $temAudio) {
        return 'audio';
    }

    return 'chatgpt';
}

/**
 * Rota da menção pelo nome: só a foto é tratada como imagem; qualquer outra
 * mensagem (inclusive voz/áudio) segue para o chatgpt — como no roteador.
 *
 * @param bool $temFoto Se há foto
 * @return string 'imagem' ou 'chatgpt'
 */
function roteamento_rotaPorMidiaFoto(bool $temFoto): string
{
    return $temFoto ? 'imagem' : 'chatgpt';
}

/**
 * Caminho relativo (a partir da raiz do bot) do comando de uma rota.
 *
 * @param string $rota Uma das rotas conhecidas
 * @return string Caminho relativo do arquivo a incluir
 * @throws InvalidArgumentException Para uma rota desconhecida (nunca ocorre
 *                                  com as rotas produzidas pelas funções acima)
 */
function roteamento_arquivoDaRota(string $rota): string
{
    switch ($rota) {
        case 'imagem':
            return 'comandos/ver_imagem.php';
        case 'audio':
            return 'comandos/ver_audio.php';
        case 'chatgpt':
            return 'comandos/chatgpt.php';
        default:
            throw new InvalidArgumentException("Rota de comando desconhecida: {$rota}.");
    }
}
