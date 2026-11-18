<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Lê a fonte de um script de topo JUNTO com a dos arquivos funcoes_*.php que
 * ele carrega com require_once.
 *
 * Existe porque as declarações de função saíram dos scripts de topo: o
 * roteador inclui o comando com `require` puro dentro do laço de bot.php,
 * e dois updates do mesmo comando abortavam com "Cannot redeclare function".
 * Os arquivos irmãos permitem carregar as funções reais sem executar o topo.
 *
 * Todo contrato que afirma sobre o TEXTO do script — espelho de fluxo, ordem
 * de guardas, presença de uma chamada — precisa continuar enxergando script +
 * funções como UMA COISA SÓ: é essa unidade que é o comando, do ponto de vista
 * do contrato. Ler só o arquivo de topo depois de uma extração faz o contrato
 * passar a ver meio fluxo, e um contrato escrito com
 * `assertStringNotContainsString` fica VERDE sem verificar mais nada.
 *
 * A busca é transitiva (um funcoes_*.php pode carregar outro) e com detecção
 * de ciclo.
 */
final class FonteDoComando
{
    /**
     * Fonte do comando seguida da fonte de cada arquivo que ele carrega com
     * require_once, transitivamente, na ordem em que aparecem.
     *
     * Só entram arquivos que existem em disco; um require de biblioteca de
     * `inclusos/` entra igual, o que é inofensivo para asserções de presença
     * e é o mesmo escopo que o PHP carregaria.
     *
     * @param string $caminho Caminho do comando
     * @return string
     */
    public static function ler(string $caminho): string
    {
        $fonte = file_get_contents($caminho);
        if ($fonte === false) {
            throw new RuntimeException("Não foi possível ler {$caminho}.");
        }

        $partes = [$fonte];
        foreach (self::arquivosDeFuncoes($caminho, $fonte) as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            if ($conteudo !== false) {
                $partes[] = $conteudo;
            }
        }

        return implode("\n", $partes);
    }

    /**
     * @param string $caminho
     * @param string $fonte
     * @return array<int, string>
     */
    private static function arquivosDeFuncoes(string $caminho, string $fonte): array
    {
        $fila = [[$caminho, $fonte]];
        $vistos = [$caminho => true];
        $encontrados = [];

        while ($fila !== []) {
            [$atual, $codigo] = $fila[0];
            array_shift($fila);
            $diretorio = dirname($atual);

            // preg_match_all devolve int|false: sem nenhuma ocorrência e com
            // regex inválida o caminho é o mesmo (nada a enfileirar), mas a
            // condição precisa dizer isso, e não depender do falsy.
            $ocorrencias = [];
            $achados = preg_match_all(
                "/require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/",
                $codigo,
                $ocorrencias
            );
            if ($achados === false || $achados === 0) {
                continue;
            }

            foreach ($ocorrencias[1] as $sufixo) {
                $absoluto = realpath($diretorio . $sufixo);
                if (!is_string($absoluto)
                    || !is_file($absoluto)
                    || isset($vistos[$absoluto])
                    || strpos(basename($absoluto), 'funcoes_') !== 0
                ) {
                    continue;
                }

                $vistos[$absoluto] = true;
                $encontrados[] = $absoluto;

                $incluido = file_get_contents($absoluto);
                if ($incluido !== false) {
                    $fila[] = [$absoluto, $incluido];
                }
            }
        }

        return $encontrados;
    }
}
