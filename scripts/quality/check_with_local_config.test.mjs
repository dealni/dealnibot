import assert from "node:assert/strict";
import { describe, it } from "node:test";
import { spawnSync } from "node:child_process";
import {
  chmodSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";

// O `pre-push` inteiro passa por este wrapper (ver lefthook.yml). Sem um portão
// aqui, trocar uma linha dele mudava em silêncio o ambiente de TODA verificação
// local: bastava perder o `CI=1` para os gates regravarem baseline, ou perder o
// `env -u` interno para as suítes reaproveitarem um banco já sujo.
// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
const bot = "dealnibot";
const prefixo = "DEALNI";
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

const raiz = resolve(import.meta.dirname, "../..");
const WRAPPER = "scripts/quality/with_local_config.sh";
const LINHA_DA_RAIZ =
  'project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"';
const fonte = readFileSync(join(raiz, WRAPPER), "utf8");

function temporario(executar) {
  const dir = mkdtempSync(join(tmpdir(), "bot-wrapper-"));
  try {
    return executar(dir);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

/**
 * Árvore sintética com o wrapper REAL e um `with_postgres.sh` dublê que grava o
 * ambiente recebido e executa o que veio depois dele — do mesmo jeito que o
 * verdadeiro faz depois de levantar o cluster.
 */
function arvore(dir, { wrapper = fonte } = {}) {
  mkdirSync(join(dir, "scripts/quality"), { recursive: true });
  const alvo = join(dir, WRAPPER);
  writeFileSync(alvo, wrapper, { mode: 0o755 });
  const registro = join(dir, "cluster.env");
  const final = join(dir, "gate.env");
  writeFileSync(
    join(dir, "scripts/quality/with_postgres.sh"),
    `#!/usr/bin/env bash
set -euo pipefail
{
  printf 'CI=%s\\n' "\${CI-<ausente>}"
  printf 'DSN=%s\\n' "\${CHATBOTS_PG_TEST_DSN-<ausente>}"
  printf 'PWD=%s\\n' "$PWD"
} > ${JSON.stringify(registro)}
export CHATBOTS_PG_TEST_DSN='pgsql:host=/tmp/cluster-do-wrapper'
export ${prefixo}_DB_DSN="$CHATBOTS_PG_TEST_DSN"
exec "$@"
`,
    { mode: 0o755 },
  );
  chmodSync(alvo, 0o755);
  return { alvo, registro, final };
}

const relatorio = (arquivo) =>
  Object.fromEntries(
    readFileSync(arquivo, "utf8")
      .trim()
      .split("\n")
      .map((linha) => {
        const corte = linha.indexOf("=");
        return [linha.slice(0, corte), linha.slice(corte + 1)];
      }),
  );

describe("with_local_config.sh", () => {
  it("é executado pelo guard do pre-push com stdin e um único job", () => {
    const lefthook = readFileSync(join(raiz, "lefthook.yml"), "utf8");
    const prePush = lefthook.split("pre-push:\n")[1]?.split(/^\S/mu)[0] ?? "";
    assert.equal((prePush.match(/^    - name:/gmu) ?? []).length, 1);
    assert.match(prePush, /^      use_stdin: true$/mu);
    assert.match(prePush, /^      script: qualidade\.sh$/mu);
    assert.match(prePush, /^      runner: sh$/mu);
    assert.match(
      readFileSync(join(raiz, ".lefthook/pre-push/qualidade.sh"), "utf8"),
      /^exec node scripts\/quality\/pre-push\.mjs bash scripts\/quality\/with_local_config\.sh composer check:all$/mu,
    );
  });

  it("recusa rodar a partir do checkout de produção", () =>
    temporario((dir) => {
      // O `/var/www/` real não existe na máquina de desenvolvimento; o que o
      // teste exercita é a MESMA guarda, com a raiz resolvida cravada.
      assert.ok(fonte.includes(LINHA_DA_RAIZ), "o wrapper mudou de forma");
      const { alvo } = arvore(dir, {
        wrapper: fonte.replace(
          LINHA_DA_RAIZ,
          `project_dir="/var/www/${bot}"`,
        ),
      });

      const resultado = spawnSync("bash", [alvo, "true"], { encoding: "utf8" });
      assert.equal(resultado.status, 2, resultado.stdout + resultado.stderr);
      assert.match(resultado.stderr, /diretório de produção/u);
    }));

  it("exporta CI=1, chama with_postgres.sh e esconde a DSN do cluster", () =>
    temporario((dir) => {
      const { alvo, registro, final } = arvore(dir);

      const resultado = spawnSync(
        "bash",
        [
          alvo,
          "bash",
          "-c",
          `printf 'CI=%s\\nDSN=%s\\n' "\${CI-<ausente>}" "\${CHATBOTS_PG_TEST_DSN-<ausente>}" > ${JSON.stringify(final)}`,
        ],
        {
          encoding: "utf8",
          env: {
            ...process.env,
            // Uma DSN já exportada no shell de quem dá push não pode virar o
            // banco das suítes: o wrapper tem de ignorá-la.
            CHATBOTS_PG_TEST_DSN: "pgsql:host=/tmp/cluster-de-fora",
            CI: undefined,
          },
        },
      );
      assert.equal(resultado.status, 0, resultado.stdout + resultado.stderr);

      const cluster = relatorio(registro);
      assert.equal(cluster.CI, "1", "o wrapper precisa exportar CI=1");
      assert.equal(
        cluster.DSN,
        "<ausente>",
        "a DSN herdada do shell não pode chegar ao with_postgres.sh",
      );
      assert.equal(cluster.PWD, dir, "o wrapper roda na raiz do projeto");

      const gate = relatorio(final);
      assert.equal(gate.CI, "1");
      assert.equal(
        gate.DSN,
        "<ausente>",
        "cada suíte sobe o próprio banco: a DSN do cluster do wrapper não passa adiante",
      );
    }));
});


function prepararIssuer(dir) {
  const bin = join(dir, "bin");
  mkdirSync(bin);
  writeFileSync(join(dir, "scripts/quality/validation-runner.mjs"), `
import { writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
const args = process.argv.slice(2);
writeFileSync('issuer.json', JSON.stringify({
  args, ci: process.env.CI, dsn: process.env.CHATBOTS_PG_TEST_DSN,
  hmac: Object.hasOwn(process.env, 'OPENPIX_HMAC_SECRET'),
}));
const command = args.slice(args.indexOf('--') + 1);
const result = spawnSync(command[0], command.slice(1), { stdio: 'inherit' });
process.exit(result.status ?? 1);
`);
  writeFileSync(join(bin, "composer"), `#!/usr/bin/env bash
set -euo pipefail
printf '%s\\n' "$@" > command.argv
printf 'CI=%s\\nDSN=%s\\n' "\${CI-<ausente>}" "\${CHATBOTS_PG_TEST_DSN-<ausente>}" > command.env
exit "\${FAKE_COMPOSER_STATUS:-0}"
`, { mode: 0o755 });
  const env = {
    ...process.env, CI: "externo", CHATBOTS_PG_TEST_DSN: "pgsql:cluster-herdado",
    PATH: `${bin}:${process.env.PATH}`,
  };
  delete env.OPENPIX_HMAC_SECRET;
  delete env.OPENPIX_NFSE_HMAC_SECRET;
  return env;
}

for (const agregado of ["check:all", "check:ci-local"]) {
  it(`captura ${agregado} antes do ambiente sintético e executa o agregado completo`, () => temporario((dir) => {
    const { alvo, registro } = arvore(dir);
    const env = prepararIssuer(dir);
    const resultado = spawnSync("bash", [alvo, "composer", agregado], { env, encoding: "utf8" });
    assert.equal(resultado.status, 0, resultado.stdout + resultado.stderr);
    const issuer = JSON.parse(readFileSync(join(dir, "issuer.json"), "utf8"));
    assert.deepEqual(issuer.args, [
      "--proof", "check:all", "--", "bash", WRAPPER, "--validation-execution", "composer", `${agregado}:run`,
    ]);
    assert.equal(issuer.ci, "externo");
    assert.equal(issuer.dsn, "pgsql:cluster-herdado");
    assert.equal(issuer.hmac, false, "HMAC sintético não pode participar da identidade externa");
    assert.equal(relatorio(registro).DSN, "<ausente>");
    assert.equal(relatorio(registro).CI, "1");
    assert.equal(readFileSync(join(dir, "command.argv"), "utf8"), agregado + ":run\n");
    assert.equal(relatorio(join(dir, "command.env")).CI, "1");
    assert.equal(relatorio(join(dir, "command.env")).DSN, "<ausente>");
  }));

  it(`execução interna de ${agregado} passa pelo cluster sem recursão no issuer`, () => temporario((dir) => {
    const { alvo, registro } = arvore(dir);
    const env = prepararIssuer(dir);
    const resultado = spawnSync("bash", [alvo, "--validation-execution", "composer", `${agregado}:run`], { env, encoding: "utf8" });
    assert.equal(resultado.status, 0, resultado.stdout + resultado.stderr);
    assert.equal(existsSync(join(dir, "issuer.json")), false);
    assert.equal(relatorio(registro).DSN, "<ausente>");
    assert.equal(readFileSync(join(dir, "command.argv"), "utf8"), agregado + ":run\n");
  }));
}

it("reprovação do agregado propaga falha pelo issuer", () => temporario((dir) => {
  const { alvo } = arvore(dir);
  const env = { ...prepararIssuer(dir), FAKE_COMPOSER_STATUS: "19" };
  const resultado = spawnSync("bash", [alvo, "composer", "check:all"], { env, encoding: "utf8" });
  assert.equal(resultado.status, 19, resultado.stdout + resultado.stderr);
}));

for (const args of [["composer", "check"], ["composer", "check:all", "--verbose"]]) {
  it(`argumentos genéricos não emitem prova: ${args.join(" ")}`, () => temporario((dir) => {
    const { alvo, registro } = arvore(dir);
    const env = prepararIssuer(dir);
    const resultado = spawnSync("bash", [alvo, ...args], { env, encoding: "utf8" });
    assert.equal(resultado.status, 0, resultado.stdout + resultado.stderr);
    assert.equal(existsSync(join(dir, "issuer.json")), false);
    assert.equal(relatorio(registro).CI, "1");
    assert.deepEqual(readFileSync(join(dir, "command.argv"), "utf8").trim().split("\n"), args.slice(1));
  }));
}

for (const args of [[], ["true"], ["composer", "check"], ["composer", "check:all"], ["composer", "check:ci-local"], ["composer", "check:all:run", "--verbose"]]) {
  it(`flag interna recusa comando fora do contrato: ${args.join(" ") || "vazio"}`, () => temporario((dir) => {
    const { alvo, registro } = arvore(dir);
    const resultado = spawnSync("bash", [alvo, "--validation-execution", ...args], { encoding: "utf8" });
    assert.equal(resultado.status, 2, resultado.stdout + resultado.stderr);
    assert.equal(existsSync(registro), false, "comando inválido não pode iniciar o cluster");
    assert.match(resultado.stderr, /Execução interna de evidência exige/);
  }));
}
