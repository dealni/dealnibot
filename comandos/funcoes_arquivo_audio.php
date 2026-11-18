<?php

declare(strict_types=1);

/**
 * Envia ao usuário uma mensagem respondendo à mensagem original. Todos os
 * avisos deste comando (áudio ausente, cota estourada, falha do download, da
 * transcrição e da API) e o reenvio sem Markdown usam exatamente estes
 * parâmetros, que antes estavam repetidos em cada ramo.
 */
function avisarUsuarioAudio(Telegram $telegram, string $texto): void
{
    $telegram->sendMessage([
        'chat_id' => $telegram->ChatID(),
        'text' => $texto,
        'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
    ]);
}

/**
 * Áudio da mensagem recebida. O voice (gravado no Telegram) tem prioridade
 * sobre o audio (arquivo enviado), como o comando sempre fez.
 *
 * 'tem_audio' falso significa mensagem sem áudio algum; 'file_id' nulo com
 * 'tem_audio' verdadeiro significa áudio presente sem identificador utilizável.
 *
 * @param array<array-key, mixed> $mensagem_recebida
 * @return array{tem_audio: bool, file_id: string|null}
 */
function audioDaMensagem(array $mensagem_recebida): array
{
    // Ausente, de tipo errado e objeto vazio sempre significaram a mesma coisa
    // aqui; representar os três por [] deixa a comparação abaixo ser estrita.
    $voice = isset($mensagem_recebida['voice']) && is_array($mensagem_recebida['voice'])
        ? $mensagem_recebida['voice']
        : [];
    $audio = isset($mensagem_recebida['audio']) && is_array($mensagem_recebida['audio'])
        ? $mensagem_recebida['audio']
        : [];

    if ($voice === [] && $audio === []) {
        return ['tem_audio' => false, 'file_id' => null];
    }

    // Obter o file_id do áudio (voice tem prioridade)
    $audio_file_id = $voice !== [] ? ($voice['file_id'] ?? null) : ($audio['file_id'] ?? null);

    return ['tem_audio' => true, 'file_id' => is_string($audio_file_id) ? $audio_file_id : null];
}

/**
 * Extrai a duração em segundos do áudio/voice da mensagem recebida.
 * Devolve null se a duração não estiver disponível ou não for inteira.
 *
 * @param array<array-key, mixed> $mensagem_recebida
 */
function duracaoDoAudio(array $mensagem_recebida): ?int
{
    $voice = isset($mensagem_recebida['voice']) && is_array($mensagem_recebida['voice'])
        ? $mensagem_recebida['voice']
        : [];
    $audio = isset($mensagem_recebida['audio']) && is_array($mensagem_recebida['audio'])
        ? $mensagem_recebida['audio']
        : [];

    $duracao = $voice !== [] ? ($voice['duration'] ?? null) : ($audio['duration'] ?? null);

    return is_int($duracao) ? $duracao : null;
}

/** Teto de duração de áudio em segundos (3 minutos). */
function limiteDeSegundosDoAudio(): int
{
    return 180;
}

/**
 * Remove o arquivo temporário do áudio. Usada no fim da transcrição e também
 * nos caminhos de erro: um temporário deixado para trás nunca mais sai do
 * disco. O is_file() evita warning quando o arquivo já não existe.
 */
function removerAudioTemporario(string $caminho): void
{
    if (is_file($caminho)) {
        unlink($caminho);
    }
}

/** Teto local independente dos limites do provedor. */
function limiteDeBytesDoAudio(): int
{
    return 20 * 1024 * 1024;
}

/**
 * Baixa o áudio do Telegram para um arquivo temporário e confirma a gravação
 * em disco antes de enviá-lo ao Whisper. Em qualquer falha devolve a mensagem
 * que o comando envia ao usuário e não deixa arquivo temporário para trás.
 * Corpo vazio conta como falha de download, e não como áudio de 0 byte.
 *
 * @param string $audio_url URL do arquivo no Telegram
 * @param callable|null $baixadorHttp Substitui o download nos testes:
 *        fn(string $url): mixed. Nulo (padrão) é o caminho de produção,
 *        com file_get_contents real.
 * @param string|null $diretorioTemporario Diretório do arquivo; nulo (padrão)
 *        usa sys_get_temp_dir(), como em produção.
 * @param callable|null $criadorTemporario Injeção exclusiva para testes de
 *        falha na criação: fn(string $diretorio, string $prefixo): string|false.
 * @return array{caminho: string, erro: null}|array{caminho: null, erro: string}
 */
function baixarAudioParaArquivoTemporario(
    string $audio_url,
    ?callable $baixadorHttp = null,
    ?string $diretorioTemporario = null,
    ?callable $criadorTemporario = null
): array {
    // baixarArquivoDoTelegram(), e não file_get_contents() cru: sem contexto de
    // stream o download herdava o `default_socket_timeout` do php.ini (60 s), o
    // único passo do fluxo sem teto declarado no código. Ver
    // segundosDeDownloadDoTelegram() em funcoes_ia_comuns.php.
    $limite = limiteDeBytesDoAudio();
    renovarLeaseDaInboxAtiva();
    $audio_content = $baixadorHttp === null
        ? baixarArquivoDoTelegram($audio_url, $limite + 1)
        : $baixadorHttp($audio_url);

    // Corpo vazio é download falho, não áudio de zero segundo: gravá-lo levaria
    // um arquivo de 0 byte para a chamada PAGA do GPT Transcribe, que responderia erro
    // de qualquer jeito. Comparação com '' e não empty(): um corpo de um único
    // byte "0" é conteúdo binário legítimo e precisa continuar passando.
    if (!is_string($audio_content) || $audio_content === '') {
        return ['caminho' => null, 'erro' => "Erro ao baixar o áudio. Tente novamente! ❌"];
    }

    if (strlen($audio_content) > $limite) {
        return ['caminho' => null, 'erro' => "Esse áudio é grande demais. Envie até 20 MiB. 🎤"];
    }

    $diretorio = $diretorioTemporario ?? sys_get_temp_dir();
    $temp_audio_path = $criadorTemporario === null
        ? tempnam($diretorio, 'dealnibot_audio_')
        : $criadorTemporario($diretorio, 'dealnibot_audio_');
    if (!is_string($temp_audio_path)) {
        return ['caminho' => null, 'erro' => "Erro ao preparar o áudio para processamento. Tente novamente! ❌"];
    }
    // tempnam cria o arquivo de forma exclusiva; reforça 0600 para o conteúdo da
    // conversa nunca depender da umask do host compartilhado.
    if (!chmod($temp_audio_path, 0o600)) {
        removerAudioTemporario($temp_audio_path);

        return ['caminho' => null, 'erro' => "Erro ao proteger o áudio para processamento. Tente novamente! ❌"];
    }

    // Verificar se o áudio foi realmente gravado em disco antes de enviá-lo ao GPT Transcribe
    if (file_put_contents($temp_audio_path, $audio_content, LOCK_EX) === false) {
        // A escrita pode ter criado o arquivo e falhado no meio (disco cheio,
        // cota estourada): sem esta remoção o temporário vazaria no erro.
        removerAudioTemporario($temp_audio_path);

        return ['caminho' => null, 'erro' => "Erro ao salvar o áudio para processamento. Tente novamente! ❌"];
    }

    return ['caminho' => $temp_audio_path, 'erro' => null];
}


/** @param array<array-key, mixed> $mensagem */
function validarAudioDoComando(Telegram $telegram, array $mensagem, bool $temAudio): bool
{
    if (!$temAudio) {
        avisarUsuarioAudio($telegram, "Por favor, envie um áudio para que eu possa transcrevê-lo! 🎤");
        return false;
    }
    $duracao = duracaoDoAudio($mensagem);
    if ($duracao !== null && $duracao > limiteDeSegundosDoAudio()) {
        avisarUsuarioAudio($telegram, "Esse áudio é longo demais. Envie áudios de até 3 minutos. 🎤");
        return false;
    }
    return true;
}
