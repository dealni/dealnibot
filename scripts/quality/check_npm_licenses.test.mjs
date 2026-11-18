import assert from "node:assert/strict";
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import test from "node:test";

const SCRIPT = resolve(dirname(fileURLToPath(import.meta.url)), "check_npm_licenses.mjs");

function executarCom(licenca) {
  const diretorio = mkdtempSync(resolve(tmpdir(), "chatbot-npm-licenses-"));
  try {
    writeFileSync(
      resolve(diretorio, "package-lock.json"),
      JSON.stringify({ packages: { "": {}, "node_modules/exemplo": { license: licenca } } }),
    );
    return spawnSync(process.execPath, [SCRIPT], { cwd: diretorio, encoding: "utf8" });
  } finally {
    rmSync(diretorio, { recursive: true, force: true });
  }
}

test("aprova licença permissiva do lockfile", () => {
  const resultado = executarCom("MIT");
  assert.equal(resultado.status, 0, resultado.stderr);
  assert.match(resultado.stdout, /1 dependências auditadas/u);
});

test("reprova licença fora da política", () => {
  const resultado = executarCom("GPL-3.0-only");
  assert.equal(resultado.status, 1);
  assert.match(resultado.stderr, /GPL-3\.0-only/u);
});


const QUALITY = dirname(SCRIPT);
const composerTrees = ["composer.lock", "tools/phpcpd/composer.lock"];
const trees = [...composerTrees, "package-lock.json"];

function fixtureLicencas(run) {
  const dir = mkdtempSync(resolve(tmpdir(), "chatbot-offline-licenses-"));
  try {
    mkdirSync(join(dir, "scripts/quality"), { recursive: true });
    mkdirSync(join(dir, "tools/phpcpd"), { recursive: true });
    mkdirSync(join(dir, "bin"));
    for (const file of ["check_licenses.php", "quality_gates.php", "check_npm_licenses.mjs"]) {
      copyFileSync(join(QUALITY, file), join(dir, "scripts/quality", file));
    }
    for (const file of composerTrees) {
      writeFileSync(join(dir, file), JSON.stringify({ packages: [], "packages-dev": [
        { name: "vendor/exemplo", version: "1.0.0", license: ["MIT"] },
      ] }));
    }
    writeFileSync(join(dir, "package-lock.json"), JSON.stringify({ packages: {
      "": {}, "node_modules/exemplo": { license: "MIT" },
    } }));
    const composer = join(dir, "bin/composer");
    writeFileSync(composer, `#!/usr/bin/env php
<?php
if (getenv('COMPOSER_DISABLE_NETWORK') !== '1'
    || !in_array('--no-plugins', $argv, true) || !in_array('--no-scripts', $argv, true)
    || !in_array('--locked', $argv, true)) { exit(88); }
$dir = substr($argv[3], strlen('--working-dir='));
$file = ($dir === '.' ? '' : $dir . '/') . 'composer.lock';
file_put_contents('calls', $file . PHP_EOL, FILE_APPEND);
if (getenv('LICENSE_TOOL_TREE') === $file) { fwrite(STDERR, 'falha sintética da ferramenta'); exit(17); }
if (getenv('LICENSE_JSON_TREE') === $file) { echo 'inválido'; exit(0); }
$lock = json_decode(file_get_contents($file), true);
$dependencies = [];
foreach (array_merge($lock['packages'], $lock['packages-dev']) as $package) {
    $dependencies[$package['name']] = $package;
}
if (getenv('LICENSE_EMPTY_TREE') === $file) { $dependencies = []; }
echo json_encode(['dependencies' => $dependencies]);
`, { mode: 0o755 });
    const executar = (extra = {}) => spawnSync("php", ["scripts/quality/check_licenses.php"], {
      cwd: dir, encoding: "utf8", env: {
        ...process.env, COMPOSER_BINARY: composer, PATH: `${join(dir, "bin")}:${process.env.PATH}`, ...extra,
      },
    });
    return run({ dir, executar });
  } finally { rmSync(dir, { recursive: true, force: true }); }
}

test("gate obrigatório audita as três árvores offline sem instalar ferramentas", () => fixtureLicencas(({ dir, executar }) => {
  const result = executar();
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.deepEqual(readFileSync(join(dir, "calls"), "utf8").trim().split("\n"), composerTrees);
  assert.match(result.stdout, /Licenças npm válidas/);
}));

for (const tree of trees) {
  for (const failure of ["licença proibida", "licença ausente", "licença inválida", "árvore ausente", "árvore vazia", "JSON inválido", "estrutura inválida", "pacote inválido", "ferramenta falha"]) {
    test(`${tree}: ${failure} reprova o gate obrigatório`, () => fixtureLicencas(({ dir, executar }) => {
      const file = join(dir, tree);
      const lock = JSON.parse(readFileSync(file, "utf8"));
      const npm = tree === "package-lock.json";
      const dependency = npm ? lock.packages["node_modules/exemplo"] : lock["packages-dev"][0];
      const extra = {};
      if (failure === "licença proibida") dependency.license = npm ? "GPL-3.0-only" : ["GPL-3.0-only"];
      if (failure === "licença ausente") delete dependency.license;
      if (failure === "licença inválida") dependency.license = npm ? {} : ["MIT", null];
      writeFileSync(file, JSON.stringify(lock));
      if (failure === "árvore ausente") rmSync(file);
      if (failure === "árvore vazia") writeFileSync(file, JSON.stringify(npm ? { packages: { "": {} } } : { packages: [], "packages-dev": [] }));
      if (failure === "JSON inválido") writeFileSync(file, "{");
      if (failure === "estrutura inválida") writeFileSync(file, JSON.stringify({ packages: [] }));
      if (failure === "pacote inválido") {
        if (npm) lock.packages["node_modules/exemplo"] = [];
        else lock["packages-dev"][0] = [];
        writeFileSync(file, JSON.stringify(lock));
      }
      if (failure === "ferramenta falha") {
        if (npm) writeFileSync(join(dir, "bin/node"), "#!/bin/sh\necho 'falha sintética da ferramenta' >&2\nexit 17\n", { mode: 0o755 });
        else extra.LICENSE_TOOL_TREE = tree;
      }
      const result = executar(extra);
      assert.notEqual(result.status, 0, result.stdout + result.stderr);
      if (failure === "licença proibida") assert.match(result.stderr, /GPL-3\.0-only/);
      if (failure === "ferramenta falha") assert.match(result.stderr, /falha sintética da ferramenta/);
      if (!npm) assert.doesNotMatch(result.stdout, /Licenças npm válidas/);
    }));
  }
}

for (const tree of composerTrees) {
  for (const variable of ["LICENSE_JSON_TREE", "LICENSE_EMPTY_TREE"]) {
    test(`${tree}: ferramenta com relatório inválido ou incompleto reprova`, () => fixtureLicencas(({ executar }) => {
      const result = executar({ [variable]: tree });
      assert.notEqual(result.status, 0, result.stdout + result.stderr);
    }));
  }
}
