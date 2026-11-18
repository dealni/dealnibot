<?php

declare(strict_types=1);

namespace Tests\Support;

use Redis;
use RedisException;
use RuntimeException;

/**
 * Dublê de Redis em memória para os testes dos comandos de IA.
 *
 * Diferença essencial para um mock comum: o eval() aqui EXECUTA o corpo do
 * script Lua recebido (via InterpretadorLuaMinimo) em vez de imitar o que o
 * script deveria fazer. Assim, qualquer mudança dentro do Lua da cota diária
 * (contador, condição do TTL, valor devolvido) muda o resultado dos testes.
 *
 * Nenhuma conexão é aberta: a instância nunca chama connect().
 */
final class RedisFalsoComLua extends Redis
{
    /** @var array<string, string> Valores por chave. */
    private $valores = [];

    /** @var array<string, int> TTL em segundos por chave. */
    private $expiracoes = [];

    /** @var array<int, string> Operações executadas, na ordem (inclusive as de dentro do Lua). */
    private $operacoes = [];

    /** @var bool Redis fora do ar devolvendo false (phpredis sem exceções). */
    private $indisponivel = false;

    /** @var bool Redis fora do ar lançando RedisException (modo padrão do phpredis). */
    private $lancaExcecao = false;

    /** @var string|null Último script Lua recebido por eval(). */
    private $ultimoScript = null;

    // -----------------------------------------------------------------
    // Controle do dublê
    // -----------------------------------------------------------------

    /** Faz todo comando devolver false, como o phpredis sem exceções. */
    public function ficarIndisponivel(): void
    {
        $this->indisponivel = true;
    }

    /** Faz todo comando lançar RedisException, como o phpredis padrão. */
    public function ficarIndisponivelComExcecao(): void
    {
        $this->lancaExcecao = true;
    }

    /** @param string|int $valor */
    public function semear(string $chave, $valor): void
    {
        $this->valores[$chave] = (string) $valor;
    }

    /** @return array<int, string> */
    public function operacoes(): array
    {
        return $this->operacoes;
    }

    public function expiracaoDe(string $chave): ?int
    {
        return $this->expiracoes[$chave] ?? null;
    }

    public function valorDe(string $chave): ?string
    {
        return $this->valores[$chave] ?? null;
    }

    /** Script Lua recebido no último eval(), exatamente como o código enviou. */
    public function ultimoScript(): ?string
    {
        return $this->ultimoScript;
    }

    /** Simula o vencimento do TTL (o Redis apaga a chave). */
    public function vencerChave(string $chave): void
    {
        unset($this->valores[$chave], $this->expiracoes[$chave]);
    }

    // -----------------------------------------------------------------
    // Comandos usados pelo comando chatgpt
    // -----------------------------------------------------------------

    public function get(string $key): mixed
    {
        if ($this->falhar('GET ' . $key)) {
            return false;
        }
        $this->operacoes[] = 'GET ' . $key;

        return $this->valores[$key] ?? false;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param mixed $options
     * @return bool
     */
    public function set(string $key, mixed $value, mixed $options = null): bool
    {
        if ($this->falhar('SET ' . $key)) {
            return false;
        }

        $opcoes = is_array($options) ? $options : [];
        $nx = false;
        $ttl = null;
        foreach ($opcoes as $indice => $opcao) {
            if (is_string($opcao) && strtoupper($opcao) === 'NX') {
                $nx = true;
            }
            if (is_string($indice) && strtoupper($indice) === 'EX' && is_numeric($opcao)) {
                $ttl = (int) $opcao;
            }
        }

        if ($nx && array_key_exists($key, $this->valores)) {
            $this->operacoes[] = 'SETNX-NEGADO ' . $key;

            return false;
        }

        $this->operacoes[] = 'SET ' . $key;
        $this->valores[$key] = is_scalar($value) ? (string) $value : '';
        if ($ttl !== null && $ttl > 0) {
            $this->expiracoes[$key] = $ttl;
        }

        return true;
    }

    /**
     * @param mixed $key
     * @param mixed ...$other_keys
     * @return int|false
     */
    public function exists(mixed $key, mixed ...$other_keys): int|false
    {
        $chave = is_string($key) || is_int($key) ? (string) $key : '';
        if ($this->falhar('EXISTS ' . $chave)) {
            return false;
        }
        $this->operacoes[] = 'EXISTS ' . $chave;

        return array_key_exists($chave, $this->valores) ? 1 : 0;
    }

    /**
     * @param string $key
     * @param int $timeout
     * @param string|null $mode
     * @return bool
     */
    public function expire(string $key, int $timeout, ?string $mode = null): bool
    {
        if ($this->falhar('EXPIRE ' . $key . ' ' . $timeout)) {
            return false;
        }
        $this->operacoes[] = 'EXPIRE ' . $key . ' ' . $timeout;
        if (!array_key_exists($key, $this->valores)) {
            return false;
        }
        $this->expiracoes[$key] = $timeout;

        return true;
    }

    /**
     * Executa DE VERDADE o script Lua recebido, com KEYS/ARGV como o Redis faz
     * (todos os argumentos chegam ao script como string).
     *
     * A chave do array fica em `array-key` (e não em `int`) porque Redis::eval()
     * declara `array` cru: apertar aqui quebraria a contravariância do dublê.
     * O corpo passa por array_values(), então a chave não importa.
     *
     * @param array<array-key, mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->ultimoScript = $script;
        if ($this->falhar('EVAL')) {
            return false;
        }

        $valores = [];
        foreach (array_values($args) as $argumento) {
            $valores[] = is_scalar($argumento) ? (string) $argumento : '';
        }

        $chaves = array_slice($valores, 0, max(0, $num_keys));
        $argumentos = array_slice($valores, max(0, $num_keys));

        $resultado = InterpretadorLuaMinimo::executar(
            $script,
            $chaves,
            $argumentos,
            /**
             * @param array<int, mixed> $parametros
             * @return mixed
             */
            function (string $comando, array $parametros) {
                return $this->comandoDoLua($comando, $parametros);
            }
        );

        return self::converterRetornoLua($resultado);
    }

    // -----------------------------------------------------------------
    // Interior do dublê
    // -----------------------------------------------------------------

    /**
     * Comandos disponíveis para o redis.call() dentro do script Lua. Comando
     * desconhecido lança: o teste precisa falhar alto se o script passar a
     * usar algo que o dublê não sabe reproduzir.
     *
     * @param array<int, mixed> $parametros
     * @return mixed
     */
    private function comandoDoLua(string $comando, array $parametros)
    {
        $chave = isset($parametros[0]) && is_scalar($parametros[0]) ? (string) $parametros[0] : '';

        switch ($comando) {
            case 'INCR':
                $this->operacoes[] = 'INCR ' . $chave;
                $atual = isset($this->valores[$chave]) && is_numeric($this->valores[$chave])
                    ? (int) $this->valores[$chave]
                    : 0;
                $atual++;
                $this->valores[$chave] = (string) $atual;

                return $atual;

            case 'INCRBY':
                $incremento = isset($parametros[1]) && is_numeric($parametros[1])
                    ? (int) $parametros[1]
                    : 0;
                $this->operacoes[] = 'INCRBY ' . $chave . ' ' . $incremento;
                $atual = isset($this->valores[$chave]) && is_numeric($this->valores[$chave])
                    ? (int) $this->valores[$chave]
                    : 0;
                $atual += $incremento;
                $this->valores[$chave] = (string) $atual;

                return $atual;

            case 'DECR':
                $this->operacoes[] = 'DECR ' . $chave;
                $atual = isset($this->valores[$chave]) && is_numeric($this->valores[$chave])
                    ? (int) $this->valores[$chave]
                    : 0;
                $atual--;
                $this->valores[$chave] = (string) $atual;

                return $atual;

            case 'EXPIRE':
                $segundos = isset($parametros[1]) && is_numeric($parametros[1]) ? (int) $parametros[1] : 0;
                $this->operacoes[] = 'EXPIRE ' . $chave . ' ' . $segundos;
                if (!array_key_exists($chave, $this->valores)) {
                    return 0;
                }
                $this->expiracoes[$chave] = $segundos;

                return 1;

            case 'TTL':
                $this->operacoes[] = 'TTL ' . $chave;
                if (!array_key_exists($chave, $this->valores)) {
                    return -2;
                }

                return $this->expiracoes[$chave] ?? -1;

            case 'GET':
                $this->operacoes[] = 'GET ' . $chave;

                return $this->valores[$chave] ?? false;

            case 'SET':
                $this->operacoes[] = 'SET ' . $chave;
                $this->valores[$chave] = isset($parametros[1]) && is_scalar($parametros[1])
                    ? (string) $parametros[1]
                    : '';
                for ($indice = 2; $indice < count($parametros) - 1; $indice++) {
                    if (is_string($parametros[$indice]) && strtoupper($parametros[$indice]) === 'EX'
                        && is_numeric($parametros[$indice + 1])) {
                        $this->expiracoes[$chave] = (int) $parametros[$indice + 1];
                    }
                }

                return true;

            case 'DEL':
                $this->operacoes[] = 'DEL ' . $chave;
                $existia = array_key_exists($chave, $this->valores);
                unset($this->valores[$chave], $this->expiracoes[$chave]);

                return $existia ? 1 : 0;
        }

        throw new RuntimeException("Comando Redis não suportado pelo dublê: {$comando}.");
    }

    /**
     * Conversão de valores Lua para o que o phpredis devolve: números viram
     * inteiros truncados, true vira 1 e false/nil viram false.
     *
     * @param mixed $resultado
     * @return mixed
     */
    private static function converterRetornoLua($resultado)
    {
        if (is_bool($resultado)) {
            return $resultado ? 1 : false;
        }
        if ($resultado === null) {
            return false;
        }
        if (is_float($resultado)) {
            return (int) $resultado;
        }

        return $resultado;
    }

    /**
     * Aplica o modo indisponível: devolve true quando o comando deve falhar.
     */
    private function falhar(string $operacao): bool
    {
        if ($this->lancaExcecao) {
            $this->operacoes[] = 'EXCECAO ' . $operacao;

            throw new RedisException('Connection refused');
        }
        if ($this->indisponivel) {
            $this->operacoes[] = 'FALHA ' . $operacao;

            return true;
        }

        return false;
    }
}
