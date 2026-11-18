<?php

declare(strict_types=1);

// Bundle contém somente ferramentas versionadas; nunca inclui ambiente ou dados.
const RAIZ_BUNDLES = '/var/lib/chatbots-publicacao';
const RAIZ_CHECKOUTS = '/var/www';
const UID_BUNDLE = 0;
const ARQUIVOS_BUNDLE = [
    'scripts/deploy/rollback.sh',
    'scripts/deploy/deploy_verify.sh',
    'scripts/deploy/registrar_publicacao.sh',
    'scripts/deploy/publicacao_operacao.sh',
    'scripts/deploy/publicacao_agendamentos.sh',
    'scripts/deploy/publicacao_processos.php',
    'scripts/deploy/publicacao_recuperacao.sh',
    'scripts/deploy/publicacao_bundle.php',
    'scripts/deploy/recuperar_publicacao.sh',
    'scripts/quality/validar_sonda_publicada.php',
];

/** @return array{bot: string, projeto: string, alvo: string, origem_sha: string, ferramentas_sha: string, operacao: string, estado: string, arquivos: array<string, string>} */
function lerBundle(string $diretorio): array
{
    if (!is_dir(RAIZ_BUNDLES) || is_link(RAIZ_BUNDLES)
        || fileowner(RAIZ_BUNDLES) !== UID_BUNDLE || (fileperms(RAIZ_BUNDLES) & 0777) !== 0700
        || !is_dir($diretorio) || is_link($diretorio) || realpath($diretorio) !== $diretorio
        || dirname($diretorio) !== RAIZ_BUNDLES
        || fileowner($diretorio) !== UID_BUNDLE || (fileperms($diretorio) & 0777) !== 0700) {
        throw new RuntimeException('Diretório privado de recuperação inválido.');
    }
    $arquivo = $diretorio . '/manifesto.json';
    if (!is_file($arquivo) || is_link($arquivo) || fileowner($arquivo) !== UID_BUNDLE
        || (fileperms($arquivo) & 0777) !== 0600) {
        throw new RuntimeException('Manifesto de recuperação inválido.');
    }
    $dados = json_decode((string) file_get_contents($arquivo), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($dados) || !is_string($dados['bot'] ?? null)
        || preg_match('/\A[a-z0-9]+\z/', $dados['bot']) !== 1
        || $diretorio !== RAIZ_BUNDLES . '/' . $dados['bot'] . '.recuperacao'
        || ($dados['projeto'] ?? null) !== RAIZ_CHECKOUTS . '/' . $dados['bot']
        || realpath($dados['projeto']) !== $dados['projeto']
        || !is_string($dados['alvo'] ?? null) || preg_match('/\A[0-9a-f]{40,64}\z/', $dados['alvo']) !== 1
        || !is_string($dados['origem_sha'] ?? null) || preg_match('/\A[0-9a-f]{40,64}\z/', $dados['origem_sha']) !== 1
        || !is_string($dados['ferramentas_sha'] ?? null) || preg_match('/\A[0-9a-f]{40,64}\z/', $dados['ferramentas_sha']) !== 1
        || !is_string($dados['operacao'] ?? null) || !in_array($dados['operacao'], ['deploy', 'rollback'], true)
        || !is_string($dados['estado'] ?? null) || !in_array($dados['estado'], ['preparada', 'incompleta', 'concluida'], true)
        || !is_array($dados['arquivos'] ?? null) || array_keys($dados['arquivos']) !== ARQUIVOS_BUNDLE) {
        throw new RuntimeException('Identidade ou estado do bundle divergente.');
    }
    $hashes = [];
    foreach (ARQUIVOS_BUNDLE as $relativo) {
        $hash = $dados['arquivos'][$relativo];
        $caminho = $diretorio . '/' . $relativo;
        $modo = str_ends_with($relativo, '.sh') ? 0700 : 0600;
        if (!is_string($hash) || !is_file($caminho) || is_link($caminho)
            || realpath($caminho) !== $caminho || fileowner($caminho) !== UID_BUNDLE
            || (fileperms($caminho) & 0777) !== $modo || hash_file('sha256', $caminho) !== $hash) {
            throw new RuntimeException('Integridade das ferramentas de recuperação divergente.');
        }
        $hashes[$relativo] = $hash;
    }
    return ['bot' => $dados['bot'], 'projeto' => $dados['projeto'], 'alvo' => $dados['alvo'],
        'origem_sha' => $dados['origem_sha'], 'ferramentas_sha' => $dados['ferramentas_sha'], 'operacao' => $dados['operacao'], 'estado' => $dados['estado'], 'arquivos' => $hashes];
}

/** @param array<string, mixed> $dados */
function escreverManifesto(string $diretorio, array $dados): void
{
    $temporario = $diretorio . '/manifesto.tmp-' . bin2hex(random_bytes(8));
    $json = json_encode($dados, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if (file_put_contents($temporario, $json) !== strlen($json) || !chmod($temporario, 0600)
        || !rename($temporario, $diretorio . '/manifesto.json')) {
        @unlink($temporario);
        throw new RuntimeException('Não foi possível registrar o estado da recuperação.');
    }
}

$argumentos = is_array($_SERVER['argv'] ?? null) ? array_values(array_filter($_SERVER['argv'], 'is_string')) : [];
try {
    if (PHP_SAPI !== 'cli' || count($argumentos) < 3) {
        throw new RuntimeException('Comando de recuperação inválido.');
    }
    [$script, $comando, $bot] = $argumentos;
    if (preg_match('/\A[a-z0-9]+\z/', $bot) !== 1) {
        throw new RuntimeException('Bot inválido.');
    }
    $bundle = RAIZ_BUNDLES . '/' . $bot . '.recuperacao';
    if ($comando === 'criar' && count($argumentos) === 8) {
        [, , , $projeto, $alvo, $origem, $operacao, $ferramentas] = $argumentos;
        if ($projeto !== RAIZ_CHECKOUTS . '/' . $bot || realpath($projeto) !== $projeto
            || preg_match('/\A[0-9a-f]{40,64}\z/', $alvo) !== 1
            || preg_match('/\A[0-9a-f]{40,64}\z/', $origem) !== 1
            || !in_array($operacao, ['deploy', 'rollback'], true)
            || is_link(RAIZ_BUNDLES) || !is_dir(RAIZ_BUNDLES)
            || fileowner(RAIZ_BUNDLES) !== UID_BUNDLE || (fileperms(RAIZ_BUNDLES) & 0777) !== 0700) {
            throw new RuntimeException('Origem ou destino do bundle inválido.');
        }
        if (realpath($ferramentas) !== $ferramentas || is_link($ferramentas)
            || ($ferramentas !== $projeto && (!str_starts_with($ferramentas, RAIZ_BUNDLES . '/')
                || fileowner($ferramentas) !== UID_BUNDLE || (fileperms($ferramentas) & 0777) !== 0700))) {
            throw new RuntimeException('Origem das ferramentas não é o checkout ou um bootstrap privado.');
        }
        $novo = RAIZ_BUNDLES . '/.' . $bot . '.recuperacao.tmp-' . bin2hex(random_bytes(8));
        if (!mkdir($novo, 0700)) {
            throw new RuntimeException('Não foi possível criar o bundle privado.');
        }
        $hashes = [];
        foreach (ARQUIVOS_BUNDLE as $relativo) {
            $fonte = $ferramentas . '/' . $relativo;
            $destino = $novo . '/' . $relativo;
            if (!is_file($fonte) || is_link($fonte) || realpath($fonte) !== $fonte) {
                throw new RuntimeException('Ferramenta de origem ausente ou não canônica.');
            }
            if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0700, true)) {
                throw new RuntimeException('Não foi possível preparar diretório das ferramentas.');
            }
            if (!copy($fonte, $destino) || !chmod($destino, str_ends_with($relativo, '.sh') ? 0700 : 0600)) {
                throw new RuntimeException('Não foi possível preservar ferramenta de recuperação.');
            }
            $hashes[$relativo] = hash_file('sha256', $destino);
        }
        escreverManifesto($novo, ['bot' => $bot, 'projeto' => $projeto, 'alvo' => $alvo,
            'origem_sha' => $origem, 'ferramentas_sha' => $ferramentas === $projeto ? $origem : $alvo, 'operacao' => $operacao, 'estado' => 'preparada', 'arquivos' => $hashes]);
        if (file_exists($bundle) || is_link($bundle)) {
            $anterior = lerBundle($bundle);
            if ($anterior['estado'] !== 'concluida') {
                throw new RuntimeException('Há publicação incompleta; use recuperar_publicacao.sh.');
            }
            if (!rename($bundle, $bundle . '.concluida-' . bin2hex(random_bytes(6)))) {
                throw new RuntimeException('Não foi possível preservar o bundle anterior.');
            }
        }
        if (!rename($novo, $bundle)) {
            throw new RuntimeException('Não foi possível publicar o bundle completo.');
        }
        lerBundle($bundle);
    } elseif ($comando === 'disponivel' && count($argumentos) === 3) {
        if ((file_exists($bundle) || is_link($bundle)) && lerBundle($bundle)['estado'] !== 'concluida') {
            throw new RuntimeException('Recupere a operação incompleta antes de publicar.');
        }
    } elseif ($comando === 'validar' && count($argumentos) === 3) {
        $dados = lerBundle($bundle);
        if ($dados['estado'] !== 'incompleta') {
            throw new RuntimeException('A recuperação exige publicação registrada como incompleta.');
        }
        echo $dados['projeto'], PHP_EOL, $dados['alvo'], PHP_EOL, $dados['origem_sha'], PHP_EOL, $dados['operacao'], PHP_EOL;
    } elseif ($comando === 'estado' && count($argumentos) === 4
        && in_array($argumentos[3], ['incompleta', 'concluida'], true)) {
        $dados = lerBundle($bundle);
        $dados['estado'] = $argumentos[3];
        escreverManifesto($bundle, $dados);
    } else {
        throw new RuntimeException('Comando de recuperação não reconhecido.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Bundle de recuperação recusado: confira identidade, integridade, permissões e estado.\n");
    exit(1);
}
