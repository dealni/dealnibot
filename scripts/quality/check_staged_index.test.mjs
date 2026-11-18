import assert from "node:assert/strict";
import { execFileSync, spawnSync } from "node:child_process";
import { mkdtempSync, writeFileSync, rmSync, mkdirSync, readFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { test } from "node:test";

const script = fileURLToPath(new URL("./check_staged.sh", import.meta.url));
const ambiente = Object.fromEntries(
  Object.entries(process.env).filter(([nome]) => !nome.startsWith("GIT_")),
);

test("índice transitório do Git é preservado sem modificar o índice normal", (t) => {
  const raiz = mkdtempSync(path.join(tmpdir(), "staged-index-"));
  t.after(() => rmSync(raiz, { recursive: true, force: true }));
  const repo = path.join(raiz, "repo");
  const indice = path.join(raiz, "indice");
  const env = { ...ambiente, GIT_INDEX_FILE: indice };
  execFileSync("git", ["init", "--quiet", repo], { env: ambiente });
  const git = (args, variaveis = ambiente) =>
    execFileSync("git", ["-C", repo, ...args], {
      env: variaveis,
      encoding: "utf8",
    }).trim();
  git(["config", "user.name", "Fixture"]);
  git(["config", "user.email", "fixture@example.invalid"]);
  git(["config", "core.hooksPath", "/dev/null"]);
  git(["config", "commit.gpgsign", "false"]);
  writeFileSync(path.join(repo, "normal.txt"), "normal\n");
  git(["add", "."]);
  git(["commit", "--quiet", "-m", "fixture inicial"]);
  git(["read-tree", "HEAD"], env);
  const proibidos = [".env", ".env.production", "backup.dump", "backup.dump.age"];
  for (const arquivo of [...proibidos, ".env.example"]) {
    writeFileSync(path.join(repo, arquivo), "FIXTURE=sem-segredo\n");
  }
  git(["add", ...proibidos, ".env.example"], env);
  const resultado = spawnSync("bash", [script], {
    cwd: repo,
    env,
    encoding: "utf8",
  });
  assert.equal(resultado.status, 1);
  assert.match(resultado.stderr, /arquivo proibido no índice/u);
  for (const arquivo of proibidos) {
    assert.ok(resultado.stderr.includes("    " + arquivo + "\n"));
  }
  assert.doesNotMatch(resultado.stderr, /    \.env\.example/u);
  assert.equal(git(["diff", "--cached", "--name-only"]), "");
});

test("git commit --only valida o índice transitório criado pelo próprio Git", (t) => {
  const raiz = mkdtempSync(path.join(tmpdir(), "commit-only-"));
  t.after(() => rmSync(raiz, { recursive: true, force: true }));
  execFileSync("git", ["init", "--quiet", raiz], { env: ambiente });
  const git = (...args) =>
    execFileSync("git", ["-C", raiz, ...args], {
      env: ambiente,
      encoding: "utf8",
    }).trim();
  git("config", "user.name", "Fixture");
  git("config", "user.email", "fixture@example.invalid");
  git("config", "core.hooksPath", "/dev/null");
  git("config", "commit.gpgsign", "false");
  writeFileSync(path.join(raiz, ".env"), "FIXTURE=inicial\n");
  git("add", ".");
  git("commit", "--quiet", "-m", "fixture inicial");
  const head = git("rev-parse", "HEAD");
  const hooks = path.join(raiz, ".git", "hooks-fixture");
  mkdirSync(hooks);
  const citar = (valor) => "'" + valor.replaceAll("'", "'\\''") + "'";
  writeFileSync(
    path.join(hooks, "pre-commit"),
    `#!/bin/sh\nexec bash ${citar(script)}\n`,
    { mode: 0o755 },
  );
  git("config", "core.hooksPath", hooks);
  writeFileSync(path.join(raiz, ".env"), "FIXTURE=alterado\n");
  const resultado = spawnSync(
    "git",
    ["commit", "--only", ".env", "-m", "fixture bloqueada"],
    { cwd: raiz, env: ambiente, encoding: "utf8" },
  );
  assert.notEqual(resultado.status, 0);
  assert.match(resultado.stderr, /arquivo proibido no índice/u);
  assert.equal(git("rev-parse", "HEAD"), head);
  assert.equal(git("diff", "--cached", "--name-only"), "");
});

for (const indiceInvalido of [false, true]) {
  test(`shellcheck resolve sources do índice mesmo com working tree ${indiceInvalido ? "corrigido" : "inválido"}`, (t) => {
    const raiz = mkdtempSync(path.join(tmpdir(), "staged-sources-"));
    t.after(() => rmSync(raiz, { recursive: true, force: true }));
    const repo = path.join(raiz, "repo");
    const bin = path.join(raiz, "bin");
    mkdirSync(bin);
    writeFileSync(path.join(bin, "gitleaks"), "#!/bin/sh\nexit 0\n", {
      mode: 0o755,
    });
    const env = {
      ...ambiente,
      PATH: `${bin}${path.delimiter}${ambiente.PATH}`,
    };
    execFileSync("git", ["init", "--quiet", repo], { env });
    const git = (...args) =>
      execFileSync("git", ["-C", repo, ...args], {
        env,
        encoding: "utf8",
      }).trim();
    git("config", "user.name", "Fixture");
    git("config", "user.email", "fixture@example.invalid");
    git("config", "core.hooksPath", "/dev/null");
    git("config", "commit.gpgsign", "false");
    mkdirSync(path.join(repo, "helpers"));
    const helper = path.join(repo, "helpers/dependencia.sh");
    const valido =
      "#!/usr/bin/env bash\nhelper_func() { printf '%s\\n' 'ok'; }\n";
    const invalido = "#!/usr/bin/env bash\nhelper_func() { echo $1; }\n";
    writeFileSync(helper, valido);
    writeFileSync(
      path.join(repo, "principal.sh"),
      "#!/usr/bin/env bash\nsource helpers/dependencia.sh\nhelper_func\n",
    );
    git("add", ".");
    git("commit", "--quiet", "-m", "fixture inicial");
    writeFileSync(
      helper,
      indiceInvalido ? invalido : `${valido}# alteração indexada\n`,
    );
    git("add", "helpers/dependencia.sh");
    writeFileSync(helper, indiceInvalido ? valido : invalido);
    const indiceAntes = git("write-tree");
    const resultado = spawnSync("bash", [script], {
      cwd: repo,
      env,
      encoding: "utf8",
    });
    assert.equal(
      resultado.status,
      indiceInvalido ? 1 : 0,
      resultado.stderr + resultado.stdout,
    );
    assert.equal(
      git("write-tree"),
      indiceAntes,
      "a validação não altera o índice",
    );
    if (indiceInvalido)
      assert.match(resultado.stderr + resultado.stdout, /SC2086/u);
  });
}

for (const tipo of ["composer.json", "tools/phpcpd/composer.json", "README.md"]) {
  for (const indiceInvalido of [false, true]) {
    test(`${tipo}: staging parcial valida o índice ${indiceInvalido ? "inválido" : "válido"} e preserva as duas árvores`, (t) => {
      const raiz = mkdtempSync(path.join(tmpdir(), "bot-staged-globals-"));
      t.after(() => rmSync(raiz, { recursive: true, force: true }));
      const repo = path.join(raiz, "repo");
      const bin = path.join(raiz, "bin");
      mkdirSync(bin);
      writeFileSync(path.join(bin, "gitleaks"), "#!/bin/sh\nexit 0\n", { mode: 0o755 });
      const env = { ...ambiente, PATH: `${bin}${path.delimiter}${ambiente.PATH}` };
      execFileSync("git", ["init", "--quiet", repo], { env });
      const git = (...args) => execFileSync("git", ["-C", repo, ...args], { env, encoding: "utf8" }).trim();
      git("config", "user.name", "Fixture");
      git("config", "user.email", "fixture@example.invalid");
      git("config", "core.hooksPath", "/dev/null");
      git("config", "commit.gpgsign", "false");
      mkdirSync(path.join(repo, "scripts/quality"), { recursive: true });
      for (const helper of ["check_doc_links.php", "quality_gates.php"]) {
        writeFileSync(path.join(repo, "scripts/quality", helper), readFileSync(new URL(helper, import.meta.url)));
      }
      const arquivo = path.join(repo, tipo);
      mkdirSync(path.dirname(arquivo), { recursive: true });
      const valido = tipo.endsWith(".md") ? "[Destino](destino.txt)\n" : '{"name":"fixture/validacao","description":"Fixture de staging parcial","license":"proprietary"}\n';
      const invalido = tipo.endsWith(".md") ? "[Destino](ausente.txt)\n" : "{ json inválido\n";
      writeFileSync(path.join(repo, "destino.txt"), "destino\n");
      writeFileSync(arquivo, valido);
      git("add", ".");
      git("commit", "--quiet", "-m", "fixture inicial");
      const indiceNormal = git("write-tree");
      env.GIT_INDEX_FILE = path.join(raiz, "indice-transitorio");
      git("read-tree", "HEAD");
      writeFileSync(arquivo, indiceInvalido ? invalido : `${valido}\n`);
      git("add", tipo);
      writeFileSync(arquivo, indiceInvalido ? valido : invalido);
      const arvoreAntes = git("write-tree");
      const conteudoAntes = readFileSync(arquivo, "utf8");
      const resultado = spawnSync("bash", [script], { cwd: repo, env, encoding: "utf8" });
      assert.equal(resultado.status, indiceInvalido ? 1 : 0, resultado.stderr + resultado.stdout);
      assert.equal(git("write-tree"), arvoreAntes, "índice transitório preservado");
      assert.equal(execFileSync("git", ["-C", repo, "write-tree"], { env: ambiente, encoding: "utf8" }).trim(), indiceNormal, "índice normal preservado");
      assert.equal(readFileSync(arquivo, "utf8"), conteudoAntes, "working tree preservado");
    });
  }
}

for (const destino of ["novo fora do índice", "removido do índice", "novo no índice", "renomeado no índice"]) {
  test(`links Markdown usam destino ${destino}`, (t) => {
    const raiz = mkdtempSync(path.join(tmpdir(), "bot-staged-links-"));
    t.after(() => rmSync(raiz, { recursive: true, force: true }));
    const repo = path.join(raiz, "repo");
    const bin = path.join(raiz, "bin");
    mkdirSync(bin);
    writeFileSync(path.join(bin, "gitleaks"), "#!/bin/sh\nexit 0\n", { mode: 0o755 });
    const env = { ...ambiente, PATH: `${bin}${path.delimiter}${ambiente.PATH}` };
    execFileSync("git", ["init", "--quiet", repo], { env });
    const git = (...args) => execFileSync("git", ["-C", repo, ...args], { env, encoding: "utf8" }).trim();
    git("config", "user.name", "Fixture");
    git("config", "user.email", "fixture@example.invalid");
    git("config", "core.hooksPath", "/dev/null");
    git("config", "commit.gpgsign", "false");
    mkdirSync(path.join(repo, "scripts/quality"), { recursive: true });
    for (const helper of ["check_doc_links.php", "quality_gates.php"]) {
      writeFileSync(path.join(repo, "scripts/quality", helper), readFileSync(new URL(helper, import.meta.url)));
    }
    writeFileSync(path.join(repo, "README.md"), "Documentação\n");
    if (["removido do índice", "renomeado no índice"].includes(destino)) {
      writeFileSync(path.join(repo, "README.md"), "[Destino](destino.txt)\n");
      writeFileSync(path.join(repo, "destino.txt"), "destino\n");
    }
    git("add", ".");
    git("commit", "--quiet", "-m", "fixture inicial");
    writeFileSync(path.join(repo, "README.md"), "[Destino](destino.txt)\n");
    writeFileSync(path.join(repo, "destino.txt"), "destino\n");
    if (destino === "removido do índice") git("rm", "--cached", "destino.txt");
    else if (destino === "renomeado no índice") git("mv", "destino.txt", "outro.txt");
    else git("add", "README.md");
    if (destino === "novo no índice") git("add", "destino.txt");
    // Uma correção não preparada do próprio validador nunca deve aprovar o commit.
    writeFileSync(path.join(repo, "scripts/quality/check_doc_links.php"), "<?php exit(0);\n");
    const arvoreAntes = git("write-tree");
    const resultado = spawnSync("bash", [script], { cwd: repo, env, encoding: "utf8" });
    assert.equal(resultado.status, destino === "novo no índice" ? 0 : 1, resultado.stderr + resultado.stdout);
    assert.equal(git("write-tree"), arvoreAntes);
    assert.equal(readFileSync(path.join(repo, destino === "renomeado no índice" ? "outro.txt" : "destino.txt"), "utf8"), "destino\n");
  });
}
