<?php

declare(strict_types=1);

/**
 * Envia ao usuário uma mensagem respondendo à mensagem original. Todos os
 * avisos deste comando (imagem ausente, cota estourada, falha ao obter o
 * arquivo e falha da API) e o reenvio sem Markdown usam exatamente estes
 * parâmetros, que antes estavam repetidos em cada ramo.
 */
function avisarUsuarioImagem(Telegram $telegram, string $texto): void
{
    $telegram->sendMessage([
        'chat_id' => $telegram->ChatID(),
        'text' => $texto,
        'reply_parameters' => json_encode(['message_id' => $telegram->MessageID(), 'allow_sending_without_reply' => true]),
    ]);
}

/**
 * Imagem da mensagem recebida, na maior resolução disponível (o Telegram
 * envia a lista de tamanhos em ordem crescente).
 *
 * 'tem_imagem' falso significa mensagem sem foto alguma; 'file_id' nulo com
 * 'tem_imagem' verdadeiro significa foto presente sem identificador utilizável.
 *
 * @param array<array-key, mixed> $mensagem_recebida
 * @return array{tem_imagem: bool, file_id: string|null}
 */
function imagemDaMensagem(array $mensagem_recebida): array
{
    // Ausente, de tipo errado e lista vazia sempre significaram a mesma coisa
    // aqui; representar os três por [] deixa a comparação abaixo ser estrita.
    $photo = isset($mensagem_recebida['photo']) && is_array($mensagem_recebida['photo'])
        ? $mensagem_recebida['photo']
        : [];

    if ($photo === []) {
        return ['tem_imagem' => false, 'file_id' => null];
    }

    // Obter a maior resolução disponível da imagem
    $ultima_foto = end($photo);
    $photo_file_id = is_array($ultima_foto) && isset($ultima_foto['file_id']) && is_string($ultima_foto['file_id'])
        ? $ultima_foto['file_id']
        : null;

    return ['tem_imagem' => true, 'file_id' => $photo_file_id];
}

/**
 * Teto do arquivo de imagem baixado do Telegram, em bytes.
 *
 * A documentação atual da visão da OpenAI permite até 512 MB no payload total,
 * mas o bot mantém um teto local menor para limitar memória, custo e latência.
 * O base64 do data: URI custa 4/3 do binário: 10 MiB de imagem viram ~13,4 MB
 * de corpo, com margem para o resto do JSON (prompt de desenvolvedor, legenda e até
 * 8 turnos de histórico). Foto do Telegram é comprimida e quase nunca passa
 * de algumas centenas de KB — o teto existe para o caso patológico, não para
 * o normal.
 */
function limiteDeBytesDaImagem(): int
{
    return 10 * 1024 * 1024;
}

/**
 * Baixa a imagem NO PRÓPRIO SERVIDOR e devolve um data: URI em base64.
 *
 * É o que impede o vazamento do TOKEN_BOT. A URL de download do Telegram é
 * `https://api.telegram.org/file/bot<TOKEN_BOT>/<file_path>`: mandá-la como
 * `image_url.url` entregava o token COMPLETO a um terceiro em toda mensagem
 * com foto — é ele que a OpenAI usava para buscar o arquivo, e ele fica no que
 * quer que ela retenha. Quem tem esse valor controla o bot inteiro: lê todas as
 * mensagens, escreve como ele e troca o webhook. O caminho do áudio
 * (comandos/ver_audio.php) nunca vazou porque sempre baixou o arquivo e subiu
 * só o binário; aqui a imagem passa a fazer o mesmo.
 *
 * Nada é gravado em disco: o base64 vive em memória e some com a requisição.
 * O comando de imagem continua sem arquivo temporário para vazar num erro
 * (travado por ComandosVerImagemFluxoTest::testComandoDeImagemNaoCriaArquivoTemporario).
 *
 * @param string $image_url URL do arquivo em api.telegram.org
 * @param callable|null $baixadorHttp Substitui o download nos testes:
 *        fn(string $url): mixed. Nulo (padrão) é o caminho de produção.
 * @return array{data_uri: string, erro: null}|array{data_uri: null, erro: string}
 */
function baixarImagemComoDataUri(string $image_url, ?callable $baixadorHttp = null): array
{
    $limite = limiteDeBytesDaImagem();
    $bytes = $baixadorHttp === null
        ? baixarArquivoDoTelegram($image_url, $limite + 1)
        : $baixadorHttp($image_url);

    // Comparação com '' e não empty(): corpo vazio é download falho, não
    // imagem de zero byte — mesma guarda de baixarAudioParaArquivoTemporario().
    if (!is_string($bytes) || $bytes === '') {
        return ['data_uri' => null, 'erro' => "Erro ao baixar a imagem. Tente novamente! ❌"];
    }

    if (strlen($bytes) > $limite) {
        return [
            'data_uri' => null,
            'erro' => "Essa imagem é grande demais para eu analisar. Envie uma menor! 🖼️",
        ];
    }

    // Tipo pelos BYTES, nunca pela extensão: ver App\Support\FormatoDeImagem.
    // FQCN em vez de `use` porque este arquivo só pode ter declarações fora
    // das funções (FuncoesDeComandoSaoInertesTest).
    $mime = \App\Support\FormatoDeImagem::tipoMime($bytes);
    if ($mime === null) {
        return [
            'data_uri' => null,
            'erro' => "Não reconheci o formato dessa imagem. Tente JPEG, PNG, GIF ou WebP! 🖼️",
        ];
    }

    return ['data_uri' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'erro' => null];
}
