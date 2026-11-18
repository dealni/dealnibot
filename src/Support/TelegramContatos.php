<?php

declare(strict_types=1);

namespace App\Support;

/** Estado de disponibilidade dos contatos privados do Telegram. */
final class TelegramContatos
{
    public function __construct(private readonly \PDO|\bdTelegram $banco)
    {
    }

    /** @param array<string, mixed> $resposta */
    public static function estadoDaRecusa(array $resposta): ?string
    {
        $codigo = $resposta['error_code'] ?? null;
        $descricao = $resposta['description'] ?? null;
        if (($resposta['ok'] ?? null) !== false || !is_int($codigo) || !is_string($descricao)) {
            return null;
        }
        $motivos = [
            '403:forbidden: bot was blocked by the user' => 'bloqueado',
            '403:forbidden: user is deactivated' => 'desativado',
            "403:forbidden: the bot can't send messages to the bot" => 'bot',
            '400:bad request: chat not found' => 'nao_encontrado',
            '400:bad request: user_bot_to_bot_disabled' => 'bot',
        ];
        return $motivos[$codigo . ':' . strtolower(trim($descricao))] ?? null;
    }

    public static function idPrivado(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (!is_string($valor) || preg_match('/^[1-9][0-9]{0,18}$/D', $valor) !== 1) {
            return null;
        }
        $id = filter_var($valor, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** Recusas antigas não anulam uma interação mais recente.
     * @param array<string, mixed> $resposta
     */
    public function registrarResposta(int $id, array $resposta, int $momento): bool
    {
        $estado = self::estadoDaRecusa($resposta);
        if ($id <= 0 || $momento <= 0 || $estado === null) {
            return false;
        }
        $descricao = $resposta['description'];
        $codigo = $resposta['error_code'];
        $this->executar(<<<'SQL'
            INSERT INTO telegram_contatos (id_telegram, estado, motivo, codigo_erro, observado_em)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (id_telegram) DO UPDATE SET
                estado = EXCLUDED.estado, motivo = EXCLUDED.motivo,
                codigo_erro = EXCLUDED.codigo_erro, observado_em = EXCLUDED.observado_em
            WHERE EXCLUDED.observado_em >= telegram_contatos.observado_em
                AND EXCLUDED.observado_em > telegram_contatos.ultima_interacao_em
            SQL, [$id, $estado, $descricao, $codigo, $momento]);
        return true;
    }

    /** @param array<string, mixed> $update */
    public function registrarInteracao(array $update): void
    {
        $mensagem = self::mensagemDoUpdate($update);
        $callback = $update['callback_query'] ?? null;
        if (is_array($callback)) {
            $mensagem = $callback['message'] ?? null;
            $autor = $callback['from'] ?? null;
            $momento = $this->momentoCallback($update);
        } else {
            if (!is_array($mensagem)) {
                return;
            }
            $autor = $mensagem['from'] ?? null;
            $momento = $mensagem['edit_date'] ?? $mensagem['date'] ?? null;
        }
        $id = self::idDaInteracao($mensagem, $autor);
        if ($id !== null && is_int($momento) && $momento > 0) {
            $this->reativar($id, $momento);
        }
    }

    /** @param array<string, mixed> $update */
    private static function mensagemDoUpdate(array $update): mixed
    {
        return $update['message'] ?? $update['edited_message'] ?? null;
    }

    private static function idDoAutor(mixed $autor): ?int
    {
        if (!is_array($autor) || ($autor['is_bot'] ?? false) === true) {
            return null;
        }
        return self::idPrivado($autor['id'] ?? null);
    }

    private static function idDaInteracao(mixed $mensagem, mixed $autor): ?int
    {
        if (!is_array($mensagem)) {
            return null;
        }
        $chat = $mensagem['chat'] ?? null;
        if (!is_array($chat) || ($chat['type'] ?? null) !== 'private') {
            return null;
        }
        $id = self::idPrivado($chat['id'] ?? null);
        return $id !== null && $id === self::idDoAutor($autor) ? $id : null;
    }

    /** Um callback retomado pela inbox conserva o instante em que chegou.
     * @param array<string, mixed> $update
     */
    private function momentoCallback(array $update): int
    {
        $id = $update['update_id'] ?? null;
        if (!is_int($id)) {
            return time();
        }
        $stmt = $this->executar('SELECT recebido_em FROM webhook_inbox WHERE update_id = ?', [$id]);
        $recebido = $stmt->fetchColumn();
        return is_int($recebido) && $recebido > 0 ? $recebido : time();
    }

    public function reativar(int $id, int $momento): void
    {
        if ($id <= 0 || $momento <= 0) {
            return;
        }
        $this->executar(<<<'SQL'
            INSERT INTO telegram_contatos (id_telegram, estado, observado_em, ultima_interacao_em)
            VALUES (?, 'disponivel', ?, ?)
            ON CONFLICT (id_telegram) DO UPDATE SET
                estado = CASE WHEN EXCLUDED.observado_em >= telegram_contatos.observado_em THEN 'disponivel' ELSE telegram_contatos.estado END,
                motivo = CASE WHEN EXCLUDED.observado_em >= telegram_contatos.observado_em THEN NULL ELSE telegram_contatos.motivo END,
                codigo_erro = CASE WHEN EXCLUDED.observado_em >= telegram_contatos.observado_em THEN NULL ELSE telegram_contatos.codigo_erro END,
                observado_em = GREATEST(telegram_contatos.observado_em, EXCLUDED.observado_em),
                ultima_interacao_em = GREATEST(telegram_contatos.ultima_interacao_em, EXCLUDED.ultima_interacao_em)
            SQL, [$id, $momento, $momento]);
    }

    public function disponivel(int $id): bool
    {
        if ($id <= 0) {
            return true; // Grupos e canais não são contatos privados.
        }
        $stmt = $this->executar("SELECT 1 FROM telegram_contatos WHERE id_telegram = ? AND estado <> 'disponivel'", [$id]);
        return $stmt->fetchColumn() === false;
    }

    /** @param list<mixed> $parametros */
    private function executar(string $sql, array $parametros): \PDOStatement
    {
        if ($this->banco instanceof \PDO) {
            $stmt = $this->banco->prepare($sql);
            if ($stmt instanceof \PDOStatement) {
                $stmt->execute($parametros);
            }
        } else {
            $stmt = $this->banco->queryExec($sql, $parametros);
        }
        if (!$stmt instanceof \PDOStatement) {
            throw new \RuntimeException('Falha ao registrar estado de contato Telegram.');
        }
        return $stmt;
    }
}
