<?php

declare(strict_types=1);

namespace Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../scripts/quality/env_schema.php';

/**
 * Prova do portão de ambiente: o parser de `.env.example` e o validador de
 * tipos que `scripts/quality/check_env.php` usa.
 *
 * O portão só serve se reprovar. Por isso cada regra aparece aqui em par —
 * o valor que passa e o valor que não pode passar.
 */
final class ContratoDeAmbienteTest extends TestCase
{
    /**
     * Schema de referência, com um exemplar de cada tipo aceito.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function schema(): array
    {
        return [
            'APP_PATH' => ['type' => 'absolute_path'],
            'BASE_URL' => ['type' => 'https_url'],
            'COUNT' => ['type' => 'positive_integer'],
            'NAME' => ['type' => 'string'],
            'OPTIONAL' => ['type' => 'string', 'allow_empty' => true, 'optional' => true],
        ];
    }

    /** @return array<string, string> */
    private static function valores(): array
    {
        return \parseEnvExample(
            "export APP_PATH=\"/srv/app\"\n"
            . "BASE_URL=https://example.com/subpath\n"
            . "COUNT=2\n"
            . "NAME=produto\n"
            . "OPTIONAL=\n",
        );
    }

    public function testExemploCompletoEAceitoComAsAspasRemovidas(): void
    {
        $valores = self::valores();

        self::assertSame('/srv/app', $valores['APP_PATH']);
        self::assertSame('', $valores['OPTIONAL']);
        self::assertSame([], \validateEnvExample($valores, self::schema()));
    }

    public function testCaminhoRelativoReprova(): void
    {
        $valores = array_replace(self::valores(), ['APP_PATH' => 'relativo']);

        self::assertNotSame([], \validateEnvExample($valores, self::schema()));
    }

    public function testUrlBaseComEsquemaOuComponenteProibidoReprova(): void
    {
        $recusadas = [
            'http://example.com',
            'https://user:pass@example.com',
            'https://example.com/path?x=1',
            'https://example.com/#fragmento',
        ];

        foreach ($recusadas as $url) {
            $valores = array_replace(self::valores(), ['BASE_URL' => $url]);
            self::assertNotSame([], \validateEnvExample($valores, self::schema()), "URL-base inválida foi aceita: {$url}");
        }
    }

    /**
     * Origem é mais estreita que URL-base: as rotas de saúde e o webhook
     * registrado são ancorados em `/`, então caminho não entra.
     */
    public function testOrigemHttpsRecusaCaminhoEAceitaBarraFinal(): void
    {
        $schema = ['ORIGEM' => ['type' => 'https_origin']];

        self::assertSame([], \validateEnvExample(['ORIGEM' => 'https://example.com'], $schema));
        self::assertSame([], \validateEnvExample(['ORIGEM' => 'https://example.com/'], $schema));
        self::assertNotSame([], \validateEnvExample(['ORIGEM' => 'https://example.com/webhook'], $schema));
        self::assertNotSame([], \validateEnvExample(['ORIGEM' => 'http://example.com'], $schema));
    }

    public function testInteiroNaoPositivoReprova(): void
    {
        $valores = array_replace(self::valores(), ['COUNT' => '0']);

        self::assertNotSame([], \validateEnvExample($valores, self::schema()));
    }

    /**
     * O contrato público é textual, mas um chamador pode montar o array na
     * mão. O valor não textual precisa reprovar SEM passar por ctype_digit():
     * desde o PHP 8.1 isso emite depreciação, e o PHP futuro muda a leitura do
     * inteiro de código ASCII para string.
     */
    public function testValorNaoTextualReprovaSemEmitirDeprecacao(): void
    {
        $deprecacoes = [];
        set_error_handler(static function (int $severidade, string $mensagem) use (&$deprecacoes): bool {
            $deprecacoes[] = $mensagem;

            return true;
        });

        try {
            $valores = array_replace(self::valores(), ['COUNT' => 2]);
            $erros = \validateEnvExample($valores, self::schema());
        } finally {
            restore_error_handler();
        }

        self::assertNotSame([], $erros, 'Valor inteiro deveria reprovar como entrada não textual.');
        self::assertSame([], $deprecacoes, 'Valor inteiro não pode emitir depreciação de ctype_digit().');
    }

    public function testVazioProibidoReprovaEOpcionalVazioPassa(): void
    {
        $valores = array_replace(self::valores(), ['NAME' => '']);

        self::assertNotSame([], \validateEnvExample($valores, self::schema()));
    }

    public function testOpcionalAusenteEAceitaEObrigatoriaAusenteReprova(): void
    {
        $semOpcional = self::valores();
        unset($semOpcional['OPTIONAL']);
        self::assertSame([], \validateEnvExample($semOpcional, self::schema()));

        $semObrigatoria = self::valores();
        unset($semObrigatoria['COUNT']);
        self::assertNotSame([], \validateEnvExample($semObrigatoria, self::schema()));
    }

    /** Variável a mais é tão errada quanto variável a menos: as duas listas são o contrato. */
    public function testChaveSemSchemaReprova(): void
    {
        $valores = self::valores() + ['EXTRA' => 'x'];

        self::assertNotSame([], \validateEnvExample($valores, self::schema()));
    }

    public function testSintaxeInvalidaReprova(): void
    {
        $this->expectException(InvalidArgumentException::class);

        \parseEnvExample("SEM_IGUAL\n");
    }

    public function testChaveDuplicadaReprova(): void
    {
        $this->expectException(InvalidArgumentException::class);

        \parseEnvExample("DUPLICADA=1\nDUPLICADA=2\n");
    }

    /**
     * O exemplo REAL deste repositório passa pelo portão, e a contraprova
     * derivada dele reprova. Sem o segundo caso, um portão que aceitasse
     * qualquer coisa continuaria verde.
     */
    public function testOpcaoFileAprovaOExemploRealEReprovaAContraprova(): void
    {
        $raiz = dirname(__DIR__, 2);
        $temporario = tempnam(sys_get_temp_dir(), 'contrato-ambiente-');
        self::assertIsString($temporario);

        try {
            $exemploReal = file_get_contents($raiz . '/.env.example');
            self::assertIsString($exemploReal);

            self::assertNotFalse(file_put_contents($temporario, $exemploReal));
            self::assertSame(0, self::rodarCheckEnv($raiz, $temporario));

            $exemploInvalido = preg_replace('/^[A-Z]+_SNAPSHOTS_DIR=.*$/m', 'INVALID_SNAPSHOTS_DIR=relativo', $exemploReal, 1);
            self::assertIsString($exemploInvalido);
            self::assertNotSame($exemploReal, $exemploInvalido, 'A contraprova precisa mudar o exemplo real.');

            self::assertNotFalse(file_put_contents($temporario, $exemploInvalido));
            self::assertSame(1, self::rodarCheckEnv($raiz, $temporario));
        } finally {
            @unlink($temporario);
        }
    }

    /** Roda o portão em subprocesso e devolve só o código de saída; a saída fica retida. */
    private static function rodarCheckEnv(string $raiz, string $arquivo): int
    {
        $processo = proc_open(
            [PHP_BINARY, $raiz . '/scripts/quality/check_env.php', '--file=' . $arquivo],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $canos,
        );
        if (!is_resource($processo)) {
            throw new RuntimeException('Não foi possível iniciar o subprocesso PHP.');
        }

        foreach ($canos as $cano) {
            if (is_resource($cano)) {
                stream_get_contents($cano);
                fclose($cano);
            }
        }

        return proc_close($processo);
    }
}
