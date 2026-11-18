import assert from "node:assert/strict";
import { mkdtempSync, mkdirSync, realpathSync, writeFileSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { resolve, join } from "node:path";
import { spawnSync } from "node:child_process";
import test from "node:test";

const root = resolve(import.meta.dirname, "../..");
function fixture(body) {
  const dir = realpathSync(mkdtempSync(join(tmpdir(), "publicacao-fixture-")));
  try { body(dir); } finally { rmSync(dir, { recursive: true, force: true }); }
}
function run(dir, args, env = process.env) {
  return spawnSync(args[0], args.slice(1), { cwd: dir, env, encoding: "utf8" });
}
function git(dir, ...args) {
  const result = run(dir, ["git", ...args]);
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

test("republicar o mesmo SHA preserva o último destino diferente para rollback", () => {
  fixture((dir) => {
    git(dir, "init", "-q");
    git(dir, "config", "user.name", "Fixture");
    git(dir, "config", "user.email", "fixture@example.invalid");
    writeFileSync(join(dir, ".gitignore"), ".release.json\n");
    writeFileSync(join(dir, "composer.lock"), "{}\n");
    git(dir, "add", ".gitignore", "composer.lock");
    git(dir, "commit", "-qm", "fixture inicial");
    const a = git(dir, "rev-parse", "HEAD");
    writeFileSync(join(dir, "codigo.txt"), "segunda versão\n");
    git(dir, "add", "codigo.txt");
    git(dir, "commit", "-qm", "fixture seguinte");
    const b = git(dir, "rev-parse", "HEAD");
    const file = join(dir, ".release.json");
    writeFileSync(file, JSON.stringify({ commit: a }));
    for (const origem of ["deploy", "deploy", "rollback"]) {
      const r = run(dir, [join(root, "scripts/deploy/registrar_publicacao.sh"), file, origem], { ...process.env, RELEASE_PROJECT_DIR: dir });
      assert.equal(r.status, 0, r.stderr);
      const data = JSON.parse(readFileSync(file, "utf8"));
      assert.equal(data.commit, b);
      assert.equal(data.commit_anterior, a);
    }
  });
});

const ready = { status: "ok", service: "fixture", version: "1234567", checks: { postgresql: "ok", redis: "ok" } };
for (const [name, data, expected] of [
  ["pronta", ready, 0],
  ["versão antiga", { ...ready, version: "7654321" }, 1],
  ["outro serviço", { ...ready, service: "vizinho" }, 1],
  ["status erro", { ...ready, status: "erro" }, 1],
  ["banco indisponível", { ...ready, checks: { postgresql: "erro", redis: "ok" } }, 1],
  ["cache indisponível", { ...ready, checks: { postgresql: "ok", redis: "erro" } }, 1],
  ["sem dependências", { ...ready, checks: {} }, 1],
  ["sem PostgreSQL", { ...ready, checks: { redis: "ok" } }, 1],
  ["sem Redis", { ...ready, checks: { postgresql: "ok" } }, 1],
  ["dependência extra reprovada", { ...ready, checks: { ...ready.checks, extra: "erro" } }, 1],
  ["JSON escalar", null, 1],
]) {
  test(`prontidão HTTP/FPM: ${name}`, () => {
    fixture((dir) => {
      const file = join(dir, "sonda.json");
      writeFileSync(file, JSON.stringify(data));
      const r = run(root, ["php", "scripts/quality/validar_sonda_publicada.php", file, "fixture", "1234567", "ready"]);
      assert.equal(r.status, expected, r.stderr);
    });
  });
}

test("verificação exige HTTP 200 e valida /ready antes de aprovar publicação", () => {
  const source = readFileSync(join(root, "scripts/deploy/deploy_verify.sh"), "utf8");
  assert.match(source, /ready_endpoint="\$\{public_url%\/\}\/ready"/);
  assert.match(source, /\[\[ "\$ready_status" != "200" \]\]/);
  assert.match(source, /php "\$validador_sonda" "\$ready_body" "\$bot_slug" "\$versao_esperada" ready/);
});

for (const [name, status, curlStatus, body, expected] of [
  ["dependências prontas", "200", 0, ready, 0],
  ["HTTP 503 mesmo com corpo antigo válido", "503", 0, ready, 1],
  ["timeout HTTP", "000", 28, ready, 1],
  ["HTTP 200 com banco reprovado", "200", 0, { ...ready, checks: { postgresql: "erro", redis: "ok" } }, 1],
  ["HTTP 200 com versão antiga", "200", 0, { ...ready, version: "antiga" }, 1],
]) {
  test(`verificador HTTP/FPM propaga falha: ${name}`, () => {
    const source = readFileSync(join(root, "scripts/deploy/deploy_verify.sh"), "utf8");
    const start = source.indexOf("# Consulte também a prontidão pelo HTTP/FPM:");
    const end = source.indexOf("if ((rodar_smoke == 1)); then", start);
    assert.ok(start > 0 && end > start);
    const script = `set -euo pipefail
falhas=0
public_url=https://fixture.invalid
bot_slug=fixture
versao_esperada=1234567
validador_sonda=scripts/quality/validar_sonda_publicada.php
https_origin_valida() { return 0; }
aprovar() { :; }
reprovar() { falhas=$((falhas+1)); }
curl() {
  local destino=""
  while (($#)); do
    if [[ "$1" == --output ]]; then destino="$2"; shift; fi
    shift
  done
  printf '%s' "$FIXTURE_BODY" > "$destino"
  printf '%s' "$FIXTURE_STATUS"
  return "$FIXTURE_CURL"
}
${source.slice(start, end)}
printf '%s' "$falhas"
`;
    const r = run(root, ["bash", "-c", script], { ...process.env, FIXTURE_BODY: JSON.stringify(body), FIXTURE_STATUS: status, FIXTURE_CURL: String(curlStatus) });
    assert.equal(r.status, 0, r.stderr);
    assert.equal(r.stdout, String(expected), r.stderr);
  });
}

for (const codigo of [0, 2]) {
  test(`release:check valida Composer uma vez e respeita saída ${codigo}`, () => {
    fixture((dir) => {
      const repo = join(dir, "repo");
      const bin = join(dir, "bin");
      mkdirSync(repo);
      mkdirSync(bin);
      mkdirSync(join(repo, "scripts/deploy"), { recursive: true });
      mkdirSync(join(repo, "database"));
      const log = join(dir, "composer.log");
      writeFileSync(join(bin, "composer"), `#!/bin/sh\nprintf '%s\\n' "$*" >> "$COMPOSER_FIXTURE_LOG"\nexit ${codigo}\n`, { mode: 0o755 });
      writeFileSync(join(repo, "scripts/deploy/release_check.sh"), readFileSync(join(root, "scripts/deploy/release_check.sh")));
      writeFileSync(join(repo, ".env.example"), "FIXTURE=sem-segredo\n");
      writeFileSync(join(repo, "database/schema.sql"), "-- fixture\n");
      git(repo, "init", "-q", "-b", "main");
      git(repo, "config", "user.name", "Fixture");
      git(repo, "config", "user.email", "fixture@example.invalid");
      git(repo, "config", "core.hooksPath", "/dev/null");
      git(repo, "config", "commit.gpgsign", "false");
      git(repo, "add", ".");
      git(repo, "commit", "-qm", "fixture inicial");
      const sha = git(repo, "rev-parse", "HEAD");
      const r = run(repo, ["/bin/bash", "-p", "scripts/deploy/release_check.sh"], { ...process.env, PATH: `${bin}:${process.env.PATH}`, COMPOSER_FIXTURE_LOG: log });
      assert.equal(r.status, codigo === 0 ? 0 : 1, r.stderr + r.stdout);
      assert.equal(readFileSync(log, "utf8"), "validate --strict --check-lock --no-check-publish --no-interaction\n");
      if (codigo === 0) assert.ok(r.stdout.includes(`scripts/deploy/publicar.sh --esperado=${sha}`));
      else assert.doesNotMatch(r.stdout, /Release liberado/u);
    });
  });
}
