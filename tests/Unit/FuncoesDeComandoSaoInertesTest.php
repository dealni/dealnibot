<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrato dos arquivos `funcoes_*.php` dos diretórios de script de topo
 * (a lista de diretórios é o bloco de dados abaixo).
 *
 * Um `funcoes_*.php` é incluído de verdade pela suíte inteira, então ele não
 * pode ter efeito colateral: fora das funções só podem existir a tag de
 * abertura, comentários, espaço em branco, as próprias declarações (com
 * `const` de valor literal), `declare(strict_types=1);` e o `require_once` de
 * OUTRO funcoes_*.php irmão coberto por este mesmo contrato — inércia
 * transitiva, não exceção na confiança: o alvo tem de ser um arquivo que este
 * mesmo teste analisa.
 *
 * `declare(strict_types=1);` é diretiva de COMPILAÇÃO, não execução, e precisa
 * acompanhar a função quando ela sai de um script que já era estrito: a
 * coerção do tipo de RETORNO segue o arquivo onde a função é DECLARADA.
 */
final class FuncoesDeComandoSaoInertesTest extends TestCase
{
    // --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) -----
    /**
     * Diretórios de script de topo que hospedam `funcoes_*.php`. Diretório novo
     * entra AQUI no mesmo commit em que ganha o primeiro arquivo — fora desta
     * lista o arquivo não é conferido por ninguém, e é ele que a suíte inclui.
     *
     * @var array<int, string>
     */
    private const DIRETORIOS = ['comandos', 'config', 'processos'];
    // --- FIM DOS DADOS; daqui para baixo o código é canônico -----------------

    /**
     * Um caso por arquivo funcoes_*.php encontrado no disco: arquivo novo entra
     * no contrato sozinho, sem ninguém lembrar de cadastrá-lo aqui.
     *
     * @return array<string, array{0: string}>
     */
    public static function providerArquivosDeFuncoes(): array
    {
        $casos = [];
        foreach (self::caminhos() as $caminho) {
            $casos[basename(dirname($caminho)) . '/' . basename($caminho)] = [$caminho];
        }

        return $casos;
    }

    /**
     * @return array<int, string>
     */
    private static function caminhos(): array
    {
        $raiz = dirname(__DIR__, 2);
        $caminhos = [];
        foreach (self::DIRETORIOS as $diretorio) {
            $achados = glob($raiz . '/' . $diretorio . '/funcoes_*.php');
            foreach (is_array($achados) ? $achados : [] as $caminho) {
                $caminhos[] = $caminho;
            }
        }
        sort($caminhos);

        return $caminhos;
    }

    public function testExisteAoMenosUmArquivoDeFuncoes(): void
    {
        // Guarda do próprio provider: um glob que deixa de casar transformaria
        // todos os casos abaixo em "0 testes, tudo verde". O número não é
        // travado de propósito — arquivo novo tem de entrar sem editar teste —,
        // mas zero é sempre defeito.
        $this->assertNotEmpty(self::caminhos());
    }

    /**
     * @param string $caminho Arquivo funcoes_*.php de produção
     */
    #[DataProvider('providerArquivosDeFuncoes')]
    public function testForaDasFuncoesNaoHaNadaExecutavel(string $caminho): void
    {
        $codigo = file_get_contents($caminho);
        $this->assertIsString($codigo);

        $tokens = token_get_all($codigo);
        $total = count($tokens);
        $intrusos = [];

        // Só o nível de topo é percorrido: cada declaração de função é pulada
        // inteira (assinatura + corpo), porque o que estiver DENTRO dela não
        // roda no include.
        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];

            // Função e classe são DECLARAÇÕES: nada dentro delas roda no
            // include. O corpo é pulado inteiro, do primeiro `{` ao `}` que o
            // fecha.
            if (is_array($token) && in_array($token[0], [T_FUNCTION, T_CLASS], true)) {
                $i = self::fimDaFuncao($tokens, $i);
                continue;
            }

            // `const X = 4000;` é declaração, não execução: o valor é literal,
            // não chama nada e o require_once garante uma única passagem. É o
            // que permite a uma constante de regra morar junto da função que a
            // usa, em vez de ficar num `define()` no topo do script — que é
            // chamada de função e, além de executar no include, deixava o valor
            // ilegível para quem não pode incluir o script.
            if (is_array($token) && $token[0] === T_CONST) {
                $fim = self::fimDaDeclaracao($tokens, $i);
                if ($fim === $i) {
                    $intrusos[] = "linha {$token[2]}: const com valor não literal";
                    continue;
                }
                $i = $fim;
                continue;
            }

            // `declare(strict_types=1);` é diretiva de COMPILAÇÃO, não
            // execução — e precisa acompanhar a função quando ela sai de um
            // script que já era estrito, porque a coerção do tipo de RETORNO
            // segue o arquivo onde a função é DECLARADA. Só essa forma passa:
            // `declare(ticks=1)` roda a cada instrução.
            if (is_array($token) && $token[0] === T_DECLARE) {
                $fim = self::fimDoDeclareEstrito($tokens, $i);
                if ($fim === null) {
                    $intrusos[] = "linha {$token[2]}: declare() que não é declare(strict_types=1)";
                    continue;
                }
                $i = $fim;
                continue;
            }

            if (is_array($token) && $token[0] === T_REQUIRE_ONCE) {
                $fim = self::fimDoRequireDeFuncoes($tokens, $i, dirname($caminho));
                if ($fim === $i) {
                    $intrusos[] = "linha {$token[2]}: require_once de algo que não é um funcoes_*.php irmão";
                    continue;
                }
                $i = $fim;
                continue;
            }

            if (is_array($token)) {
                if (in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $intrusos[] = "linha {$token[2]}: " . token_name($token[0]) . ' (' . trim($token[1]) . ')';
                continue;
            }

            if (trim($token) === '' || $token === ';') {
                continue;
            }

            $intrusos[] = "token solto: {$token}";
        }

        $this->assertSame(
            [],
            $intrusos,
            basename($caminho) . " tem código executável fora das funções — incluir o arquivo deixaria de ser inerte:\n"
                . implode("\n", $intrusos)
        );
    }

    /**
     * Todo funcoes_*.php precisa ser carregado por alguém dos diretórios de
     * efeito real.
     *
     * Arquivo órfão entra no denominador da cobertura sem ninguém para
     * exercitá-lo — exatamente o efeito que a extração existe para evitar.
     * `funcoes_ia_comuns.php` e `funcoes_http_openai.php` são
     * compartilhados pelos comandos de IA e não têm script homônimo.
     *
     * @param string $caminho Arquivo funcoes_*.php de produção
     */
    #[DataProvider('providerArquivosDeFuncoes')]
    public function testCadaArquivoDeFuncoesEhCarregadoPorAlgumScript(string $caminho): void
    {
        $carregadores = [];
        foreach (self::arquivosDosDiretoriosDeEfeitoReal() as $arquivo) {
            if ($arquivo === $caminho) {
                continue;
            }

            $codigo = file_get_contents($arquivo);
            if ($codigo === false) {
                continue;
            }

            if (str_contains($codigo, "require_once __DIR__ . '/" . basename($caminho) . "';")) {
                $carregadores[] = $arquivo;
            }
        }

        $this->assertNotSame(
            [],
            $carregadores,
            basename($caminho) . ' não é carregado por nenhum script: ou alguém passou a declarar a função'
                . ' de novo no topo, ou o arquivo virou órfão e infla o denominador da cobertura.'
        );
    }

    /**
     * Todos os .php dos diretórios de efeito real (bloco de dados).
     *
     * @return array<int, string>
     */
    private static function arquivosDosDiretoriosDeEfeitoReal(): array
    {
        $raiz = dirname(__DIR__, 2);
        $arquivos = [];
        foreach (self::DIRETORIOS as $diretorio) {
            $achados = glob($raiz . '/' . $diretorio . '/*.php');
            foreach (is_array($achados) ? $achados : [] as $caminho) {
                $arquivos[] = $caminho;
            }
        }

        return $arquivos;
    }

    /**
     * Índice do `;` de um `declare(strict_types=1);` que começa em $inicio, ou
     * `null` quando o `declare` é de outra coisa (ticks, encoding, ou a forma
     * com bloco entre chaves) — aí o chamador o trata como intruso.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice do T_DECLARE
     * @return int|null Índice do `;`
     */
    private static function fimDoDeclareEstrito(array $tokens, int $inicio): ?int
    {
        $total = count($tokens);
        $texto = '';

        for ($i = $inicio; $i < $total; $i++) {
            $token = $tokens[$i];
            $texto .= is_array($token) ? $token[1] : $token;

            if ($token === ';') {
                return preg_match('/^declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;$/', trim($texto)) === 1
                    ? $i
                    : null;
            }
        }

        return null;
    }

    /**
     * Índice do `;` que fecha uma declaração `const` iniciada em $inicio.
     *
     * O valor tem de ser literal: `const X = calcular();` é chamada de função
     * no include, exatamente o que este contrato existe para barrar.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice do T_CONST
     * @return int Índice do `;`, ou $inicio quando o valor não é literal
     */
    private static function fimDaDeclaracao(array $tokens, int $inicio): int
    {
        $total = count($tokens);

        for ($i = $inicio; $i < $total; $i++) {
            $token = $tokens[$i];

            if ($token === ';') {
                return $i;
            }

            // Parêntese dentro de uma const só aparece em chamada de função.
            if ($token === '(') {
                return $inicio;
            }
        }

        return $inicio;
    }

    /**
     * Índice do `;` que fecha um `require_once __DIR__ . '/funcoes_x.php';`.
     *
     * Só é aceito o require_once de um arquivo que COMEÇA por `funcoes_` e que
     * existe no mesmo diretório — isto é, um arquivo que este mesmo teste
     * também analisa. Qualquer outra forma (caminho variável, concatenação com
     * chamada, arquivo de fora) devolve $inicio e vira intruso.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice do T_REQUIRE_ONCE
     * @param string $diretorio Diretório do arquivo analisado
     * @return int Índice do `;`, ou $inicio quando o alvo não é aceitável
     */
    private static function fimDoRequireDeFuncoes(array $tokens, int $inicio, string $diretorio): int
    {
        $total = count($tokens);
        $literais = [];
        $permitidos = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_DIR, T_CONSTANT_ENCAPSED_STRING];

        for ($i = $inicio + 1; $i < $total; $i++) {
            $token = $tokens[$i];

            if ($token === ';') {
                if (count($literais) !== 1) {
                    return $inicio;
                }

                $alvo = realpath($diretorio . $literais[0]);
                if (!is_string($alvo)
                    || !is_file($alvo)
                    || !str_starts_with(basename($alvo), 'funcoes_')
                    || dirname($alvo) !== realpath($diretorio)
                ) {
                    return $inicio;
                }

                return $i;
            }

            if ($token === '.') {
                continue;
            }

            if (!is_array($token) || !in_array($token[0], $permitidos, true)) {
                return $inicio;
            }

            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literais[] = trim($token[1], "'\"");
            }
        }

        return $inicio;
    }

    /**
     * Índice do `}` que fecha a declaração de função que começa em $inicio.
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @param int $inicio Índice do T_FUNCTION
     * @return int Índice do último token da declaração
     */
    private static function fimDaFuncao(array $tokens, int $inicio): int
    {
        $total = count($tokens);
        $profundidade = 0;
        $abriu = false;

        for ($i = $inicio; $i < $total; $i++) {
            $token = $tokens[$i];
            $texto = is_array($token) ? $token[1] : $token;

            if ($texto === '{') {
                $profundidade++;
                $abriu = true;
                continue;
            }
            if ($texto === '}') {
                $profundidade--;
                if ($abriu && $profundidade === 0) {
                    return $i;
                }
            }
        }

        // Arquivo com chaves desbalanceadas não compila; o php -l do gate pega
        // antes, e aqui o resto do arquivo simplesmente não é analisado.
        return $total - 1;
    }
}
