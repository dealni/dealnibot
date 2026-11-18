<?php

declare(strict_types=1);

// Inclui credenciais locais em produção e valores seguros durante testes.
require_once __DIR__ . '/config_loader.php';
carregarConfiguracaoSensivel();

// A URL HTTPS é necessária para o registro do webhook. O polling local
// funciona sem ela.
$publicBaseUrl = trim((string) getenv('DEALNI_PUBLIC_BASE_URL'));
if ($publicBaseUrl !== '' && !urlBasePublicaValida($publicBaseUrl)) {
    throw new RuntimeException('DEALNI_PUBLIC_BASE_URL deve ser uma URL HTTPS válida.');
}
define('PUBLIC_BASE_URL', rtrim($publicBaseUrl, '/'));

// Ativar/desativar logs em arquivo (pasta logs/)
define('LOGS_ATIVO', true);
