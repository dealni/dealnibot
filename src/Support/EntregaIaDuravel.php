<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use PDO;
use PDOStatement;

/** Checkpoint por update, protegido pela tentativa/lease da inbox. */
final class EntregaIaDuravel
{
    private const SQL_GRAVAR = "UPDATE webhook_inbox SET entrega_ia_estado = :estado, resposta_ia_json = :conteudo WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa AND tentativa_em > :limite";
    private const SQL_AGENDAR = "UPDATE webhook_inbox SET entrega_ia_estado = 'pendente', status = 'erro', disponivel_em = :quando, ultimo_erro = 'Entrega IA adiada pelo Telegram (429)' WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa AND tentativa_em > :limite";
    private const SQL_CONCLUIR = "UPDATE webhook_inbox SET entrega_ia_estado = :estado, resposta_ia_json = NULL WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa AND tentativa_em > :limite";
    private const SQL_INDETERMINADA = "UPDATE webhook_inbox SET entrega_ia_estado = 'indeterminada' WHERE update_id = :id AND status = 'processando' AND tentativas = :tentativa AND tentativa_em > :limite";

    private static ?self $ativa = null;

    /** @var array<string, mixed> */
    private array $memoria = [];

    /** @param Closure(): int $relogio */
    public function __construct(private \bdTelegram $bd, private int $id, private int $tentativa, private Closure $relogio)
    {
    }

    public static function definir(?self $ativa): ?self
    {
        $anterior = self::$ativa;
        self::$ativa = $ativa;
        return $anterior;
    }

    /** @param array<string, mixed> $conteudo */
    public static function preparar(array $conteudo): void
    {
        $ativa = self::$ativa;
        $ativa?->gravar('enviando', json_encode(['conteudo' => $conteudo, 'memoria' => $ativa->memoria], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array<int, array{role: 'user'|'assistant', content: string}> $historico */
    public static function memorizar(string $chave, array $historico, string $pergunta, string $resposta, string $espelho): void
    {
        if (self::$ativa !== null) {
            self::$ativa->memoria = compact('chave', 'historico', 'pergunta', 'resposta', 'espelho');
        }
    }

    /** @param array<string, mixed> $resultado */
    public static function concluir(array $resultado): void
    {
        $ativa = self::$ativa;
        if ($ativa === null) {
            return;
        }
        $atraso = self::atraso($resultado);
        if ($atraso !== null) {
            $agora = ($ativa->relogio)();
            $ativa->atualizar(self::SQL_AGENDAR, [':quando' => $agora + min($atraso, PHP_INT_MAX - $agora)]);
            LoggerFactory::channel('telegram', true)->warning('Resposta de IA preservada para entrega posterior', ['retry_after' => $atraso]);
            throw new RespostaIaAdiada('Entrega IA reagendada sem regenerar resposta.');
        }
        $estado = ($resultado['ok'] ?? null) === true ? 'entregue' : 'indeterminada';
        if (($resultado['ok'] ?? null) === false && in_array($resultado['error_code'] ?? null, [400, 403], true)) {
            $estado = 'recusada';
        }
        if ($estado === 'indeterminada') {
            $ativa->atualizar(self::SQL_INDETERMINADA, []);
            LoggerFactory::channel('telegram', true)->warning('Entrega de IA com resultado indeterminado; não reenviada', self::diagnostico($resultado));
        } else {
            $ativa->atualizar(self::SQL_CONCLUIR, [':estado' => $estado]);
        }
    }

    /** @param array<string, mixed> $resultado
     * @return array<string, int> */
    private static function diagnostico(array $resultado): array
    {
        // O transporte também usa error_code para o errno do cURL. Não chamar
        // este campo de status HTTP nem incluir descrição, destino ou corpo.
        $codigo = $resultado['error_code'] ?? null;
        return is_int($codigo) && $codigo >= 1 && $codigo <= 599 ? ['codigo_erro' => $codigo] : [];
    }

    /** @param array<string, mixed> $resultado */
    private static function atraso(array $resultado): ?int
    {
        if (($resultado['ok'] ?? null) !== false || ($resultado['error_code'] ?? null) !== 429) {
            return null;
        }
        $parametros = $resultado['parameters'] ?? null;
        $atraso = is_array($parametros) ? ($parametros['retry_after'] ?? null) : null;
        return is_int($atraso) && $atraso > 0 ? $atraso : 30;
    }

    /** Retorna true quando o checkpoint já impede voltar à IA/às cotas. */
    public function retomar(\Telegram $telegram, \Redis $redis): bool
    {
        $consulta = $this->bd->queryExec('SELECT resposta_ia_json, entrega_ia_estado FROM webhook_inbox WHERE update_id = :id', [':id' => $this->id]);
        if (!$consulta instanceof PDOStatement) {
            throw new \RuntimeException('Falha ao ler checkpoint de IA; roteamento recusado.');
        }
        $linha = $consulta->fetch(PDO::FETCH_ASSOC);
        if (!is_array($linha)) {
            throw new \RuntimeException('Update do checkpoint IA não encontrado.');
        }
        if ($linha['entrega_ia_estado'] === null) {
            return false;
        }
        if ($linha['entrega_ia_estado'] === 'pendente' && is_string($linha['resposta_ia_json'])) {
            $checkpoint = json_decode($linha['resposta_ia_json'], true, 512, JSON_THROW_ON_ERROR);
            $conteudo = is_array($checkpoint) ? ($checkpoint['conteudo'] ?? null) : null;
            if (!is_array($conteudo) || !is_string($conteudo['text'] ?? null)) {
                throw new \RuntimeException('Checkpoint de resposta IA inválido.');
            }
            $this->memoria = self::chavesTextuais($checkpoint['memoria'] ?? null);
            require_once dirname(__DIR__, 2) . '/comandos/funcoes_ia_comuns.php';
            if (\entregarConteudoRespostaIa($telegram, $redis, self::chavesTextuais($conteudo))) {
                $this->salvarMemoria($redis);
            }
        } elseif ($linha['entrega_ia_estado'] === 'enviando') {
            $this->atualizar(self::SQL_INDETERMINADA, []);
            LoggerFactory::channel('telegram', true)->error('Entrega de IA interrompida com resultado indeterminado; não reenviada');
        }
        return true;
    }

    /** @return array<string, mixed> */
    private static function chavesTextuais(mixed $dados): array
    {
        $resultado = [];
        if (is_array($dados)) {
            foreach ($dados as $chave => $valor) {
                if (is_string($chave)) {
                    $resultado[$chave] = $valor;
                }
            }
        }
        return $resultado;
    }

    private function salvarMemoria(\Redis $redis): void
    {
        if ($this->memoria === []) {
            return;
        }
        foreach (['chave', 'pergunta', 'resposta', 'espelho'] as $campo) {
            if (!is_string($this->memoria[$campo] ?? null)) {
                throw new \RuntimeException('Metadado da memória IA inválido.');
            }
        }
        \salvarTurnoMemoria($redis, $this->memoria['chave'], \normalizarHistoricoMemoria($this->memoria['historico'] ?? null), $this->memoria['pergunta'], $this->memoria['resposta']);
        \salvarTextoComTtl($redis, $this->memoria['espelho'], $this->memoria['resposta'], \ttlMemoriaConversa());
    }

    private function gravar(string $estado, ?string $conteudo): void
    {
        $this->atualizar(self::SQL_GRAVAR, [':estado' => $estado, ':conteudo' => $conteudo]);
    }

    /** @param array<string, int|string|null> $parametros */
    private function atualizar(string $sql, array $parametros): void
    {
        $agora = ($this->relogio)();
        $resultado = $this->bd->queryExec($sql, $parametros + [':id' => $this->id, ':tentativa' => $this->tentativa, ':limite' => $agora - LeaseWebhook::SEGUNDOS]);
        if (!$resultado instanceof PDOStatement || $resultado->rowCount() !== 1) {
            throw new \RuntimeException('Checkpoint IA não persistido; próximo envio recusado.');
        }
    }
}
