import assert from "node:assert/strict";
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import test from "node:test";

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
const CLI = resolve(ROOT, "node_modules/@commitlint/cli/cli.js");
const PACKAGE = JSON.parse(readFileSync(resolve(ROOT, "package.json"), "utf8"));

function executar(args, input = "") {
  return spawnSync(process.execPath, [CLI, ...args], {
    cwd: ROOT,
    encoding: "utf8",
    input,
  });
}

test("o hook chama o commitlint direto, sem wrapper no package.json", () => {
  // O lefthook invoca o binário travado no lock com `--edit` sobre o arquivo
  // da mensagem; a faixa do CI é dada por `--from`/`--to` na própria linha.
  // Um script `commitlint` no package.json seria uma segunda porta de entrada
  // que ninguém usa e que envelhece sem nada reclamar.
  const lefthook = readFileSync(resolve(ROOT, "lefthook.yml"), "utf8");
  assert.match(lefthook, /run: npm exec --offline -- commitlint --edit "\{1\}"/);
  for (const wrapper of ["commitlint", "commitlint:range", "pre-commit"]) {
    assert.equal(PACKAGE.scripts[wrapper], undefined, `${wrapper} não deveria existir`);
  }
});

test("a configuração preserva o parser e os ignores oficiais", () => {
  const resultado = executar(["--print-config", "json"]);
  assert.equal(resultado.status, 0, resultado.stderr);
  const config = JSON.parse(resultado.stdout);
  // Sem `defaultIgnores` explícito na configuração: vale o padrão do
  // commitlint, que já ignora merge/revert/SemVer e commits automáticos.
  assert.notEqual(config.defaultIgnores, false);
  assert.equal(
    config.parserPreset.name,
    "conventional-changelog-conventionalcommits",
  );
  assert.equal(
    config.prompt.questions.subject.description,
    "Write a short, imperative tense description of the change",
  );
});

test("a entrada por stdin aceita uma mensagem válida e recusa uma inválida", () => {
  assert.equal(executar([], "fix: adiciona validação\n").status, 0);
  assert.equal(executar([], "mensagem sem tipo\n").status, 1);
});

test("--edit lê o arquivo indicado pelo hook commit-msg", () => {
  const diretorio = mkdtempSync(resolve(tmpdir(), "chatbot-commitlint-"));
  const mensagem = resolve(diretorio, "COMMIT_EDITMSG");
  try {
    writeFileSync(mensagem, "fix: valida mensagem do hook\n");
    assert.equal(executar(["--edit", mensagem]).status, 0);
  } finally {
    rmSync(diretorio, { recursive: true, force: true });
  }
});

test("--from e --to leem a faixa Git sem cair no stdin", () => {
  // O checkout do CI é raso (fetch-depth 1): sem HEAD~1 o commitlint recusa
  // a faixa HEAD..HEAD e pede `--last`, que lê o mesmo HEAD do Git e ainda
  // prova que a CLI não caiu no stdin.
  const temPai = spawnSync("git", ["rev-parse", "--verify", "-q", "HEAD~1"], {
    cwd: ROOT,
    encoding: "utf8",
  }).status === 0;
  const faixa = temPai ? ["--from", "HEAD~1", "--to", "HEAD"] : ["--last"];
  const resultado = executar([...faixa, "--verbose"]);
  assert.equal(resultado.status, 0, resultado.stderr);
  assert.match(resultado.stdout, /found 0 problems/u);
});

test("a configuração local é carregada e mantém a regra em português", () => {
  const resultado = executar([], "fix: adicionando\n");
  assert.equal(resultado.status, 1);
  assert.match(resultado.stdout, /subject-imperativo-portugues/u);
});

test("a configuração local reprova escopo fora de minúsculas", () => {
  // O `config-conventional` traz `type-case` mas não `scope-case`: sem a
  // regra própria em `commitlint.config.mjs`, esta mensagem passaria com
  // exit 0 e "tipo e escopo em minúsculas" (docs/CONVENCOES.md) só valeria
  // para o tipo.
  const resultado = executar([], "fix(Bot): corrige a validação\n");
  assert.equal(resultado.status, 1);
  assert.match(resultado.stdout, /scope-case/u);
});

test("aceita `palavra: texto` no corpo sem linha em branco", () => {
  // Em português, `palavra: texto` aparece no corpo sem ser rodapé; a regra
  // footer-leading-blank fica desligada por isso.
  const resultado = executar([], "fix(bot): corrige validação\nRefs: #123\n");

  assert.equal(resultado.status, 0, resultado.stdout);
});

test("não confunde imperativo plural com primeira pessoa", () => {
  for (const sujeito of ["vamos", "corrijamos", "façamos", "validemos"]) {
    const resultado = executar([], `fix: ${sujeito} a validação\n`);
    assert.equal(resultado.status, 0, `${sujeito}: ${resultado.stdout}`);
  }

  const primeiraPessoa = executar([], "fix: adicionei a validação\n");
  assert.equal(primeiraPessoa.status, 1);
  assert.match(primeiraPessoa.stdout, /primeira pessoa/u);
});
