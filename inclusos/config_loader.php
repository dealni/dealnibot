<?php

declare(strict_types=1);

function telegramUsernameConfigurado(): string
{
    $username = (string) getenv('DEALNI_BOT_USERNAME');

    return preg_match('/\A[A-Za-z0-9_]{5,32}\z/', $username) === 1 ? $username : '';
}

/**
 * Carrega credenciais locais em produção e valores inofensivos durante testes.
 */
function carregarConfiguracaoSensivel(): void
{
    $requiredConstants = ['TOKEN_BOT', 'OPENROUTER_API_KEY', 'TELEGRAM_WEBHOOK_SECRET'];

    // Espelha App\Support\RuntimeEnvironment::isTesting(): uma variável vazada não basta.
    if (getenv('CHATBOTS_TESTING') !== '1' || getenv('APP_ENV') !== 'test') {
        require_once __DIR__ . '/config.php';
        validarConstantesDeConfiguracao($requiredConstants, $requiredConstants);

        return;
    }

    $value = static function (string $name, string $fallback): string {
        $environmentValue = getenv($name);

        return is_string($environmentValue) && $environmentValue !== ''
            ? $environmentValue
            : $fallback;
    };

    $testConstants = [
        'TOKEN_BOT' => $value('TELEGRAM_BOT_TOKEN', '000000:TEST_TOKEN'),
        'OPENROUTER_API_KEY' => $value('OPENROUTER_API_KEY', 'test-only'),
        'TELEGRAM_WEBHOOK_SECRET' => $value('TELEGRAM_WEBHOOK_SECRET', 'test-webhook-secret-0123456789abcd'),
    ];
    foreach ($testConstants as $name => $valor) {
        if (!defined($name)) {
            define($name, $valor);
        }
    }

    validarConstantesDeConfiguracao($requiredConstants);
}

/**
 * @param array<int, string> $requiredConstants
 * @param array<int, string> $nonEmptyConstants
 */
function validarConstantesDeConfiguracao(array $requiredConstants, array $nonEmptyConstants = []): void
{
    $missing = array_values(array_filter($requiredConstants, static function (string $name): bool {
        return !defined($name);
    }));

    if ($missing !== []) {
        throw new RuntimeException(
            'Configuração incompleta em inclusos/config.php. Constantes ausentes: ' . implode(', ', $missing)
        );
    }

    $empty = array_values(array_filter($nonEmptyConstants, static function (string $name): bool {
        $value = defined($name) ? constant($name) : null;
        return !is_string($value) || trim($value) === '';
    }));
    if ($empty !== []) {
        throw new RuntimeException('Configuração vazia em inclusos/config.php: ' . implode(', ', $empty));
    }

    validarFormatoDoSegredoWebhookTelegram($requiredConstants);
}

/**
 * Valida a URL-base que recebe o caminho público do webhook.
 *
 * FILTER_VALIDATE_URL confirma a sintaxe da URL, mas aceita componentes que
 * não fazem parte de uma URL-base (credenciais, query ou fragmento). Esses
 * componentes precisam ser rejeitados antes de concatenar /bot_web.php.
 */
function urlBasePublicaValida(mixed $value): bool
{
    if (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($value);

    return is_array($parts)
        && isset($parts['scheme'], $parts['host'])
        && strcasecmp($parts['scheme'], 'https') === 0
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['query'])
        && !isset($parts['fragment']);
}

/**
 * @param array<int, string> $requiredConstants
 */
function validarFormatoDoSegredoWebhookTelegram(array $requiredConstants): void
{
    if (!in_array('TELEGRAM_WEBHOOK_SECRET', $requiredConstants, true)) {
        return;
    }

    $segredo = defined('TELEGRAM_WEBHOOK_SECRET') ? constant('TELEGRAM_WEBHOOK_SECRET') : null;
    if (!is_string($segredo) || preg_match('/\A[A-Za-z0-9_-]{32,256}\z/D', $segredo) !== 1) {
        throw new RuntimeException(
            'TELEGRAM_WEBHOOK_SECRET deve ter 32 a 256 caracteres: A-Z, a-z, 0-9, _ ou -.'
        );
    }
}
