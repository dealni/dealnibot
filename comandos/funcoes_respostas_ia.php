<?php

declare(strict_types=1);

/** @return array{tipo: 'resposta'|'erro_api'|'falha', texto: string} */
function interpretarRespostaDeChat(mixed $response, string $operacao, string $erroPublico): array
{
    if (!is_string($response)) {
        return ['tipo' => 'falha', 'texto' => $erroPublico];
    }
    $dados = json_decode($response, true);
    $dados = is_array($dados) ? $dados : [];
    $escolhas = is_array($dados['choices'] ?? null) ? $dados['choices'] : [];
    $primeira = is_array($escolhas[0] ?? null) ? $escolhas[0] : [];
    $erro = $dados['error'] ?? $primeira['error'] ?? null;
    if ($erro !== null) {
        registrarErroDaApiOpenAi($operacao, mensagemPrivadaDeErroDeChat($erro));
        return ['tipo' => 'erro_api', 'texto' => $erroPublico];
    }
    $conteudo = conteudoDaPrimeiraRespostaDeChat($dados);
    if (!is_string($conteudo) || trim($conteudo) === '') {
        return ['tipo' => 'falha', 'texto' => $erroPublico];
    }
    return ['tipo' => 'resposta', 'texto' => cortarMensagem($conteudo)];
}

/** Detalhe do provedor jamais é enviado ao usuário. */
function mensagemPrivadaDeErroDeChat(mixed $erro): string
{
    $mensagem = is_array($erro) ? ($erro['message'] ?? null) : null;
    return is_scalar($mensagem) ? (string) $mensagem : '';
}

/** @param array<array-key, mixed> $dados */
function conteudoDaPrimeiraRespostaDeChat(array $dados): mixed
{
    $escolhas = $dados['choices'] ?? null;
    if (!is_array($escolhas)) {
        return null;
    }
    $primeira = $escolhas[0] ?? null;
    if (!is_array($primeira)) {
        return null;
    }
    $mensagem = $primeira['message'] ?? null;
    return is_array($mensagem) ? ($mensagem['content'] ?? null) : null;
}
