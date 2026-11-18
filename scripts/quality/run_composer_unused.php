<?php

declare(strict_types=1);

/*
 * Dependência declarada no composer.json que nenhum símbolo do código usa.
 *
 * O composer-unused é chamado por aqui, e não direto no composer.json, por
 * causa de um E_NOTICE conhecido do próprio symbol-parser: ele tenta ler como
 * arquivo o diretório raiz do autoload. O wrapper filtra SOMENTE essa mensagem
 * exata. Qualquer outro notice, warning, depreciação ou erro continua visível e
 * reprova; desligar E_NOTICE para o processo inteiro mascararia defeitos novos.
 */

$raiz = dirname(__DIR__, 2);
$composerUnused = $raiz . '/vendor/bin/composer-unused';

if (!is_file($composerUnused)) {
    fwrite(STDERR, "composer-unused não instalado — a checagem NÃO rodou.\n");
    fwrite(STDERR, "    composer install\n");
    exit(2);
}

// Array de argumentos evita uma segunda interpretação pelo shell. Assim os
// argumentos extras continuam sendo valores, nunca trechos de comando.
$comando = [
    PHP_BINARY,
    '-d',
    'error_reporting=' . E_ALL,
    '-d',
    // O valor `stderr` não depende do error_log configurado no php.ini. Com
    // display_errors=stderr um host não pode mandar avisos inesperados para um
    // arquivo externo e o gate os perderia.
    'display_errors=stderr',
    '-d',
    'log_errors=0',
    $composerUnused,
    '--no-progress',
];

$argumentos = $_SERVER['argv'] ?? [];
foreach (is_array($argumentos) ? array_slice($argumentos, 1) : [] as $argumento) {
    if (!is_string($argumento)) {
        fwrite(STDERR, "Argumento inválido para o composer-unused.\n");
        exit(2);
    }

    $comando[] = $argumento;
}

$stdout = tmpfile();
$stderr = tmpfile();
if ($stdout === false || $stderr === false) {
    if (is_resource($stdout)) {
        fclose($stdout);
    }
    if (is_resource($stderr)) {
        fclose($stderr);
    }
    fwrite(STDERR, "Não foi possível criar os arquivos temporários do composer-unused.\n");
    exit(2);
}

// O quarto argumento é o diretório de trabalho do filho. Sem ele, o
// composer-unused analisaria o composer.json do diretório de onde o comando foi
// chamado, e o gate passaria a medir outro projeto quando rodado de fora.
$pipes = [];
$processo = proc_open($comando, [1 => $stdout, 2 => $stderr], $pipes, $raiz);
if (!is_resource($processo)) {
    fclose($stdout);
    fclose($stderr);
    fwrite(STDERR, "Não foi possível iniciar o composer-unused.\n");
    exit(2);
}

$status = proc_close($processo);
rewind($stdout);
rewind($stderr);
$saida = stream_get_contents($stdout);
$saidaDeErro = stream_get_contents($stderr);
fclose($stdout);
fclose($stderr);

if (!is_string($saida) || !is_string($saidaDeErro)) {
    fwrite(STDERR, "Não foi possível ler a saída do composer-unused.\n");
    exit(2);
}

echo $saida;

$errosInesperados = [];
$linhasDeErro = preg_split('/\\R/', trim($saidaDeErro));
foreach ($linhasDeErro === false ? [] : $linhasDeErro as $linhaDeErro) {
    if ($linhaDeErro === '') {
        continue;
    }

    // O prefixo "PHP " só aparece quando os erros também vão para o log, e o
    // separador de diretório e o número da linha mudam com o host e com a
    // versão do symbol-parser. O resto da mensagem é o que identifica o aviso.
    $avisoConhecido = preg_match(
        '~^(?:PHP )?Notice:\\s+file_get_contents\\(\\): Read of \\d+ bytes failed with errno=21 '
        . 'Is a directory in .*[\\\\/]vendor[\\\\/]composer-unused[\\\\/]symbol-parser[\\\\/]src'
        . '[\\\\/]File[\\\\/]FileContentProvider\\.php on line \\d+$~',
        $linhaDeErro
    ) === 1;
    if (!$avisoConhecido) {
        $errosInesperados[] = $linhaDeErro;
    }
}

if ($errosInesperados !== []) {
    fwrite(STDERR, implode("\n", $errosInesperados) . "\n");
    if ($status === 0) {
        $status = 2;
    }
}

exit($status);
