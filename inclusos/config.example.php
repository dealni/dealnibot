<?php

declare(strict_types=1);

// Copie para config.php e preencha fora do Git. O arquivo real
// (inclusos/config.php) NÃO é versionado: está no .gitignore e nunca deve ser
// commitado.
define('TOKEN_BOT', '');
define('OPENROUTER_API_KEY', '');

// OBRIGATÓRIO — valor aleatório de 32..256 caracteres [A-Za-z0-9_-].
// Preencha e rode config/config_webhook.php para registrar o mesmo segredo no
// Telegram. Vazio/ausente faz o webhook recusar todos os updates.
//
// A linha fica DESCOMENTADA de propósito, como as duas de cima: config_loader.php
// exige a constante em produção e lança RuntimeException sem ela, então quem
// copiava este arquivo seguindo o README subia um bot que respondia 500 em todo
// request. Comentada, ela só documentava — não fazia parte do contrato.
define('TELEGRAM_WEBHOOK_SECRET', '');
