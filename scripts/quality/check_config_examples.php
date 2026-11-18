<?php

declare(strict_types=1);

/**
 * Confere se os exemplos versionados acompanham o contrato real de produção.
 *
 * O config.php verdadeiro não existe no CI; esta checagem trabalha apenas com
 * nomes de constantes e variáveis, nunca com valores ou credenciais.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Chaves que NÃO derrubam o bot e travam uma funcionalidade inteira. O
 * $requiredConstants lido do config_loader cobre o que o processo recusa para
 * subir; estas sobem e deixam o bot mudo naquilo que a documentação promete.
 * Lista vazia é o normal: só entra aqui chave que já enganou alguém.
 *
 * @var list<string>
 */
$chavesOpcionaisQueTravamFuncionalidade = [];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

$root = dirname(__DIR__, 2);
$loaderPath = $root . '/inclusos/config_loader.php';
$configExamplePath = $root . '/inclusos/config.example.php';
$environmentExamplePath = $root . '/.env.example';

$read = static function (string $path): string {
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException("Não foi possível ler {$path}.");
    }

    return $contents;
};

try {
    $loader = $read($loaderPath);
    $configExample = $read($configExamplePath);
    $environmentExample = $read($environmentExamplePath);

    if (preg_match('/\$requiredConstants\s*=\s*\[(.*?)\];/s', $loader, $requiredBlock) !== 1) {
        throw new RuntimeException('Não foi possível localizar $requiredConstants em config_loader.php.');
    }

    preg_match_all("/['\"]([A-Z][A-Z0-9_]*)['\"]/", $requiredBlock[1], $requiredMatches);
    $requiredConstants = array_values(array_unique($requiredMatches[1]));
    if ($requiredConstants === []) {
        throw new RuntimeException('config_loader.php não declarou constantes obrigatórias.');
    }

    $missingConstants = [];
    foreach ($requiredConstants as $constant) {
        $pattern = '/define\s*\(\s*[\'\"]' . preg_quote($constant, '/') . '[\'\"]/';
        if (preg_match($pattern, $configExample) !== 1) {
            $missingConstants[] = $constant;
        }
    }
    if ($missingConstants !== []) {
        throw new RuntimeException(
            'Constantes obrigatórias ausentes de inclusos/config.example.php: '
            . implode(', ', $missingConstants)
        );
    }

    $environmentVariables = [];
    foreach ([$loaderPath, $root . '/inclusos/dados_bot.php', $root . '/inclusos/caminhos.php'] as $sourcePath) {
        $source = $read($sourcePath);
        preg_match_all("/getenv\(\s*['\"](DEALNI_[A-Z0-9_]+)['\"]\s*\)/", $source, $matches);
        array_push($environmentVariables, ...$matches[1]);
    }
    $environmentVariables[] = 'PHP85_BIN';
    $environmentVariables = array_values(array_unique($environmentVariables));

    $missingEnvironment = [];
    foreach ($environmentVariables as $name) {
        if (preg_match('/^' . preg_quote($name, '/') . '=/m', $environmentExample) !== 1) {
            $missingEnvironment[] = $name;
        }
    }
    if ($missingEnvironment !== []) {
        throw new RuntimeException(
            'Variáveis obrigatórias ausentes de .env.example: '
            . implode(', ', $missingEnvironment)
        );
    }

    $tokens = token_get_all($configExample, TOKEN_PARSE);
    if ($tokens === []) {
        throw new RuntimeException('inclusos/config.example.php está vazio.');
    }

    // A chave precisa estar PRESENTE e ATIVA nos dois templates, mesmo vazia:
    // valor vazio é configuração pendente e visível; linha ausente é armadilha.
    // Foi assim que uma chave de integração passou despercebida em 2026-08-24: ela
    // estava comentada no config.example.php e fora dos outros dois, e quem
    // seguia o README à risca subia um bot com a funcionalidade travada.
    //
    // Por isso a exigência é sobre define() ATIVO, e não sobre a regra geral
    // "todo define opcional aparece no .env.example": essa se silencia
    // sozinha bastando recomentar a linha.
    if ($chavesOpcionaisQueTravamFuncionalidade !== []) {
        $definidasNoExemplo = [];
        $total = count($tokens);
        for ($i = 0; $i < $total; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'define') {
                continue;
            }
            // Comentário não vira T_STRING: chegar aqui já significa código ativo.
            for ($j = $i + 1; $j < $total; $j++) {
                $seguinte = $tokens[$j];
                if (is_array($seguinte) && $seguinte[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $definidasNoExemplo[] = trim($seguinte[1], "'\"");
                    break;
                }
                if ($seguinte !== '(') {
                    break;
                }
            }
        }

        // O cron carrega este mesmo .env pelo wrapper versionado; duplicar a
        // chave em config/crontab.txt criaria uma segunda fonte de verdade.
        $templates = ['.env.example' => $environmentExample];

        foreach ($chavesOpcionaisQueTravamFuncionalidade as $chave) {
            if (!in_array($chave, $definidasNoExemplo, true)) {
                throw new RuntimeException(
                    "{$chave} precisa de um define() ATIVO (pode ser vazio) em inclusos/config.example.php"
                    . ' — comentada, ela some da vista de quem instala.'
                );
            }
            foreach ($templates as $arquivo => $conteudo) {
                if (preg_match('/^' . preg_quote($chave, '/') . '=/m', $conteudo) !== 1) {
                    throw new RuntimeException("{$chave} ausente de {$arquivo}.");
                }
            }
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Contrato dos exemplos de configuração inválido: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo 'Exemplos de configuração coerentes: '
    . count($requiredConstants) . ' constante(s), '
    . count($environmentVariables) . ' variável(is) de ambiente.' . PHP_EOL;
