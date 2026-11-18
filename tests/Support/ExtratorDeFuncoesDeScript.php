<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Extrai e define funções nomeadas de scripts de produção que NÃO podem ser
 * incluídos nos testes: os comandos executam código top-level com
 * efeitos reais (sendMessage, banco, Redis, exit) no momento do include.
 *
 * A extração usa o tokenizer do PHP e captura apenas os blocos
 * `function nome(...) { ... }`; o eval() resultante SOMENTE define as
 * funções — nenhuma linha top-level do script é executada.
 *
 * O eval é o último recurso: carregar() prefere `require_once` dos irmãos
 * `funcoes_*.php`, para exercitar as funções reais e evitar copiar seu código.
 */
final class ExtratorDeFuncoesDeScript
{
    /**
     * `require_once __DIR__ . '/funcoes_alguma_coisa.php';` — o jeito como um
     * comando carrega as próprias funções (ver docs/CONVENCOES.md).
     */
    private const PADRAO_REQUIRE_IRMAO = "/require_once\\s+__DIR__\\s*\\.\\s*'\\/(funcoes_[A-Za-z0-9_]+\\.php)'\\s*;/";

    /**
     * Carrega DE VERDADE, com require_once, os `funcoes_*.php` irmãos que o
     * script inclui — e nada mais dele.
     *
     * Existe porque os testes rodam o corpo do comando por eval(), e dentro de
     * eval() o `__DIR__` é o do arquivo que chamou eval (o teste), não o do
     * script: o require_once do irmão procuraria em tests/. Então o par correto
     * é este método (carrega pelo caminho real) mais
     * semRequiresDeFuncoesIrmas() (tira a linha do código avaliado).
     *
     * Carregar o arquivo real mantém as declarações e dependências do produto,
     * sem reproduzir o código por tokenização.
     *
     * Diferente do que carregar() faz sozinho, aqui a ausência do irmão é ERRO:
     * quem chama este método está declarando que o script depende dele.
     *
     * @param string $caminhoArquivo Caminho do script de produção
     * @return array<int, string> Caminhos dos irmãos carregados
     */
    public static function carregarFuncoesIrmas(string $caminhoArquivo): array
    {
        $codigo = file_get_contents($caminhoArquivo);
        if ($codigo === false) {
            throw new RuntimeException("Não foi possível ler {$caminhoArquivo}.");
        }

        if (preg_match_all(self::PADRAO_REQUIRE_IRMAO, $codigo, $casamentos) === false) {
            throw new RuntimeException("Falha ao procurar funcoes_*.php em {$caminhoArquivo}.");
        }

        $carregados = [];
        foreach ($casamentos[1] as $nomeArquivo) {
            $caminhoIrmao = dirname($caminhoArquivo) . '/' . $nomeArquivo;
            if (!is_file($caminhoIrmao)) {
                throw new RuntimeException(
                    "{$caminhoArquivo} inclui {$nomeArquivo}, que não existe."
                );
            }

            require_once $caminhoIrmao;
            $carregados[] = $caminhoIrmao;
        }

        return $carregados;
    }

    /**
     * Remove do código as linhas `require_once __DIR__ . '/funcoes_*.php';`.
     * Complementar a carregarFuncoesIrmas(): o irmão já foi carregado pelo
     * caminho real, então repetir o require dentro do eval() só reintroduzia o
     * `__DIR__` errado.
     *
     * @param string $codigo
     * @return string
     */
    public static function semRequiresDeFuncoesIrmas(string $codigo): string
    {
        $limpo = preg_replace(self::PADRAO_REQUIRE_IRMAO, '', $codigo);

        return is_string($limpo) ? $limpo : $codigo;
    }

    /**
     * Define no processo atual as funções nomeadas de um script sem executar
     * o restante do arquivo. Funções já definidas são puladas (a suíte roda
     * em um único processo, então chamadas repetidas são no-op).
     *
     * O sufixo existe para as CÓPIAS duplicadas: quando o mesmo helper aparece
     * em mais de um comando, só a primeira ocorrência encontrada poderia ser
     * definida com o nome original. Com um sufixo por arquivo as cópias
     * coexistem no mesmo processo e podem ser EXECUTADAS lado a lado, em vez de
     * apenas comparadas como texto.
     *
     * @param string $caminhoArquivo Caminho do script de produção
     * @param array<int, string> $nomesFuncoes Nomes das funções a definir
     * @param string $sufixo Sufixo aplicado ao nome definido ('' mantém o original)
     * @return void
     */
    public static function carregar(string $caminhoArquivo, array $nomesFuncoes, string $sufixo = ''): void
    {
        $faltantes = [];
        foreach ($nomesFuncoes as $nomeFuncao) {
            if (!\function_exists($nomeFuncao . $sufixo)) {
                $faltantes[] = $nomeFuncao;
            }
        }
        if ($faltantes === []) {
            return;
        }

        // Caminho preferido: as funções dos comandos vivem em arquivos
        // funcoes_*.php que SÓ declaram funções (nenhum efeito no include), e
        // incluí-los de verdade preserva suas declarações e dependências.
        // Não vale quando há sufixo: aí a
        // intenção é definir CÓPIAS lado a lado com nomes diferentes, o que só
        // o eval consegue.
        if ($sufixo === '') {
            $codigoDoScript = file_get_contents($caminhoArquivo);
            if ($codigoDoScript !== false) {
                foreach (self::arquivosIncluidos($caminhoArquivo, $codigoDoScript) as $incluido) {
                    if (strpos(basename($incluido), 'funcoes_') !== 0) {
                        continue;
                    }
                    require_once $incluido;
                }
            }

            $faltantes = array_values(array_filter(
                $faltantes,
                static function (string $nome): bool {
                    return !\function_exists($nome);
                }
            ));
            if ($faltantes === []) {
                return;
            }
        }

        $declaracoes = self::extrairDeclaracoes($caminhoArquivo, $faltantes, $sufixo);

        // Somente declarações "function nome(...) { ... }" chegam aqui, então
        // o eval define as funções sem executar nenhuma linha do script.
        eval($declaracoes);

        foreach ($faltantes as $nomeFuncao) {
            if (!\function_exists($nomeFuncao . $sufixo)) {
                throw new RuntimeException(
                    "Função {$nomeFuncao}{$sufixo} não encontrada em {$caminhoArquivo}."
                );
            }
        }
    }

    /**
     * Retorna o código do script com as declarações das funções nomeadas
     * REMOVIDAS (tudo o mais é preservado). Complementar a carregar(): usado
     * para rodar via eval() o restante top-level de um script que também
     * declara uma função no topo, sem "Cannot redeclare function" quando a
     * função já foi definida antes (por carregar()) no mesmo processo.
     *
     * @param string $caminhoArquivo
     * @param array<int, string> $nomesFuncoes
     * @return string
     */
    public static function semDeclaracoes(string $caminhoArquivo, array $nomesFuncoes): string
    {
        $codigo = file_get_contents($caminhoArquivo);
        if ($codigo === false) {
            throw new RuntimeException("Não foi possível ler {$caminhoArquivo}.");
        }

        $tokens = token_get_all($codigo);
        $totalTokens = count($tokens);
        $resultado = '';

        for ($indice = 0; $indice < $totalTokens; $indice++) {
            if (self::ehToken($tokens[$indice], T_FUNCTION)) {
                $indiceNome = self::proximoTokenSignificativo($tokens, $indice + 1);
                if ($indiceNome !== null) {
                    $tokenNome = $tokens[$indiceNome];
                    if (self::ehToken($tokenNome, T_STRING) && in_array($tokenNome[1], $nomesFuncoes, true)) {
                        list(, $fim) = self::capturarBloco($tokens, $indice);
                        $indice = $fim;
                        continue;
                    }
                }
            }

            $token = $tokens[$indice];
            $resultado .= is_array($token) ? $token[1] : $token;
        }

        return $resultado;
    }

    /**
     * @param string $caminhoArquivo
     * @param array<int, string> $nomesFuncoes
     * @param string $sufixo Acrescentado ao nome na declaração extraída
     * @return string Código PHP contendo apenas as declarações extraídas
     */
    private static function extrairDeclaracoes(
        string $caminhoArquivo,
        array $nomesFuncoes,
        string $sufixo = ''
    ): string {
        $codigo = file_get_contents($caminhoArquivo);
        if ($codigo === false) {
            throw new RuntimeException("Não foi possível ler {$caminhoArquivo}.");
        }

        $declaracoes = self::declaracoesDoCodigo($codigo, $nomesFuncoes, $sufixo);

        // As funções dos comandos vivem em arquivos funcoes_*.php carregados
        // com require_once (o comando em si só tem efeitos), então a busca
        // segue os require_once do script — transitivamente, porque um
        // funcoes_*.php ainda pode carregar outro.
        $pendentes = array_values(array_filter(
            $nomesFuncoes,
            static function (string $nome) use ($declaracoes, $sufixo): bool {
                return strpos($declaracoes, 'function ' . $nome . $sufixo) === false;
            }
        ));
        foreach (self::arquivosIncluidos($caminhoArquivo, $codigo) as $incluido) {
            if ($pendentes === []) {
                break;
            }
            $codigoIncluido = file_get_contents($incluido);
            if ($codigoIncluido === false) {
                continue;
            }
            $declaracoes .= self::declaracoesDoCodigo($codigoIncluido, $pendentes, $sufixo);
            $pendentes = array_values(array_filter(
                $pendentes,
                static function (string $nome) use ($declaracoes, $sufixo): bool {
                    return strpos($declaracoes, 'function ' . $nome . $sufixo) === false;
                }
            ));
        }

        return $declaracoes;
    }

    /**
     * Caminhos alcançados pelos `require_once __DIR__ . '/arquivo.php'` do
     * script, em largura e sem repetir. Só entram os que existem em disco.
     *
     * Aqui os arquivos são apenas LIDOS (file_get_contents) para descobrir os
     * requires seguintes; quem decide incluir algum de verdade é carregar(), e
     * só quando o nome começa com `funcoes_`.
     *
     * @param string $caminhoArquivo
     * @param string $codigo
     * @return array<int, string>
     */
    private static function arquivosIncluidos(string $caminhoArquivo, string $codigo): array
    {
        $fila = [[$caminhoArquivo, $codigo]];
        $vistos = [$caminhoArquivo => true];
        $encontrados = [];

        while ($fila !== []) {
            [$atual, $fonte] = $fila[0];
            array_shift($fila);
            $diretorio = dirname($atual);

            // preg_match_all devolve int|false: sem nenhuma ocorrência e com
            // regex inválida o caminho é o mesmo (nada a enfileirar), mas a
            // condição precisa dizer isso, e não depender do falsy.
            $ocorrencias = [];
            $achados = preg_match_all(
                "/require(?:_once)?\s+__DIR__\s*\.\s*'([^']+)'/",
                $fonte,
                $ocorrencias
            );
            if ($achados === false || $achados === 0) {
                continue;
            }

            foreach ($ocorrencias[1] as $sufixoCaminho) {
                $absoluto = realpath($diretorio . $sufixoCaminho);
                if (!is_string($absoluto) || !is_file($absoluto) || isset($vistos[$absoluto])) {
                    continue;
                }
                $vistos[$absoluto] = true;
                $encontrados[] = $absoluto;

                $fonteIncluida = file_get_contents($absoluto);
                if ($fonteIncluida !== false) {
                    $fila[] = [$absoluto, $fonteIncluida];
                }
            }
        }

        return $encontrados;
    }

    /**
     * @param string $codigo
     * @param array<int, string> $nomesFuncoes
     * @param string $sufixo
     * @return string
     */
    private static function declaracoesDoCodigo(string $codigo, array $nomesFuncoes, string $sufixo = ''): string
    {
        $tokens = token_get_all($codigo);
        $totalTokens = count($tokens);
        $declaracoes = '';

        for ($indice = 0; $indice < $totalTokens; $indice++) {
            if (!self::ehToken($tokens[$indice], T_FUNCTION)) {
                continue;
            }

            $indiceNome = self::proximoTokenSignificativo($tokens, $indice + 1);
            if ($indiceNome === null) {
                continue;
            }

            $tokenNome = $tokens[$indiceNome];
            if (!self::ehToken($tokenNome, T_STRING)) {
                continue; // closure/função anônima: não tem nome próprio
            }
            if (!in_array($tokenNome[1], $nomesFuncoes, true)) {
                continue;
            }

            list($trecho, $fim) = self::capturarBloco($tokens, $indice, $indiceNome, $sufixo);
            $declaracoes .= $trecho . "\n";
            $indice = $fim;
        }

        return $declaracoes;
    }

    /**
     * Captura o texto de um token T_FUNCTION até o fechamento do corpo
     * (chaves balanceadas, contando também interpolação em strings).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice do token T_FUNCTION
     * @param int|null $indiceNome Índice do token com o nome da função (null quando o sufixo não se aplica)
     * @param string $sufixo Acrescentado ao nome (só no token do nome)
     * @return array{0: string, 1: int} Trecho extraído e índice do último token
     */
    private static function capturarBloco(
        array $tokens,
        int $inicio,
        ?int $indiceNome = null,
        string $sufixo = ''
    ): array {
        $trecho = '';
        $profundidade = 0;
        $abriuCorpo = false;
        $totalTokens = count($tokens);
        $indice = $inicio;

        for (; $indice < $totalTokens; $indice++) {
            $token = $tokens[$indice];
            $trecho .= is_array($token) ? $token[1] : $token;
            // Só o token do NOME recebe o sufixo: chamadas internas e qualquer
            // outra ocorrência do mesmo texto ficam intactas.
            if ($indiceNome !== null && $indice === $indiceNome) {
                $trecho .= $sufixo;
            }

            if ($token === '{'
                || (is_array($token)
                    && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            ) {
                $profundidade++;
                $abriuCorpo = true;
            } elseif ($token === '}') {
                $profundidade--;
                if ($abriuCorpo && $profundidade === 0) {
                    break;
                }
            }
        }

        return [$trecho, $indice];
    }

    /**
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio
     * @return int|null Índice do próximo token fora de espaço/comentário
     */
    private static function proximoTokenSignificativo(array $tokens, int $inicio): ?int
    {
        $totalTokens = count($tokens);
        for ($indice = $inicio; $indice < $totalTokens; $indice++) {
            $token = $tokens[$indice];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }
            return $indice;
        }
        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     * @param int $tipo
     * @return bool
     * @phpstan-assert-if-true array{0: int, 1: string, 2: int} $token
     */
    private static function ehToken(array|string $token, int $tipo): bool
    {
        return is_array($token) && $token[0] === $tipo;
    }
}
