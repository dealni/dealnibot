import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { copyFileSync, existsSync, readFileSync, readdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { fixture } from "./validation-fixture.mjs";

function prepare(t) {
  const f = fixture(t);
  const files = ["validation-runner.mjs", "validation-evidence.mjs", "validation-identity.mjs", "validation-active.mjs", "validation-environment.mjs", "validation-delegation.mjs"];
  for (const file of files) copyFileSync(path.join(import.meta.dirname, file), path.join(f.repo, "scripts/quality", file));
  const composer = { scripts: {
    "check:all": ["@check:all:run"],
    "check:ci-local": ["@check:ci-local:run"],
    "check:all:run": ["Composer\\Config::disableProcessTimeout", "@check:base", "@test"],
    "check:ci-local:run": ["@check:base", "@test"],
    "check:base": ["@quality"],
    quality: "php gate.php",
    test: ["Composer\\Config::disableProcessTimeout", "@php scripts/quality/run_tests.php"],
  } };
  const npm = { scripts: { "test:quality-tools": "node --test", "check:licenses": "node licenses.mjs", "check:vulnerabilities": "npm audit" } };
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const plan = JSON.parse(readFileSync(file, "utf8"));
  plan.revision = "bots-v4";
  plan.equivalence = { composerScripts: structuredClone(composer.scripts), npmScripts: structuredClone(npm.scripts) };
  const save = () => {
    writeFileSync(path.join(f.repo, "composer.json"), JSON.stringify(composer));
    writeFileSync(path.join(f.repo, "package.json"), JSON.stringify(npm));
    writeFileSync(file, JSON.stringify(plan));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "test: prepara contratos sintéticos dos comandos");
  };
  const run = () => spawnSync(process.execPath, ["scripts/quality/validation-runner.mjs", "--proof", "check:all", "--", ...f.gate], {
    cwd: f.repo, env: f.environment, encoding: "utf8",
  });
  const receipts = () => {
    const dir = path.join(f.repo, ".git/quality-validation");
    return existsSync(dir) ? readdirSync(dir).filter((entry) => entry.endsWith(".json")) : [];
  };
  return { ...f, composer, npm, plan, save, run, receipts };
}

test("bindings válidos permitem executar o wrapper e emitir recibo", (t) => {
  const f = prepare(t);
  f.save();
  const result = f.run();
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /GATE_EXECUTED/);
  assert.equal(f.receipts().length, 1);
});

for (const [name, mutate] of [
  ["remove @test do agregado", (f) => { f.composer.scripts["check:all:run"].pop(); }],
  ["troca o comando normal", (f) => { f.composer.scripts.test = "php -r 'exit(0);'"; }],
  ["remove binding do agregado", (f) => { delete f.plan.equivalence.composerScripts["check:all:run"]; }],
  ["remove binding da suíte", (f) => { delete f.plan.equivalence.composerScripts.test; }],
  ["remove binding da base", (f) => { delete f.plan.equivalence.composerScripts["check:base"]; f.composer.scripts["check:base"] = []; }],
  ["remove binding de folha transitiva", (f) => { delete f.plan.equivalence.composerScripts.quality; f.composer.scripts.quality = "php -r 'exit(0);'"; }],
  ["remove bindings Composer", (f) => { delete f.plan.equivalence.composerScripts; }],
  ["remove bindings npm", (f) => { delete f.plan.equivalence.npmScripts; }],
  ["troca uma folha npm", (f) => { f.npm.scripts["test:quality-tools"] = "node -e ''"; }],
]) {
  test(`contrato divergente ${name} bloqueia o comando e o recibo`, (t) => {
    const f = prepare(t);
    mutate(f);
    f.save();
    const result = f.run();
    assert.notEqual(result.status, 0, result.stdout + result.stderr);
    assert.match(result.stderr, /contrato de equivalência divergente/);
    assert.ok(!result.stdout.includes("GATE_EXECUTED"));
    assert.deepEqual(f.receipts(), []);
  });
}

test("bindings divergentes impedem reutilizar recibo anteriormente válido", (t) => {
  const f = prepare(t);
  f.save();
  assert.equal(f.run().status, 0);
  f.composer.scripts.test = "php -r 'exit(0);'";
  f.save();
  const result = f.reuse();
  assert.notEqual(result.status, 0);
  assert.ok(!result.stdout.includes("REUSED=true"));
  const rerun = f.run();
  assert.notEqual(rerun.status, 0);
  assert.ok(!rerun.stdout.includes("GATE_EXECUTED"));
});
