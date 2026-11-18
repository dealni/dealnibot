/**
 * Contrato de autenticação de conectarRedis().
 *
 * O caminho de senha do Redis não é exercitável pela suíte PHP: conectarRedis()
 * abre socket de verdade e, na falha, encerra o processo. Aqui a função é
 * extraída do arquivo de produção e executada num subprocesso `php -n`, contra
 * um dublê de phpredis — o que se prova é que o AUTH acontece exatamente uma
 * vez quando há senha, nenhuma vez quando não há, e que uma senha recusada
 * (por `false` ou por exceção) nunca devolve um cliente.
 */
import assert from "node:assert/strict";
import { test } from "node:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from "node:fs";
import { resolve, join } from "node:path";
import { tmpdir } from "node:os";

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
// Arquivo de produção que declara conectarRedis(), funções auxiliares que ela
// chama (o extrator só renomeia a declaração pedida, por isso cada auxiliar
// ganha um encaminhador com o nome original) e a porta padrão do Redis local.
const arquivoDoConectarRedis = "inclusos/funcoes.php";
const funcoesAuxiliares = ["estabelecerConexaoRedis"];
const porta = 6380;
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

const root = resolve(import.meta.dirname, "../..");
// No Linux o tokenizer pode ser compartilhado e o -n remove seu ini. Carregue
// só essa extensão; o phpredis real continua ausente para o dublê abaixo.
const tokenizer = spawnSync("php", ["-n", "-r", 'exit(function_exists("token_get_all") ? 0 : 1);']);
const phpIsolado = tokenizer.status === 0 ? ["-n"] : ["-n", "-d", "extension=tokenizer"];
const preflight = spawnSync("php", [...phpIsolado, "-r", 'exit(function_exists("token_get_all") && !class_exists("Redis") ? 0 : 1);'], { encoding: "utf8" });
assert.equal(preflight.status, 0, `PHP isolado exige tokenizer e ausência de phpredis: ${preflight.stderr}${preflight.stdout}`);
const code = String.raw`<?php
// Dublê de phpredis: o subprocesso roda sem a extensão carregada (php -n).
class Redis {
    public const OPT_SERIALIZER = 1;
    public const OPT_MAX_RETRIES = 11;
    public const SERIALIZER_NONE = 0;
    public static int $auths = 0;
    public static int $porta = 0;
    private bool $autenticado = false;
    public function connect($host, $port, ...$args): bool { self::$porta = (int) $port; return true; }
    public function pconnect($host, $port, ...$args): bool { self::$porta = (int) $port; return true; }
    public function setOption(...$args): bool { return true; }
    public function auth($segredo): bool {
        self::$auths++;
        if (getenv('PROBE_AUTH') === 'throw') { throw new RuntimeException('auth'); }
        return $this->autenticado = getenv('PROBE_AUTH') === 'ok';
    }
    public function ping(): bool {
        if (getenv('REDIS_PASSWORD') !== '' && !$this->autenticado) { throw new RuntimeException('NOAUTH'); }
        return true;
    }
    public function close(): bool { return true; }
}
function escreverLog(...$args): void {}
function encerrarPorFalhaDoRedis($mensagem): never { throw new RuntimeException('recusado'); }
function enderecoDoRedis(): array { return ['host' => '127.0.0.1', 'port' => (int) getenv('PROBE_PORTA')]; }
function enderecoDaConexaoRedis(): array { return ['host' => '127.0.0.1', 'port' => (int) getenv('PROBE_PORTA')]; }

[, $raiz, $arquivo, $auxiliaresBrutos, $persistente, $relatorio] = $argv;
$auxiliares = $auxiliaresBrutos === '' ? [] : explode(',', $auxiliaresBrutos);

// A função de produção encerra o processo com exit(1) em parte dos bots e
// devolve o cliente nos demais; o desfecho é gravado no desligamento para que
// os dois caminhos sejam lidos do mesmo jeito.
$resultado = ['ok' => false];
register_shutdown_function(static function () use (&$resultado, $relatorio): void {
    $resultado['auth_calls'] = Redis::$auths;
    $resultado['porta'] = Redis::$porta;
    file_put_contents($relatorio, json_encode($resultado));
});

require $raiz . '/tests/Support/ExtratorDeFuncoesDeScript.php';
Tests\Support\ExtratorDeFuncoesDeScript::carregar(
    $raiz . '/' . $arquivo,
    array_merge($auxiliares, ['conectarRedis']),
    '_probe'
);
foreach ($auxiliares as $auxiliar) {
    eval("function {$auxiliar}(...\$argumentos) { return {$auxiliar}_probe(...\$argumentos); }");
}

try {
    conectarRedis_probe($persistente === 'true');
    $resultado['ok'] = true;
} catch (Throwable $e) {
    $resultado['ok'] = false;
}
`;

for (const persistent of [false, true]) {
  for (const [password, mode, expected] of [["", "ok", true], ["synthetic", "ok", true], ["synthetic", "false", false], ["synthetic", "throw", false]]) {
    test(`Redis ${persistent ? "persistente" : "normal"}: senha ${password ? mode : "ausente"}`, () => {
      const dir = mkdtempSync(join(tmpdir(), "redis-auth-"));
      try {
        const script = join(dir, "probe.php");
        const relatorio = join(dir, "relatorio.json");
        writeFileSync(script, code);
        const result = spawnSync("php", [
          ...phpIsolado, script, root, arquivoDoConectarRedis, funcoesAuxiliares.join(","), String(persistent), relatorio,
        ], {
          encoding: "utf8",
          env: { ...process.env, REDIS_PASSWORD: password, PROBE_AUTH: mode, PROBE_PORTA: String(porta) },
        });
        let relatado;
        try {
          relatado = JSON.parse(readFileSync(relatorio, "utf8"));
        } catch {
          assert.fail(`o subprocesso não deixou relatório: ${result.stderr}${result.stdout}`);
        }
        assert.deepEqual(relatado, { ok: expected, auth_calls: password ? 1 : 0, porta }, `${result.stderr}${result.stdout}`);
      } finally { rmSync(dir, { recursive: true, force: true }); }
    });
  }
}
