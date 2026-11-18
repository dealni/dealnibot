<?php

declare(strict_types=1);

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Variáveis de ambiente prefixadas por bot pelo mesmo motivo do DSN do banco:
 * um `SNAPSHOTS_DIR` genérico faria dois bots empilharem snapshot no mesmo
 * diretório, e a rotação de um apagaria o do outro — os nomes são iguais.
 */
const VARIAVEL_DO_DIRETORIO_DE_SNAPSHOTS = 'DEALNI_SNAPSHOTS_DIR';
const VARIAVEL_DE_SNAPSHOTS_A_MANTER = 'DEALNI_SNAPSHOTS_MANTER';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

/**
 * Diretório dos snapshots locais do banco (processos/snapshot_banco.php).
 *
 * Produção define a variável apontando para FORA do checkout — o ideal é
 * outro volume, porque snapshot no mesmo disco do banco protege contra erro
 * de escrita e migração ruim, não contra perda do disco. O fallback é
 * `snapshots/` na raiz do repositório, que o .gitignore já cobre.
 */
function caminhoSnapshotsDoBot(): string
{
    $configurado = getenv(VARIAVEL_DO_DIRETORIO_DE_SNAPSHOTS);
    if (is_string($configurado) && trim($configurado) !== '') {
        return rtrim(trim($configurado), '/');
    }

    return dirname(__DIR__) . '/snapshots';
}

/**
 * Inteiro positivo lido de uma variável de ambiente, ou null.
 *
 * Aceita só dígitos (com espaço em volta e zeros à esquerda tolerados), exige
 * valor >= 1 e recusa o que não cabe em PHP_INT_MAX. A comparação é feita como
 * TEXTO antes do cast porque ctype_digit() só garante a forma decimal: o cast
 * de um número maior que o inteiro do PHP satura no limite em vez de falhar, e
 * quem chama decidiria "manter PHP_INT_MAX snapshots" sem ninguém ter pedido.
 * Zero também é recusado — é o valor que apagaria o snapshot recém-criado.
 */
function inteiroPositivoDaEnv(string $nome): ?int
{
    $configurado = getenv($nome);
    if (!is_string($configurado)) {
        return null;
    }

    $valor = trim($configurado);
    if (!ctype_digit($valor)) {
        return null;
    }

    $semZeros = ltrim($valor, '0');
    $limite = (string) PHP_INT_MAX;
    if ($semZeros === ''
        || strlen($semZeros) > strlen($limite)
        || (strlen($semZeros) === strlen($limite) && strcmp($semZeros, $limite) > 0)
    ) {
        return null;
    }

    return (int) $semZeros;
}

/**
 * Janela de retenção diária em dias corridos; a variável de ambiente ajusta.
 *
 * Sete cobre uma semana mesmo com execuções extras. Os três últimos dumps
 * completos são preservados também fora da janela, como reserva em falhas. Valor não numérico, menor que 1 ou que
 * não caiba no inteiro do PHP cai no padrão em vez de virar zero ou saturar no
 * limite — zero apagaria o snapshot recém-criado.
 */
function quantosSnapshotsManter(): int
{
    return inteiroPositivoDaEnv(VARIAVEL_DE_SNAPSHOTS_A_MANTER) ?? 7;
}

/**
 * Quantos backups de pré-deploy a rotação própria deles preserva.
 *
 * Eles têm prefixo próprio (`pre-deploy-`) e não entram na conta do
 * `DEALNI_SNAPSHOTS_MANTER`: dentro dela, cada deploy empurrava um diário para
 * fora e encurtava a semana de histórico. Três cobrem o último deploy e os dois
 * anteriores, que é para o que servem.
 */
const BACKUPS_PRE_DEPLOY_A_MANTER = 3;
