import assert from "node:assert/strict";
import { test } from "node:test";
import { execFile, spawnSync } from "node:child_process";
import { createServer } from "node:http";
import { mkdtempSync, readFileSync, writeFileSync, rmSync, existsSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
import { promisify } from "node:util";

const root = resolve(import.meta.dirname, "../..");
const execute = promisify(execFile);
function temporary(run) {
  const dir = mkdtempSync(join(tmpdir(), "bot-standard-"));
  try { return run(dir); } finally { rmSync(dir, { recursive: true, force: true }); }
}

// A fixture mede a propagação de CI, sem executar a suíte real.
const passosSubstituidosPorNoop = new Set(["@test"]);

test("check:ci-local protege o baseline mesmo com CI ausente ou desativado", () => temporary((dir) => {
  const original = JSON.parse(readFileSync(join(root, "composer.json"), "utf8"));
  const ci = original.scripts["check:ci-local:run"].map(
    (step) => step.startsWith("npm ") || passosSubstituidosPorNoop.has(step) ? "@php -r 'exit(0);'" : step,
  );
  writeFileSync(join(dir, "probe.php"), `<?php
require ${JSON.stringify(join(root, "scripts/quality/baseline_tamanho.php"))};
if (!Quality\\baselineEhSomenteLeitura()) { file_put_contents(__DIR__ . '/baseline-modified', 'regressao'); exit(1); }
`);
  writeFileSync(join(dir, "composer.json"), JSON.stringify({ scripts: {
    "check:ci-local:run": ci, "check:base": "@php probe.php", "check:online": "@php -r 'exit(0);'",
  } }));
  for (const value of [undefined, "0", "false"]) {
    const env = { ...process.env, COMPOSER_NO_INTERACTION: "1" };
    delete env.CI;
    if (value !== undefined) env.CI = value;
    const result = spawnSync("composer", ["check:ci-local:run"], { cwd: dir, env, encoding: "utf8" });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(existsSync(join(dir, "baseline-modified")), false);
  }
}));

for (const comando of ["check:toolchain", "check:all", "check:ci-local", "check:all:run", "check:ci-local:run"]) {
test(`${comando} desativa o timeout do Composer externo antes do agregado`, () => temporary((dir) => {
  const original = JSON.parse(readFileSync(join(root, "composer.json"), "utf8"));
  const passos = [original.scripts[comando]].flat();
  writeFileSync(join(dir, "probe.php"), `<?php
final class TimeoutProbe {
    public static function verificar($event): void {
        if (\\Composer\\Util\\ProcessExecutor::getTimeout() !== 0) {
            throw new RuntimeException('O Composer externo ainda pode interromper o agregado.');
        }
    }
}
`);
  writeFileSync(join(dir, "composer.json"), JSON.stringify({
    config: { "process-timeout": 1 },
    autoload: { classmap: ["probe.php"] },
    scripts: {
      [comando]: passos.map((passo) => passo === "Composer\\Config::disableProcessTimeout" || passo.startsWith("@putenv ") ? passo : "TimeoutProbe::verificar"),
    },
  }));
  const env = { ...process.env, COMPOSER_NO_INTERACTION: "1" };
  const autoload = spawnSync("composer", ["--no-plugins", "dump-autoload", "--no-scripts"], { cwd: dir, env, encoding: "utf8" });
  assert.equal(autoload.status, 0, autoload.stdout + autoload.stderr);
  const result = spawnSync("composer", ["--no-plugins", comando], { cwd: dir, env, encoding: "utf8" });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}));
}

test("auditoria visita os dois lockfiles e propaga falha do PHPCPD", () => temporary((dir) => {
  const stub = join(dir, "composer");
  const log = join(dir, "calls");
  writeFileSync(stub, `#!/bin/sh
pwd >> "$AUDIT_CALLS"
printf '%s\\n' "$*" >> "$AUDIT_CALLS"
case "$PWD" in */tools/phpcpd) exit "\${AUDIT_ISOLATED_STATUS:-0}" ;; esac
`, { mode: 0o755 });
  for (const status of ["0", "1"]) {
    writeFileSync(log, "");
    const result = spawnSync("php", ["scripts/quality/composer_audit_retry.php"], {
      cwd: root, encoding: "utf8", env: { ...process.env, COMPOSER_BINARY: stub, AUDIT_CALLS: log, AUDIT_ISOLATED_STATUS: status },
    });
    const calls = readFileSync(log, "utf8").trim().split("\n");
    assert.equal(calls.length, 4, result.stdout + result.stderr);
    assert.equal(calls[0], root);
    assert.equal(calls[2], join(root, "tools/phpcpd"));
    assert.equal(calls[1], "audit --locked --no-interaction --abandoned=fail");
    assert.equal(calls[3], calls[1]);
    assert.equal(result.status, Number(status), result.stderr);
  }
}));

// Exercita o bloco HTTP real do verificador contra um servidor local; não toca
// em systemd, arquivos da VPS nem nos endpoints públicos dos bots.
for (const [status, body, expected] of [
  [200, { status: "ok", service: "fixturebot", version: "abc1234" }, 0],
  [500, { status: "ok", service: "fixturebot", version: "abc1234" }, 1],
  [302, { status: "ok", service: "fixturebot", version: "abc1234" }, 1],
  [200, { status: "ok", service: "outro", version: "abc1234" }, 1],
  [200, { status: "ok", service: "fixturebot", version: "antiga" }, 1],
  [200, { service: "fixturebot", version: "abc1234" }, 1],
  [200, {}, 1],
]) {
  test(`health: HTTP ${status}, ${JSON.stringify(body)}`, async () => {
    const server = createServer((_req, res) => {
      res.writeHead(status, { "Content-Type": "application/json" });
      res.end(JSON.stringify(body));
    });
    await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
    try {
      const source = readFileSync(join(root, "scripts/deploy/deploy_verify.sh"), "utf8");
      const start = source.indexOf('health_body="$(mktemp');
      let block;
      if (start >= 0) {
        const end = source.indexOf('rm -f "$health_body"', start);
        assert.ok(end > start);
        block = source.slice(start, end + 'rm -f "$health_body"'.length);
      } else {
        const oldStart = source.indexOf('            if ! corpo="$(curl');
        assert.ok(oldStart >= 0);
        block = source.slice(oldStart, source.indexOf("\n        fi", oldStart));
      }
      const script = 'set -euo pipefail\nfailed=0\naprovar() { :; }; reprovar() { failed=1; };\n' + block + '\nexit "$failed"';
      let actual = 0;
      try {
        await execute("bash", ["-c", script], { env: {
          ...process.env, bot_slug: "fixturebot", versao_esperada: "abc1234",
          validador_sonda: join(root, "scripts/quality/validar_sonda_publicada.php"),
          health_endpoint: `http://127.0.0.1:${server.address().port}/health`,
        } });
      } catch (error) { actual = error.code; }
      assert.equal(actual, expected);
    } finally { await new Promise((resolve) => server.close(resolve)); }
  });
}

if (existsSync(join(root, "suporte/tests/run.php"))) {
  test("CI inclui a suíte independente de suporte", () => {
    const workflow = readFileSync(join(root, ".github/workflows/ci.yml"), "utf8");
    assert.match(workflow, /^\s+run: composer test:suporte\s*$/m);
  });
}
