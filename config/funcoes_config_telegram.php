<?php

declare(strict_types=1);

/**
 * Executa somente quando chamado pelos entrypoints administrativos.
 * POST, TLS e limites são comuns ao registro e à remoção do webhook.
 *
 * @param array<string, mixed>|null $campos null preserva o POST sem corpo da remoção
 * @return array{response: string|bool, httpCode: int, erro_curl: string}
 */
function config_telegram_requisicao(string $url, ?array $campos = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, 'Erro: não foi possível iniciar o cURL.' . PHP_EOL);
        exit(1);
    }

    if (!config_telegram_aplicar_opcoes($ch, $campos)) {
        // O detalhe do libcurl (quando há) diz QUAL opção foi recusada.
        $detalhe = curl_errno($ch) !== 0 ? ' (' . curl_error($ch) . ')' : '';
        fwrite(STDERR, 'Erro: não foi possível configurar o cURL' . $detalhe . '.' . PHP_EOL);
        exit(1);
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro_curl = curl_errno($ch) !== 0 ? curl_error($ch) : '';

    return ['response' => $response, 'httpCode' => $httpCode, 'erro_curl' => $erro_curl];
}

/** @return array{saida: string, codigo: int} */
function config_telegram_resultado(mixed $response, string $erroCurl, int $httpCode, string $acao, string $sucesso): array
{
    // Erro de transporte prevalece mesmo quando há um corpo parcial com ok=true.
    if (!is_string($response) || $erroCurl !== '') {
        return ['saida' => "Falha ao {$acao} o webhook: erro de cURL ({$erroCurl})" . PHP_EOL, 'codigo' => 1];
    }
    try {
        $dados = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        $dados = null;
    }
    if ($httpCode >= 200 && $httpCode < 300 && is_array($dados) && ($dados['ok'] ?? null) === true) {
        return ['saida' => $sucesso . PHP_EOL, 'codigo' => 0];
    }
    $descricao = is_array($dados) && isset($dados['description']) && is_string($dados['description'])
        ? $dados['description'] : $response;
    return ['saida' => "Falha ao {$acao} o webhook (HTTP {$httpCode}). Resposta do Telegram: {$descricao}" . PHP_EOL, 'codigo' => 1];
}

/** @param array<string, mixed>|null $campos */
function config_telegram_aplicar_opcoes(\CurlHandle $ch, ?array $campos): bool
{
    if (
        !curl_setopt($ch, CURLOPT_POST, true)
        || ($campos !== null && !curl_setopt($ch, CURLOPT_POSTFIELDS, $campos))
        || !curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false)
        || !curl_setopt($ch, CURLOPT_RETURNTRANSFER, true)
        || !curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5)
        || !curl_setopt($ch, CURLOPT_TIMEOUT, 15)
        // Deixe a política TLS explícita: não dependa dos defaults da libcurl.
        || !curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true)
        || !curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2)
        // Sem sinais do resolvedor: o timeout não pode derrubar o processo.
        || !curl_setopt($ch, CURLOPT_NOSIGNAL, true)
    ) {
        return false;
    }
    return true;
}
