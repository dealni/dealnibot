/**
 * Contrato do wrapper local de actions/setup-node.
 *
 * O actionlint valida os workflows, mas não interpreta os passos internos de
 * uma composite action. Estes testes mantêm a parte específica do setup-node
 * executável e testam o caminho que os três jobs realmente chamam.
 */
import assert from "node:assert/strict";
import {
  chmodSync,
  existsSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  writeFileSync,
} from "node:fs";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";
import { describe, it } from "node:test";

const raiz = fileURLToPath(new URL("../../", import.meta.url));
const caminhoDaAction = join(raiz, ".github/actions/setup-node/action.yml");
const caminhoDoVerificador = join(
  raiz,
  "scripts/install/verificar_node_runtime.sh",
);
const action = readFileSync(caminhoDaAction, "utf8");
const verificador = readFileSync(caminhoDoVerificador, "utf8");
const pacote = JSON.parse(readFileSync(join(raiz, "package.json"), "utf8"));
const nodeVersion = readFileSync(join(raiz, ".node-version"), "utf8").trim();

const passosDeSetup = action
  .split(/^    - name: /mu)
  .slice(1)
  .filter((passo) => passo.includes("actions/setup-node@"));

describe("actions/setup-node", () => {
  it("fixa todas as invocações no mesmo SHA e release", () => {
    const referencias = [
      ...action.matchAll(/uses:\s*actions\/setup-node@(\S+)/gu),
    ].map(([, referencia]) => referencia);

    assert.equal(referencias.length, 3);
    assert.deepEqual(new Set(referencias), new Set([
      "949feb2413d6458794dcd2491c4babbbce0c15c1",
    ]));
    assert.ok(
      action.match(/actions\/setup-node@[0-9a-f]{40}\s+# v7\.1\.0/gu),
      "o SHA deve continuar legível com o comentário da release",
    );
  });

  it("prepara Node sem cache e só restaura cache depois de fixar npm", () => {
    assert.equal(passosDeSetup.length, 3);
    for (const passo of passosDeSetup) {
      assert.match(passo, /node-version-file:\s*\.node-version/u);
    }
    const [bootstrap, semCache, comCache] = passosDeSetup;
    assert.match(bootstrap, /if: inputs\.install-dependencies == 'true'/u);
    assert.match(bootstrap, /package-manager-cache:\s*false/u);
    assert.doesNotMatch(bootstrap, /^\s+cache:\s*npm/mu);
    const pin = action.indexOf("- name: Fixar o npm declarado pelo projeto");
    const cache = action.indexOf("- name: Restaurar cache com o npm do projeto");
    assert.ok(pin > 0 && pin < cache, "npm config do cache exige o npm do projeto");
    const fixacao = action.slice(pin, cache);
    assert.doesNotMatch(fixacao, /if: inputs\.install-dependencies/u);
    assert.match(fixacao, /cd "\$\{RUNNER_TEMP\}"/u);
    assert.match(fixacao, /test "\$\(npm --version\)" = "\$npm_version"/u);
    assert.match(comCache, /if: inputs\.install-dependencies == 'true'/u);
    assert.match(comCache, /cache:\s*npm/u);
    assert.match(semCache, /if: inputs\.install-dependencies != 'true'/u);
    assert.doesNotMatch(semCache, /^\s+cache:\s*npm/mu);
    // setup-node v6+ ativaria o cache só por detectar packageManager.
    assert.match(semCache, /package-manager-cache:\s*false/u);
    assert.doesNotMatch(action, /registry-url:/u);
  });

  it("fixa o npm a partir de package.json#packageManager", () => {
    assert.match(pacote.packageManager, /^npm@\d+\.\d+\.\d+$/u);
    assert.match(
      action,
      /require\('\.\/package\.json'\)\.packageManager\.split\('@'\)\.at\(-1\)/u,
    );
    assert.match(action, /npm install --global "npm@\$\{npm_version\}"/u);
    assert.match(action, /set -euo pipefail/u);
  });

  it("confia no checkout do job antes de instalar", () => {
    // Em job com `container:`, o `npm ci` roda o lifecycle do Lefthook, que
    // consulta o Git: sem o safe.directory o checkout do host é recusado.
    assert.match(
      action,
      /run: git config --global --add safe\.directory "\$\{GITHUB_WORKSPACE\}"/u,
    );
    assert.ok(
      action.indexOf("- name: Confiar no checkout do job")
        < action.indexOf("- name: Instalar dependências"),
      "o passo deve vir antes do npm ci",
    );
  });

  it("instala com ou sem lifecycle scripts conforme o input", () => {
    assert.match(action, /inputs\.ignore-scripts != 'true'\n\s+shell: bash\n\s+run: npm ci\n/u);
    assert.match(action, /inputs\.ignore-scripts == 'true'\n\s+shell: bash\n\s+run: npm ci --ignore-scripts\n/u);
  });
});

describe("compatibilidade Node/npm do wrapper", () => {
  it("mantém o pin exato dentro das faixas de engines", () => {
    assert.match(nodeVersion, /^\d+\.\d+\.\d+$/u);

    const [, nodeMajor, nodeMinor] = /^(\d+)\.(\d+)\./u.exec(nodeVersion) ?? [];
    const [, minNodeMajor, minNodeMinor] = />=(\d+)\.(\d+)/u.exec(
      pacote.engines.node,
    ) ?? [];
    const [, maxNodeMajor] = /<(\d+)/u.exec(pacote.engines.node) ?? [];
    assert.equal(nodeMajor, minNodeMajor);
    assert.ok(Number(nodeMinor) >= Number(minNodeMinor));
    assert.ok(Number(nodeMajor) < Number(maxNodeMajor));

    const [, npmMajor] = /^npm@(\d+)\./u.exec(pacote.packageManager) ?? [];
    const [, minNpmMajor] = />=(\d+)/u.exec(pacote.engines.npm) ?? [];
    const [, maxNpmMajor] = /<(\d+)/u.exec(pacote.engines.npm) ?? [];
    assert.ok(Number(npmMajor) >= Number(minNpmMajor));
    assert.ok(Number(npmMajor) < Number(maxNpmMajor));
    if (pacote.devEngines?.packageManager !== undefined) {
      assert.equal(pacote.devEngines.packageManager.name, "npm");
      assert.equal(pacote.devEngines.packageManager.version, pacote.engines.npm);
      assert.equal(pacote.devEngines.packageManager.onFail, "error");
    }
    assert.ok(existsSync(join(raiz, "package-lock.json")));
  });

  it("os instaladores validam Node e npm antes do npm ci", () => {
    assert.match(verificador, /package\.json#engines\.node/u);
    assert.match(verificador, /package\.json#engines\.npm/u);
    // A lista sai do disco em vez de ficar cravada: instalador novo que rode
    // `npm ci` entra no portão sozinho, e instalador que não mexe em Node não
    // é cobrado por uma linha que não lhe diz respeito.
    const diretorioDeInstaladores = join(raiz, "scripts/install");
    const linhaDoNpmCi = /^[ \t]*npm ci\b/mu;
    const instaladoresComNpmCi = readdirSync(diretorioDeInstaladores)
      .filter((nome) => nome.endsWith(".sh") && nome !== "verificar_node_runtime.sh")
      .map((nome) => [
        nome,
        readFileSync(join(diretorioDeInstaladores, nome), "utf8"),
      ])
      .filter(([, texto]) => linhaDoNpmCi.test(texto));

    assert.ok(
      instaladoresComNpmCi.length > 0,
      "nenhum instalador executa npm ci: o portão ficaria vazio",
    );
    for (const [instalador, texto] of instaladoresComNpmCi) {
      const validacao = "source scripts/install/verificar_node_runtime.sh";
      const posicaoDoPin = texto.indexOf(
        instalador === "setup_dev.sh" ? validacao : `${validacao} --fix-npm`,
      );
      if (instalador === "setup_dev.sh") {
        assert.ok(!texto.includes(`${validacao} --fix-npm`), "setup_dev.sh não pode instalar npm global");
      }
      assert.notEqual(
        posicaoDoPin,
        -1,
        `${instalador} deve fixar o npm pinado antes do npm ci`,
      );
      assert.ok(
        posicaoDoPin < texto.search(linhaDoNpmCi),
        `${instalador} fixa o npm depois do npm ci`,
      );
    }

    assert.match(verificador, /npm_bootstrap_dir="\$\(mktemp -d\)"/u);
    assert.match(
      verificador,
      /cd "\$npm_bootstrap_dir"[\s\S]*"\$\{npm_install\[@\]\}" "npm@\$\{npm_pin\}"/u,
    );
    assert.match(
      verificador,
      /cd "\$npm_bootstrap_dir"\n\s+npm prefix --global/u,
    );
    assert.match(verificador, /export PATH="\$npm_prefix\/bin:\$PATH"/u);

    // O contrato exige o npm exato do manifesto, portanto o npm instalado no
    // host do desenvolvedor pode legitimamente ser diferente do pin do CI.
    // Controle apenas o binário consultado pelo verificador para este teste;
    // não transforme a versão acidental da máquina em pré-requisito da suíte.
    const binarioTemporario = mkdtempSync(join(tmpdir(), "chatbot-node-runtime-"));
    const npmFalso = join(binarioTemporario, "npm");
    writeFileSync(
      npmFalso,
      "#!/usr/bin/env bash\n"
        + 'if [[ "${1:-}" == "--version" ]]; then\n'
        + `  printf "${pacote.packageManager.split("@").at(-1)}\\n"\n`
        + "  exit 0\n"
        + "fi\n"
        + "exit 0\n",
    );
    chmodSync(npmFalso, 0o755);
    try {
      const resultado = spawnSync("bash", [caminhoDoVerificador], {
        cwd: raiz,
        encoding: "utf8",
        env: {
          ...process.env,
          PATH: `${binarioTemporario}:${process.env.PATH ?? ""}`,
        },
      });
      assert.equal(resultado.status, 0, resultado.stderr);
    } finally {
      rmSync(binarioTemporario, { recursive: true, force: true });
    }
  });

  it("é a única superfície setup-node dos workflows que executam npm", () => {
    const workflows = readdirSync(join(raiz, ".github/workflows"))
      .filter((nome) => /\.ya?ml$/u.test(nome))
      .map((nome) => readFileSync(join(raiz, ".github/workflows", nome), "utf8"));

    for (const workflow of workflows) {
      assert.doesNotMatch(workflow, /actions\/setup-node@/u);
    }
    const jobs = {
      "ci.yml": ["commit-messages", "workflows"],
      "security.yml": ["dependencies"],
    };
    for (const [workflowName, jobNames] of Object.entries(jobs)) {
      const workflow = readFileSync(
        join(raiz, ".github/workflows", workflowName),
        "utf8",
      );
      for (const jobName of jobNames) {
        const inicio = workflow.indexOf(`\n  ${jobName}:\n`);
        assert.notEqual(inicio, -1, `${workflowName}/${jobName} ausente`);
        const secao = workflow.slice(inicio + 1);
        const fim = secao.search(/\n  [A-Za-z0-9_-]+:\n/u);
        const bloco = fim === -1 ? secao : secao.slice(0, fim);
        assert.match(
          bloco,
          /uses:\s*\.\/\.github\/actions\/setup-node/u,
          `${workflowName}/${jobName} deve preparar Node`,
        );
        assert.match(
          bloco,
          /ignore-scripts:\s*"true"/u,
          `${workflowName}/${jobName} deve instalar sem lifecycle scripts`,
        );
      }
    }
  });
});

for (const [version, accepted] of [["12.2.0", false], ["12.2.1", true], ["12.2.2", true], ["12.3.0", true], ["13.0.0", false]]) {
  it(`validador respeita o piso de npm para ${version} sem instalar pacotes`, () => {
    const dir = mkdtempSync(join(tmpdir(), "chatbot-npm-floor-"));
    try {
      writeFileSync(join(dir, "package.json"), JSON.stringify({ ...pacote, packageManager: `npm@${version}` }));
      const npm = join(dir, "npm");
      writeFileSync(npm, `#!/usr/bin/env bash\n[[ "$*" == --version ]] || exit 97\nprintf '%s\\n' '${version}'\n`);
      chmodSync(npm, 0o755);
      const result = spawnSync("bash", [caminhoDoVerificador], {
        cwd: dir, encoding: "utf8", env: { ...process.env, PATH: `${dir}:${process.env.PATH}` },
      });
      assert.equal(result.status, accepted ? 0 : 1, result.stderr);
      if (!accepted) assert.match(result.stderr, /fora de >=12\.2\.1 <13/);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
}

for (const argument of ["--fix-npm", "inesperado"]) {
  it(`setup local recusa ${argument} antes de consultar ou instalar ferramentas`, () => {
    const dir = mkdtempSync(join(tmpdir(), "chatbot-setup-args-"));
    try {
      const log = join(dir, "calls");
      writeFileSync(log, "");
      for (const tool of ["php", "composer", "node", "npm"]) {
        const path = join(dir, tool);
        writeFileSync(path, '#!/usr/bin/env bash\nprintf called >> "$TOOL_CALLS"\nexit 97\n');
        chmodSync(path, 0o755);
      }
      const result = spawnSync("bash", [join(raiz, "scripts/install/setup_dev.sh"), argument], {
        cwd: raiz, encoding: "utf8", env: { ...process.env, TOOL_CALLS: log, PATH: `${dir}:${process.env.PATH}` },
      });
      assert.equal(result.status, 2, result.stderr);
      assert.match(result.stderr, /sem argumentos/);
      assert.equal(readFileSync(log, "utf8"), "");
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
}
