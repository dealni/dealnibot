<?php

declare(strict_types=1);

/** @return array<string, string> */
function parseEnvExample(string $contents): array
{
    $values = [];
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        throw new InvalidArgumentException('Não foi possível separar as linhas do ambiente.');
    }
    foreach ($lines as $index => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(?:export\s+)?([A-Z][A-Z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
            throw new InvalidArgumentException('Linha ' . ($index + 1) . ' não usa NOME=valor.');
        }
        if (array_key_exists($matches[1], $values)) {
            throw new InvalidArgumentException("Variável duplicada: {$matches[1]}.");
        }
        $value = trim($matches[2]);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $values[$matches[1]] = $value;
    }
    return $values;
}

/**
 * @param array<string, mixed> $values
 * @param array<mixed, mixed> $schema
 * @return list<string>
 */
function validateEnvExample(array $values, array $schema): array
{
    $errors = [];
    foreach (array_diff(array_keys($schema), array_keys($values)) as $name) {
        $rule = $schema[$name] ?? null;
        if (!is_array($rule) || ($rule['optional'] ?? false) !== true) {
            $errors[] = "Variável ausente: {$name}.";
        }
    }
    foreach (array_diff(array_keys($values), array_keys($schema)) as $name) {
        $errors[] = "Variável sem schema: {$name}.";
    }
    foreach ($schema as $name => $rule) {
        if (!is_string($name)) {
            $errors[] = 'O schema contém uma chave que não é textual.';
            continue;
        }
        if (!array_key_exists($name, $values)) {
            continue;
        }
        if (!is_array($rule) || !isset($rule['type']) || !is_string($rule['type'])) {
            $errors[] = "Schema inválido para {$name}.";
            continue;
        }
        $value = $values[$name];
        if (!is_string($value)) {
            $errors[] = "{$name} deve ser textual.";
            continue;
        }
        if ($value === '') {
            if (($rule['allow_empty'] ?? false) !== true) {
                $errors[] = "{$name} não pode ficar vazia.";
            }
            continue;
        }
        // A anotação do array é string, mas este helper também é usado pelos
        // testes e pode receber dados montados por outro chamador. A guarda
        // mantém ctype_digit() estritamente no contrato string: desde PHP 8.1
        // inteiros e outros tipos em ctype_* emitem depreciação, e o futuro
        // PHP os interpretará como string (em vez do código ASCII legado).
        $valid = match ($rule['type']) {
            'absolute_path' => str_starts_with($value, '/'),
            'https_url' => isHttpsBaseUrl($value),
            'https_origin' => isHttpsOrigin($value),
            'positive_integer' => ctype_digit($value) && (int) $value > 0,
            'string' => true,
            default => false,
        };
        if (!$valid) {
            $errors[] = "{$name} não atende ao tipo {$rule['type']}.";
        }
    }
    return $errors;
}

function isHttpsBaseUrl(string $value): bool
{
    if (filter_var($value, FILTER_VALIDATE_URL) === false || parse_url($value, PHP_URL_SCHEME) !== 'https') {
        return false;
    }
    $parts = parse_url($value);
    return is_array($parts)
        && isset($parts['host'])
        && $parts['host'] !== ''
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['query'])
        && !isset($parts['fragment']);
}

/**
 * Valida uma origem HTTPS após a mesma normalização de barra final usada por
 * dados_bot.php. Ao contrário de uma URL genérica, uma origem não pode
 * carregar caminho: as rotas de saúde do Nginx e o webhook registrado são
 * ancorados em `/`.
 */
function isHttpsOrigin(string $value): bool
{
    $normalized = rtrim(trim($value), '/');
    if ($normalized === '' || !isHttpsBaseUrl($normalized)) {
        return false;
    }

    $parts = parse_url($normalized);
    return is_array($parts) && !isset($parts['path']);
}
