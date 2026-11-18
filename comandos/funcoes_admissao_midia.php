<?php

declare(strict_types=1);

/** @return array<array-key, mixed> */
function mensagemRecebidaDeMidia(Telegram $telegram): array
{
    $dados = $telegram->getData();
    return isset($dados['message']) && is_array($dados['message']) ? $dados['message'] : [];
}

/** @param array<array-key, mixed> $mensagem */
function tipoDeChatDaMidia(array $mensagem): ?string
{
    $chat = $mensagem['chat'] ?? null;
    $tipo = is_array($chat) ? ($chat['type'] ?? null) : null;
    return is_string($tipo) ? $tipo : null;
}

function caminhoDoArquivoDeMidia(Telegram $telegram, string $fileId): ?string
{
    $arquivo = $telegram->getFile($fileId);
    $resultado = $arquivo['result'] ?? null;
    $caminho = is_array($resultado) ? ($resultado['file_path'] ?? null) : null;
    return is_string($caminho) ? $caminho : null;
}

/** @param callable(Telegram, string): void $avisar */
function admitirMidiaDoComando(Redis $redis, Telegram $telegram, ?string $tipo, int $sujeito, int $unidades, callable $avisar): bool
{
    $admissao = admitir_midia($redis, $sujeito, $unidades);
    if ($admissao === 'permitida') {
        return true;
    }
    if ($tipo === 'private') {
        $avisar($telegram, $admissao === 'cota'
            ? "Você atingiu o limite de 100 requisições diárias. Tente novamente amanhã! 🙂"
            : "Muitas mídias em pouco tempo. Tente novamente em um minuto. 🙂");
    }
    return false;
}

/** Grupos mantêm a memória compartilhada no espelho legado. */
function idDoContextoDeMidia(Telegram $telegram, ?string $tipo, int $usuario): int
{
    if ($tipo === 'group' || $tipo === 'supergroup') {
        return $telegram->ChatID() ?? $usuario;
    }
    return $usuario;
}
