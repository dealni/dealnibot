import assert from "node:assert/strict";
import { test } from "node:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, mkdirSync, rmSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";

const root = resolve(import.meta.dirname, "../..");
const candidates = [
  "/opt/homebrew/opt/postgresql@17/bin", "/usr/local/opt/postgresql@17/bin",
  "/opt/homebrew/opt/postgresql@16/bin", "/usr/local/opt/postgresql@16/bin",
  "/usr/lib/postgresql/17/bin", "/usr/lib/postgresql/16/bin",
];
function fixture(run) {
  const dir = mkdtempSync(join(tmpdir(), "bot-postgres-bin-"));
  try {
    let source = readFileSync(join(root, "scripts/quality/with_postgres.sh"), "utf8");
    const bins = candidates.map((candidate, index) => {
      const bin = join(dir, `pg-${index}`);
      source = source.replaceAll(candidate, bin);
      return bin;
    });
    const script = join(dir, "with_postgres.sh");
    writeFileSync(script, source);
    const log = join(dir, "calls");
    writeFileSync(log, "");
    const env = { ...process.env, FAKE_PG_LOG: log };
    delete env.CHATBOTS_PG_TEST_DSN;
    delete env.CHATBOTS_PG_BIN;
    const install = (bin, tools = ["initdb", "pg_ctl", "createdb"]) => {
      mkdirSync(bin, { recursive: true });
      for (const tool of tools) {
        writeFileSync(join(bin, tool), `#!/bin/bash\nprintf '%s\\n' "$0 $*" >> "$FAKE_PG_LOG"\n`, { mode: 0o755 });
      }
    };
    return run({ bins, script, env, log, install });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}
function execute(script, env) {
  return spawnSync("bash", [script, "bash", "-c", '[[ "$CHATBOTS_PG_TEST_DSN" == pgsql:host=/tmp/bot-pg.* ]]'], { env, encoding: "utf8" });
}
for (let index = 0; index < candidates.length; index++) {
  test(`descobre PostgreSQL isolado em ${candidates[index]}`, () => fixture(({ bins, script, env, log, install }) => {
    install(bins[index]);
    const result = execute(script, env);
    assert.equal(result.status, 0, result.stderr);
    const calls = readFileSync(log, "utf8").trim().split("\n");
    assert.equal(calls.length, 4);
    assert.ok(calls.every((call) => call.startsWith(bins[index] + "/")));
    assert.match(calls[1], /listen_addresses=''/);
    assert.match(calls[3], /-m immediate stop/);
  }));
}
test("override explícito prevalece sobre descoberta automática", () => fixture(({ bins, script, env, log, install }) => {
  install(bins[0]);
  install(bins[3]);
  env.CHATBOTS_PG_BIN = bins[3];
  assert.equal(execute(script, env).status, 0);
  assert.ok(readFileSync(log, "utf8").trim().split("\n").every((call) => call.startsWith(bins[3] + "/")));
}));
test("override incompleto informa executável faltante antes de criar cluster", () => fixture(({ bins, script, env, log, install }) => {
  install(bins[0], ["initdb"]);
  env.CHATBOTS_PG_BIN = bins[0];
  const result = execute(script, env);
  assert.equal(result.status, 2);
  assert.match(result.stderr, /CHATBOTS_PG_BIN inválido: falta executável pg_ctl/);
  assert.equal(readFileSync(log, "utf8"), "");
}));
test("ausência dos binários orienta CHATBOTS_PG_BIN sem iniciar instalação", () => fixture(({ script, env, log }) => {
  const result = execute(script, env);
  assert.equal(result.status, 2);
  assert.match(result.stderr, /PostgreSQL 16\/17 não encontrado.*CHATBOTS_PG_BIN/);
  assert.equal(readFileSync(log, "utf8"), "");
}));
test("DSN de testes fornecida preserva bypass de descoberta", () => fixture(({ script, env, log }) => {
  env.CHATBOTS_PG_TEST_DSN = "pgsql:host=fixture";
  const result = spawnSync("bash", [script, "bash", "-c", '[[ "$CHATBOTS_PG_TEST_DSN" == pgsql:host=fixture ]]'], { env, encoding: "utf8" });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(readFileSync(log, "utf8"), "");
}));
