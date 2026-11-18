<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;

/**
 * Interpretador de um SUBCONJUNTO de Lua usado exclusivamente nos testes para
 * EXECUTAR de verdade o script que inclusos/limites_requisicoes.php envia ao
 * Redis em consumir_limite_diario() via EVAL.
 *
 * Motivo: sem executar o corpo do script, o Lua não é exercitado por teste
 * nenhum — trocar `return atual` por `return 0`, remover o EXPIRE ou mudar a
 * condição `atual == 1` deixaria a suíte verde. Com o interpretador, os testes
 * da cota falham de verdade quando a lógica de dentro do Lua muda.
 *
 * Suporta apenas o que o script da cota usa: `local`, `if/elseif/else/end`,
 * `return`, chamadas (`redis.call`, `tonumber`, `tostring`), índices
 * (`KEYS[1]`, `ARGV[1]`), comparações, concatenação e aritmética. Qualquer
 * construção fora desse subconjunto lança RuntimeException — é melhor o teste
 * quebrar alto do que aprovar em silêncio um script que ele não sabe executar.
 */
final class InterpretadorLuaMinimo
{
    /** @var array<int, string> Palavras reservadas do Lua. */
    private const PALAVRAS_RESERVADAS = [
        'and', 'break', 'do', 'else', 'elseif', 'end', 'false', 'for', 'function',
        'if', 'in', 'local', 'nil', 'not', 'or', 'repeat', 'return', 'then',
        'true', 'until', 'while',
    ];

    /** @var array<int, array{tipo: string, valor: string}> */
    private $tokens;

    /** @var int Índice do token atual. */
    private $posicao = 0;

    /** @var array<string, mixed> Variáveis globais e locais (escopo único). */
    private $ambiente = [];

    /**
     * Executa um script Lua do subconjunto suportado.
     *
     * @param string $script Corpo do script, exatamente como enviado ao EVAL
     * @param array<int, string> $keys Valores de KEYS (1-based no Lua)
     * @param array<int, string> $argv Valores de ARGV (1-based no Lua)
     * @param callable(string, array<int, mixed>): mixed $redisCall Ponte do redis.call
     * @return mixed Valor devolvido pelo `return` do script (null sem return)
     */
    public static function executar(string $script, array $keys, array $argv, callable $redisCall)
    {
        return (new self($script, $keys, $argv, $redisCall))->rodar();
    }

    /**
     * @param string $script
     * @param array<int, string> $keys
     * @param array<int, string> $argv
     * @param callable(string, array<int, mixed>): mixed $redisCall
     */
    private function __construct(string $script, array $keys, array $argv, callable $redisCall)
    {
        $this->tokens = self::tokenizar($script);
        $this->ambiente = [
            'KEYS' => self::tabelaLua($keys),
            'ARGV' => self::tabelaLua($argv),
            'redis' => [
                'call' => Closure::fromCallable(
                    /** @param mixed ...$argumentos */
                    static function (...$argumentos) use ($redisCall) {
                        $comando = array_shift($argumentos);
                        if (!is_string($comando)) {
                            throw new RuntimeException('redis.call exige o comando como string.');
                        }

                        return $redisCall(strtoupper($comando), array_values($argumentos));
                    }
                ),
            ],
            'tonumber' => Closure::fromCallable(
                /** @param mixed ...$argumentos */
                static function (...$argumentos) {
                    $valor = $argumentos[0] ?? null;
                    if (is_int($valor) || is_float($valor)) {
                        return $valor;
                    }

                    return is_string($valor) && is_numeric($valor) ? self::comoNumero($valor) : null;
                }
            ),
            'tostring' => Closure::fromCallable(
                /** @param mixed ...$argumentos */
                static function (...$argumentos) {
                    return self::comoTexto($argumentos[0] ?? null);
                }
            ),
        ];
    }

    /**
     * @param array<int, string> $valores
     * @return array<int, string> Tabela Lua 1-based
     */
    private static function tabelaLua(array $valores): array
    {
        $tabela = [];
        foreach (array_values($valores) as $indice => $valor) {
            $tabela[$indice + 1] = $valor;
        }

        return $tabela;
    }

    /** @return mixed */
    private function rodar()
    {
        $bloco = $this->analisarBloco([]);
        if ($this->atual()['tipo'] !== 'fim') {
            throw new RuntimeException('Sobrou código Lua não suportado depois do bloco principal.');
        }

        list($retornou, $valor) = $this->executarBloco($bloco);

        return $retornou ? $valor : null;
    }

    // -----------------------------------------------------------------
    // Tokenização
    // -----------------------------------------------------------------

    /**
     * @param string $codigo
     * @return array<int, array{tipo: string, valor: string}>
     */
    private static function tokenizar(string $codigo): array
    {
        $simbolosDuplos = ['==', '~=', '<=', '>=', '..'];
        $simbolosSimples = ['=', '<', '>', '+', '-', '*', '/', '%', '#', '(', ')', '[', ']', ',', '.', ';'];

        $tokens = [];
        $tamanho = strlen($codigo);
        $indice = 0;

        while ($indice < $tamanho) {
            $caractere = $codigo[$indice];

            if (trim($caractere) === '') {
                $indice++;
                continue;
            }

            if (substr($codigo, $indice, 2) === '--') {
                $quebra = strpos($codigo, "\n", $indice);
                $indice = $quebra === false ? $tamanho : $quebra + 1;
                continue;
            }

            if (ctype_alpha($caractere) || $caractere === '_') {
                $inicio = $indice;
                while ($indice < $tamanho && (ctype_alnum($codigo[$indice]) || $codigo[$indice] === '_')) {
                    $indice++;
                }
                $tokens[] = ['tipo' => 'nome', 'valor' => substr($codigo, $inicio, $indice - $inicio)];
                continue;
            }

            if (ctype_digit($caractere)) {
                $inicio = $indice;
                while ($indice < $tamanho && (ctype_digit($codigo[$indice]) || $codigo[$indice] === '.')) {
                    $indice++;
                }
                $tokens[] = ['tipo' => 'numero', 'valor' => substr($codigo, $inicio, $indice - $inicio)];
                continue;
            }

            if ($caractere === "'" || $caractere === '"') {
                $indice++;
                $texto = '';
                while ($indice < $tamanho && $codigo[$indice] !== $caractere) {
                    if ($codigo[$indice] === '\\' && $indice + 1 < $tamanho) {
                        $texto .= $codigo[$indice + 1];
                        $indice += 2;
                        continue;
                    }
                    $texto .= $codigo[$indice];
                    $indice++;
                }
                if ($indice >= $tamanho) {
                    throw new RuntimeException('String Lua sem aspas de fechamento.');
                }
                $indice++;
                $tokens[] = ['tipo' => 'texto', 'valor' => $texto];
                continue;
            }

            $duplo = substr($codigo, $indice, 2);
            if (in_array($duplo, $simbolosDuplos, true)) {
                $tokens[] = ['tipo' => 'simbolo', 'valor' => $duplo];
                $indice += 2;
                continue;
            }

            if (in_array($caractere, $simbolosSimples, true)) {
                $tokens[] = ['tipo' => 'simbolo', 'valor' => $caractere];
                $indice++;
                continue;
            }

            throw new RuntimeException("Caractere Lua não suportado: {$caractere}");
        }

        $tokens[] = ['tipo' => 'fim', 'valor' => ''];

        return $tokens;
    }

    // -----------------------------------------------------------------
    // Análise sintática
    // -----------------------------------------------------------------

    /** @return array{tipo: string, valor: string} */
    private function atual(): array
    {
        return $this->tokens[$this->posicao] ?? ['tipo' => 'fim', 'valor' => ''];
    }

    private function ehNome(string $nome): bool
    {
        $token = $this->atual();

        return $token['tipo'] === 'nome' && $token['valor'] === $nome;
    }

    private function ehSimbolo(string $simbolo): bool
    {
        $token = $this->atual();

        return $token['tipo'] === 'simbolo' && $token['valor'] === $simbolo;
    }

    private function consumirNome(string $nome): void
    {
        if (!$this->ehNome($nome)) {
            throw new RuntimeException("Esperado '{$nome}' no script Lua.");
        }
        $this->posicao++;
    }

    private function consumirSimbolo(string $simbolo): void
    {
        if (!$this->ehSimbolo($simbolo)) {
            throw new RuntimeException("Esperado '{$simbolo}' no script Lua.");
        }
        $this->posicao++;
    }

    /**
     * @param array<int, string> $terminadores Palavras que encerram o bloco
     * @return array<int, array<string, mixed>>
     */
    private function analisarBloco(array $terminadores): array
    {
        $comandos = [];
        while (true) {
            $token = $this->atual();
            if ($token['tipo'] === 'fim') {
                break;
            }
            if ($token['tipo'] === 'nome' && in_array($token['valor'], $terminadores, true)) {
                break;
            }
            if ($this->ehSimbolo(';')) {
                $this->posicao++;
                continue;
            }
            $comandos[] = $this->analisarComando();
        }

        return $comandos;
    }

    /** @return array<string, mixed> */
    private function analisarComando(): array
    {
        if ($this->ehNome('local')) {
            $this->posicao++;
            $nome = $this->atual();
            if ($nome['tipo'] !== 'nome' || in_array($nome['valor'], self::PALAVRAS_RESERVADAS, true)) {
                throw new RuntimeException('Nome inválido em declaração local do Lua.');
            }
            $this->posicao++;
            $this->consumirSimbolo('=');

            return ['tipo' => 'local', 'nome' => $nome['valor'], 'valor' => $this->analisarExpressao()];
        }

        if ($this->ehNome('if')) {
            return $this->analisarSe();
        }

        if ($this->ehNome('return')) {
            $this->posicao++;
            $token = $this->atual();
            $fimDeBloco = $token['tipo'] === 'fim'
                || ($token['tipo'] === 'nome' && in_array($token['valor'], ['end', 'else', 'elseif'], true));

            return ['tipo' => 'retorno', 'valor' => $fimDeBloco ? null : $this->analisarExpressao()];
        }

        $expressao = $this->analisarSufixado();

        if ($this->ehSimbolo('=')) {
            $this->posicao++;
            if (($expressao['tipo'] ?? null) !== 'variavel') {
                throw new RuntimeException('Só atribuição a variável simples é suportada no Lua.');
            }

            return [
                'tipo' => 'atribuicao',
                'nome' => self::comoTextoObrigatorio($expressao['nome'] ?? null),
                'valor' => $this->analisarExpressao(),
            ];
        }

        if (($expressao['tipo'] ?? null) !== 'chamada') {
            throw new RuntimeException('Comando Lua não suportado (esperada chamada ou atribuição).');
        }

        return ['tipo' => 'expressao', 'valor' => $expressao];
    }

    /** @return array<string, mixed> */
    private function analisarSe(): array
    {
        $ramos = [];
        $this->consumirNome('if');
        $condicao = $this->analisarExpressao();
        $this->consumirNome('then');
        $ramos[] = ['condicao' => $condicao, 'bloco' => $this->analisarBloco(['elseif', 'else', 'end'])];

        while ($this->ehNome('elseif')) {
            $this->posicao++;
            $condicao = $this->analisarExpressao();
            $this->consumirNome('then');
            $ramos[] = ['condicao' => $condicao, 'bloco' => $this->analisarBloco(['elseif', 'else', 'end'])];
        }

        $senao = null;
        if ($this->ehNome('else')) {
            $this->posicao++;
            $senao = $this->analisarBloco(['end']);
        }
        $this->consumirNome('end');

        return ['tipo' => 'se', 'ramos' => $ramos, 'senao' => $senao];
    }

    /** @return array<string, mixed> */
    private function analisarExpressao(): array
    {
        return $this->analisarOu();
    }

    /** @return array<string, mixed> */
    private function analisarOu(): array
    {
        $esquerda = $this->analisarE();
        while ($this->ehNome('or')) {
            $this->posicao++;
            $esquerda = ['tipo' => 'binario', 'operador' => 'or', 'esquerda' => $esquerda, 'direita' => $this->analisarE()];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarE(): array
    {
        $esquerda = $this->analisarComparacao();
        while ($this->ehNome('and')) {
            $this->posicao++;
            $esquerda = [
                'tipo' => 'binario',
                'operador' => 'and',
                'esquerda' => $esquerda,
                'direita' => $this->analisarComparacao(),
            ];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarComparacao(): array
    {
        $esquerda = $this->analisarConcatenacao();
        while (true) {
            $token = $this->atual();
            if ($token['tipo'] !== 'simbolo'
                || !in_array($token['valor'], ['==', '~=', '<', '>', '<=', '>='], true)
            ) {
                break;
            }
            $this->posicao++;
            $esquerda = [
                'tipo' => 'binario',
                'operador' => $token['valor'],
                'esquerda' => $esquerda,
                'direita' => $this->analisarConcatenacao(),
            ];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarConcatenacao(): array
    {
        $esquerda = $this->analisarAditivo();
        if ($this->ehSimbolo('..')) {
            $this->posicao++;

            // Concatenação é associativa à direita no Lua.
            return [
                'tipo' => 'binario',
                'operador' => '..',
                'esquerda' => $esquerda,
                'direita' => $this->analisarConcatenacao(),
            ];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarAditivo(): array
    {
        $esquerda = $this->analisarMultiplicativo();
        while ($this->ehSimbolo('+') || $this->ehSimbolo('-')) {
            $operador = $this->atual()['valor'];
            $this->posicao++;
            $esquerda = [
                'tipo' => 'binario',
                'operador' => $operador,
                'esquerda' => $esquerda,
                'direita' => $this->analisarMultiplicativo(),
            ];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarMultiplicativo(): array
    {
        $esquerda = $this->analisarUnario();
        while ($this->ehSimbolo('*') || $this->ehSimbolo('/') || $this->ehSimbolo('%')) {
            $operador = $this->atual()['valor'];
            $this->posicao++;
            $esquerda = [
                'tipo' => 'binario',
                'operador' => $operador,
                'esquerda' => $esquerda,
                'direita' => $this->analisarUnario(),
            ];
        }

        return $esquerda;
    }

    /** @return array<string, mixed> */
    private function analisarUnario(): array
    {
        if ($this->ehNome('not') || $this->ehSimbolo('-') || $this->ehSimbolo('#')) {
            $operador = $this->atual()['valor'];
            $this->posicao++;

            return ['tipo' => 'unario', 'operador' => $operador, 'operando' => $this->analisarUnario()];
        }

        return $this->analisarSufixado();
    }

    /** @return array<string, mixed> */
    private function analisarSufixado(): array
    {
        $expressao = $this->analisarPrimario();

        while (true) {
            if ($this->ehSimbolo('.')) {
                $this->posicao++;
                $campo = $this->atual();
                if ($campo['tipo'] !== 'nome') {
                    throw new RuntimeException('Campo inválido depois de "." no Lua.');
                }
                $this->posicao++;
                $expressao = [
                    'tipo' => 'indice',
                    'alvo' => $expressao,
                    'chave' => ['tipo' => 'texto', 'valor' => $campo['valor']],
                ];
                continue;
            }

            if ($this->ehSimbolo('[')) {
                $this->posicao++;
                $chave = $this->analisarExpressao();
                $this->consumirSimbolo(']');
                $expressao = ['tipo' => 'indice', 'alvo' => $expressao, 'chave' => $chave];
                continue;
            }

            if ($this->ehSimbolo('(')) {
                $this->posicao++;
                $argumentos = [];
                if (!$this->ehSimbolo(')')) {
                    $argumentos[] = $this->analisarExpressao();
                    while ($this->ehSimbolo(',')) {
                        $this->posicao++;
                        $argumentos[] = $this->analisarExpressao();
                    }
                }
                $this->consumirSimbolo(')');
                $expressao = ['tipo' => 'chamada', 'alvo' => $expressao, 'argumentos' => $argumentos];
                continue;
            }

            break;
        }

        return $expressao;
    }

    /** @return array<string, mixed> */
    private function analisarPrimario(): array
    {
        $token = $this->atual();

        if ($token['tipo'] === 'numero') {
            $this->posicao++;

            return ['tipo' => 'numero', 'valor' => self::comoNumero($token['valor'])];
        }

        if ($token['tipo'] === 'texto') {
            $this->posicao++;

            return ['tipo' => 'texto', 'valor' => $token['valor']];
        }

        if ($token['tipo'] === 'nome') {
            if ($token['valor'] === 'true' || $token['valor'] === 'false') {
                $this->posicao++;

                return ['tipo' => 'booleano', 'valor' => $token['valor'] === 'true'];
            }
            if ($token['valor'] === 'nil') {
                $this->posicao++;

                return ['tipo' => 'nulo'];
            }
            if (in_array($token['valor'], self::PALAVRAS_RESERVADAS, true)) {
                throw new RuntimeException("Palavra reservada não suportada no Lua: {$token['valor']}");
            }
            $this->posicao++;

            return ['tipo' => 'variavel', 'nome' => $token['valor']];
        }

        if ($this->ehSimbolo('(')) {
            $this->posicao++;
            $expressao = $this->analisarExpressao();
            $this->consumirSimbolo(')');

            return $expressao;
        }

        throw new RuntimeException("Expressão Lua não suportada perto de '{$token['valor']}'.");
    }

    // -----------------------------------------------------------------
    // Execução
    // -----------------------------------------------------------------

    /**
     * @param array<int, array<string, mixed>> $bloco
     * @return array{0: bool, 1: mixed} Se houve return e o valor devolvido
     */
    private function executarBloco(array $bloco): array
    {
        foreach ($bloco as $comando) {
            $tipo = $comando['tipo'] ?? null;

            if ($tipo === 'local' || $tipo === 'atribuicao') {
                $nome = self::comoTextoObrigatorio($comando['nome'] ?? null);
                $this->ambiente[$nome] = $this->avaliar($comando['valor'] ?? null);
                continue;
            }

            if ($tipo === 'expressao') {
                $this->avaliar($comando['valor'] ?? null);
                continue;
            }

            if ($tipo === 'retorno') {
                $valor = $comando['valor'] ?? null;

                return [true, $valor === null ? null : $this->avaliar($valor)];
            }

            if ($tipo === 'se') {
                $resultado = $this->executarSe($comando);
                if ($resultado[0]) {
                    return $resultado;
                }
                continue;
            }

            throw new RuntimeException('Comando Lua desconhecido na execução.');
        }

        return [false, null];
    }

    /**
     * @param array<string, mixed> $comando
     * @return array{0: bool, 1: mixed}
     */
    private function executarSe(array $comando): array
    {
        $ramos = $comando['ramos'] ?? null;
        if (!is_array($ramos)) {
            throw new RuntimeException('Bloco if do Lua sem ramos.');
        }

        foreach ($ramos as $ramo) {
            if (!is_array($ramo)) {
                throw new RuntimeException('Ramo inválido no if do Lua.');
            }
            if (self::verdadeiro($this->avaliar($ramo['condicao'] ?? null))) {
                return $this->executarBloco(self::comoBloco($ramo['bloco'] ?? null));
            }
        }

        $senao = $comando['senao'] ?? null;
        if ($senao !== null) {
            return $this->executarBloco(self::comoBloco($senao));
        }

        return [false, null];
    }

    /**
     * @param mixed $no
     * @return mixed
     */
    private function avaliar($no)
    {
        if (!is_array($no)) {
            throw new RuntimeException('Nó Lua inválido na execução.');
        }

        $tipo = $no['tipo'] ?? null;

        if ($tipo === 'numero' || $tipo === 'texto' || $tipo === 'booleano') {
            return $no['valor'] ?? null;
        }

        if ($tipo === 'nulo') {
            return null;
        }

        if ($tipo === 'variavel') {
            $nome = self::comoTextoObrigatorio($no['nome'] ?? null);

            return array_key_exists($nome, $this->ambiente) ? $this->ambiente[$nome] : null;
        }

        if ($tipo === 'indice') {
            $alvo = $this->avaliar($no['alvo'] ?? null);
            $chave = $this->avaliar($no['chave'] ?? null);
            if (!is_array($alvo)) {
                throw new RuntimeException('Índice aplicado a valor que não é tabela no Lua.');
            }
            if (is_float($chave)) {
                $chave = (int) $chave;
            }
            if (!is_int($chave) && !is_string($chave)) {
                throw new RuntimeException('Chave de tabela Lua inválida.');
            }

            return $alvo[$chave] ?? null;
        }

        if ($tipo === 'chamada') {
            $funcao = $this->avaliar($no['alvo'] ?? null);
            if (!$funcao instanceof Closure) {
                throw new RuntimeException('Chamada a valor que não é função no Lua.');
            }
            $argumentos = $no['argumentos'] ?? null;
            if (!is_array($argumentos)) {
                throw new RuntimeException('Argumentos inválidos em chamada Lua.');
            }
            $avaliados = [];
            foreach ($argumentos as $argumento) {
                $avaliados[] = $this->avaliar($argumento);
            }

            return $funcao(...$avaliados);
        }

        if ($tipo === 'unario') {
            return $this->avaliarUnario($no);
        }

        if ($tipo === 'binario') {
            return $this->avaliarBinario($no);
        }

        throw new RuntimeException('Expressão Lua desconhecida na execução.');
    }

    /**
     * @param array<array-key, mixed> $no
     * @return mixed
     */
    private function avaliarUnario(array $no)
    {
        $operador = self::comoTextoObrigatorio($no['operador'] ?? null);
        $valor = $this->avaliar($no['operando'] ?? null);

        if ($operador === 'not') {
            return !self::verdadeiro($valor);
        }

        if ($operador === '-') {
            return -self::numero($valor);
        }

        if ($operador === '#') {
            if (is_string($valor)) {
                return strlen($valor);
            }
            if (is_array($valor)) {
                return count($valor);
            }

            throw new RuntimeException('Operador # aplicado a valor sem tamanho no Lua.');
        }

        throw new RuntimeException("Operador unário Lua não suportado: {$operador}");
    }

    /**
     * @param array<array-key, mixed> $no
     * @return mixed
     */
    private function avaliarBinario(array $no)
    {
        $operador = self::comoTextoObrigatorio($no['operador'] ?? null);
        $esquerda = $this->avaliar($no['esquerda'] ?? null);

        // Curto-circuito: o lado direito só é avaliado quando necessário.
        if ($operador === 'and') {
            return self::verdadeiro($esquerda) ? $this->avaliar($no['direita'] ?? null) : $esquerda;
        }
        if ($operador === 'or') {
            return self::verdadeiro($esquerda) ? $esquerda : $this->avaliar($no['direita'] ?? null);
        }

        $direita = $this->avaliar($no['direita'] ?? null);

        switch ($operador) {
            case '==':
                return self::iguais($esquerda, $direita);
            case '~=':
                return !self::iguais($esquerda, $direita);
            case '<':
                return self::comparar($esquerda, $direita) < 0;
            case '>':
                return self::comparar($esquerda, $direita) > 0;
            case '<=':
                return self::comparar($esquerda, $direita) <= 0;
            case '>=':
                return self::comparar($esquerda, $direita) >= 0;
            case '..':
                return self::comoTexto($esquerda) . self::comoTexto($direita);
            case '+':
                return self::numero($esquerda) + self::numero($direita);
            case '-':
                return self::numero($esquerda) - self::numero($direita);
            case '*':
                return self::numero($esquerda) * self::numero($direita);
            case '/':
                $divisor = self::numero($direita);
                if ((float) $divisor === 0.0) {
                    throw new RuntimeException('Divisão por zero no Lua.');
                }

                return self::numero($esquerda) / $divisor;
            case '%':
                $divisor = self::numero($direita);
                if ((float) $divisor === 0.0) {
                    throw new RuntimeException('Módulo por zero no Lua.');
                }

                return fmod((float) self::numero($esquerda), (float) $divisor);
        }

        throw new RuntimeException("Operador binário Lua não suportado: {$operador}");
    }

    // -----------------------------------------------------------------
    // Semântica de valores
    // -----------------------------------------------------------------

    /**
     * No Lua só `false` e `nil` são falsos — 0 e "" são verdadeiros.
     *
     * @param mixed $valor
     */
    private static function verdadeiro($valor): bool
    {
        return $valor !== false && $valor !== null;
    }

    /**
     * @param mixed $esquerda
     * @param mixed $direita
     */
    private static function iguais($esquerda, $direita): bool
    {
        $numeroEsquerda = is_int($esquerda) || is_float($esquerda);
        $numeroDireita = is_int($direita) || is_float($direita);
        if ($numeroEsquerda && $numeroDireita) {
            return (float) $esquerda === (float) $direita;
        }

        // Lua não converte tipos na igualdade: "1" == 1 é falso.
        return $esquerda === $direita;
    }

    /**
     * @param mixed $esquerda
     * @param mixed $direita
     */
    private static function comparar($esquerda, $direita): int
    {
        if (is_string($esquerda) && is_string($direita)) {
            return strcmp($esquerda, $direita);
        }

        $primeiro = (float) self::numero($esquerda);
        $segundo = (float) self::numero($direita);

        return $primeiro <=> $segundo;
    }

    /**
     * @param mixed $valor
     * @return int|float
     */
    private static function numero($valor)
    {
        if (is_int($valor) || is_float($valor)) {
            return $valor;
        }
        if (is_string($valor) && is_numeric($valor)) {
            return self::comoNumero($valor);
        }

        throw new RuntimeException('Operação aritmética com valor não numérico no Lua.');
    }

    /**
     * @param string $texto
     * @return int|float
     */
    private static function comoNumero(string $texto)
    {
        if (!is_numeric($texto)) {
            throw new RuntimeException("Número Lua inválido: {$texto}");
        }
        // `numeric-string + 0` já devolve int para inteiro e float para o
        // resto: o ternário com cast que havia aqui reafirmava o que a soma
        // acabara de decidir.
        return $texto + 0;
    }

    /** @param mixed $valor */
    private static function comoTexto($valor): string
    {
        if (is_string($valor)) {
            return $valor;
        }
        if (is_int($valor)) {
            return (string) $valor;
        }
        if (is_float($valor)) {
            return $valor === floor($valor) ? (string) (int) $valor : (string) $valor;
        }
        if ($valor === null) {
            return 'nil';
        }
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }

        throw new RuntimeException('Valor Lua sem conversão para texto.');
    }

    /** @param mixed $valor */
    private static function comoTextoObrigatorio($valor): string
    {
        if (!is_string($valor)) {
            throw new RuntimeException('Esperado nome/operador em texto no nó Lua.');
        }

        return $valor;
    }

    /**
     * @param mixed $bloco
     * @return array<int, array<string, mixed>>
     */
    private static function comoBloco($bloco): array
    {
        if (!is_array($bloco)) {
            throw new RuntimeException('Bloco Lua inválido.');
        }

        $comandos = [];
        foreach ($bloco as $comando) {
            if (!is_array($comando)) {
                throw new RuntimeException('Comando Lua inválido dentro do bloco.');
            }
            $normalizado = [];
            foreach ($comando as $chave => $valor) {
                $normalizado[(string) $chave] = $valor;
            }
            $comandos[] = $normalizado;
        }

        return $comandos;
    }
}
