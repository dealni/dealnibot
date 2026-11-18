<?php

declare(strict_types=1);

return [
    'DEALNI_DB_DSN' => ['type' => 'string'],
    'DEALNI_DB_USER' => ['type' => 'string'],
    'DEALNI_DB_PASSWORD' => ['type' => 'string', 'optional' => true, 'allow_empty' => true],

    'DEALNI_PUBLIC_BASE_URL' => ['type' => 'https_url'],
    'DEALNI_BOT_USERNAME' => ['type' => 'string', 'optional' => true, 'allow_empty' => true],
    // Chat do Telegram que recebe o aviso de falha do snapshot (id numérico;
    // grupo começa com -100). Ausente ou vazia, o aviso fica desligado.
    'DEALNI_ALERTA_CHAT_ID' => ['type' => 'string', 'optional' => true, 'allow_empty' => true],
    'DEALNI_SNAPSHOTS_DIR' => ['type' => 'absolute_path'],
    'DEALNI_SNAPSHOTS_MANTER' => ['type' => 'positive_integer'],
    // Aceita arquivos de ambiente antigos; esta variável não filtra nem apaga contatos.
    'DEALNI_RETENCAO_CONTATOS_DIAS' => ['type' => 'positive_integer', 'allow_empty' => true, 'optional' => true],
    // Aceita arquivos de ambiente anteriores; esta opção deixou de ser usada.
    'DEALNI_LOG_SUCCESS_BATCH' => ['type' => 'positive_integer', 'optional' => true],
    'PHP85_BIN' => ['type' => 'absolute_path'],
    'REDIS_HOST' => ['type' => 'string', 'optional' => true],
    'REDIS_PORT' => ['type' => 'positive_integer', 'optional' => true],
    'REDIS_PASSWORD' => ['type' => 'string', 'allow_empty' => true, 'optional' => true],
];
