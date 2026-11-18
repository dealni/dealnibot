<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Trava o resultado da migração para PHP 8.5: depois de migrada, a base não
 * pode voltar a ganhar compatibilidade com runtime anterior ao 8.5.
 *
 * Os três itens abaixo foram removidos ao longo da migração e este teste os
 * mantém removidos — sem ele a verificação seria manual e valeria só no dia em
 * que alguém a fizesse:
 *
 *   1. curl_close(), que virou no-op no PHP 8.0 e foi descontinuada no 8.5;
 *   2. ReflectionProperty/ReflectionMethod::setAccessible(), que só era
 *      necessária antes do PHP 8.1 e vinha protegida por PHP_VERSION_ID;
 *   3. qualquer menção a runtime PHP anterior ao 8.5 em código, configuração,
 *      script de instalação, CI ou documentação.
 *
 * Nenhum arquivo é incluído: a varredura lê o conteúdo e, no caso do PHP, usa
 * o tokenizer — que já descarta comentários e strings, evitando falso positivo
 * de uma explicação que apenas *cita* a função. Arquivos com credenciais
 * (inclusos/config.php, inclusos/dados_bot.php) entram na varredura, mas as
 * mensagens de falha só reportam caminho e linha, nunca o conteúdo.
 */
final class LegadoDeRuntimeAntigoTest extends TestCase
{
    /**
     * Diretórios que não são código deste repositório.
     *
     * `tools/phpcpd/vendor` é o vendor de uma FERRAMENTA, não do bot: o phpcpd
     * tem composer.json próprio (ver docs/TESTES.md) e o autoload que
     * o Composer gera lá dentro traz o mesmo `PHP_VERSION_ID <` do autoload da
     * raiz — que já estava ignorado por estar sob `vendor/`.
     */
    private const DIRETORIOS_IGNORADOS = [
        'vendor',
        'tools/phpcpd/vendor',
        '.git',
        '.github/workflows/cache',
        'logs',
        '.artifacts',
        'node_modules',
    ];

    /** Arquivos gerados ou de terceiros: não são fonte deste repositório. */
    private const ARQUIVOS_IGNORADOS = ['composer.lock', 'package-lock.json', '.php-cs-fixer.cache', 'bot.db'];

    /** Extensões varridas pela busca textual por runtime antigo. */
    private const EXTENSOES_DE_TEXTO = ['php', 'md', 'sh', 'yml', 'yaml', 'json', 'neon', 'xml', 'txt', 'sql', 'example'];

    public function testNenhumaChamadaResidualACurlClose(): void
    {
        $ocorrencias = $this->ocorrenciasDeIdentificadorPhp('curl_close');

        $this->assertSame(
            [],
            $ocorrencias,
            "curl_close() virou no-op no PHP 8.0 e foi descontinuada no 8.5: libere o handle com unset(). Ocorrências: "
                . implode(', ', $ocorrencias)
        );
    }

    public function testNenhumUsoDeSetAccessible(): void
    {
        $ocorrencias = $this->ocorrenciasDeIdentificadorPhp('setAccessible');

        $this->assertSame(
            [],
            $ocorrencias,
            'setAccessible() só era necessária antes do PHP 8.1; a Reflection já dá acesso direto. Ocorrências: '
                . implode(', ', $ocorrencias)
        );
    }

    /**
     * PHP_VERSION_ID em código PHP só aparece para escrever um ramo por versão
     * de runtime. Como existe um runtime só, qualquer ramo desses é legado.
     * (Os gates de instalação usam PHP_VERSION_ID em .sh, que não é varrido
     * aqui — lá ele checa justamente a linha 8.5.)
     */
    public function testNenhumRamoCondicionalPorVersaoDoPhp(): void
    {
        $ocorrencias = $this->ocorrenciasDeIdentificadorPhp('PHP_VERSION_ID');

        $this->assertSame(
            [],
            $ocorrencias,
            'Existe um runtime só (>=8.5.12 <8.6): ramo condicional por versão do PHP é legado. Ocorrências: '
                . implode(', ', $ocorrencias)
        );
    }

    /**
     * Nenhum arquivo do repositório pode mencionar runtime PHP anterior ao 8.5
     * — nem como instrução, nem como comentário, nem como registro histórico.
     * Não há allowlist: a documentação histórica foi reescrita para descrever
     * "o alvo anterior" sem citar a versão, justamente para que este gate possa
     * ser absoluto.
     */
    public function testNenhumaMencaoARuntimePhpAnteriorAo85(): void
    {
        // Montado em partes para o próprio arquivo do teste não casar consigo
        // mesmo caso alguém o inclua na varredura por engano.
        $sete = '7';
        $padroes = [
            'php ' . $sete . '.',
            'php' . $sete . '.',
            'php-' . $sete . '.',
            'php' . $sete . '-',
            $sete . '0400',
            '>=' . $sete . '.',
            '"' . $sete . '.',
        ];

        $encontrados = [];
        foreach ($this->arquivosDeTexto() as $caminho) {
            if ($caminho === __FILE__) {
                continue;
            }

            $conteudo = $this->ler($caminho);
            $linhas = explode("\n", $conteudo);
            foreach ($linhas as $numero => $linha) {
                $minuscula = mb_strtolower($linha, 'UTF-8');
                foreach ($padroes as $padrao) {
                    if (mb_strpos($minuscula, $padrao) !== false) {
                        // Só caminho e linha: o conteúdo pode ser credencial.
                        $encontrados[] = $this->relativo($caminho) . ':' . ($numero + 1);
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $encontrados,
            'Referência a runtime PHP anterior ao 8.5 (o projeto exige >=8.5.12 <8.6). Locais: '
                . implode(', ', $encontrados)
        );
    }

    /**
     * A varredura precisa realmente estar olhando para os arquivos do projeto:
     * sem esta âncora, um erro no filtro deixaria os testes acima verdes por
     * não terem lido nada.
     *
     * As âncoras têm que ser arquivos RASTREADOS pelo git. `inclusos/config.php`
     * já foi usada aqui e quebrava o CI: é gitignorada, existe na máquina de
     * quem desenvolve e nunca em um checkout limpo. Que a varredura também
     * alcance arquivos gitignorados continua verificado logo abaixo, por
     * `logs/` estar em DIRETORIOS_IGNORADOS e o restante não.
     */
    public function testVarreduraAlcancaOsArquivosDoProjeto(): void
    {
        $php = array_map(fn (string $caminho): string => $this->relativo($caminho), $this->arquivosPhp());

        $this->assertContains('inclusos/Telegram.php', $php);
        $this->assertContains('comandos/ver_audio.php', $php);
        $this->assertContains('inclusos/funcoes.php', $php);
        $this->assertGreaterThan(40, count($php));

        $texto = array_map(fn (string $caminho): string => $this->relativo($caminho), $this->arquivosDeTexto());
        $this->assertContains('README.md', $texto);
        $this->assertContains('docs/OPERACAO.md', $texto);
        $this->assertContains('.github/workflows/ci.yml', $texto);
        $this->assertContains('.github/workflows/security.yml', $texto);
        $this->assertContains('scripts/install/instalar_linux.sh', $texto);
    }

    /**
     * O tokenizer precisa enxergar chamadas reais e ignorar comentários — é o
     * que separa "curl_close() foi removida" (comentário legítimo, e existente
     * hoje em quatro arquivos) de uma chamada de verdade.
     */
    public function testBuscaPorIdentificadorIgnoraComentarioEString(): void
    {
        $comChamada = "<?php\ncurl_close(\$ch);\n";
        $semChamada = "<?php\n// curl_close(\$ch) foi removida\n\$x = 'curl_close';\n";

        $this->assertSame([2], $this->linhasDoIdentificador($comChamada, 'curl_close'));
        $this->assertSame([], $this->linhasDoIdentificador($semChamada, 'curl_close'));
    }

    // ------------------------------------------------------------------
    // Varredura
    // ------------------------------------------------------------------

    /**
     * Ocorrências reais (fora de comentário e string) de um identificador em
     * todos os arquivos PHP do repositório.
     *
     * @param string $identificador
     * @return array<int, string> "caminho/relativo.php:linha"
     */
    private function ocorrenciasDeIdentificadorPhp(string $identificador): array
    {
        $ocorrencias = [];
        foreach ($this->arquivosPhp() as $caminho) {
            if ($caminho === __FILE__) {
                continue;
            }

            foreach ($this->linhasDoIdentificador($this->ler($caminho), $identificador) as $linha) {
                $ocorrencias[] = $this->relativo($caminho) . ':' . $linha;
            }
        }

        return $ocorrencias;
    }

    /**
     * Linhas em que o identificador aparece como token de código.
     *
     * @param string $codigo
     * @param string $identificador
     * @return array<int, int>
     */
    private function linhasDoIdentificador(string $codigo, string $identificador): array
    {
        $linhas = [];
        foreach (token_get_all($codigo) as $token) {
            if (!is_array($token)) {
                continue;
            }
            if ($token[0] !== T_STRING || $token[1] !== $identificador) {
                continue;
            }
            $linhas[] = $token[2];
        }

        return $linhas;
    }

    /**
     * @return array<int, string> Caminhos absolutos dos arquivos .php
     */
    private function arquivosPhp(): array
    {
        return array_values(array_filter(
            $this->arquivosDoProjeto(),
            static fn (string $caminho): bool => str_ends_with($caminho, '.php')
        ));
    }

    /**
     * @return array<int, string> Caminhos absolutos dos arquivos textuais
     */
    private function arquivosDeTexto(): array
    {
        return array_values(array_filter($this->arquivosDoProjeto(), static function (string $caminho): bool {
            $extensao = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));

            return in_array($extensao, self::EXTENSOES_DE_TEXTO, true);
        }));
    }

    /**
     * @return array<int, string> Caminhos absolutos, ordenados
     */
    private function arquivosDoProjeto(): array
    {
        $raiz = $this->raiz();
        $iterador = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $caminhos = [];
        /** @var SplFileInfo $arquivo */
        foreach ($iterador as $arquivo) {
            if (!$arquivo->isFile()) {
                continue;
            }

            $caminho = $arquivo->getPathname();
            $relativo = $this->relativo($caminho);
            if ($this->ignorado($relativo)) {
                continue;
            }

            $caminhos[] = $caminho;
        }

        sort($caminhos);

        return $caminhos;
    }

    private function ignorado(string $relativo): bool
    {
        foreach (self::DIRETORIOS_IGNORADOS as $diretorio) {
            if (str_starts_with($relativo, $diretorio . '/')) {
                return true;
            }
        }

        return in_array($relativo, self::ARQUIVOS_IGNORADOS, true);
    }

    private function raiz(): string
    {
        $raiz = realpath(__DIR__ . '/../..');
        if ($raiz === false) {
            throw new RuntimeException('Não foi possível resolver a raiz do projeto.');
        }

        return $raiz;
    }

    private function relativo(string $caminho): string
    {
        return ltrim(substr($caminho, strlen($this->raiz())), '/');
    }

    private function ler(string $caminho): string
    {
        $conteudo = file_get_contents($caminho);
        if ($conteudo === false) {
            throw new RuntimeException("Não foi possível ler {$this->relativo($caminho)}.");
        }

        return $conteudo;
    }
}
