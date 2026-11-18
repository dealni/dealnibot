<?php

declare(strict_types=1);

namespace Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use ValueError;

/**
 * Contrato da extensão JSON usada pela aplicação no PHP 8.5.
 *
 * O teste exercita as bordas que não podem ser inferidas apenas pelo retorno
 * `mixed` das funções: UTF-8, exceções, profundidade, tipos numéricos e o
 * conjunto deliberadamente pequeno de flags de json_validate().
 */
final class ExtJsonContratoTest extends TestCase
{
    private const UTF8_INVALIDO = "\xB1\x31";

    /** @return mixed */
    private static function decodificar(string $json, int $flags = JSON_THROW_ON_ERROR): mixed
    {
        return json_decode($json, true, 512, $flags);
    }

    /** @return string|false */
    private static function codificar(mixed $valor, int $flags): string|false
    {
        return json_encode($valor, $flags);
    }

    private static function profundidadeInvalida(): int
    {
        return random_int(0, 0);
    }

    private static function flagNaoAceita(): int
    {
        return JSON_INVALID_UTF8_SUBSTITUTE;
    }

    /** @return mixed */
    private static function validarComArgumentos(mixed ...$argumentos): mixed
    {
        return (new \ReflectionFunction('json_validate'))->invokeArgs($argumentos);
    }

    public function testJsonEhExtensaoObrigatoriaDoManifestoEEstáDisponivel(): void
    {
        $this->assertTrue(extension_loaded('json'));
        $this->assertTrue(function_exists('json_encode'));
        $this->assertTrue(function_exists('json_decode'));
        $this->assertTrue(function_exists('json_validate'));

        $manifesto = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($manifesto);
        $require = $manifesto['require'] ?? null;
        $this->assertIsArray($require);
        $this->assertSame('*', $require['ext-json'] ?? null);
    }

    public function testEncodeExigeUtf8MasSubstitutoPreservaEstrutura(): void
    {
        try {
            self::codificar(['texto' => self::UTF8_INVALIDO], JSON_THROW_ON_ERROR);
            $this->fail('JSON_THROW_ON_ERROR deveria rejeitar UTF-8 inválido.');
        } catch (JsonException $erro) {
            $this->assertSame(JSON_ERROR_UTF8, $erro->getCode());
        }

        $json = self::codificar(
            ['texto' => self::UTF8_INVALIDO],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        $this->assertIsString($json);
        $decodificado = self::decodificar($json);
        $this->assertIsArray($decodificado);
        $this->assertSame("\xEF\xBF\xBD1", $decodificado['texto'] ?? null);
    }

    public function testEncodeLancaParaTipoNaoSerializavelEFormaLegadaRetornaFalse(): void
    {
        $recurso = fopen('php://memory', 'rb');
        if ($recurso === false) {
            $this->fail('O recurso de memória precisa abrir para exercitar o contrato.');
        }

        try {
            try {
                self::codificar(['recurso' => $recurso], JSON_THROW_ON_ERROR);
                $this->fail('JSON_THROW_ON_ERROR deveria rejeitar recursos.');
            } catch (JsonException $erro) {
                $this->assertSame(JSON_ERROR_UNSUPPORTED_TYPE, $erro->getCode());
            }

            $this->assertFalse(self::codificar(['recurso' => $recurso], 0));
            $this->assertSame(JSON_ERROR_UNSUPPORTED_TYPE, json_last_error());
        } finally {
            fclose($recurso);
        }
    }

    public function testDecodeDistingueTiposERejeitaPayloadInvalido(): void
    {
        $valor = self::decodificar('{"objeto":{},"lista":[],"numero":3,"booleano":true,"nulo":null}');
        $this->assertIsArray($valor);
        $this->assertIsArray($valor['objeto'] ?? null);
        $this->assertIsArray($valor['lista'] ?? null);
        $this->assertSame(3, $valor['numero'] ?? null);
        $this->assertTrue($valor['booleano'] ?? false);
        $this->assertNull($valor['nulo'] ?? null);

        // O literal JSON null é válido e não deve ser confundido com erro de
        // parse; por isso o retorno nulo sempre precisa ser acompanhado do
        // código de erro (ou da exceção quando THROW está ativo).
        $this->assertNull(self::decodificar('null', 0));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());

        try {
            self::decodificar('{"incompleto":');
            $this->fail('JSON_THROW_ON_ERROR deveria rejeitar sintaxe inválida.');
        } catch (JsonException $erro) {
            $this->assertSame(JSON_ERROR_SYNTAX, $erro->getCode());
        }
    }

    public function testInteiroGrandePodeSerPreservadoComoString(): void
    {
        $valor = self::decodificar('{"id":9223372036854775808}', JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
        $this->assertIsArray($valor);
        $this->assertSame('9223372036854775808', $valor['id'] ?? null);
    }

    public function testLimiteDeProfundidadeFalhaFechado(): void
    {
        $jsonProfundo = str_repeat('[', 513) . str_repeat(']', 513);

        try {
            self::decodificar($jsonProfundo);
            $this->fail('Decode acima da profundidade deveria lançar JsonException.');
        } catch (JsonException $erro) {
            $this->assertSame(JSON_ERROR_DEPTH, $erro->getCode());
        }

        $validado = json_validate($jsonProfundo, 512);
        $this->assertFalse($validado);
        $this->assertSame(JSON_ERROR_DEPTH, json_last_error());
    }

    public function testValidateSoAceitaIgnorarUtf8InvalidoENaoLancaExcecaoDeSintaxe(): void
    {
        $json = '{"texto":"' . self::UTF8_INVALIDO . '"}';

        $this->assertFalse(json_validate($json));
        $this->assertSame(JSON_ERROR_UTF8, json_last_error());
        $this->assertTrue(json_validate($json, 512, JSON_INVALID_UTF8_IGNORE));
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertFalse(json_validate('{"incompleto":'));
        $this->assertSame(JSON_ERROR_SYNTAX, json_last_error());
    }

    public function testProfundidadeInvalidaLancaValueError(): void
    {
        $this->expectException(ValueError::class);
        $resultado = self::validarComArgumentos('{}', self::profundidadeInvalida());
        $this->assertTrue($resultado);
    }

    public function testValidateRejeitaFlagsQueNaoSaoDeValidacao(): void
    {
        $this->expectException(ValueError::class);
        $resultado = self::validarComArgumentos('{}', 512, self::flagNaoAceita());
        $this->assertTrue($resultado);
    }
}
