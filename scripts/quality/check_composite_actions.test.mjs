/**
 * Testes do portão de composite actions.
 *
 * Nenhum teste aqui lê `.github/actions` do disco: o conteúdo é injetado, para
 * que o portão continue testável num repositório que ainda não tenha action
 * nenhuma e para que um teste não passe a verde só porque a árvore de hoje
 * está correta. O que se prova aqui é que cada invariante REPROVA quando
 * violado — portão que só foi visto passando não foi visto.
 */
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { describe, it } from "node:test";

import {
  actionsReferenciadas,
  analisarPassos,
  corpoDoRun,
  diretoriosDoDependabot,
  validarAction,
  validarCobertura,
  validarSetupNodeSemCache,
} from "./check_composite_actions.mjs";

const INTERPOLACAO = "${{ github.event.issue.title }}";

const action = (passos) =>
  `name: exemplo\ndescription: exemplo\n\nruns:\n  using: composite\n  steps:\n${passos}`;

const PASSO_VALIDO = [
  "    - name: Passo correto",
  "      uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1",
].join("\n");

describe("análise dos passos", () => {
  it("só enxerga a lista de runs.steps, não listas de outras chaves", () => {
    const texto = [
      "inputs:",
      "  valores:",
      "    - um",
      "    - dois",
      "runs:",
      "  using: composite",
      "  steps:",
      "    - name: primeiro",
      "      shell: bash",
      "      run: echo um",
      "    - name: segundo",
      "      shell: bash",
      "      run: echo dois",
    ].join("\n");

    assert.equal(analisarPassos(texto).length, 2);
  });

  it("lê o corpo do run em bloco e na mesma linha", () => {
    const [emBloco, inline] = analisarPassos(
      action(
        [
          "    - name: bloco",
          "      shell: bash",
          "      run: |",
          "        set -euo pipefail",
          "        echo dentro",
          "    - name: inline",
          "      shell: bash",
          "      run: npm ci",
        ].join("\n"),
      ),
    );

    assert.ok(emBloco !== undefined && inline !== undefined);
    assert.match(corpoDoRun(emBloco) ?? "", /echo dentro/u);
    assert.doesNotMatch(corpoDoRun(emBloco) ?? "", /name: inline/u);
    assert.equal(corpoDoRun(inline), "npm ci");
  });

  it("não vê run onde não há", () => {
    const [passo] = analisarPassos(action(PASSO_VALIDO));
    assert.ok(passo !== undefined);
    assert.equal(corpoDoRun(passo), undefined);
  });
});

describe("invariantes da action", () => {
  it("aprova um passo em dia", () => {
    assert.deepEqual(validarAction("a.yml", action(PASSO_VALIDO)), []);
  });

  it("reprova arquivo sem passo nenhum, em vez de passar em silêncio", () => {
    const problemas = validarAction("a.yml", "name: vazio\n");
    assert.equal(problemas.length, 1);
    assert.match(problemas[0] ?? "", /nenhum passo/u);
  });

  it("reprova uses com tag em vez de SHA", () => {
    const problemas = validarAction(
      "a.yml",
      action("    - name: solto\n      uses: actions/setup-node@v7"),
    );
    assert.match(problemas.join("\n"), /não está fixado por SHA de 40 hex/u);
  });

  it("reprova SHA sem o comentário de versão", () => {
    const problemas = validarAction(
      "a.yml",
      action(
        "    - name: ilegível\n      uses: actions/cache@1111111111111111111111111111111111111111",
      ),
    );
    assert.match(problemas.join("\n"), /sem o comentário/u);
  });

  it("não exige SHA de uma action local", () => {
    assert.deepEqual(
      validarAction(
        "a.yml",
        action("    - name: local\n      uses: ./.github/actions/setup-node"),
      ),
      [],
    );
  });

  it("reprova run sem shell, que dentro de composite nem executa", () => {
    const problemas = validarAction(
      "a.yml",
      action("    - name: sem shell\n      run: echo oi"),
    );
    assert.match(problemas.join("\n"), /`run:` e sem `shell:`/u);
  });

  it("reprova interpolação dentro do run", () => {
    const problemas = validarAction(
      "a.yml",
      action(
        [
          "    - name: injeção",
          "      shell: bash",
          "      run: |",
          `        echo "${INTERPOLACAO}"`,
        ].join("\n"),
      ),
    );
    assert.match(problemas.join("\n"), /interpolado dentro de `run:`/u);
  });

  it("aceita a mesma expressão quando ela entra por env", () => {
    assert.deepEqual(
      validarAction(
        "a.yml",
        action(
          [
            "    - name: por env",
            "      shell: bash",
            "      env:",
            `        TITULO: ${INTERPOLACAO}`,
            "      run: |",
            '        echo "$TITULO"',
          ].join("\n"),
        ),
      ),
      [],
    );
  });

  it("reprova run com shell vazio", () => {
    const problemas = validarAction(
      "a.yml",
      action("    - name: shell vazio\n      shell:\n      run: echo oi"),
    );
    assert.match(problemas.join("\n"), /`run:` e `shell:` vazio/u);
  });

  it("delega run bash ao ShellCheck quando o callback é fornecido", () => {
    const chamadas = [];
    const problemas = validarAction(
      "a.yml",
      action(
        [
          "    - name: shellcheck",
          "      shell: bash",
          "      run: echo $FOO",
        ].join("\n"),
      ),
      (shell, script) => {
        chamadas.push([shell, script]);
        return "a.yml:1:6: note: quote [SC2086]";
      },
    );

    assert.deepEqual(chamadas, [["bash", "echo $FOO"]]);
    assert.match(problemas.join("\n"), /ShellCheck reprovou/u);
    assert.match(problemas.join("\n"), /SC2086/u);
  });

  it("reprova passo sem name", () => {
    const problemas = validarAction(
      "a.yml",
      action(
        "    - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1",
      ),
    );
    assert.match(problemas.join("\n"), /sem `name:`/u);
  });
});

describe("contrato do setup-node", () => {
  it("desativa o auto-cache no ramo sem instalação", () => {
    const texto = action(
      [
        "    - name: Setup Node.js sem cache",
        "      if: inputs.install-dependencies != 'true'",
        "      uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0",
        "      with:",
        "        node-version-file: .node-version",
        "        package-manager-cache: false",
      ].join("\n"),
    );

    assert.deepEqual(validarSetupNodeSemCache(texto), []);
  });

  it("reprova o ramo sem instalação sem desativar o auto-cache", () => {
    const texto = action(
      [
        "    - name: Setup Node.js sem cache",
        "      if: inputs.install-dependencies != 'true'",
        "      uses: actions/setup-node@949feb2413d6458794dcd2491c4babbbce0c15c1 # v7.1.0",
        "      with:",
        "        node-version-file: .node-version",
      ].join("\n"),
    );

    assert.match(
      validarSetupNodeSemCache(texto).join("\n"),
      /package-manager-cache: false/u,
    );
  });
});

describe("cobertura", () => {
  const cobertura = (parcial) =>
    validarCobertura({
      emDisco: ["setup-node"],
      referenciadas: ["setup-node"],
      dependabot: [".github/actions/setup-node"],
      ...parcial,
    });

  it("aprova a frota como ela está", () => {
    assert.deepEqual(cobertura({}), []);
  });

  it("reprova action referenciada que não existe", () => {
    assert.match(
      cobertura({ emDisco: [], dependabot: [] }).join("\n"),
      /que não existe/u,
    );
  });

  it("reprova action órfã, que ninguém chama", () => {
    assert.match(
      cobertura({ referenciadas: [] }).join("\n"),
      /nenhum workflow chama/u,
    );
  });

  it("reprova action fora do dependabot, que é o SHA congelando", () => {
    const problemas = cobertura({ dependabot: [] }).join("\n");
    assert.match(problemas, /sem entrada `github-actions`/u);
    assert.match(problemas, /congelados/u);
  });

  it("não aceita a entrada da raiz como cobertura da action", () => {
    assert.match(cobertura({ dependabot: [""] }).join("\n"), /congelados/u);
  });

  it("lê os diretórios declarados no dependabot, só do ecossistema certo", () => {
    const texto = [
      "version: 2",
      "updates:",
      "  - package-ecosystem: npm",
      '    directory: "/nao-e-action"',
      "  - package-ecosystem: github-actions",
      '    directory: "/"',
      "  - package-ecosystem: github-actions",
      '    directory: "/.github/actions/setup-node"',
    ].join("\n");

    // A raiz vira `""`, e é assim que ela deve entrar: é um diretório
    // declarado de verdade. O que não pode aparecer é o `/nao-e-action`, que
    // é do ecossistema npm.
    assert.deepEqual(diretoriosDoDependabot(texto), [
      "",
      ".github/actions/setup-node",
    ]);
  });
});

describe("actions referenciadas pelos workflows", () => {
  it("acha a chamada local e ignora as de terceiro", () => {
    const workflows = new Map([
      [
        "ci.yml",
        [
          "      - uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1",
          "      - uses: ./.github/actions/setup-node",
        ].join("\n"),
      ],
      ["security.yml", "      - uses: ./.github/actions/setup-node"],
    ]);

    assert.deepEqual(actionsReferenciadas(workflows), ["setup-node"]);
  });
});

// Os comandos de validação dos workflows rodam no CI e ficam disponíveis
// separadamente para quem revisa alterações nos arquivos do GitHub Actions.
it("segue chamável à mão, fora do agregado local", () => {
  const manifesto = JSON.parse(
    readFileSync(new URL("../../composer.json", import.meta.url), "utf8"),
  );
  const pacote = JSON.parse(
    readFileSync(new URL("../../package.json", import.meta.url), "utf8"),
  );

  assert.equal(
    pacote.scripts["check:composite-actions"],
    "node scripts/quality/check_composite_actions.mjs",
  );
  assert.equal(
    manifesto.scripts["check:workflows"],
    "@php scripts/quality/check_workflows.php",
  );

  const agregado = manifesto.scripts["check:all"];
  assert.ok(Array.isArray(agregado));
  assert.ok(!agregado.includes("npm run check:composite-actions"));
  assert.ok(!agregado.includes("@check:workflows"));
});
