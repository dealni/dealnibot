<?php

declare(strict_types=1);

require_once __DIR__ . '/inclusos/config_loader.php';

$username = telegramUsernameConfigurado();
if ($username === '') {
    http_response_code(404);
    exit;
}

header('Location: https://t.me/' . $username);
