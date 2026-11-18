<?php

/*
 * ARQUIVO DE MENTIRA. Não é incluído por nada, não roda, e cada linha aqui é
 * uma violação DE PROPÓSITO — uma por regra de ../php.yaml.
 *
 * Ele existe porque um gate que não dispara é indistinguível de um gate que
 * passa. `scripts/quality/run_semgrep.php` roda o Semgrep contra este arquivo
 * ANTES de rodar contra o repositório e reprova se qualquer regra deixar de
 * aparecer. É assim que se descobre que um pattern quebrou numa atualização,
 * em vez de descobrir no vazamento.
 *
 * Ao criar uma regra em php.yaml, acrescente aqui o caso que ela tem que pegar,
 * com o id da regra no comentário.
 *
 * Este diretório está fora do PHPStan, do php-cs-fixer, do Rector e da
 * cobertura — ver phpstan.neon, .php-cs-fixer.dist.php, rector.php e phpunit.xml.
 */

/** @param mixed $bd */
function violacoesDeExemplo($bd, string $entrada, string $recebido): void
{
    // sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM usuarios WHERE nome = '{$entrada}'");

    // comando-de-shell-com-variavel
    shell_exec("ls {$entrada}");

    // unserialize-sem-allowed-classes
    unserialize($entrada);

    // segredo-comparado-sem-hash-equals
    if ($recebido === TELEGRAM_WEBHOOK_SECRET) {
        echo 'ok';
    }
    $segredoDoAmbiente = getenv('TELEGRAM_WEBHOOK_SECRET');
    if ($recebido === $segredoDoAmbiente) {
        echo 'ok';
    }
    if ($recebido === getenv('TELEGRAM_WEBHOOK_SECRET')) {
        echo 'ok';
    }

    // curl-sem-verificar-certificado
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // Mesma violação na forma de array, que é como o Telegram.php monta
    // as opções desde que elas passaram a ser afirmáveis por teste.
    $opcoes = [];
    $opcoes[CURLOPT_SSL_VERIFYHOST] = 0;
    curl_setopt_array($ch, $opcoes);

    // aleatorio-fraco-para-segredo
    $token = uniqid();
    echo $token;

    // entrada-da-requisicao-vira-caminho-de-arquivo
    $caminhoSemLista = basename($_GET['arquivo']);
    echo file_get_contents(__DIR__ . '/' . $caminhoSemLista);
    $caminhoAindaControlado = preg_replace('/x/', 'y', $_GET['arquivo']);
    echo file_get_contents(__DIR__ . '/' . $caminhoAindaControlado);

    // entrada-da-requisicao-impressa-sem-escape
    echo $_POST['nome'];
    echo json_encode($_POST['nome']);
    echo urlencode($_POST['nome']);
}
