import assert from "node:assert/strict";
import { test } from "node:test";
import { execFileSync, spawnSync } from "node:child_process";
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

const root = resolve(import.meta.dirname, "../..");
const { scripts } = JSON.parse(readFileSync(join(root, "composer.json"), "utf8"));
const gatesRapidos = [
  "check:runtime", "check:tests", "check:complexity", "check:strict-types",
  "check:deploy-guards", "check:licenses", "check:gitleaks",
];
const callbackTimeout = "Composer\\Config::disableProcessTimeout";

test("inventário obrigatório inclui os testes de recibos com concorrência um", () => {
  const npmScripts = JSON.parse(readFileSync(join(root, "package.json"), "utf8")).scripts;
  const command = npmScripts["test:quality-tools"].split(/\s+/);
  assert.equal(command.filter((argument) => argument === "--test-concurrency=1").length, 1);
  for (const file of [
    "validation-evidence.test.mjs", "validation-environment.test.mjs",
    "validation-recovery.test.mjs", "validation-bindings.test.mjs",
  ]) {
    const relative = `scripts/quality/${file}`;
    assert.equal(command.filter((argument) => argument === relative).length, 1, relative);
    assert.ok(existsSync(join(root, relative)), `Teste obrigatório ausente: ${relative}`);
  }
});

function expandir(nome, visitados = []) {
  assert.ok(!visitados.includes(nome), `Ciclo no agregado: ${[...visitados, nome].join(" -> ")}`);
  assert.ok(Object.hasOwn(scripts, nome), `Comando ausente: ${nome}`);
  return [scripts[nome]].flat().flatMap((passo) => {
    if (passo === callbackTimeout || passo.startsWith("@putenv ")) return [];
    const referencia = passo.startsWith("@") ? passo.slice(1) : "";
    return Object.hasOwn(scripts, referencia)
      ? expandir(referencia, [...visitados, nome])
      : [{ nome: ["check:all:run", "check:base"].includes(nome) ? passo : nome, passo }];
  });
}

function fixture(run) {
  const dir = mkdtempSync(join(tmpdir(), "bot-gates-ordem-"));
  try {
    const substituidos = Object.fromEntries(Object.entries(scripts).map(([nome, passos]) => [
      nome,
      [passos].flat().map((passo) => {
        if (passo === callbackTimeout || passo.startsWith("@putenv ")) return passo;
        if (passo.startsWith("@") && Object.hasOwn(scripts, passo.slice(1))) return passo;
        const id = ["check:all:run", "check:base"].includes(nome) ? passo : nome;
        return `@php probe.php ${Buffer.from(id).toString("hex")}`;
      }),
    ]));
    writeFileSync(join(dir, "composer.json"), JSON.stringify({ scripts: substituidos }));
    writeFileSync(join(dir, "probe.php"), `<?php
$nome = hex2bin($argv[1]);
file_put_contents(__DIR__ . '/calls', $nome . PHP_EOL, FILE_APPEND);
exit($nome === getenv('FAIL_GATE') ? 23 : 0);
`);
    return run(dir);
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

function executar(dir, falha = "", agregado = "check:all:run") {
  const result = spawnSync("composer", ["--no-plugins", "--no-interaction", agregado], {
    cwd: dir, encoding: "utf8", env: { ...process.env, FAIL_GATE: falha },
  });
  const log = join(dir, "calls");
  return { result, calls: existsSync(log) ? readFileSync(log, "utf8").trim().split("\n") : [] };
}

test("agregados públicos emitem evidência e privados não aninham o issuer", () => {
  for (const agregado of ["check:all", "check:ci-local"]) {
    assert.deepEqual(scripts[agregado], [
      callbackTimeout,
      `node scripts/quality/validation-runner.mjs --proof check:all -- composer ${agregado}:run`,
    ]);
  }
  for (const privado of ["check:all:run", "check:ci-local:run"]) assert.equal(scripts[privado][0], callbackTimeout);
  assert.ok(!scripts["check:ci-local:run"].includes("@check:base"));
  assert.ok(scripts["check:ci-local:run"].includes("@check:all:run"));
  assert.ok(!scripts["check:ci-local:run"].includes("@check:all"));
});

test("check:all preserva os gates e antecipa falhas baratas antes das suítes", () => {
  assert.deepEqual(scripts["check:all:run"], [callbackTimeout, "@check:base", "@test"]);
  const baseEsperada = [
    ...gatesRapidos.map((gate) => `@${gate}`), "npm run test:quality-tools",
    "@check:config", "@check:config:production", "@check:config:examples", "@check:env",
    "@check:postgres", "@check:editorconfig", "@check:shell", "@check:docs", "@typecheck", "@format:check",
    "@check:deps:undeclared", "@check:dead-code", "@check:security", "@check:duplication",
  ];
  assert.deepEqual(scripts["check:base"], [callbackTimeout, ...baseEsperada]);
  const baseDiaria = expandir("check").filter(({ nome }) => nome !== "test").map(({ passo }) => passo);
  const folhasComuns = expandir("check:base").map(({ passo }) => passo);
  for (const passo of baseDiaria) assert.ok(folhasComuns.includes(passo), `Folha diária perdida: ${passo}`);
  assert.deepEqual(expandir("check:all:run"), [...expandir("check:base"), ...expandir("test")]);
  const ordem = expandir("check:all:run").map(({ nome }) => nome);
  for (const gate of [...gatesRapidos, "npm run test:quality-tools", "check:editorconfig", "check:shell", "check:docs"]) {
    assert.ok(ordem.indexOf(gate) >= 0, `${gate} ausente`);
    assert.ok(ordem.indexOf(gate) < ordem.indexOf("typecheck"), `${gate} deve preceder o PHPStan`);
    assert.ok(ordem.indexOf(gate) < ordem.indexOf("test"), `${gate} deve preceder o PHPUnit`);
  }
  assert.equal(scripts["check:runtime"], "bash scripts/quality/check_php_versions.sh runtime");
  assert.equal(ordem.filter((nome) => nome === "test").length, 1);
  assert.ok(!ordem.includes("check:toolchain"), "O agregado não pode chamar a toolchain completa recursivamente");
});

test("check preserva a suíte inteira e ci-local conserva a suíte integral e auditorias", () => {
  const base = [
    "@check:config", "@check:config:production", "@check:config:examples", "@check:env",
    "@check:postgres", "@typecheck", "@format:check", "@check:editorconfig",
    "@check:shell", "@check:docs", "@test",
  ];
  if (Object.hasOwn(scripts, "test:suporte")) base.push("@test:suporte");
  assert.deepEqual([...scripts.check].sort(), base.sort());
  assert.deepEqual(scripts["check:ci-local:run"], [
    callbackTimeout, "@putenv CI=1", "@check:all:run",
    "@check:online",
  ]);
});

for (const gate of ["check:runtime", "check:tests", "check:complexity", "check:strict-types"]) {
  test(`falha em ${gate} interrompe o Composer antes dos gates pesados`, () => fixture((dir) => {
    const { result, calls } = executar(dir, gate);
    assert.notEqual(result.status, 0, result.stdout + result.stderr);
    assert.equal(calls.at(-1), gate, result.stdout + result.stderr);
    for (const pesado of ["typecheck", "format:check", "test", "test:suporte", "check:security", "check:duplication"]) {
      assert.ok(!calls.includes(pesado), `${pesado} rodou após a falha de ${gate}`);
    }
  }));
}

test("agregado aprovado continua executando todos os passos uma única vez", () => fixture((dir) => {
  const { result, calls } = executar(dir);
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.deepEqual(calls, expandir("check:all:run").map(({ nome }) => nome));
}));

test("ci-local executa todas as folhas comuns uma vez e executa a suíte normal integral", () => fixture((dir) => {
  const { result, calls } = executar(dir, "", "check:ci-local:run");
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.deepEqual(calls, expandir("check:ci-local:run").map(({ nome }) => nome));
  for (const { nome } of expandir("check:base")) {
    assert.equal(calls.filter((chamada) => chamada === nome).length, expandir("check:base").filter((folha) => folha.nome === nome).length);
  }
  assert.equal(calls.filter((nome) => nome === "test").length, 1);
}));

for (const gate of ["test"]) {
  test(`falha em ${gate} impede aprovar ci-local ou executar auditorias posteriores`, () => fixture((dir) => {
    const { result, calls } = executar(dir, gate, "check:ci-local:run");
    assert.notEqual(result.status, 0, result.stdout + result.stderr);
    assert.equal(calls.at(-1), gate);
    assert.ok(!calls.includes("check:online"));
    assert.ok(!calls.includes("check:vulnerabilities"));
  }));
}

test("plano de evidência acompanha a composição real e mantém online fora do reuso", async () => {
  const plan = JSON.parse(readFileSync(join(root, "scripts/quality/validation-plan.json"), "utf8"));
  const packageScripts = JSON.parse(readFileSync(join(root, "package.json"), "utf8")).scripts;
  const reachable = {};
  function visitar(nome) {
    if (Object.hasOwn(reachable, nome)) return;
    reachable[nome] = scripts[nome];
    for (const passo of [scripts[nome]].flat()) {
      if (passo.startsWith("@") && Object.hasOwn(scripts, passo.slice(1))) visitar(passo.slice(1));
    }
  }
  visitar("check:ci-local");
  visitar("check:ci-local:run");
  visitar("check:all");
  visitar("check:all:run");
  assert.equal(plan.version, 1);
  assert.deepEqual(plan.equivalence.composerScripts, reachable);
  assert.deepEqual(plan.equivalence.npmScripts, Object.fromEntries(
    ["test:quality-tools", "check:licenses", "check:vulnerabilities"].map((nome) => [nome, packageScripts[nome]]),
  ));
  assert.deepEqual(plan.equivalence.ciLocalAlwaysExecutes, [
    "@check:online",
  ]);
  const publicCommand = ["bash", "scripts/quality/with_local_config.sh", "composer", "check:all"];
  assert.deepEqual(plan.reuse, [{ command: publicCommand, proof: "check:all" }]);
  assert.deepEqual(plan.executions, ["check:all:run", "check:ci-local:run"].flatMap((agregado) => [
    { command: ["composer", agregado], proves: ["check:all"] },
    { command: ["bash", "scripts/quality/with_local_config.sh", "--validation-execution", "composer", agregado], proves: ["check:all"] },
  ]));
  const { executionProofs } = await import("./validation-evidence.mjs");
  const manifest = join(root, "scripts/quality/validation-plan.json");
  assert.deepEqual(executionProofs(publicCommand, manifest), []);
  for (const { command } of plan.executions) {
    assert.deepEqual(executionProofs(command, manifest), ["check:all"]);
    assert.deepEqual(executionProofs([...command, "--verbose"], manifest), []);
  }
  for (const tool of [
    "php", "composer", "bash", "git", "node", "npm", "redis-server", "redis-cli",
    "gitleaks", "semgrep", "shellcheck", "/usr/bin/sandbox-exec",
    ...["initdb", "pg_ctl", "createdb", "psql", "postgres", "pg_dump", "pg_restore"].map((nome) => `/opt/homebrew/opt/postgresql@17/bin/${nome}`),
    ...["phpunit", "phpstan", "php-cs-fixer", "ec", "composer-require-checker", "composer-unused"].map((nome) => `vendor/bin/${nome}`),
    "tools/phpcpd/vendor/bin/phpcpd",
  ]) assert.ok(plan.tools.includes(tool), `Ferramenta fora da identidade: ${tool}`);
  assert.deepEqual(plan.artifacts, [
    ".artifacts", "vendor/.phpstan-cache", ".phpunit.cache", ".phpunit.result.cache", ".php-cs-fixer.cache", "logs",
  ]);
  for (const input of [".env", "inclusos/config.php", "vendor/autoload.php", "node_modules", "scripts", "tests", "tools/quality"]) {
    assert.ok(!plan.artifacts.some((prefix) => input === prefix || input.startsWith(`${prefix}/`)), `Entrada sensível tratada como artefato: ${input}`);
  }
});

test("falha do Composer público invalida recibo verde antes do próximo hook", () => {
  const dir = mkdtempSync(join(tmpdir(), "bot-composer-publico-"));
  try {
    const repo = join(dir, "repo");
    mkdirSync(join(repo, "scripts/quality"), { recursive: true });
    const blocked = new Set([
      "BASH_ENV", "ENV", "NODE_OPTIONS", "NODE_PATH", "PHPRC", "PHP_INI_SCAN_DIR",
      "COMPOSER_HOME", "NPM_CONFIG_USERCONFIG", "NPM_CONFIG_GLOBALCONFIG", "XDG_CONFIG_HOME",
      "GITLEAKS_CONFIG", "SEMGREP_SETTINGS_FILE", "PHP85_BIN", "COMPOSER85_BIN",
      "CHATBOTS_PG_BIN", "PHP_BINARY_TESTES", "CHATBOTS_REDIS_TEST_SOCKET", "CHATBOTS_PG_TEST_DSN",
    ]);
    const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
      !key.startsWith("GIT_") && !key.startsWith("NFSE_CERT_") && !blocked.has(key),
    ));
    env.GATE_STATE = join(dir, "gate-state");
    env.GATE_COUNTER = join(dir, "gate-counter");
    writeFileSync(env.GATE_STATE, "0");
    const run = (argv, input) => spawnSync(argv[0], argv.slice(1), { cwd: repo, env, input, encoding: "utf8" });
    const git = (...args) => execFileSync("git", ["-C", repo, ...args], { env, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }).trim();
    git("init", "--quiet");
    git("config", "user.name", "Fixture");
    git("config", "user.email", "fixture@example.invalid");
    git("config", "core.hooksPath", "/dev/null");
    git("config", "commit.gpgsign", "false");
    for (const file of ["validation-runner.mjs", "validation-evidence.mjs", "validation-identity.mjs", "validation-active.mjs", "validation-environment.mjs", "validation-delegation.mjs", "pre-push.mjs", "with_local_config.sh"]) {
      copyFileSync(join(root, "scripts/quality", file), join(repo, "scripts/quality", file));
    }
    writeFileSync(join(repo, "scripts/quality/with_postgres.sh"), '#!/usr/bin/env bash\nset -euo pipefail\nexec "$@"\n');
    writeFileSync(join(repo, "gate.php"), `<?php
file_put_contents(getenv('GATE_COUNTER'), "gate\\n", FILE_APPEND);
exit((int) file_get_contents(getenv('GATE_STATE')));
`);
    const fixtureScripts = {
      "check:all": scripts["check:all"],
      "check:ci-local": scripts["check:ci-local"],
      "check:all:run": [callbackTimeout, "@php gate.php"],
      "check:ci-local:run": [callbackTimeout, "@putenv CI=1", "@check:all:run"],
    };
    writeFileSync(join(repo, "composer.json"), JSON.stringify({ scripts: fixtureScripts }));
    const manifest = JSON.parse(readFileSync(join(root, "scripts/quality/validation-plan.json"), "utf8"));
    manifest.revision = "fixture-public-v1";
    manifest.equivalence = { composerScripts: fixtureScripts };
    manifest.tools = ["node", "git"];
    manifest.artifacts = ["output"];
    writeFileSync(join(repo, "scripts/quality/validation-plan.json"), JSON.stringify(manifest));
    git("add", ".");
    git("commit", "--quiet", "-m", "test: prepara fixture dos agregados públicos");
    const head = git("rev-parse", "HEAD");
    const command = manifest.reuse[0].command;
    const hook = () => run([process.execPath, "scripts/quality/pre-push.mjs", ...command], `HEAD ${head} refs/heads/main ${"0".repeat(40)}\n`);
    const counter = () => readFileSync(env.GATE_COUNTER, "utf8").trim().split("\n").length;
    const aprovado = run(command);
    assert.equal(aprovado.status, 0, aprovado.stdout + aprovado.stderr);
    assert.equal(counter(), 1, "wrapper deve executar o agregado privado uma única vez");
    const hit = hook();
    assert.equal(hit.status, 0, hit.stdout + hit.stderr);
    assert.match(hit.stdout, /reutilizando check:all completo/);
    assert.equal(counter(), 1, "recibo verde deve evitar repetir o gate na fixture");

    // Só a fixture externa muda: o checkout, o ambiente e os artefatos da
    // aprovação continuam iguais. A invalidação precisa vir do issuer.
    writeFileSync(env.GATE_STATE, "1");
    const falha = run(["composer", "--no-interaction", "check:all"]);
    assert.notEqual(falha.status, 0, falha.stdout + falha.stderr);
    assert.equal(counter(), 2);
    writeFileSync(env.GATE_STATE, "0");
    const miss = hook();
    assert.equal(miss.status, 0, miss.stdout + miss.stderr);
    assert.doesNotMatch(miss.stdout, /reutilizando check:all completo/);
    assert.equal(counter(), 3, "hook deve repetir o gate após falha do Composer público");
  } finally { rmSync(dir, { recursive: true, force: true }); }
});
