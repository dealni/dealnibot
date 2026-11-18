<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Envelope do contrato de observabilidade da frota, uma linha JSON por evento.
 *
 * O Monolog nomeia os campos à maneira dele (`datetime`, `level_name`,
 * `message`); o contrato nomeia à maneira da frota (`ts`, `level`, `msg`) e
 * exige `service`, `env` e `version` em TODO evento. A tradução vive aqui, num
 * formatter, e não em cada chamador: quem loga continua chamando
 * `escreverLog()` como sempre chamou.
 *
 * Por que formatter e não processor: um `Processor` só alcança `context` e
 * `extra` do `LogRecord` — os campos de topo são propriedades readonly com nome
 * fixo. Renomear campo de topo é, por construção, trabalho de serialização.
 *
 * `channel` continua no evento, como campo extra do produto: é por ele que os
 * runbooks filtram (`jq 'select(.channel=="webhook_erros")'`) e ele guarda o
 * nome do arquivo de log legado de onde cada fluxo veio.
 *
 * Sem valor por bot aqui dentro: o `service` do evento sai de
 * `ContextoDeExecucao`, que o deriva do diretório raiz.
 */
final class FormatadorDeContrato extends JsonFormatter
{
    /**
     * ISO 8601 em UTC com milissegundos: `2026-08-30T14:03:11.482Z`.
     *
     * O `Z` é literal e o fuso é convertido antes: um log com fuso local vira
     * aritmética de horário de verão na hora do incidente, que é o pior momento
     * possível para fazer conta.
     */
    private const FORMATO_TS = 'Y-m-d\TH:i:s.v\Z';

    /**
     * Os oito níveis do PSR-3 nos cinco do contrato.
     *
     * `notice` cai em `info` e `critical`/`alert`/`emergency` caem em `fatal`:
     * o contrato quer poucos degraus, para que um alerta possa ser escrito
     * contra `level` sem enumerar sinônimos.
     */
    private static function nivel(Level $nivel): string
    {
        return match ($nivel) {
            Level::Debug => 'debug',
            Level::Info, Level::Notice => 'info',
            Level::Warning => 'warn',
            Level::Error => 'error',
            Level::Critical, Level::Alert, Level::Emergency => 'fatal',
        };
    }

    /**
     * Nomes que o contrato da frota reserva no topo do evento. Ver a seção 1 de
     * CONTRATO_OBSERVABILIDADE: quem loga passa por `context`, e o formatador
     * é quem os promove.
     *
     * @var list<string>
     */
    private const CAMPOS_DO_CONTRATO = ['user_id', 'duration_ms', 'http', 'err'];

    /**
     * Marcador neutro para um valor de contexto que não pode ser serializado
     * (JsonSerializable que lança, ciclo, profundidade absurda). O evento
     * sai; o valor, não — e o objeto não é chamado uma segunda vez.
     */
    private const NAO_SERIALIZAVEL = '[unserializable]';

    /**
     * Teto de recursão da proteção, o mesmo do processor de redação: estourar
     * a pilha dentro do formatter transformaria uma linha de log em erro fatal.
     */
    private const PROFUNDIDADE_MAXIMA = 12;

    /** @return array<array<mixed>|bool|float|int|\stdClass|string|null> */
    protected function normalizeRecord(LogRecord $record): array
    {
        // A normalização do Monolog transforma exceções em arrays antes de
        // chegarem aqui. Guarde a identidade original para não interpretar um
        // array arbitrário passado na chave `exception` como uma exceção. A
        // PSR-3 exige que o implementador verifique esse tipo antes de usá-lo.
        $excecao = $record->context['exception'] ?? null;
        $temExcecao = $excecao instanceof \Throwable;
        $excecaoRedigida = is_array($excecao)
            && ProcessadorDeRedacao::ehMarcadorDeExcecao(
                $excecao[ProcessadorDeRedacao::MARCADOR_EXCECAO] ?? null
            );
        $normalizado = parent::normalizeRecord($record);

        // O JsonFormatter do Monolog deixa `JsonSerializable` para o
        // `json_encode` final; um `jsonSerialize()` que lança derrubaria o
        // logger depois de todos os processors. Materializar aqui, antes da
        // redação, fecha essa porta sem chamar o objeto uma segunda vez.
        $normalizado = self::protegerEstruturaJson($normalizado);

        $normalizado = self::redigirNormalizado($normalizado);

        $evento = [
            'ts' => $record->datetime
                ->setTimezone(new DateTimeZone('UTC'))
                ->format(self::FORMATO_TS),
            'level' => self::nivel($record->level),
            'msg' => $normalizado['message'] ?? '',
            'service' => ContextoDeExecucao::servico(),
            'env' => ContextoDeExecucao::ambiente(),
            'version' => ContextoDeExecucao::versao(),
            'request_id' => ContextoDeExecucao::requestId(),
        ];

        // Os campos opcionais do contrato sobem ao TOPO em vez de ficarem
        // dentro de `context`. Sem isso o filtro do runbook seria
        // `jq '.context.duration_ms'`, e o nome combinado entre os oito
        // produtos passaria a depender de onde cada chamador o pôs.
        $contexto = $normalizado['context'] ?? [];
        if (is_array($contexto)) {
            [$contexto, $promovidos] = self::separarCamposDoContrato(
                $contexto,
                $temExcecao,
                $excecaoRedigida
            );
            $evento = array_merge($evento, $promovidos);
            $normalizado['context'] = $contexto === [] ? new \stdClass() : $contexto;
        }

        $evento['channel'] = $normalizado['channel'] ?? '';

        // context e extra entram como o JsonFormatter os deixou (objeto vazio
        // quando não houver nada), preservando o formato que os leitores do
        // app.log já esperam.
        foreach (['context', 'extra'] as $bloco) {
            if (array_key_exists($bloco, $normalizado)) {
                $evento[$bloco] = $normalizado[$bloco];
            }
        }

        return $evento;
    }

    /**
     * Materializa JsonSerializable antes do json_encode final.
     *
     * O JsonFormatter do Monolog preserva esses objetos para o encode final;
     * se `jsonSerialize()` lançar, o erro atravessa o `@json_encode` e viola a
     * garantia de leniência do contexto PSR-3. Valores válidos continuam com
     * sua forma serializada; ciclos ou falhas viram um marcador neutro.
     *
     * @param array<array-key, mixed> $valores
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    private static function protegerEstruturaJson(array $valores, int $profundidade = 0): array
    {
        foreach ($valores as $chave => $item) {
            $valores[$chave] = self::protegerValorJson($item, $profundidade + 1);
        }

        return $valores;
    }

    /**
     * @return array<mixed>|bool|float|int|\stdClass|string|null
     */
    private static function protegerValorJson(mixed $valor, int $profundidade = 0): array|bool|float|int|\stdClass|string|null
    {
        if ($profundidade >= self::PROFUNDIDADE_MAXIMA) {
            return self::NAO_SERIALIZAVEL;
        }

        if (is_array($valor)) {
            return self::protegerEstruturaJson($valor, $profundidade);
        }

        if ($valor instanceof \stdClass) {
            return (object) self::protegerEstruturaJson((array) $valor, $profundidade + 1);
        }

        if (!$valor instanceof \JsonSerializable) {
            if (is_bool($valor) || is_float($valor) || is_int($valor) || is_string($valor) || $valor === null) {
                return $valor;
            }

            // O normalizador do Monolog já trata os objetos conhecidos acima;
            // qualquer objeto restante não pode ser confiado ao json_encode.
            return self::NAO_SERIALIZAVEL;
        }

        try {
            $serializado = $valor->jsonSerialize();
        } catch (\Throwable) {
            return self::NAO_SERIALIZAVEL;
        }

        if ($serializado === $valor) {
            return self::NAO_SERIALIZAVEL;
        }

        return self::protegerValorJson($serializado, $profundidade + 1);
    }

    /**
     * O PSR-3 exige que um valor arbitrário de contexto não derrube o logger.
     *
     * O JsonFormatter do Monolog trata Stringable e Throwable com leniência,
     * mas um valor que lança ao ser normalizado escaparia durante a escrita
     * da linha. Neste limite, um marcador neutro preserva o evento sem chamar
     * novamente o objeto nem expor seus dados.
     */
    protected function normalize(mixed $data, int $depth = 0): mixed
    {
        try {
            return parent::normalize($data, $depth);
        } catch (\Throwable) {
            return self::NAO_SERIALIZAVEL;
        }
    }

    /**
     * Tira do contexto os nomes que o contrato reserva no topo do evento.
     *
     * Devolve o contexto sem eles e o mapa do que subiu, em vez de escrever
     * nos dois por referência: separado assim, o `normalizeRecord` continua
     * legível e esta regra ganha teste próprio.
     *
     * @param array<array-key, mixed> $contexto
     * @return array{0: array<array-key, mixed>, 1: array<string, array<mixed>|bool|float|int|\stdClass|string|null>}
     */
    private static function separarCamposDoContrato(
        array $contexto,
        bool $temExcecao,
        bool $excecaoRedigida
    ): array {
        $promovidos = [];

        if ($excecaoRedigida && is_array($contexto['exception'] ?? null)) {
            unset($contexto['exception'][ProcessadorDeRedacao::MARCADOR_EXCECAO]);
        }

        foreach (self::CAMPOS_DO_CONTRATO as $campo) {
            if (array_key_exists($campo, $contexto)) {
                $promovidos[$campo] = self::valorPromovido($campo, $contexto[$campo]);
                unset($contexto[$campo]);
            }
        }

        // `$logger->error($msg, ['exception' => $e])` é o idioma do Monolog; o
        // contrato chama isso de `err`. Traduzimos aqui para o chamador não ter
        // de saber os dois nomes.
        if (($temExcecao || $excecaoRedigida)
            && !array_key_exists('err', $promovidos)
            && isset($contexto['exception'])
        ) {
            $err = self::erroDaExcecao($contexto['exception']);
            if ($err !== null) {
                $promovidos['err'] = $err;
                unset($contexto['exception']);
            }
        }

        return [$contexto, $promovidos];
    }

    /** @return array<mixed>|bool|float|int|\stdClass|string|null */
    private static function valorPromovido(string $campo, mixed $valor): array|bool|float|int|\stdClass|string|null
    {
        if ($campo === 'err') {
            return self::erroDaExcecao($valor) ?? self::valorDoEvento($valor);
        }

        return self::valorDoEvento($valor);
    }

    /**
     * @param array<array-key, mixed> $normalizado
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    private static function redigirNormalizado(array $normalizado): array
    {
        $redigido = [];
        foreach ($normalizado as $chave => $valor) {
            if ($chave === 'message' && is_string($valor)) {
                $valor = ProcessadorDeRedacao::redigirTexto($valor);
            }
            if (is_string($chave)
                && ($chave === 'context' || $chave === 'extra')
                && is_array($valor)
            ) {
                $valor = ProcessadorDeRedacao::redigirEstrutura($valor);
            }
            $redigido[$chave] = self::valorDoEvento($valor);
        }

        return $redigido;
    }

    /**
     * Só `type` e `message`: `file` e `line` entregariam o caminho absoluto do
     * servidor a quem lê o log, e o stack já está desligado no LoggerFactory.
     *
     * @return array{type: string, message: string}|null
     */
    private static function erroDaExcecao(mixed $excecao): ?array
    {
        if ($excecao instanceof \stdClass) {
            $excecao = (array) $excecao;
        }

        if (!is_array($excecao)) {
            return null;
        }

        $tipo = $excecao['class'] ?? null;
        $mensagem = $excecao['message'] ?? null;

        return [
            'type' => is_string($tipo) ? $tipo : 'Throwable',
            'message' => is_string($mensagem) ? $mensagem : '',
        ];
    }

    /**
     * Estreita o valor promovido para o que a assinatura do JsonFormatter
     * admite.
     *
     * `parent::normalizeRecord()` já reduziu o contexto a escalar, array ou
     * `stdClass`; o `else` existe porque a assinatura do Monolog não promete
     * isso, e um dia em que ele mudar a normalização é melhor descobrir por um
     * evento estranho no log do que por um erro de tipo em produção.
     *
     * @return array<mixed>|bool|float|int|\stdClass|string|null
     */
    private static function valorDoEvento(mixed $valor): array|bool|float|int|\stdClass|string|null
    {
        if (
            is_array($valor)
            || is_bool($valor)
            || is_float($valor)
            || is_int($valor)
            || is_string($valor)
            || $valor === null
            || $valor instanceof \stdClass
        ) {
            return $valor;
        }

        // A borda só é alcançada para um tipo que o formatter não conhece.
        // Ainda assim, o logger não pode transformar UTF-8 inválido em string
        // vazia nem emitir warning no caminho de tratamento de falha.
        return (string) json_encode($valor, JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
