import assert from "node:assert/strict";
import { test } from "node:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, chmodSync, rmSync, mkdirSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";

const root = resolve(import.meta.dirname, "../..");
function fixture(version, run, phpVersion = "8.5.12") {
  const dir = mkdtempSync(join(tmpdir(), "bot-composer-pin-"));
  try {
    const log = join(dir, "calls");
    const composer = join(dir, "composer");
    const php = join(dir, "php");
    writeFileSync(composer, `#!/bin/bash\nif [[ "$*" == *--version* ]]; then\n printf 'Composer version %s 2026-08-27\\n' "$FAKE_VERSION"\nelse\n printf '%s\\n' "$*" >> "$FAKE_LOG"\n if [[ "$*" == check-platform-reqs ]]; then exit "\${FAKE_PLATFORM_STATUS:-0}"; fi\nfi\n`);
    writeFileSync(php, `#!/bin/bash\nif [[ "$1" == -r ]]; then\n if [[ "$2" == *PHP_VERSION_ID* ]]; then echo $FAKE_PHP_VERSION_ID; else echo $FAKE_PHP_VERSION; fi\nelse\n exec "$@"\nfi\n`);
    chmodSync(composer, 0o755);
    chmodSync(php, 0o755);
    writeFileSync(log, "");
    const home = join(dir, "home");
    mkdirSync(home);
    const env = { ...process.env, HOME: home, FAKE_VERSION: version, FAKE_LOG: log, FAKE_PHP_VERSION: phpVersion, FAKE_PHP_VERSION_ID: String(phpVersion.split(".").reduce((result, value, index) => result + Number(value) * [10000, 100, 1][index], 0)), PHP85_BIN: php, COMPOSER85_BIN: composer, PATH: `${dir}:${process.env.PATH}` };
    return run({ dir, log, env });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}
function toolchain(env, ...args) {
  return spawnSync("bash", [join(root, "scripts/quality/check_php_versions.sh"), ...args], { env, encoding: "utf8" });
}
test("toolchain aceita o pin e mantém os gates de plataforma e qualidade", () => fixture("2.10.3", ({ env, log }) => {
  const result = toolchain(env);
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(readFileSync(log, "utf8").trim().split("\n"), ["check-platform-reqs", "check:all"]);
}));
test("modo local explícito preserva a toolchain completa", () => fixture("2.10.3", ({ env, log }) => {
  const result = toolchain(env, "local");
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(readFileSync(log, "utf8").trim().split("\n"), ["check-platform-reqs", "check:all"]);
}));
test("runtime valida a plataforma sem executar o agregado", () => fixture("2.10.3", ({ env, log }) => {
  const result = toolchain(env, "runtime");
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(readFileSync(log, "utf8").trim().split("\n"), ["check-platform-reqs"]);
}));
for (const mode of ["runtime", "local"]) {
  test(`${mode} propaga falha da plataforma antes do agregado`, () => fixture("2.10.3", ({ env, log }) => {
    const result = toolchain({ ...env, FAKE_PLATFORM_STATUS: "17" }, mode);
    assert.equal(result.status, 17, result.stderr);
    assert.deepEqual(readFileSync(log, "utf8").trim().split("\n"), ["check-platform-reqs"]);
  }));
  test(`${mode} recusa Composer divergente antes da plataforma`, () => fixture("2.10.2", ({ env, log }) => {
    const result = toolchain(env, mode);
    assert.equal(result.status, 1, result.stderr);
    assert.equal(readFileSync(log, "utf8"), "");
  }));
  test(`${mode} recusa PHP fora do contrato antes da plataforma`, () => fixture("2.10.3", ({ env, log }) => {
    const result = toolchain(env, mode);
    assert.equal(result.status, 1, result.stderr);
    assert.equal(readFileSync(log, "utf8"), "");
  }, "8.6.0"));
}
for (const args of [["invalido"], ["runtime", "extra"]]) {
  test(`toolchain rejeita argumentos inválidos: ${args.join(" ")}`, () => fixture("2.10.3", ({ env, log }) => {
    const result = toolchain(env, ...args);
    assert.equal(result.status, 2, result.stderr);
    assert.match(result.stderr, /Uso:/);
    assert.equal(readFileSync(log, "utf8"), "");
  }));
}
for (const [phpVersion, accepted] of [["8.5.0", false], ["8.5.11", false], ["8.5.12", true], ["8.5.13", true], ["8.6.0", false]]) {
  test(`toolchain aplica o piso de segurança no PHP ${phpVersion}`, () => fixture("2.10.3", ({ env, log }) => {
    const result = toolchain(env);
    assert.equal(result.status, accepted ? 0 : 1, result.stderr);
    if (!accepted) {
      assert.match(result.stderr, /Esperado PHP 8\.5\.12/);
      assert.equal(readFileSync(log, "utf8"), "");
    }
  }, phpVersion));
}
for (const version of ["2.10.2", "3.0.0", ""]) {
  test(`toolchain recusa versão divergente ${version || "ausente"} antes dos gates`, () => fixture(version, ({ env, log }) => {
    const result = toolchain(env);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /Composer 2\.10\.3 exigido/);
    assert.equal(readFileSync(log, "utf8"), "");
  }));
}
test("contrato Linux fixa a versão sem remover verificação SHA384 prévia", () => {
  const code = readFileSync(join(root, "scripts/install/instalar_linux.sh"), "utf8");
  const invoke = code.indexOf("--quiet --version=2.10.3 --filename=composer.phar");
  assert.ok(invoke > 0);
  assert.ok(code.indexOf("https://composer.github.io/installer.sig") < invoke);
  assert.ok(code.indexOf("hash_file('sha384'") < invoke);
  assert.ok(code.indexOf("Assinatura do instalador do Composer não confere") < invoke);
  assert.ok(!code.includes("self-update"));
});
for (const version of ["2.10.3", "2.10.2"]) {
  test(`guard Linux ${version} não instala nem atualiza Composer preexistente`, () => fixture(version, ({ env, log }) => {
    const code = readFileSync(join(root, "scripts/install/instalar_linux.sh"), "utf8");
    const begin = code.indexOf('composer_version="$(composer');
    assert.ok(begin > 0);
    const end = code.indexOf("\nfi", begin) + 3;
    const result = spawnSync("bash", ["-euo", "pipefail", "-c", code.slice(begin, end)], { env, encoding: "utf8" });
    assert.equal(result.status, version === "2.10.3" ? 0 : 1, result.stderr);
    assert.equal(readFileSync(log, "utf8"), "");
  }));
}

function instalarComposerMac(env) {
  const code = readFileSync(join(root, "scripts/install/instalar_macos.sh"), "utf8");
  const begin = code.indexOf("# Selecionar somente o PHAR fixado");
  const end = code.indexOf("# Configurando horário", begin);
  assert.ok(begin > 0 && end > begin);
  const dependencyBegin = code.indexOf('if [ -f "composer.json" ]; then', end);
  const dependencyEnd = code.indexOf("\nfi", dependencyBegin) + 3;
  assert.ok(dependencyBegin > end && dependencyEnd > dependencyBegin);
  return spawnSync("bash", ["-euo", "pipefail", "-c", code.slice(begin, end) + code.slice(dependencyBegin, dependencyEnd)], {
    env, cwd: env.HOME, encoding: "utf8",
  });
}

for (const version of ["2.10.3", "2.10.2", "3.0.0", ""]) {
  test(`guard macOS ${version || "ausente"} exige pin antes de instalar dependências`, () => fixture(version, ({ env, log }) => {
    writeFileSync(join(env.HOME, "composer.json"), "{}\n");
    const result = instalarComposerMac(env);
    assert.equal(result.status, version === "2.10.3" ? 0 : 1, result.stderr);
    if (version !== "2.10.3") {
      assert.match(result.stderr, /Composer 2\.10\.3 exigido/);
      assert.equal(readFileSync(log, "utf8"), "");
    } else {
      assert.deepEqual(readFileSync(log, "utf8").trim().split("\n"), ["install --no-interaction", "install -d tools/phpcpd --no-interaction"]);
    }
  }));
}

for (const version of ["2.10.3", "2.10.2"]) {
  test(`instalador macOS prefere PHAR isolado ${version} e preserva o Composer do PATH`, () => fixture("2.11.2", ({ env, log }) => {
    const pharDirectory = join(env.HOME, ".local/share/composer/2.10.3");
    mkdirSync(pharDirectory, { recursive: true });
    const phar = join(pharDirectory, "composer.phar");
    writeFileSync(phar, `#!/bin/bash\nif [[ "$*" == *--version* ]]; then printf 'Composer version ${version} 2026-08-27\\n'; else printf 'isolado:%s\\n' "$*" >> "$FAKE_LOG"; fi\n`);
    chmodSync(phar, 0o755);
    writeFileSync(join(env.HOME, "composer.json"), "{}\n");
    const original = readFileSync(join(env.HOME, "../composer"), "utf8");
    const result = instalarComposerMac(env);
    assert.equal(result.status, version === "2.10.3" ? 0 : 1, result.stderr);
    assert.equal(readFileSync(join(env.HOME, "../composer"), "utf8"), original);
    assert.equal(readFileSync(log, "utf8"), version === "2.10.3" ? "isolado:install --no-interaction\nisolado:install -d tools/phpcpd --no-interaction\n" : "");
  }));
}

test("instalador macOS conserva ambiente higienizado sem adicionar binários do usuário ao PATH", () => {
  const code = readFileSync(join(root, "scripts/install/instalar_macos.sh"), "utf8");
  assert.ok(code.includes("exec /usr/bin/env -i"));
  assert.ok(!code.includes("self-update"));
  assert.doesNotMatch(code, /PATH=.*(?:\.local\/bin|\.local\/share)/);
});
