<?php

declare(strict_types=1);

namespace App\Support\Telegram;

use App\Support\TelegramContatos;

require_once __DIR__ . '/../TelegramContatos.php';

/** Ligação explícita do cliente Telegram ao banco deste bot. */
trait ObservadorContatos
{
    private ?TelegramContatos $contatosTelegram = null;
    private bool $falhaContatosNotificada = false;

    public function acompanharContatos(\bdTelegram $banco): void
    {
        $this->contatosTelegram = new TelegramContatos($banco);
    }

    /** @param array<string, mixed> $update */
    public function observarInteracaoContato(array $update): void
    {
        try {
            $this->contatosTelegram?->registrarInteracao($update);
        } catch (\Throwable) {
            $this->avisarFalhaContatos();
        }
    }

    /** @param array<string, mixed> $conteudo
     * @param array<string, mixed> $resposta
     */
    private function observarRespostaContato(string $metodo, array $conteudo, array $resposta, int $momento): void
    {
        if (!str_starts_with($metodo, 'send') && !in_array($metodo, ['copyMessage', 'forwardMessage', 'copyMessages', 'forwardMessages'], true)) {
            return;
        }
        $id = TelegramContatos::idPrivado($conteudo['chat_id'] ?? null);
        if ($id === null || TelegramContatos::estadoDaRecusa($resposta) === null) {
            return;
        }
        try {
            $this->contatosTelegram?->registrarResposta($id, $resposta, $momento);
        } catch (\Throwable) {
            $this->avisarFalhaContatos();
        }
    }

    private function avisarFalhaContatos(): void
    {
        if (!$this->falhaContatosNotificada) {
            try {
                if (!class_exists(\App\Support\LoggerFactory::class)) {
                    $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
                    if (is_file($autoload)) {
                        require_once $autoload;
                    }
                }
                \App\Support\LoggerFactory::channel('telegram_contatos', true)->error('Não foi possível atualizar o estado de contato Telegram.');
            } catch (\Throwable) {
                // Fallback constante: sem payload nem serialização de objeto.
                error_log('{"level":"error","channel":"telegram_contatos","msg":"Falha ao atualizar contato Telegram","log_fallback":true}');
            }
            $this->falhaContatosNotificada = true;
        }
    }
}
