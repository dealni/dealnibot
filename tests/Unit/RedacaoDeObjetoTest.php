<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProcessadorDeRedacao;
use App\Support\RedatorJson;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * `json_decode($corpo)` — sem o segundo argumento — devolve stdClass. A
 * recursão do redator só descia em array, então um corpo de gateway
 * decodificado assim ia em claro para o disco. Não há chamador assim hoje nos
 * bots; o redator é um controle de segurança e não pode depender de quem o
 * chama para valer.
 */
final class RedacaoDeObjetoTest extends TestCase
{
    public function testObjetoNoContextoEhRedigidoComoArray(): void
    {
        $objeto = json_decode('{"access_token":"SEGREDO","ok":true}');

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('SEGREDO', $saida);
        $this->assertStringContainsString(ProcessadorDeRedacao::SUBSTITUTO, $saida);
        $this->assertStringContainsString('"ok":true', $saida);
    }

    public function testObjetoAninhadoTambemEhAlcancado(): void
    {
        $objeto = json_decode('{"envelope":{"dados":{"cpf":"12345678909"}}}');

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('12345678909', $saida);
    }

    public function testObjetoVazioContinuaObjetoENaoViraLista(): void
    {
        // Virar `[]` mudaria o formato do evento para quem consome o JSON.
        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['vazio' => json_decode('{}')])
        );

        $this->assertStringContainsString('"vazio":{}', $saida);
    }

    public function testProfundidadeMaximaDescartaValorNoLimite(): void
    {
        $this->assertSame(
            [],
            ProcessadorDeRedacao::redigirEstrutura(['token' => 'SEGREDO'], 12)
        );
    }

    public function testRedacaoDeArrayProfundoMantemAProfundidadeEsperada(): void
    {
        $dados = ['token' => 'SEGREDO'];
        for ($nivel = 0; $nivel < 6; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $redigido = ProcessadorDeRedacao::redigirEstrutura($dados);
        $cursor = $redigido;
        for ($nivel = 0; $nivel < 6; $nivel++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nivel'] ?? null;
        }

        $this->assertIsArray($cursor);
        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $cursor['token'] ?? null);
    }

    public function testMensagemDeExcecaoEhRedigidaAntesDoFormatter(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable(),
            'teste',
            Level::Error,
            'falha',
            ['exception' => new \RuntimeException('authorization=Bearer SEGREDO')],
        );

        $redigido = (new ProcessadorDeRedacao())($record);
        $excecao = $redigido->context['exception'] ?? null;

        $this->assertIsArray($excecao);
        $this->assertSame(
            'authorization=' . ProcessadorDeRedacao::SUBSTITUTO,
            $excecao['message'] ?? null
        );
        $this->assertStringNotContainsString('SEGREDO', (string) json_encode($redigido->context));
    }

    public function testMarcadorDeExcecaoContinuaValidoDepoisDeOutraExcecao(): void
    {
        $primeiro = ProcessadorDeRedacao::redigirEstrutura([
            'exception' => new \RuntimeException('primeira falha'),
        ])['exception'] ?? null;
        $this->assertIsArray($primeiro);
        $marcadorPrimeiro = $primeiro[ProcessadorDeRedacao::MARCADOR_EXCECAO] ?? null;
        $this->assertIsObject($marcadorPrimeiro);

        $segundo = ProcessadorDeRedacao::redigirEstrutura([
            'exception' => new \RuntimeException('segunda falha'),
        ])['exception'] ?? null;
        $this->assertIsArray($segundo);
        $marcadorSegundo = $segundo[ProcessadorDeRedacao::MARCADOR_EXCECAO] ?? null;
        $this->assertIsObject($marcadorSegundo);

        $this->assertTrue(ProcessadorDeRedacao::ehMarcadorDeExcecao($marcadorPrimeiro));
        $this->assertTrue(ProcessadorDeRedacao::ehMarcadorDeExcecao($marcadorSegundo));
    }

    public function testObjetoJsonSerializableEhRedigidoRecursivamente(): void
    {
        $objeto = new class () implements \JsonSerializable {
            /** @return array<string, bool|string> */
            public function jsonSerialize(): array
            {
                return ['access_token' => 'SEGREDO', 'ok' => true];
            }
        };

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('SEGREDO', $saida);
        $this->assertStringContainsString(ProcessadorDeRedacao::SUBSTITUTO, $saida);
    }

    public function testJsonSerializableTemPrecedenciaSobreStringable(): void
    {
        $objeto = new class () implements \JsonSerializable, \Stringable {
            /** @return array<string, string> */
            public function jsonSerialize(): array
            {
                return ['origem' => 'json'];
            }

            public function __toString(): string
            {
                return 'origem=texto';
            }
        };

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['objeto' => $objeto]);
        $valor = $redigido['objeto'] ?? null;

        $this->assertIsArray($valor);
        $this->assertSame('json', $valor['origem'] ?? null);
    }

    public function testJsonSerializableQueRetornaAPropriaInstanciaNaoEhSerializadoDeNovo(): void
    {
        $objeto = new class () implements \JsonSerializable {
            private int $chamadas = 0;

            public function jsonSerialize(): mixed
            {
                $this->chamadas++;

                return $this->chamadas === 1 ? $this : ['segundo' => 'SEGREDO'];
            }
        };

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['objeto' => $objeto]);

        $this->assertSame($objeto::class, $redigido['objeto'] ?? null);
    }

    public function testJsonSerializableRespeitaOTetoDeProfundidadeAoRedigir(): void
    {
        $dados = ['token' => 'SEGREDO'];
        for ($nivel = 0; $nivel < 10; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $objeto = new class ($dados) implements \JsonSerializable {
            /** @param array<string, mixed> $dados */
            public function __construct(private array $dados)
            {
            }

            /** @return array<string, mixed> */
            public function jsonSerialize(): array
            {
                return $this->dados;
            }
        };

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['objeto' => $objeto]);
        $cursor = $redigido['objeto'] ?? null;
        for ($nivel = 0; $nivel < 9; $nivel++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nivel'] ?? null;
        }

        $this->assertIsArray($cursor);
        $this->assertSame([], $cursor['nivel'] ?? null);
    }

    public function testObjetoComFallbackJsonRespeitaOTetoDeProfundidade(): void
    {
        $dados = ['token' => 'SEGREDO'];
        for ($nivel = 0; $nivel < 9; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $objeto = new class () {
            /** @var array<string, mixed> */
            public array $nivel = [];
        };
        $objeto->nivel = $dados;

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['objeto' => $objeto]);
        $cursor = $redigido['objeto'] ?? null;
        for ($nivel = 0; $nivel < 9; $nivel++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nivel'] ?? null;
        }

        $this->assertIsArray($cursor);
        $this->assertSame([], $cursor['nivel'] ?? null);
    }

    public function testObjetoComFallbackJsonFalhaFechadoNoLimiteDoDecodificador(): void
    {
        $dados = 'folha';
        for ($nivel = 0; $nivel < 511; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $objeto = new class () {
            public mixed $nivel = null;
        };
        $objeto->nivel = $dados;

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['objeto' => $objeto]);

        $this->assertSame($objeto::class, $redigido['objeto'] ?? null);
    }

    public function testRedacaoDeJsonPreservaChaveEFormato(): void
    {
        $this->assertSame(
            '{"access_token":"' . ProcessadorDeRedacao::SUBSTITUTO . '","ok":true}',
            ProcessadorDeRedacao::redigirTexto('{"access_token":"SEGREDO","ok":true}')
        );
    }

    public function testRedacaoDeJsonRedigeValorDeObjetoInteiro(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta da API | {"envelope":{"token":{"valor":"SEGREDO","id":7}},"ok":true}'
        );

        $this->assertStringNotContainsString('SEGREDO', $redigido);
        $this->assertStringContainsString(
            '"envelope":{"token":"' . ProcessadorDeRedacao::SUBSTITUTO . '"}',
            $redigido
        );
        $this->assertStringContainsString('"ok":true', $redigido);
    }

    public function testRedacaoDeJsonRedigeChaveProibidaDentroDeArray(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            '{"items":[{"id":1,"authorization":["SEGREDO","SEGREDO-2"]},{"id":2}]}'
        );

        $json = json_decode($redigido, true);
        $this->assertIsArray($json);
        $items = $json['items'] ?? null;
        $this->assertIsArray($items);
        $primeiroItem = $items[0] ?? null;
        $this->assertIsArray($primeiroItem);
        $segundoItem = $items[1] ?? null;
        $this->assertIsArray($segundoItem);
        $this->assertSame(
            ProcessadorDeRedacao::SUBSTITUTO,
            $primeiroItem['authorization'] ?? null
        );
        $this->assertSame(2, $segundoItem['id'] ?? null);
        $this->assertStringNotContainsString('SEGREDO', $redigido);
    }

    public function testRedacaoDeFragmentoJsonRedigeArraySemObjetoExterno(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta parcial: "token":["SEGREDO","SEGREDO-2"] | id=42'
        );

        $this->assertSame(
            'Resposta parcial: "token":"' . ProcessadorDeRedacao::SUBSTITUTO . '" | id=42',
            $redigido
        );
        $this->assertStringNotContainsString('SEGREDO', $redigido);
    }

    public function testJsonInvalidoComChaveProibidaFalhaFechado(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta incompleta: {"token":{"valor":"SEGREDO"}'
        );

        $this->assertSame(
            'Resposta incompleta: "' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $redigido
        );
        $this->assertStringNotContainsString('SEGREDO', $redigido);
    }

    public function testJsonEscapadoInvalidoComChaveProibidaFalhaFechado(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta textual: {\"token\":\"SEGREDO\"}'
        );

        $this->assertSame(
            'Resposta textual: "' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $redigido
        );
        $this->assertStringNotContainsString('SEGREDO', $redigido);
    }

    public function testEscalarJsonSemObjetoExternoTambemEhRedigido(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta parcial: "token":"ESCALAR-SECRETO" | ok=true'
        );

        $this->assertSame(
            'Resposta parcial: "token":"' . ProcessadorDeRedacao::SUBSTITUTO . '" | ok=true',
            $redigido
        );
        $this->assertStringNotContainsString('ESCALAR-SECRETO', $redigido);
    }

    public function testFragmentoJsonInvalidoFalhaFechado(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta parcial: "token":["FRAGMENTO-SECRETO"'
        );

        $this->assertSame(
            'Resposta parcial: "token":"' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $redigido
        );
        $this->assertStringNotContainsString('FRAGMENTO-SECRETO', $redigido);
    }

    public function testRedacaoContinuaAposFragmentoJsonAnterior(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'prefixo "safe":{"ok":true} | "token":["FRAGMENTO-SECRETO"]'
        );

        $this->assertSame(
            'prefixo "safe":{"ok":true} | "token":"' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $redigido
        );
        $this->assertStringNotContainsString('FRAGMENTO-SECRETO', $redigido);
    }

    public function testRedacaoContinuaAposBlocoJsonInvalido(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'prefixo {"token":"PRIMEIRO-SECRETO"] depois {"password":"SEGUNDO-SECRETO"}'
        );

        $this->assertSame(
            'prefixo "' . ProcessadorDeRedacao::SUBSTITUTO
                . '" depois {"password":"' . ProcessadorDeRedacao::SUBSTITUTO . '"}',
            $redigido
        );
        $this->assertStringNotContainsString('PRIMEIRO-SECRETO', $redigido);
        $this->assertStringNotContainsString('SEGUNDO-SECRETO', $redigido);
    }

    public function testBlocoJsonInvalidoSemChaveProibidaNaoEhDescartado(): void
    {
        $texto = 'prefixo {"safe":"valor-publico"] sufixo';

        $this->assertSame($texto, ProcessadorDeRedacao::redigirTexto($texto));
    }

    public function testBlocoJsonInvalidoComChaveEscapadaInvalidaFalhaFechado(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'prefixo {"tok\\uZZZZ":"BLOCO-SECRETO"] sufixo'
        );

        $this->assertSame(
            'prefixo "' . ProcessadorDeRedacao::SUBSTITUTO . '" sufixo',
            $redigido
        );
        $this->assertStringNotContainsString('BLOCO-SECRETO', $redigido);
    }

    public function testJsonRedigidoMantemUnicodeETrataBarrasComoJsonCanonico(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'Resposta: {"safe":"não-ascii","url":"https://example.test/a","token":{"inner":"PAYLOAD"}}'
        );

        $this->assertSame(
            'Resposta: {"safe":"não-ascii","url":"https://example.test/a","token":"'
                . ProcessadorDeRedacao::SUBSTITUTO . '"}',
            $redigido
        );
        $this->assertStringNotContainsString('PAYLOAD', $redigido);
    }

    public function testJsonValidoSemRedacaoPreservaEspacosEFormaOriginal(): void
    {
        $texto = 'prefixo {"ok": true, "url":"https://example.test/a"} sufixo';

        $this->assertSame($texto, ProcessadorDeRedacao::redigirTexto($texto));
    }

    public function testScannerJsonIgnoraDelimitadoresEAspasEscapadasEmStrings(): void
    {
        $redigido = ProcessadorDeRedacao::redigirTexto(
            'prefixo {"message":"fecho } ] e \\" aspas","quote":"literal \\"","items":[{"token":"SCANNER-SECRETO"}],"ok":true} sufixo'
        );

        $this->assertSame(
            'prefixo {"message":"fecho } ] e \\" aspas","quote":"literal \\"","items":[{"token":"'
                . ProcessadorDeRedacao::SUBSTITUTO . '"}],"ok":true} sufixo',
            $redigido
        );
        $this->assertStringNotContainsString('SCANNER-SECRETO', $redigido);
    }

    public function testFallbackJsonSubstituiUtf8InvalidoSemAbandonarRedacao(): void
    {
        $objeto = new class () {
            public string $texto = '';
        };
        $objeto->texto = "prefixo\xFF";

        $redigido = ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto]);
        $corpo = $redigido['corpo'] ?? null;

        $this->assertIsArray($corpo);
        $this->assertSame("prefixo\xEF\xBF\xBD", $corpo['texto'] ?? null);
    }

    public function testCallbackDeJsonRespeitaLimiteDeProfundidadeAoRedigir(): void
    {
        $dados = ['token' => 'LIMITE-SECRETO'];
        for ($nivel = 0; $nivel < 11; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $redigido = json_decode(
            ProcessadorDeRedacao::redigirTexto((string) json_encode($dados)),
            true
        );
        $this->assertIsArray($redigido);
        $cursor = $redigido;
        for ($nivel = 0; $nivel < 11; $nivel++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nivel'] ?? null;
        }

        $this->assertIsArray($cursor);
        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $cursor['token'] ?? null);
    }

    public function testCallbackDeJsonDescartaValorNoLimiteDeProfundidade(): void
    {
        $dados = ['token' => 'LIMITE-SECRETO'];
        for ($nivel = 0; $nivel < 12; $nivel++) {
            $dados = ['nivel' => $dados];
        }

        $redigido = json_decode(
            ProcessadorDeRedacao::redigirTexto((string) json_encode($dados)),
            true
        );
        $this->assertIsArray($redigido);
        $cursor = $redigido;
        for ($nivel = 0; $nivel < 12; $nivel++) {
            $this->assertIsArray($cursor);
            $cursor = $cursor['nivel'] ?? null;
        }

        $this->assertSame([], $cursor);
    }

    public function testChaveJsonEscapadaOuInvalidaFalhaFechadoNoFragmento(): void
    {
        $escapada = ProcessadorDeRedacao::redigirTexto(
            'prefixo "t\\u006fken":["ESCAPE-SECRETO"]'
        );
        $invalida = ProcessadorDeRedacao::redigirTexto(
            'prefixo "tok\\uZZZZ":["CHAVE-SECRETO"]'
        );

        $this->assertSame(
            'prefixo "t\\u006fken":"' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $escapada
        );
        $this->assertSame(
            'prefixo "tok\\uZZZZ":"' . ProcessadorDeRedacao::SUBSTITUTO . '"',
            $invalida
        );
        $this->assertStringNotContainsString('ESCAPE-SECRETO', $escapada);
        $this->assertStringNotContainsString('CHAVE-SECRETO', $invalida);
    }

    public function testRedatorJsonMantemObjetoAoDecodificarFragmento(): void
    {
        $tipos = [];
        $redator = new RedatorJson(
            ProcessadorDeRedacao::SUBSTITUTO,
            static function (mixed $valor) use (&$tipos): mixed {
                $tipos[] = get_debug_type($valor);

                return $valor;
            },
            static fn (string $chave): bool => false,
        );

        $this->assertSame(
            'prefixo "safe":{"ok":true}',
            $redator->redigir('prefixo "safe":{"ok":true}')
        );
        $this->assertSame([
            \stdClass::class,
            \stdClass::class,
        ], $tipos);
    }

    public function testRedatorJsonProcessaArrayDoFragmentoDepoisDoBloco(): void
    {
        $chamadas = 0;
        $redator = new RedatorJson(
            ProcessadorDeRedacao::SUBSTITUTO,
            static function (mixed $valor) use (&$chamadas): mixed {
                $chamadas++;

                return $valor;
            },
            static fn (string $chave): bool => false,
        );

        $this->assertSame(
            'prefixo "safe":[1,2]',
            $redator->redigir('prefixo "safe":[1,2]')
        );
        $this->assertSame(2, $chamadas);
    }

    public function testRedatorJsonPublicaFragmentoRedigidoQuandoCallbackMudaObjeto(): void
    {
        $redator = new RedatorJson(
            ProcessadorDeRedacao::SUBSTITUTO,
            static fn (mixed $valor): mixed => [
                'token' => ProcessadorDeRedacao::SUBSTITUTO,
                'ok' => true,
            ],
            static fn (string $chave): bool => false,
        );

        $redigido = $redator->redigir('prefixo "payload":{"token":"SEGREDO","ok":true}');

        $this->assertSame(
            'prefixo "payload":{"token":"' . ProcessadorDeRedacao::SUBSTITUTO . '","ok":true}',
            $redigido
        );
        $this->assertStringNotContainsString('SEGREDO', $redigido);
    }

    public function testChaveComCaseFoldingCompletoContinuaProibida(): void
    {
        // MB_CASE_FOLD trata a expansão Unicode ß -> ss; lowercase simples
        // deixaria essa variante de "password" passar pela lista.
        $this->assertTrue(ProcessadorDeRedacao::chaveProibida('paßword'));
    }

    public function testChaveComUtf8InvalidoEhRedigidaPorFalhaFechada(): void
    {
        $chave = "nome\xFF";
        $redigido = ProcessadorDeRedacao::redigirEstrutura([$chave => 'SEGREDO']);

        $this->assertSame(ProcessadorDeRedacao::SUBSTITUTO, $redigido[$chave] ?? null);
    }

    public function testObjetoJsonSerializavelEhRedigidoAntesDoJsonFormatter(): void
    {
        $objeto = new class () implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['access_token' => 'SEGREDO', 'ok' => true];
            }
        };

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('SEGREDO', $saida);
        $this->assertStringContainsString(ProcessadorDeRedacao::SUBSTITUTO, $saida);
    }

    public function testObjetoStringableEhRedigidoAntesDoJsonFormatter(): void
    {
        $objeto = new class () implements \Stringable {
            public function __toString(): string
            {
                return 'token=SEGREDO';
            }
        };

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('SEGREDO', $saida);
        $this->assertStringContainsString('token=' . ProcessadorDeRedacao::SUBSTITUTO, $saida);
    }

    public function testObjetoComPropriedadesPublicasEhRedigidoAntesDoJsonFormatter(): void
    {
        $objeto = new class () {
            public string $access_token = 'SEGREDO';

            public bool $ok = true;
        };

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringNotContainsString('SEGREDO', $saida);
        $this->assertStringContainsString(ProcessadorDeRedacao::SUBSTITUTO, $saida);
    }

    public function testObjetoSemPropriedadesPublicasViraEstruturaVaziaSemVazarNada(): void
    {
        // O fallback serializa o objeto como JSON e o redige como ARRAY: um
        // objeto sem propriedade pública não tem o que vazar e sai como
        // estrutura vazia — nunca como o nome da classe nem como `null`.
        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['vazio' => new class () {}])
        );

        $this->assertStringContainsString('"vazio":[]', $saida);
        $this->assertStringNotContainsString('class@anonymous', $saida);
    }

    public function testObjetoAninhadoVazioViraEstruturaVazia(): void
    {
        $objeto = new class () {
            public object $vazio;

            public function __construct()
            {
                $this->vazio = new class () {};
            }
        };

        $saida = (string) json_encode(
            ProcessadorDeRedacao::redigirEstrutura(['corpo' => $objeto])
        );

        $this->assertStringContainsString('"vazio":[]', $saida);
        $this->assertStringNotContainsString('class@anonymous', $saida);
    }
}
