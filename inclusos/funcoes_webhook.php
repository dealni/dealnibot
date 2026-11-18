<?php

declare(strict_types=1);

/**
 * Funções de bot_web.php.
 *
 * Separadas do script pelo mesmo motivo dos outros `funcoes_*.php`: bot_web.php
 * é script de topo e não pode ser incluído por teste (instancia o Telegram com
 * o token real, abre banco e Redis e despacha comandos). Enquanto a declaração
 * morava lá, o teste só alcançava a função por
 * Support\ExtratorDeFuncoesDeScript, que termina em `eval()` — e cobertura de
 * código avaliado não é atribuída ao arquivo de origem, então o gate do webhook
 * contava zero statement coberto mesmo com a suíte inteira exercitando-o.
 *
 * Este arquivo só declara função, então o teste faz `require_once` de verdade.
 */

/**
 * Confere o segredo do webhook do Telegram (header X-Telegram-Bot-Api-Secret-Token).
 *
 * A URL é pública. Updates forjados poderiam acionar chamadas pagas e
 * contornar a cota diária, que usa a identidade do remetente no update.
 * O único jeito de o Telegram provar que a chamada é dele é o secret_token
 * registrado em setWebhook (config/config_webhook.php), devolvido a cada update
 * neste header. A comparação é hash_equals (tempo constante).
 *
 * O gate falha fechado: configuração ausente, vazia ou inválida também é
 * recusada. O carregador de produção exige a constante; depois de defini-la,
 * rode `php config/config_webhook.php` para registrar o mesmo valor no Telegram.
 *
 * @param mixed $segredoConfigurado Valor de TELEGRAM_WEBHOOK_SECRET (ou null)
 * @param mixed $segredoRecebido Header X-Telegram-Bot-Api-Secret-Token da requisição
 * @return bool true quando a requisição pode ser processada
 */
function webhookTelegramAutorizado($segredoConfigurado, $segredoRecebido): bool
{
    if (
        !is_string($segredoConfigurado)
        || preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $segredoConfigurado) !== 1
    ) {
        return false;
    }

    return is_string($segredoRecebido) && hash_equals($segredoConfigurado, $segredoRecebido);
}
