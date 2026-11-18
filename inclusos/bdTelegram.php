<?php

declare(strict_types=1);

use App\Support\LoggerFactory;

require_once __DIR__ . '/caminhos.php';
require_once __DIR__ . '/banco.php';

class bdTelegram
{
    private PDO $con;

    public function __construct(?string $caminho_bd = null)
    {
        $dsn = $caminho_bd ?? $this->dsnPadrao();

        try {
            // Abre a conexão PostgreSQL configurada para este bot.
            $this->con = conectarBancoDoBot($dsn);
            $this->con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException|RuntimeException $e) {
            $mensagem = 'Erro ao conectar ao banco de dados: ' . $e->getMessage();

            if (self::loggerDisponivel()) {
                LoggerFactory::channel('bd', true)->error($mensagem, [
                    'exception' => $e,
                ]);
            }

            // Lança em vez de die(): quem instancia decide como encerrar. Os
            // entrypoints canônicos (bot.php / bot_web.php) envolvem o `new`
            // em try/catch e chamam ecoarEEncerrar().
            throw new RuntimeException($mensagem, 0, $e);
        }
    }

    private function dsnPadrao(): string
    {
        return dsnBancoDoBot();
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchRow(PDOStatement $statement)
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->normalizeRow($row) : false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(PDOStatement $statement): array
    {
        // No PHP 8.5 fetchAll() sempre devolve array (lança PDOException em
        // caso de erro): não existe retorno false a tratar aqui.
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $result[] = $this->normalizeRow($row);
            }
        }
        return $result;
    }

    /**
     * @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $result = [];
        foreach ($row as $column => $value) {
            if (is_string($column)) {
                $result[$column] = $value;
            }
        }
        return $result;
    }

    /**
     * Mantém os campos ativos do cadastro em um formato estável.
     *
     * @param array<string, mixed> $row
     * @return array{id: mixed, id_telegram: mixed, codigo_idioma: mixed}
     */
    private function normalizeUsuario(array $row): array
    {
        return [
            'id' => $row['id'] ?? null,
            'id_telegram' => $row['id_telegram'] ?? null,
            'codigo_idioma' => $row['codigo_idioma'] ?? null,
        ];
    }

    // Método para obter informações do usuário ou adicioná-lo se não existir.
    /**
     * @param int|string $id_telegram
     * @return array{id: mixed, id_telegram: mixed, codigo_idioma: mixed}|false
     */
    public function usuario($id_telegram)
    {
        // `=== false` e não `empty()`: getUsuario() devolve `false` ou uma
        // linha com chaves — uma linha nunca é "vazia" — e o teste estrito
        // deixa explícito que só a AUSÊNCIA dispara o cadastro.
        if ($this->getUsuario($id_telegram) === false) {
            $this->addUsuario($id_telegram);
        }

        return $this->getUsuario($id_telegram);
    }

    // Adiciona um novo usuário com o ID do Telegram.
    /**
     * @param int|string $id_telegram
     */
    public function addUsuario($id_telegram): bool
    {
        return $this->queryExec("INSERT INTO usuarios (id_telegram) VALUES (?)", [$id_telegram], true);
    }

    // Obtém informações do usuário com base no ID do Telegram.
    /**
     * @param int|string $id_telegram
     * @return array{id: mixed, id_telegram: mixed, codigo_idioma: mixed}|false
     */
    public function getUsuario($id_telegram)
    {
        $busca = $this->queryExec("SELECT id, id_telegram, codigo_idioma FROM usuarios WHERE id_telegram=?", [$id_telegram]);
        if ($busca === false) {
            return false;
        }
        $linha = $this->fetchRow($busca);
        if ($linha === false) {
            return false;
        }
        return $this->normalizeUsuario($linha);
    }

    /** Registra uma conversa privada sem duplicar o cadastro em reentregas. */
    public function registrarContato(int $idTelegram): bool
    {
        if ($idTelegram < 1) {
            return false;
        }

        return $this->queryExec(
            'INSERT OR IGNORE INTO usuarios (id_telegram) VALUES (?)',
            [$idTelegram],
            true
        );
    }

    // Obtém informações de todos os usuários ordenados por ID.
    /**
     * @return array<int, array<string, mixed>>
     */
    public function todosUsuarios(): array
    {
        $busca = $this->queryExec("SELECT id, id_telegram, codigo_idioma FROM usuarios ORDER BY id DESC");
        if ($busca === false) {
            return [];
        }
        return $this->fetchRows($busca);
    }

    // Executa consultas SQL preparadas e retorna resultados.
    // Melhore a manipulação de exceções nas consultas SQL.
    /**
     * @param array<int|string, mixed> $data
     * @return ($status is true ? bool : PDOStatement|false)
     */
    public function queryExec(string $sql, array $data = [], bool $status = false)
    {
        try {
            $prepare = $this->con->prepare($sql);

            if (!$prepare) {
                return false;
            }

            $exec = $prepare->execute($data);

            if ($status) {
                return $exec;
            }

            return $prepare;
        } catch (PDOException $e) {
            $this->registrarFalhaDeConsulta($sql, $e);

            return false;
        }
    }

    /**
     * Registra a falha de uma consulta no canal `bd`.
     *
     * `$forcar = true` como no construtor: banco fora do ar é infraestrutura, e
     * precisa aparecer mesmo com `LOGS_ATIVO` desligado.
     *
     * O SQL entra porque é TEXTO FIXO do código — a regra `sql-montado-por-
     * interpolacao` do Semgrep reprova qualquer consulta montada com variável,
     * e sem ele não dá para saber qual consulta falhou. Os PARÂMETROS ficam de
     * fora: ali é que moram id do Telegram e o resto do dado do usuário.
     */
    private function registrarFalhaDeConsulta(string $sql, PDOException $e): void
    {
        if (!self::loggerDisponivel()) {
            return;
        }

        LoggerFactory::channel('bd', true)->error(
            'Consulta ao banco falhou: ' . $e->getMessage(),
            [
                'sql' => $sql,
                'sqlstate' => (string) $e->getCode(),
            ]
        );
    }

    /**
     * Garante o autoload do Composer antes de usar o LoggerFactory.
     *
     * Esta classe é instanciada por entrypoints que podem não ter passado pelo
     * autoload (bot_web.php dos bots legados, scripts soltos): carrega sob
     * demanda e desiste em silêncio se não houver `vendor/`. Registrar não pode
     * derrubar o processo que estava tentando falar com o banco.
     *
     * Um método só, e não o bloco copiado nos dois pontos que logam: as duas
     * cópias eram idênticas, e uma correção em uma delas não chegaria à outra.
     */
    private static function loggerDisponivel(): bool
    {
        if (!class_exists(LoggerFactory::class)) {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }

        return class_exists(LoggerFactory::class);
    }
}
