<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

/*
 * O composer-unused só enxerga o que o autoload do composer.json aponta, e aqui
 * isso é o código com namespace em `src/`. Todo o resto do bot mora em
 * diretórios procedurais fora do PSR-4. Sem a lista abaixo a ferramenta acusa
 * PDO, Redis e companhia como não usados, o que é o contrário da verdade e
 * transforma o relatório em ruído.
 *
 * setAdditionalFilesFor() recebe o nome do PACOTE RAIZ (lido do composer.json):
 * são arquivos deste pacote que ele deve varrer além do autoload.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
// Diretórios procedurais deste bot, além dos arquivos PHP soltos na raiz.
/** @var list<string> $diretorios */
$diretorios = [
    'comandos',
    'config',
    'inclusos',
    'processos',
    'scripts',
    'tests',
];
// Gitignorados: existem só na máquina de quem desenvolve, e carregam segredo.
// Além de fazerem local e CI verem coisas diferentes, o parser não deve sequer
// abri-los.
/** @var list<string> $arquivosGitignorados */
$arquivosGitignorados = [
    'inclusos/config.php',
];
// Pacotes exigidos sem nenhum `use` no código (implementações de pacote
// virtual, por exemplo), que a ferramenta acusaria como não usados.
/** @var list<string> $pacotesFiltrados */
$pacotesFiltrados = [
];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

$raiz = __DIR__;

$conteudoComposer = file_get_contents($raiz . '/composer.json');
if ($conteudoComposer === false) {
    throw new \RuntimeException('Não foi possível ler composer.json para o composer-unused.');
}

$manifestoComposer = json_decode($conteudoComposer, true, 512, JSON_THROW_ON_ERROR);
if (!is_array($manifestoComposer)) {
    throw new \RuntimeException('composer.json não contém um objeto válido.');
}

$nomePacoteRaiz = $manifestoComposer['name'] ?? '__root__';
if (!is_string($nomePacoteRaiz) || trim($nomePacoteRaiz) === '') {
    throw new \RuntimeException('composer.json não define um nome de pacote válido.');
}

$arquivos = [];
foreach ($diretorios as $diretorio) {
    $caminho = $raiz . '/' . $diretorio;
    if (!is_dir($caminho)) {
        continue;
    }

    $iterador = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($caminho, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterador as $arquivo) {
        if ($arquivo instanceof SplFileInfo
            && $arquivo->isFile()
            && $arquivo->getExtension() === 'php'
        ) {
            $arquivos[] = $arquivo->getPathname();
        }
    }
}

$arquivosDaRaiz = glob($raiz . '/*.php');
foreach ($arquivosDaRaiz === false ? [] : $arquivosDaRaiz as $arquivoDaRaiz) {
    if (is_file($arquivoDaRaiz)) {
        $arquivos[] = $arquivoDaRaiz;
    }
}

$caminhosGitignorados = array_map(
    static fn (string $relativo): string => $raiz . '/' . $relativo,
    $arquivosGitignorados,
);
$arquivos = array_values(array_filter(
    $arquivos,
    static fn (string $arquivo): bool => !in_array($arquivo, $caminhosGitignorados, true),
));

// Ordem estável e sem repetição: o relatório fica determinístico entre máquinas
// e entre sistemas de arquivos que iteram em ordens diferentes.
sort($arquivos, SORT_STRING);
$arquivos = array_values(array_unique($arquivos));

return static function (Configuration $config) use ($arquivos, $nomePacoteRaiz, $pacotesFiltrados): Configuration {
    $config = $config->setAdditionalFilesFor($nomePacoteRaiz, $arquivos);
    foreach ($pacotesFiltrados as $pacote) {
        $config = $config->addNamedFilter(NamedFilter::fromString($pacote));
    }

    return $config;
};
