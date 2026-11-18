<?php

declare(strict_types=1);

namespace App\Support;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Redação obrigatória do contrato de observabilidade: nenhum valor das chaves
 * proibidas sai em claro, em nenhum nível, em nenhum ambiente.
 *
 * Fica no Monolog como PROCESSOR, e não em cada chamador, porque a regra só
 * vale se for impossível de esquecer: chamador novo, canal novo e contexto
 * aninhado herdam a redação sem escrever uma linha. `LoggerFactory` o empurra
 * em TODO canal, sem exceção — inclusive os canais por onde trafega dado
 * financeiro.
 *
 * Duas superfícies são redigidas, e a segunda é a que importa aqui:
 *
 * 1. **Chaves** de `context` e `extra`, recursivamente. É o caso do contrato.
 * 2. **O texto da mensagem.** `escreverLog()` recebe uma string no estilo
 *    `chave=valor | chave=valor`, e é dentro dela que um `token=` ou um `cpf=`
 *    poderia aparecer. O processador mascara esses valores também na string.
 */
final class ProcessadorDeRedacao implements ProcessorInterface
{
    public const SUBSTITUTO = '[REDACTED]';

    /**
     * Marcador interno para o formatter reconhecer uma exceção já reduzida.
     * Um array arbitrário em `context['exception']` não pode virar `err` por
     * semelhança estrutural; este campo só é adicionado quando a origem era
     * realmente um Throwable.
     */
    public const MARCADOR_EXCECAO = '__chatbots_exception';

    /** @var object|null Sentinela não-forjável por dados do chamador. */
    private static ?object $sentinelaExcecao = null;

    private static ?RedatorJson $redatorJson = null;

    /**
     * Lista de campos sensíveis, comparada com folding Unicode e por
     * SUBSTRING: `X-Auth-Token` casa duas vezes, `apiKey`/`api_key`/`secretKey`
     * casam em `key`. É intencionalmente ampla — um campo legítimo redigido
     * por engano custa muito menos que um segredo em disco.
     *
     * Documentos e dados de pagamento também são tratados como sensíveis.
     *
     * @var list<string>
     */
    private const CHAVES_PROIBIDAS = [
        'password', 'senha', 'token', 'authorization', 'cookie', 'session',
        'secret', 'key', 'jwt', 'auth', 'credential', 'cpf', 'cnpj', 'card',
        'cvv', 'pix',
    ];

    /**
     * Teto de recursão. Contexto de erro pode trazer estrutura cíclica ou
     * absurdamente funda; estourar a pilha DENTRO do logger transformaria uma
     * linha de log num erro fatal, que é o oposto do que ele existe para fazer.
     */
    private const PROFUNDIDADE_MAXIMA = 12;

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: self::redigirTexto($record->message),
            context: self::redigirEstrutura($record->context),
            extra: self::redigirEstrutura($record->extra),
        );
    }

    /**
     * Verdadeiro quando o NOME da chave contém qualquer termo proibido.
     */
    public static function chaveProibida(string $chave): bool
    {
        // Chaves são dados externos. Uma chave inválida não deve chegar ao
        // log em claro: a redação falha fechada, além de manter o comparador
        // determinístico em UTF-8.
        if (!mb_check_encoding($chave, 'UTF-8')) {
            return true;
        }

        // MB_CASE_FOLD é a operação documentada para matching sem distinção
        // de caixa; ao contrário do lowercase, ela também trata expansões
        // como ß -> ss.
        $normalizada = mb_convert_case($chave, MB_CASE_FOLD, 'UTF-8');

        foreach (self::CHAVES_PROIBIDAS as $termo) {
            if (str_contains($normalizada, $termo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $dados
     * @return array<array-key, mixed>
     */
    public static function redigirEstrutura(array $dados, int $profundidade = 0): array
    {
        if ($profundidade >= self::PROFUNDIDADE_MAXIMA) {
            return [];
        }

        $redigido = [];
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && self::chaveProibida($chave)) {
                // A CHAVE é preservada de propósito: saber que havia um token
                // ali é diagnóstico; saber qual era é vazamento.
                $redigido[$chave] = self::SUBSTITUTO;
                continue;
            }

            $redigido[$chave] = self::redigirValor($valor, $profundidade + 1);
        }

        return $redigido;
    }

    /**
     * Redige um valor antes que um formatter o serialize.
     *
     * O `JsonFormatter` também normaliza `Throwable` e aceita objetos
     * `JsonSerializable`. Se o processor deixasse esses objetos intactos,
     * uma exceção com `token=...` na mensagem — ou um payload serializável com
     * `access_token` — seria convertido depois do ponto em que a redação
     * acontece e escaparia para o arquivo. Objetos que não podem ser
     * serializados com segurança viram apenas o nome da classe: falhar no
     * logger nunca deve expor o valor original nem derrubar a aplicação.
     */
    private static function redigirValor(mixed $valor, int $profundidade): mixed
    {
        if ($profundidade >= self::PROFUNDIDADE_MAXIMA) {
            return [];
        }
        if (is_array($valor)) {
            return self::redigirEstrutura($valor, $profundidade);
        }
        if (is_object($valor)) {
            return self::redigirObjeto($valor, $profundidade);
        }

        return is_string($valor) ? self::redigirTexto($valor) : $valor;
    }

    private static function redigirObjeto(object $valor, int $profundidade): mixed
    {
        if ($valor instanceof \Throwable) {
            return [
                self::MARCADOR_EXCECAO => self::sentinelaExcecao(),
                'class' => $valor::class,
                'message' => self::redigirTexto($valor->getMessage()),
            ];
        }
        if ($valor instanceof \stdClass) {
            return (object) self::redigirEstrutura((array) $valor, $profundidade);
        }
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format(\DateTimeInterface::ATOM);
        }
        if ($valor instanceof \JsonSerializable) {
            try {
                $serializado = $valor->jsonSerialize();
            } catch (\Throwable) {
                return $valor::class;
            }
            return $serializado === $valor
                ? $valor::class
                : self::redigirValor($serializado, $profundidade + 1);
        }
        if ($valor instanceof \Stringable) {
            try {
                return self::redigirTexto($valor->__toString());
            } catch (\Throwable) {
                return $valor::class;
            }
        }
        try {
            $serializado = json_decode(
                json_encode($valor, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\Throwable) {
            return $valor::class;
        }
        return self::redigirValor($serializado, $profundidade + 1);
    }

    /**
     * Confere a origem do mapa de exceção sem confiar num valor fornecido pelo
     * chamador. Um booleano no contexto poderia ser forjado por qualquer
     * payload e faria o formatter promovê-lo para `err` indevidamente.
     */
    public static function ehMarcadorDeExcecao(mixed $valor): bool
    {
        return self::$sentinelaExcecao !== null && $valor === self::$sentinelaExcecao;
    }

    private static function sentinelaExcecao(): object
    {
        return self::$sentinelaExcecao ??= new class () {
        };
    }

    /**
     * Redige pares `chave=valor` e `"chave": "valor"` embutidos em texto.
     *
     * Os dois formatos são os que este repositório de fato produz: o primeiro é
     * o idioma do `escreverLog()` (`paymentLinkId=X | valor=Y`), o segundo
     * aparece quando uma resposta de gateway é despejada com `json_encode()`
     * dentro da mensagem de uma exceção — que é exatamente onde um
     * `access_token` iria parar.
     */
    public static function redigirTexto(string $texto): string
    {
        $texto = preg_replace('~/bot[0-9]+(?::|%3A)[A-Za-z0-9_-]+~i', '/bot' . self::SUBSTITUTO, $texto) ?? self::SUBSTITUTO;
        $texto = self::redatorJson()->redigir($texto);

        $comPares = preg_replace_callback(self::padraoDePares(), static fn (array $casamento): string =>
            $casamento[1] . '=' . self::SUBSTITUTO . $casamento[3], $texto);

        return is_string($comPares) ? $comPares : $texto;
    }

    private static function redatorJson(): RedatorJson
    {
        return self::$redatorJson ??= new RedatorJson(
            self::SUBSTITUTO,
            static fn (mixed $valor): mixed => self::redigirValor($valor, 0),
            static fn (string $chave): bool => self::chaveProibida($chave),
        );
    }

    /**
     * Só as chaves PROIBIDAS entram no padrão, e o valor delas vai até o `|` ou
     * até o fim da linha.
     *
     * A alternativa óbvia — casar qualquer `chave=valor` e decidir no callback —
     * tem um furo silencioso: num trecho sem `|`, como
     * `user=5 authorization=Bearer abc`, o primeiro par (chave inocente)
     * consumiria o resto da linha e o `authorization` nunca seria examinado.
     * Com a lista dentro do padrão, o motor de regex encontra a chave proibida
     * onde quer que ela esteja.
     *
     * Ir até o delimitador, e não até o primeiro espaço, é o que apaga o
     * `Bearer <token>` inteiro e o `cookie=a=b; c=d` inteiro. Redigir demais
     * dentro do campo de um segredo é o lado certo de errar.
     */
    private static function padraoDePares(): string
    {
        $termos = implode('|', array_map(
            static fn (string $termo): string => preg_quote($termo, '/'),
            self::CHAVES_PROIBIDAS
        ));

        return '/(?<![\w.-])([A-Za-z0-9_.-]*(?:' . $termos . ')[A-Za-z0-9_.-]*)\s*=\s*([^|\r\n]*?)(\s*)(?=\||\r|\n|$)/i';
    }
}
