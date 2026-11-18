<?php

// Regressões do parser PHP: formas globais, locais e chamadas aninhadas mistas.
// Cada função isola o fluxo; ruleid/ok são conferidos pelo semgrep --test.
namespace RegressaoSemgrep;

function caso1($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$entrada}");
}

function caso2($bd, $entrada, $ch): void
{
    // ok: sql-montado-por-interpolacao
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$entrada}");
}

function caso3($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$entrada}");
}

function caso4($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$entrada}");
}

function caso5($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    system("ls {$entrada}");
}

function caso6($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$entrada}");
}

function caso7($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$entrada}");
}

function caso8($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$entrada}");
}

function caso9($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$entrada}", "r");
}

function caso10($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$entrada}", "r");
}

function caso11($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$entrada}", [], $pipes);
}

function caso12($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$entrada}", [], $pipes);
}

function caso13($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    exec("ls " . $entrada);
}

function caso14($bd, $entrada, $ch): void
{
    // ok: sql-montado-por-interpolacao
    // ruleid: comando-de-shell-com-variavel
    \exec("ls " . $entrada);
}

function caso15($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls " . $entrada);
}

function caso16($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls " . $entrada);
}

function caso17($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    system("ls " . $entrada);
}

function caso18($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \system("ls " . $entrada);
}

function caso19($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    passthru("ls " . $entrada);
}

function caso20($bd, $entrada, $ch): void
{
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls " . $entrada);
}

function caso21($bd, $entrada, $ch): void
{
    // ruleid: unserialize-sem-allowed-classes
    unserialize($entrada);
}

function caso22($bd, $entrada, $ch): void
{
    // ruleid: unserialize-sem-allowed-classes
    \unserialize($entrada);
}

function caso23($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp($entrada, TELEGRAM_WEBHOOK_SECRET);
}

function caso24($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp($entrada, TELEGRAM_WEBHOOK_SECRET);
}

function caso25($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp(TELEGRAM_WEBHOOK_SECRET, $entrada);
}

function caso26($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp(TELEGRAM_WEBHOOK_SECRET, $entrada);
}

function caso27($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === hash_hmac("sha256", $entrada, "key");
}

function caso28($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === \hash_hmac("sha256", $entrada, "key");
}

function caso29($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    hash_hmac("sha256", $entrada, "key") == $entrada;
}

function caso30($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \hash_hmac("sha256", $entrada, "key") == $entrada;
}

function caso31($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === hash("sha256", $entrada);
}

function caso32($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === \hash("sha256", $entrada);
}

function caso33($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    md5($entrada) === $entrada;
}

function caso34($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \md5($entrada) === $entrada;
}

function caso35($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === sha1($entrada);
}

function caso36($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === \sha1($entrada);
}

function caso37($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada == getenv("API_TOKEN");
}

function caso38($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada == \getenv("API_TOKEN");
}

function caso39($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    getenv("API_TOKEN") === $entrada;
}

function caso40($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \getenv("API_TOKEN") === $entrada;
}

function caso41($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp($entrada, getenv("API_TOKEN"));
}

function caso42($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp($entrada, \getenv("API_TOKEN"));
}

function caso43($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp($entrada, getenv("API_TOKEN"));
}

function caso44($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp($entrada, \getenv("API_TOKEN"));
}

function caso45($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp(getenv("API_TOKEN"), $entrada);
}

function caso46($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp(\getenv("API_TOKEN"), $entrada);
}

function caso47($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp(getenv("API_TOKEN"), $entrada);
}

function caso48($bd, $entrada, $ch): void
{
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp(\getenv("API_TOKEN"), $entrada);
}

function caso49($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

function caso50($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

function caso51($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

function caso52($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    \curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

function caso53($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    curl_setopt_array($ch, [CURLOPT_SSL_VERIFYPEER => false]);
}

function caso54($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    \curl_setopt_array($ch, [CURLOPT_SSL_VERIFYPEER => false]);
}

function caso55($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    curl_setopt_array($ch, [CURLOPT_SSL_VERIFYHOST => 0]);
}

function caso56($bd, $entrada, $ch): void
{
    // ruleid: curl-sem-verificar-certificado
    \curl_setopt_array($ch, [CURLOPT_SSL_VERIFYHOST => 0]);
}

function caso57($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $token = rand();
}

function caso58($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $token = \rand();
}

function caso59($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $segredo = mt_rand();
}

function caso60($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $segredo = \mt_rand();
}

function caso61($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $chave = uniqid();
}

function caso62($bd, $entrada, $ch): void
{
    // ruleid: aleatorio-fraco-para-segredo
    $chave = \uniqid();
}

function caso63($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    file_get_contents($_GET["arquivo"]);
}

function caso64($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \file_get_contents($_GET["arquivo"]);
}

function caso65($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    file_put_contents($_POST["arquivo"], "x");
}

function caso66($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \file_put_contents($_POST["arquivo"], "x");
}

function caso67($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    fopen($_REQUEST["arquivo"], "r");
}

function caso68($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \fopen($_REQUEST["arquivo"], "r");
}

function caso69($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    unlink($_COOKIE["arquivo"]);
}

function caso70($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \unlink($_COOKIE["arquivo"]);
}

function caso71($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    rename($_GET["arquivo"], "destino");
}

function caso72($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \rename($_GET["arquivo"], "destino");
}

function caso73($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    copy($_GET["arquivo"], "destino");
}

function caso74($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \copy($_GET["arquivo"], "destino");
}

function caso75($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    mkdir($_GET["arquivo"]);
}

function caso76($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-vira-caminho-de-arquivo
    \mkdir($_GET["arquivo"]);
}

function caso77($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-impressa-sem-escape
    echo $_GET["nome"];
}

function caso78($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-impressa-sem-escape
    echo json_encode($_POST["nome"]);
}

function caso79($bd, $entrada, $ch): void
{
    // ruleid: entrada-da-requisicao-impressa-sem-escape
    echo urlencode($_POST["nome"]);
}

function caso80($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp($entrada, $segredo);
}

function caso81($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp($entrada, $segredo);
}

function caso82($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === $segredo;
}

function caso83($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    $segredo == $entrada;
}

function caso84($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === false;
}

function caso85($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === '';
}

function caso86($bd, $entrada, $ch): void
{
    $segredo = getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === null;
}

function caso87($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    strcmp($entrada, $segredo);
}

function caso88($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    \strcmp($entrada, $segredo);
}

function caso89($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    $entrada === $segredo;
}

function caso90($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ruleid: segredo-comparado-sem-hash-equals
    $segredo == $entrada;
}

function caso91($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === false;
}

function caso92($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === '';
}

function caso93($bd, $entrada, $ch): void
{
    $segredo = \getenv("API_TOKEN");
    // ok: segredo-comparado-sem-hash-equals
    $segredo === null;
}

function caso94($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    getenv("APP_ENV") === $entrada;
}

function caso95($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    \getenv("APP_ENV") === $entrada;
}

function caso96($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    getenv("API_TOKEN") === false;
}

function caso97($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    \getenv("API_TOKEN") === false;
}

function caso98($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    '' == getenv("API_TOKEN");
}

function caso99($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    '' == \getenv("API_TOKEN");
}

function caso100($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    getenv("API_TOKEN") === null;
}

function caso101($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    \getenv("API_TOKEN") === null;
}

function caso102($bd, $entrada, $ch): void
{
    // ok: segredo-comparado-sem-hash-equals
    \hash_equals(\getenv("API_TOKEN"), $entrada);
}

function caso103($bd, $entrada, $ch): void
{
    $limite = max(1, (int) $entrada);
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$limite}");
}

function caso104($bd, $entrada, $ch): void
{
    $limite = \max(1, (int) $entrada);
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$limite}");
}

function caso105($bd, $entrada, $ch): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$entrada}");
}

function caso106($bd, $entrada, $ch): void
{
    $limite = meuMax($entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$limite}");
}

function caso107($bd, $entrada, $ch): void
{
    $marcadores = implode(",", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id IN ({$marcadores})", [1, 2, 3]);
}

function caso108($bd, $entrada, $ch): void
{
    $marcadores = implode(",", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id IN ({$marcadores})", [1, 2, 3]);
}

function caso109($bd, $entrada, $ch): void
{
    $marcadores = \implode(",", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id IN ({$marcadores})", [1, 2, 3]);
}

function caso110($bd, $entrada, $ch): void
{
    $marcadores = \implode(",", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id IN ({$marcadores})", [1, 2, 3]);
}

function caso111($bd, $entrada, $ch): void
{
    $marcadores = implode(",", $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id IN ({$marcadores})");
}

function caso112($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo htmlspecialchars($_GET["nome"], ENT_QUOTES);
}

function caso113($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo \htmlspecialchars($_GET["nome"], ENT_QUOTES);
}

function caso114($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo htmlentities($_POST["nome"], ENT_QUOTES);
}

function caso115($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo \htmlentities($_POST["nome"], ENT_QUOTES);
}

function caso116($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo intval($_GET["numero"]);
}

function caso117($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-impressa-sem-escape
    echo \intval($_GET["numero"]);
}

function caso118($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-vira-caminho-de-arquivo
    file_get_contents("/tmp/" . intval($_GET["id"]));
}

function caso119($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-vira-caminho-de-arquivo
    file_get_contents("/tmp/" . \intval($_GET["id"]));
}

function caso120($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-vira-caminho-de-arquivo
    \file_get_contents("/tmp/" . intval($_GET["id"]));
}

function caso121($bd, $entrada, $ch): void
{
    // ok: entrada-da-requisicao-vira-caminho-de-arquivo
    \file_get_contents("/tmp/" . \intval($_GET["id"]));
}

function caso122($bd, $entrada, $ch): void
{
    // ok: unserialize-sem-allowed-classes
    unserialize($entrada, ["allowed_classes" => false]);
}

function caso123($bd, $entrada, $ch): void
{
    // ok: unserialize-sem-allowed-classes
    \unserialize($entrada, ["allowed_classes" => false]);
}

function caso124($bd, $entrada, $ch): void
{
    $cmd = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    shell_exec("ls {$cmd}");
}

function caso125($bd, $entrada, $ch): void
{
    $cmd = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls {$cmd}");
}

function caso126($bd, $entrada, $ch): void
{
    $cmd = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    shell_exec("ls {$cmd}");
}

function caso127($bd, $entrada, $ch): void
{
    $cmd = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls {$cmd}");
}

function caso128($bd, $entrada, $ch): void
{
    // ok: curl-sem-verificar-certificado
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
}

function caso129($bd, $entrada, $ch): void
{
    // ok: curl-sem-verificar-certificado
    \curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
}

function caso130($bd, $entrada, $ch): void
{
    // ok: aleatorio-fraco-para-segredo
    $token = \random_bytes(32);
}

function caso131($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso132($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso133($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso134($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso135($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso136($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso137($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso138($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso139($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso140($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso141($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso142($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso143($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso144($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso145($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso146($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso147($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso148($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso149($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso150($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso151($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso152($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso153($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso154($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso155($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso156($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso157($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso158($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso159($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso160($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso161($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso162($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso163($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso164($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso165($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso166($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso167($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso168($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso169($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso170($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso171($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso172($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso173($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso174($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso175($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso176($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso177($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso178($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso179($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->queryExec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso180($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->queryExec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso181($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->query("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso182($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->query("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso183($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->exec("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso184($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->exec("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso185($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->prepare("DELETE FROM pedidos WHERE id = {$entrada}");
}

function caso186($pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->prepare("DELETE FROM pedidos WHERE id = " . $entrada);
}

function caso187($entrada): void
{
    $cmd = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel, sql-montado-por-interpolacao
    exec("ls {$cmd}");
}

function caso188($entrada): void
{
    $cmd = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel, sql-montado-por-interpolacao
    \exec("ls {$cmd}");
}

function caso189($entrada): void
{
    $cmd = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel, sql-montado-por-interpolacao
    exec("ls {$cmd}");
}

function caso190($entrada): void
{
    $cmd = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel, sql-montado-por-interpolacao
    \exec("ls {$cmd}");
}

function caso191($pdo, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("SELECT * FROM pedidos WHERE nome = '{$entrada}'");
}

function caso192($pdo, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("SELECT * FROM pedidos WHERE id = " . intval($entrada));
}

function caso193($pdo, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("SELECT * FROM pedidos WHERE nome = '{$entrada}'");
}

function caso194($pdo, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec("SELECT * FROM pedidos WHERE id = " . \intval($entrada));
}

function caso195($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec($entrada . " WHERE ativo = 1");
}

function caso196($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->query($entrada . " WHERE ativo = 1");
}

function caso197($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->exec($entrada . " WHERE ativo = 1");
}

function caso198($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $pdo->prepare($entrada . " WHERE ativo = 1");
}

function caso199($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->query($entrada . " WHERE ativo = 1");
}

function caso200($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->exec($entrada . " WHERE ativo = 1");
}

function caso201($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $factory->connection()->prepare($entrada . " WHERE ativo = 1");
}

function caso202($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->query($entrada . " WHERE ativo = 1");
}

function caso203($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->exec($entrada . " WHERE ativo = 1");
}

function caso204($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    getPdo()->prepare($entrada . " WHERE ativo = 1");
}

function caso205($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->query($entrada . " WHERE ativo = 1");
}

function caso206($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->exec($entrada . " WHERE ativo = 1");
}

function caso207($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    ($pdo ?? getPdo())->prepare($entrada . " WHERE ativo = 1");
}

function caso208($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->query($entrada . " WHERE ativo = 1");
}

function caso209($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->exec($entrada . " WHERE ativo = 1");
}

function caso210($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $this->pdo->prepare($entrada . " WHERE ativo = 1");
}

function caso211($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->query($entrada . " WHERE ativo = 1");
}

function caso212($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->exec($entrada . " WHERE ativo = 1");
}

function caso213($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    (new \PDO("sqlite::memory:"))->prepare($entrada . " WHERE ativo = 1");
}

function caso214($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->query($entrada . " WHERE ativo = 1");
}

function caso215($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->exec($entrada . " WHERE ativo = 1");
}

function caso216($bd, $pdo, $factory, $connections, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $connections["principal"]->prepare($entrada . " WHERE ativo = 1");
}

function caso217($bd, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id = " . strval($entrada));
}

function caso218($bd, $entrada): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE id = " . \strval($entrada));
}

function caso219($pdo): void
{
    // ok: sql-montado-por-interpolacao
    $pdo->queryExec("SELECT * FROM " . "pedidos WHERE ativo = 1");
}

function caso220($pdo): void
{
    // ok: sql-montado-por-interpolacao
    $pdo->query("SELECT * FROM " . "pedidos WHERE ativo = 1");
}

function caso221($pdo): void
{
    // ok: sql-montado-por-interpolacao
    $pdo->exec("SELECT * FROM " . "pedidos WHERE ativo = 1");
}

function caso222($pdo): void
{
    // ok: sql-montado-por-interpolacao
    $pdo->prepare("SELECT * FROM " . "pedidos WHERE ativo = 1");
}

// Sanitização vinculada ao argumento: casos seguros, independentes e mistos.
function caso223($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso224($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso225($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso226($bd, $entrada, $outro): void
{
    $seguro = \max(0, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso227($bd, $entrada, $outro): void
{
    $seguro = \max(0, (int) $entrada);
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso228($bd, $entrada, $outro): void
{
    $seguro = \max(0, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso229($bd, $entrada, $outro): void
{
    $seguro = $entrada ? "ASC" : "DESC";
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso230($bd, $entrada, $outro): void
{
    $seguro = $entrada ? "ASC" : "DESC";
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso231($bd, $entrada, $outro): void
{
    $seguro = $entrada ? "ASC" : "DESC";
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso232($bd, $entrada, $outro): void
{
    $seguro = implode(",", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso233($bd, $entrada, $outro): void
{
    $seguro = implode(",", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso234($bd, $entrada, $outro): void
{
    $seguro = implode(",", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso235($bd, $entrada, $outro): void
{
    $seguro = implode(",", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso236($bd, $entrada, $outro): void
{
    $seguro = implode(",", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso237($bd, $entrada, $outro): void
{
    $seguro = implode(",", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso238($bd, $entrada, $outro): void
{
    $seguro = \implode(",", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso239($bd, $entrada, $outro): void
{
    $seguro = \implode(",", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso240($bd, $entrada, $outro): void
{
    $seguro = \implode(",", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso241($bd, $entrada, $outro): void
{
    $seguro = \implode(",", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}'");
}

function caso242($bd, $entrada, $outro): void
{
    $seguro = \implode(",", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos ORDER BY {$seguro}");
}

function caso243($bd, $entrada, $outro): void
{
    $seguro = \implode(",", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos WHERE nome = '{$outro}' ORDER BY {$seguro}");
}

function caso244($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . $seguro . " OFFSET " . $outro);
}

function caso245($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso246($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro .= $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso247($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->query("SELECT * FROM pedidos LIMIT " . $seguro . " OFFSET " . $outro);
}

function caso248($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->query("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso249($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro .= $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->query("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso250($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->exec("SELECT * FROM pedidos LIMIT " . $seguro . " OFFSET " . $outro);
}

function caso251($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->exec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso252($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro .= $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->exec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso253($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->prepare("SELECT * FROM pedidos LIMIT " . $seguro . " OFFSET " . $outro);
}

function caso254($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->prepare("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso255($bd, $entrada, $outro): void
{
    $seguro = max(1, (int) $entrada);
    $seguro .= $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->prepare("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso256($bd, $entrada, $outro): void
{
    $seguro = max(1, $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso257($bd, $entrada, $outro): void
{
    $seguro = \max(1, $entrada);
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso258($bd, $entrada, $outro): void
{
    $seguro = implode(",", array_fill(0, 3, $entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso259($bd, $entrada, $outro): void
{
    $seguro = \implode(",", \array_fill(0, 3, $entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso260($bd, $entrada, $outro): void
{
    $seguro = implode($entrada, array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT {$seguro}");
}

function caso261($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . max(1, (int) $entrada));
}

function caso262($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . max(1, (int) $entrada) . " OFFSET " . $outro);
}

function caso263($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \max(0, (int) $entrada));
}

function caso264($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \max(0, (int) $entrada) . " OFFSET " . $outro);
}

function caso265($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . ($entrada ? "ASC" : "DESC"));
}

function caso266($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . ($entrada ? "ASC" : "DESC") . " OFFSET " . $outro);
}

function caso267($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . implode(",", array_fill(0, 3, "?")));
}

function caso268($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . implode(",", array_fill(0, 3, "?")) . " OFFSET " . $outro);
}

function caso269($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . implode(",", \array_fill(0, 3, "?")));
}

function caso270($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . implode(",", \array_fill(0, 3, "?")) . " OFFSET " . $outro);
}

function caso271($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \implode(",", array_fill(0, 3, "?")));
}

function caso272($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \implode(",", array_fill(0, 3, "?")) . " OFFSET " . $outro);
}

function caso273($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \implode(",", \array_fill(0, 3, "?")));
}

function caso274($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos LIMIT " . \implode(",", \array_fill(0, 3, "?")) . " OFFSET " . $outro);
}

function caso275($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$outro}");
}

function caso276($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro} {$outro}");
}

function caso277($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso278($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso279($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso280($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    exec("ls " . escapeshellarg($entrada));
}

function caso281($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    exec("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso282($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$outro}");
}

function caso283($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro} {$outro}");
}

function caso284($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso285($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso286($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    exec("ls {$seguro}");
}

function caso287($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    exec("ls " . \escapeshellarg($entrada));
}

function caso288($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    exec("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso289($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$outro}");
}

function caso290($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro} {$outro}");
}

function caso291($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso292($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso293($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso294($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \exec("ls " . escapeshellarg($entrada));
}

function caso295($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \exec("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso296($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$outro}");
}

function caso297($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro} {$outro}");
}

function caso298($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso299($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso300($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \exec("ls {$seguro}");
}

function caso301($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \exec("ls " . \escapeshellarg($entrada));
}

function caso302($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \exec("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso303($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$outro}");
}

function caso304($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro} {$outro}");
}

function caso305($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso306($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso307($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso308($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    shell_exec("ls " . escapeshellarg($entrada));
}

function caso309($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso310($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$outro}");
}

function caso311($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro} {$outro}");
}

function caso312($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso313($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso314($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls {$seguro}");
}

function caso315($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    shell_exec("ls " . \escapeshellarg($entrada));
}

function caso316($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    shell_exec("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso317($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$outro}");
}

function caso318($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro} {$outro}");
}

function caso319($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso320($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso321($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso322($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls " . escapeshellarg($entrada));
}

function caso323($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso324($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$outro}");
}

function caso325($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro} {$outro}");
}

function caso326($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso327($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso328($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls {$seguro}");
}

function caso329($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \shell_exec("ls " . \escapeshellarg($entrada));
}

function caso330($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \shell_exec("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso331($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    system("ls {$outro}");
}

function caso332($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro} {$outro}");
}

function caso333($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso334($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso335($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso336($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    system("ls " . escapeshellarg($entrada));
}

function caso337($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    system("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso338($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    system("ls {$outro}");
}

function caso339($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro} {$outro}");
}

function caso340($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso341($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso342($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    system("ls {$seguro}");
}

function caso343($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    system("ls " . \escapeshellarg($entrada));
}

function caso344($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    system("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso345($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$outro}");
}

function caso346($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro} {$outro}");
}

function caso347($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso348($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso349($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso350($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \system("ls " . escapeshellarg($entrada));
}

function caso351($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \system("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso352($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$outro}");
}

function caso353($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro} {$outro}");
}

function caso354($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso355($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso356($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \system("ls {$seguro}");
}

function caso357($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \system("ls " . \escapeshellarg($entrada));
}

function caso358($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \system("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso359($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$outro}");
}

function caso360($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro} {$outro}");
}

function caso361($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso362($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso363($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso364($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    passthru("ls " . escapeshellarg($entrada));
}

function caso365($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    passthru("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso366($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$outro}");
}

function caso367($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro} {$outro}");
}

function caso368($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso369($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso370($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    passthru("ls {$seguro}");
}

function caso371($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    passthru("ls " . \escapeshellarg($entrada));
}

function caso372($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    passthru("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso373($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$outro}");
}

function caso374($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro} {$outro}");
}

function caso375($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso376($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso377($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso378($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \passthru("ls " . escapeshellarg($entrada));
}

function caso379($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls " . escapeshellarg($entrada) . " " . $outro);
}

function caso380($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$outro}");
}

function caso381($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro} {$outro}");
}

function caso382($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso383($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso384($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls {$seguro}");
}

function caso385($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \passthru("ls " . \escapeshellarg($entrada));
}

function caso386($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \passthru("ls " . \escapeshellarg($entrada) . " " . $outro);
}

function caso387($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$outro}", "r");
}

function caso388($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro} {$outro}", "r");
}

function caso389($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso390($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso391($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso392($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    popen("ls " . escapeshellarg($entrada), "r");
}

function caso393($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    popen("ls " . escapeshellarg($entrada) . " " . $outro, "r");
}

function caso394($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$outro}", "r");
}

function caso395($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro} {$outro}", "r");
}

function caso396($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso397($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso398($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    popen("ls {$seguro}", "r");
}

function caso399($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    popen("ls " . \escapeshellarg($entrada), "r");
}

function caso400($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    popen("ls " . \escapeshellarg($entrada) . " " . $outro, "r");
}

function caso401($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$outro}", "r");
}

function caso402($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro} {$outro}", "r");
}

function caso403($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso404($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso405($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso406($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \popen("ls " . escapeshellarg($entrada), "r");
}

function caso407($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \popen("ls " . escapeshellarg($entrada) . " " . $outro, "r");
}

function caso408($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$outro}", "r");
}

function caso409($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro} {$outro}", "r");
}

function caso410($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso411($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso412($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \popen("ls {$seguro}", "r");
}

function caso413($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \popen("ls " . \escapeshellarg($entrada), "r");
}

function caso414($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \popen("ls " . \escapeshellarg($entrada) . " " . $outro, "r");
}

function caso415($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$outro}", [], $pipes);
}

function caso416($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro} {$outro}", [], $pipes);
}

function caso417($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso418($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso419($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso420($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    proc_open("ls " . escapeshellarg($entrada), [], $pipes);
}

function caso421($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls " . escapeshellarg($entrada) . " " . $outro, [], $pipes);
}

function caso422($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$outro}", [], $pipes);
}

function caso423($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro} {$outro}", [], $pipes);
}

function caso424($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso425($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso426($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls {$seguro}", [], $pipes);
}

function caso427($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    proc_open("ls " . \escapeshellarg($entrada), [], $pipes);
}

function caso428($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    proc_open("ls " . \escapeshellarg($entrada) . " " . $outro, [], $pipes);
}

function caso429($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$outro}", [], $pipes);
}

function caso430($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro} {$outro}", [], $pipes);
}

function caso431($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso432($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso433($bd, $entrada, $outro): void
{
    $seguro = escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso434($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \proc_open("ls " . escapeshellarg($entrada), [], $pipes);
}

function caso435($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls " . escapeshellarg($entrada) . " " . $outro, [], $pipes);
}

function caso436($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$outro}", [], $pipes);
}

function caso437($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro} {$outro}", [], $pipes);
}

function caso438($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    // ok: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso439($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro = $outro;
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso440($bd, $entrada, $outro): void
{
    $seguro = \escapeshellarg($entrada);
    $seguro .= $outro;
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls {$seguro}", [], $pipes);
}

function caso441($bd, $entrada, $outro): void
{
    // ok: comando-de-shell-com-variavel
    \proc_open("ls " . \escapeshellarg($entrada), [], $pipes);
}

function caso442($bd, $entrada, $outro): void
{
    // ruleid: comando-de-shell-com-variavel
    \proc_open("ls " . \escapeshellarg($entrada) . " " . $outro, [], $pipes);
}

class SanitizadoresSqlVinculados
{
    function caso443($bd, $entrada, $outro): void
    {
        $seguro = $this->limiteSeguro($entrada);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $outro);
    }

    function caso444($bd, $entrada, $outro): void
    {
        $seguro = $this->limiteSeguro($entrada);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro . " " . $outro);
    }

    function caso445($bd, $entrada, $outro): void
    {
        $seguro = $this->limiteSeguro($entrada);
        // ok: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro);
    }

    function caso446($bd, $entrada, $outro): void
    {
        $seguro = $this->marcadores(3);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $outro);
    }

    function caso447($bd, $entrada, $outro): void
    {
        $seguro = $this->marcadores(3);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro . " " . $outro);
    }

    function caso448($bd, $entrada, $outro): void
    {
        $seguro = $this->marcadores(3);
        // ok: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro);
    }

    function caso449($bd, $entrada, $outro): void
    {
        $seguro = $this->filtroDeAreaParaCategorizar(true);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $outro);
    }

    function caso450($bd, $entrada, $outro): void
    {
        $seguro = $this->filtroDeAreaParaCategorizar(true);
        // ruleid: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro . " " . $outro);
    }

    function caso451($bd, $entrada, $outro): void
    {
        $seguro = $this->filtroDeAreaParaCategorizar(true);
        // ok: sql-montado-por-interpolacao
        $bd->queryExec("SELECT " . $seguro);
    }

}

function caso452($entrada): void
{
    // ok: comando-de-shell-com-variavel
    proc_open(["php", "/tmp/" . $entrada], [], $pipes);
}

function caso453($entrada): void
{
    // ok: comando-de-shell-com-variavel
    \proc_open(\Tests\Support\ExecutorCli::comando("/tmp/" . $entrada), [], $pipes);
}

function caso454($bd, $entrada): void
{
    $fragmento = "WHERE id = ?";
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos {$fragmento}", [$entrada]);
}

function caso455($bd, $entrada): void
{
    $fragmento = "WHERE nome = '{$entrada}'";
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos {$fragmento}");
}

function caso456($bd, $entrada, $outro): void
{
    $seguro = max(0, intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso457($bd, $entrada, $outro): void
{
    $seguro = max(0, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso458($bd, $entrada, $outro): void
{
    $seguro = max(0, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso459($bd, $entrada, $outro): void
{
    $seguro = max(0, intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso460($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(0, intval($entrada)));
}

function caso461($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(0, intval($entrada)) . " " . $outro);
}

function caso462($bd, $entrada, $outro): void
{
    $seguro = max(1, intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso463($bd, $entrada, $outro): void
{
    $seguro = max(1, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso464($bd, $entrada, $outro): void
{
    $seguro = max(1, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso465($bd, $entrada, $outro): void
{
    $seguro = max(1, intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso466($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(1, intval($entrada)));
}

function caso467($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(1, intval($entrada)) . " " . $outro);
}

function caso468($bd, $entrada, $outro): void
{
    $seguro = max(0, \intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso469($bd, $entrada, $outro): void
{
    $seguro = max(0, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso470($bd, $entrada, $outro): void
{
    $seguro = max(0, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso471($bd, $entrada, $outro): void
{
    $seguro = max(0, \intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso472($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(0, \intval($entrada)));
}

function caso473($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(0, \intval($entrada)) . " " . $outro);
}

function caso474($bd, $entrada, $outro): void
{
    $seguro = max(1, \intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso475($bd, $entrada, $outro): void
{
    $seguro = max(1, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso476($bd, $entrada, $outro): void
{
    $seguro = max(1, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso477($bd, $entrada, $outro): void
{
    $seguro = max(1, \intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso478($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(1, \intval($entrada)));
}

function caso479($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . max(1, \intval($entrada)) . " " . $outro);
}

function caso480($bd, $entrada, $outro): void
{
    $seguro = \max(0, intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso481($bd, $entrada, $outro): void
{
    $seguro = \max(0, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso482($bd, $entrada, $outro): void
{
    $seguro = \max(0, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso483($bd, $entrada, $outro): void
{
    $seguro = \max(0, intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso484($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(0, intval($entrada)));
}

function caso485($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(0, intval($entrada)) . " " . $outro);
}

function caso486($bd, $entrada, $outro): void
{
    $seguro = \max(1, intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso487($bd, $entrada, $outro): void
{
    $seguro = \max(1, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso488($bd, $entrada, $outro): void
{
    $seguro = \max(1, intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso489($bd, $entrada, $outro): void
{
    $seguro = \max(1, intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso490($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(1, intval($entrada)));
}

function caso491($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(1, intval($entrada)) . " " . $outro);
}

function caso492($bd, $entrada, $outro): void
{
    $seguro = \max(0, \intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso493($bd, $entrada, $outro): void
{
    $seguro = \max(0, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso494($bd, $entrada, $outro): void
{
    $seguro = \max(0, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso495($bd, $entrada, $outro): void
{
    $seguro = \max(0, \intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso496($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(0, \intval($entrada)));
}

function caso497($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(0, \intval($entrada)) . " " . $outro);
}

function caso498($bd, $entrada, $outro): void
{
    $seguro = \max(1, \intval($entrada));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso499($bd, $entrada, $outro): void
{
    $seguro = \max(1, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso500($bd, $entrada, $outro): void
{
    $seguro = \max(1, \intval($entrada));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso501($bd, $entrada, $outro): void
{
    $seguro = \max(1, \intval($entrada));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso502($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(1, \intval($entrada)));
}

function caso503($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \max(1, \intval($entrada)) . " " . $outro);
}

function caso504($bd, $entrada, $outro): void
{
    $seguro = implode(", ", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso505($bd, $entrada, $outro): void
{
    $seguro = implode(", ", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso506($bd, $entrada, $outro): void
{
    $seguro = implode(", ", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso507($bd, $entrada, $outro): void
{
    $seguro = implode(", ", array_fill(0, 3, "?"));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso508($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . implode(", ", array_fill(0, 3, "?")));
}

function caso509($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . implode(", ", array_fill(0, 3, "?")) . " " . $outro);
}

function caso510($bd, $entrada, $outro): void
{
    $seguro = implode(", ", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso511($bd, $entrada, $outro): void
{
    $seguro = implode(", ", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso512($bd, $entrada, $outro): void
{
    $seguro = implode(", ", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso513($bd, $entrada, $outro): void
{
    $seguro = implode(", ", \array_fill(0, 3, "?"));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso514($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . implode(", ", \array_fill(0, 3, "?")));
}

function caso515($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . implode(", ", \array_fill(0, 3, "?")) . " " . $outro);
}

function caso516($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso517($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso518($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso519($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", array_fill(0, 3, "?"));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso520($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \implode(", ", array_fill(0, 3, "?")));
}

function caso521($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \implode(", ", array_fill(0, 3, "?")) . " " . $outro);
}

function caso522($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", \array_fill(0, 3, "?"));
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso523($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso524($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", \array_fill(0, 3, "?"));
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso525($bd, $entrada, $outro): void
{
    $seguro = \implode(", ", \array_fill(0, 3, "?"));
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso526($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \implode(", ", \array_fill(0, 3, "?")));
}

function caso527($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . \implode(", ", \array_fill(0, 3, "?")) . " " . $outro);
}

function caso528($bd, $entrada, $outro): void
{
    $seguro = "WHERE id = ?";
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso529($bd, $entrada, $outro): void
{
    $seguro = "WHERE id = ?";
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $outro);
}

function caso530($bd, $entrada, $outro): void
{
    $seguro = "WHERE id = ?";
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro . " " . $outro);
}

function caso531($bd, $entrada, $outro): void
{
    $seguro = "WHERE id = ?";
    $seguro = $outro;
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . $seguro);
}

function caso532($bd, $entrada, $outro): void
{
    // ok: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . "WHERE id = ?");
}

function caso533($bd, $entrada, $outro): void
{
    // ruleid: sql-montado-por-interpolacao
    $bd->queryExec("SELECT * FROM pedidos " . "WHERE id = ?" . " " . $outro);
}
