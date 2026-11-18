<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Testes dos helpers definidos em comandos/chatgpt.php.
 *
 * O script NÃO pode ser incluído nos testes: depois de definir as funções,
 * o topo valida o contexto ($telegram/$redis), chama sendChatAction e
 * sendMessage reais e faz cURL para a API da OpenAI. As funções são
 * definidas via Support\ExtratorDeFuncoesDeScript, que extrai apenas as declarações
 * sem executar o fluxo top-level.
 *
 * cortarMensagem() e escapeMarkdown() também existem, idênticas, em
 * comandos/ver_audio.php e comandos/ver_imagem.php; o sincronismo das
 * cópias é garantido por ComandosHelpersDuplicadosTest.
 */
final class ComandosChatgptHelpersTest extends TestCase
{
    private const CAMINHO_SCRIPT = __DIR__ . '/../../comandos/chatgpt.php';

    public static function setUpBeforeClass(): void
    {
        Support\ExtratorDeFuncoesDeScript::carregar(self::CAMINHO_SCRIPT, [
            'cortarMensagem',
            'escapeMarkdown',
            'respostaIaTemMarcadorAberto',
        ]);
    }

    /** @return array<string, array{string, bool}> */
    public static function markdownDaIaProvider(): array
    {
        return [
            'texto comum' => ['Olá, tudo bem?', false],
            'destaques completos' => ['Olá *mundo* e _amigo_!', false],
            'negrito aberto' => ['Resposta *cortada', true],
            'itálico aberto' => ['nome_arquivo.php', true],
            'markdown comum' => ['**Destaque**', false],
            'código com marcador literal' => ['Use `nome_arquivo`.', false],
            'link com marcador literal' => ['Veja [referência](https://exemplo.test/a_b)', false],
            'escape' => ['Use \*literal', false],
            'resposta truncada' => [str_repeat('a', 4095) . '*', true],
            'código aberto' => ['Use `nome_arquivo', true],
            'bloco completo' => ["```php\nnome_arquivo * literal\n```", false],
            'bloco aberto' => ["```php\nnome_arquivo", true],
            'destaque aberto após código' => ['Use `nome_arquivo` e *destaque', true],
        ];
    }

    #[DataProvider('markdownDaIaProvider')]
    public function testValidaMarkdownAntesDaPrimeiraTentativa(string $texto, bool $esperado): void
    {
        $this->assertSame($esperado, respostaIaTemMarcadorAberto($texto));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function naoStringProvider(): array
    {
        return [
            'null' => [null],
            'inteiro' => [123],
            'float' => [1.5],
            'booleano true' => [true],
            'booleano false' => [false],
            'array' => [['texto']],
        ];
    }

    /**
     * @param mixed $mensagem
     */
    #[DataProvider('naoStringProvider')]
    public function testCortarMensagemDevolveErroParaNaoString($mensagem): void
    {
        $this->assertSame('Erro ao gerar mensagem.', cortarMensagem($mensagem));
    }

    public function testCortarMensagemMantemMensagemDentroDoLimite(): void
    {
        $this->assertSame('', cortarMensagem(''));
        $this->assertSame('oi, tudo bem?', cortarMensagem('oi, tudo bem?'));
        // Exatamente no limite: devolve intacta, sem corte.
        $this->assertSame('abcde', cortarMensagem('abcde', 5));
    }

    public function testCortarMensagemUsaLimitePadraoDe4096(): void
    {
        $mensagemNoLimite = str_repeat('a', 4096);
        $this->assertSame($mensagemNoLimite, cortarMensagem($mensagemNoLimite));

        // Um caractere acima do limite, sem espaços: corta exatamente em 4096.
        $this->assertSame($mensagemNoLimite, cortarMensagem(str_repeat('a', 4097)));
    }

    public function testCortarMensagemComLimitePadraoCortaNoUltimoEspaco(): void
    {
        // 5001 caracteres; o único espaço dentro dos 4096 primeiros está na
        // posição 4000, então o corte preserva apenas a primeira palavra.
        $mensagem = str_repeat('a', 4000) . ' ' . str_repeat('b', 1000);

        $this->assertSame(str_repeat('a', 4000), cortarMensagem($mensagem));
    }

    public function testCortarMensagemSemEspacoCortaNoLimiteExato(): void
    {
        $this->assertSame('abcde', cortarMensagem('abcdefghij', 5));
    }

    public function testCortarMensagemCortaNoUltimoEspacoAntesDoLimite(): void
    {
        // mb_substr(0, 10) = 'abcde fghi'; último espaço na posição 5.
        $this->assertSame('abcde', cortarMensagem('abcde fghij klm', 10));
    }

    public function testCortarMensagemContaCaracteresMultibyteNaoBytes(): void
    {
        // 5 caracteres de 2 bytes: se o corte fosse por bytes, 3 quebraria o
        // 2º caractere; por caractere, sobram exatamente 3.
        $this->assertSame('ááá', cortarMensagem('ááááá', 3));
        // Emojis de 4 bytes também contam como 1 caractere cada.
        $this->assertSame('😼😼', cortarMensagem('😼😼😼😼', 2));
    }

    public function testCortarMensagemMultibyteCortaNoUltimoEspaco(): void
    {
        // mb_substr(0, 6) = 'áé çõ '; último espaço na posição 5.
        $this->assertSame('áé çõ', cortarMensagem('áé çõ üü', 6));
    }

    public function testCortarMensagemComEspacoNaPosicaoZeroCaiParaCorteDuro(): void
    {
        // Se o único espaço do trecho cortado for o primeiro caractere, o
        // corte "no espaço" devolveria string vazia (e o sendMessage
        // falharia); nesse caso a função cai para o corte duro no limite.
        $this->assertSame(' ab', cortarMensagem(' abcdef', 3));
        // Variante multibyte: espaço na posição 0 seguido de emojis.
        $this->assertSame(' 😼😼', cortarMensagem(' 😼😼😼😼', 3));
        // Espaço em posição > 0 continua sendo o ponto de corte normal.
        $this->assertSame('a', cortarMensagem('a bcdef', 3));
    }

    public function testCortarMensagemRecusaUtf8InvalidoAntesDeContarOuCortar(): void
    {
        // mb_* trata bytes inválidos com substituição silenciosa. O helper não
        // pode devolver uma string que às vezes é inválida (ramo curto) e às
        // vezes já foi alterada (ramo longo): ambos os casos são rejeitados.
        $invalidaCurta = "texto\xFF";
        $invalidaLonga = str_repeat('a', 4096) . "\xFF";

        $this->assertFalse(mb_check_encoding($invalidaCurta, 'UTF-8'));
        $this->assertFalse(mb_check_encoding($invalidaLonga, 'UTF-8'));
        $this->assertSame('Erro ao gerar mensagem.', cortarMensagem($invalidaCurta));
        $this->assertSame('Erro ao gerar mensagem.', cortarMensagem($invalidaLonga));
    }

    public function testCortarMensagemRecusaLimiteNegativo(): void
    {
        // mb_substr() aceitaria -1 e removeria o último caractere, o que não é
        // um limite máximo válido para o contrato deste helper.
        $this->assertSame('Erro ao gerar mensagem.', cortarMensagem('abc', -1));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function escapeMarkdownProvider(): array
    {
        return [
            'underscore' => ['a_b', 'a\\_b'],
            'asterisco' => ['a*b', 'a\\*b'],
            'crase' => ['a`b', 'a\\`b'],
            'todos os especiais juntos' => ['_*`', '\\_\\*\\`'],
            'ocorrencias repetidas' => ['__negrito__', '\\_\\_negrito\\_\\_'],
            'demais caracteres intactos' => [
                'texto normal, [link](url) #titulo!',
                'texto normal, [link](url) #titulo!',
            ],
            'string vazia' => ['', ''],
        ];
    }

    #[DataProvider('escapeMarkdownProvider')]
    public function testEscapeMarkdown(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, escapeMarkdown($entrada));
    }

    public function testEscapeMarkdownNaoEIdempotente(): void
    {
        // Comportamento atual: texto já escapado ganha uma segunda barra
        // (a barra original é preservada e o "_" é re-escapado).
        $this->assertSame('a\\\\_b', escapeMarkdown('a\\_b'));
    }
}
