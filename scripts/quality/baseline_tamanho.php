<?php

declare(strict_types=1);

namespace Quality;

use JsonException;
use UnexpectedValueException;

require_once __DIR__ . '/quality_gates.php';

/* Persistência exclusiva do ratchet de tamanho e complexidade. */

/**
 * `CHATBOTS_BASELINE` é sobrescrita de TESTE, no mesmo padrão de
 * a execução isolada: sem ela a suíte gravaria no baseline de verdade e o
 * portão passaria a aprovar o que ele mesmo acabou de escrever.
 */
function caminhoDoBaseline(): string
{
    $sobrescrita = getenv('CHATBOTS_BASELINE');
    if (is_string($sobrescrita) && $sobrescrita !== '') {
        return $sobrescrita;
    }

    return dirname(__DIR__, 2) . '/tools/quality/tamanho-baseline.json';
}

/**
 * Baseline inexistente devolve seção vazia em vez de erro: é o estado do
 * primeiro `--atualizar-baseline`, e do repositório que ainda não mediu.
 *
 * @return array<string, mixed>
 */
function lerBaseline(): array
{
    $arquivo = caminhoDoBaseline();
    if (!is_file($arquivo)) {
        return [];
    }

    $conteudo = file_get_contents($arquivo);
    if (!is_string($conteudo)) {
        throw new UnexpectedValueException('Não foi possível ler ' . basename($arquivo) . '.');
    }

    try {
        $dados = json_decode($conteudo, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $erro) {
        throw new UnexpectedValueException(
            basename($arquivo) . ' não é JSON válido: ' . $erro->getMessage(),
        );
    }

    if (!is_array($dados)) {
        throw new UnexpectedValueException(basename($arquivo) . ' não contém um objeto.');
    }

    /** @var array<string, mixed> $dados */
    return $dados;
}

/**
 * @param array<string, mixed> $secao
 */
function gravarSecaoDoBaseline(string $chave, array $secao): void
{
    $baseline = lerBaseline();
    $secao['medido_em'] = date('Y-m-d');
    $baseline[$chave] = $secao;
    ksort($baseline);

    $json = json_encode(
        $baseline,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    );

    if (file_put_contents(caminhoDoBaseline(), $json . "\n") === false) {
        throw new UnexpectedValueException('Não foi possível gravar ' . basename(caminhoDoBaseline()) . '.');
    }
}

/**
 * No CI o baseline é só leitura.
 *
 * Não é zelo: se o CI regravasse, o portão passaria a aprovar o que ele mesmo
 * acabou de medir, e deixaria de ser portão. Melhoria não commitada apenas
 * espera a próxima execução local — não reprova ninguém.
 */
function baselineEhSomenteLeitura(): bool
{
    $ci = getenv('CI');

    return is_string($ci) && $ci !== '' && $ci !== '0' && $ci !== 'false';
}

/**
 * Baseline editado à mão pode chegar aqui com chave faltando ou com `null`; o
 * portão precisa dizer isso em vez de tratar o ausente como zero e aprovar
 * qualquer placar.
 *
 * @param array<mixed> $secao
 * @return array{numero: float, inteiro: int}
 */
function numeroDoBaseline(array $secao, string $chave): array
{
    $valor = $secao[$chave] ?? null;
    if (!is_int($valor) && !is_float($valor)) {
        throw new UnexpectedValueException(
            "tools/quality/tamanho-baseline.json: '{$chave}' ausente ou não numérico. "
            . 'Regrave o baseline.',
        );
    }

    return ['numero' => (float) $valor, 'inteiro' => (int) $valor];
}
