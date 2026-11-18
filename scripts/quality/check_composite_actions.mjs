#!/usr/bin/env node
/**
 * Composite actions: o `actionlint` confere o metadata (`name`, `description`,
 * `runs` etc.) de uma action local quando ela é usada por um workflow, mas na
 * versão 1.7.12 ele ainda não confere os `runs.steps` da composite action.
 * Este portão cobre esse recorte, inclusive o ShellCheck dos `run:` bash/sh.
 *
 * Passar `action.yml` como argumento ao actionlint também não ajuda: ele
 * interpreta o arquivo como workflow e reprova por `"jobs" section is
 * missing`. O Dependabot tem outro recorte: com `directory: "/"` varre
 * `/.github/workflows` e o `action.yml` DA RAIZ, e não `.github/actions/*`
 * (dependabot-core#6704) — um SHA que more só dentro de uma composite action
 * não recebe atualização de segurança nenhuma.
 *
 * Este portão cobre as duas lacunas. Lê linhas em vez de usar um parser de
 * YAML porque este repositório tem três devDependencies no total, nenhuma de
 * YAML: `js-yaml` seria uma dependência nova só para ler um punhado de linhas.
 */
import { execFileSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const DIRETORIO_DE_ACTIONS = ".github/actions";
const DIRETORIO_DE_WORKFLOWS = ".github/workflows";
const DEPENDABOT = ".github/dependabot.yml";

/** Sob demanda: importar este arquivo para testá-lo não pode depender do disco. */
function raizDoRepositorio() {
  return fileURLToPath(new URL("../../", import.meta.url));
}

/** Um passo é o bloco que começa no `-` e vai até o próximo `-` irmão. */

const recuo = (linha) => linha.length - linha.trimStart().length;

/**
 * Os passos de `runs.steps`. Um `- ` em outro lugar do arquivo (uma lista
 * dentro de `inputs`) fica de fora: a varredura só começa depois de `steps:`.
 */
export function analisarPassos(texto) {
  const linhas = texto.split("\n");
  const inicio = linhas.findIndex((linha) => /^\s*steps:\s*$/u.test(linha));
  if (inicio === -1) return [];

  const recuoDaChave = recuo(linhas[inicio] ?? "");
  const passos = [];

  for (let indice = inicio + 1; indice < linhas.length; indice += 1) {
    const linha = linhas[indice] ?? "";
    if (linha.trim() === "" || linha.trimStart().startsWith("#")) continue;
    // Voltou para o nível de `steps:` ou acima: a lista acabou.
    if (recuo(linha) <= recuoDaChave) break;

    if (/^\s*-\s/u.test(linha)) {
      passos.push({ linha: indice + 1, linhas: [linha] });
      continue;
    }
    const atual = passos.at(-1);
    if (atual === undefined) continue;
    passos[passos.length - 1] = {
      linha: atual.linha,
      linhas: [...atual.linhas, linha],
    };
  }

  return passos;
}

/** O corpo do `run:`, seja em bloco (`|`) ou na mesma linha. */
export function corpoDoRun(passo) {
  const indice = passo.linhas.findIndex((linha) =>
    /^\s*(-\s+)?run:/u.test(linha),
  );
  if (indice === -1) return undefined;

  const cabecalho = passo.linhas[indice] ?? "";
  const inline = /run:\s*(?!\||>)(\S.*)$/u.exec(cabecalho)?.[1];
  if (inline !== undefined) return inline;

  const recuoDoRun = recuo(cabecalho.replace(/^(\s*)-\s/u, "$1  "));
  const corpo = [];
  for (const linha of passo.linhas.slice(indice + 1)) {
    if (linha.trim() !== "" && recuo(linha) <= recuoDoRun) break;
    corpo.push(linha);
  }
  return corpo.join("\n");
}

const temChave = (passo, chave) =>
  passo.linhas.some((linha) =>
    new RegExp(`^\\s*(-\\s+)?${chave}:`, "u").test(linha),
  );

/** Valor simples de uma chave do passo; expressões não são shell conhecido. */
function valorDaChave(passo, chave) {
  const padrao = new RegExp(`^\\s*(-\\s+)?${chave}:\\s*`, "u");
  const linha = passo.linhas.find((item) => padrao.test(item));
  if (linha === undefined) return undefined;
  const valor = linha.replace(padrao, "").trim();
  if (valor.length < 2) return valor;
  const aspas = valor[0];
  return (aspas === "'" || aspas === '"') && valor.endsWith(aspas)
    ? valor.slice(1, -1)
    : valor;
}

/** O actionlint usa bash/sh; shell dinâmico recebe a mesma suposição otimista. */
function shellDoPasso(passo) {
  const valor = valorDaChave(passo, "shell");
  if (valor?.includes("${{") === true) return "bash";
  const shell = valor?.split(/\s+/u)[0];
  return shell === "bash" || shell === "sh" ? shell : undefined;
}

/**
 * Invoca o mesmo linter que o actionlint usa nos `run:` de workflows. O stdin
 * evita criar arquivos de trabalho e `-f gcc` mantém linha/coluna úteis no
 * diagnóstico. A configuração do ambiente é neutralizada para o resultado
 * local coincidir com o gate de workflows.
 */
const verificarShellcheck = (shell, script) => {
  try {
    execFileSync(
      "shellcheck",
      [
        "--norc",
        "--severity=warning",
        "-s",
        shell,
        "--color=never",
        "-f",
        "gcc",
        "-",
      ],
      {
        cwd: raizDoRepositorio(),
        encoding: "utf8",
        input: script,
        stdio: ["pipe", "pipe", "pipe"],
        env: { ...process.env, SHELLCHECK_OPTS: "" },
      },
    );
    return undefined;
  } catch (erro) {
    if (typeof erro !== "object" || erro === null) return String(erro);
    const saidas = ["stdout", "stderr"]
      .map((chave) => Reflect.get(erro, chave))
      .map((saida) =>
        Buffer.isBuffer(saida) ? saida.toString("utf8") : String(saida ?? ""),
      )
      .map((saida) => saida.trim())
      .filter((saida) => saida.length > 0);
    return saidas.length > 0
      ? saidas.join("\n")
      : "não foi possível executar `shellcheck`";
  }
};

/**
 * Sem o SHA a tag se move debaixo do repositório; sem o comentário de versão o
 * SHA é ilegível na revisão e no PR que o Dependabot escreve.
 */
function problemasDoUses(passo, onde) {
  return passo.linhas.flatMap((linha) => {
    const alvo = /^\s*(?:-\s+)?uses:\s*(\S+)/u.exec(linha)?.[1];
    if (alvo === undefined || alvo.startsWith("./")) return [];
    if (!/@[0-9a-f]{40}$/u.test(alvo)) {
      return [`${onde}: \`uses: ${alvo}\` não está fixado por SHA de 40 hex`];
    }
    return /#\s*v\d/u.test(linha)
      ? []
      : [
          `${onde}: \`uses: ${alvo}\` sem o comentário \`# vX.Y.Z\` ao lado do SHA`,
        ];
  });
}

function problemasDoShell(passo, onde) {
  const shellDeclarado = valorDaChave(passo, "shell");
  if (!temChave(passo, "shell")) {
    return [`${onde}: passo com \`run:\` e sem \`shell:\``];
  }
  return shellDeclarado === undefined || shellDeclarado.length === 0
    ? [`${onde}: passo com \`run:\` e \`shell:\` vazio`]
    : [];
}

function problemasDaExpressao(run, onde) {
  const expressao = /\$\{\{([^}]*)\}\}/u.exec(run);
  return expressao === null
    ? []
    : [
        `${onde}: \`\${{${expressao[1] ?? ""}}}\` interpolado dentro de \`run:\` — ` +
          "passe o valor por `env:` e leia a variável no script",
      ];
}

function problemasDoShellcheck(passo, onde, run, shellcheck) {
  if (shellcheck === undefined || /\$\{\{[^}]*\}\}/u.test(run)) return [];
  const shell = shellDoPasso(passo);
  if (shell === undefined) return [];
  const diagnostico = shellcheck(shell, run);
  return diagnostico === undefined
    ? []
    : [`${onde}: ShellCheck reprovou o \`run:\` (${shell}):\n${diagnostico}`];
}

/**
 * Dentro de composite action o `shell:` não tem padrão: sem ele o passo nem
 * chega a executar. E `${{ }}` interpolado vira texto dentro do script antes
 * de o shell rodar, que é o caminho de injeção de expressão.
 */
function problemasDoRun(passo, onde, shellcheck) {
  const run = corpoDoRun(passo);
  if (run === undefined) return [];
  return [
    ...problemasDoShell(passo, onde),
    ...problemasDaExpressao(run, onde),
    ...problemasDoShellcheck(passo, onde, run, shellcheck),
  ];
}

/**
 * Complementa os checks de metadata do actionlint 1.7.12: `runs.steps` de
 * composite action ainda não são percorridos pelo actionlint, então este
 * portão exige nome/SHA/shell, rejeita interpolação em `run:` e delega os
 * scripts bash/sh ao ShellCheck quando o callback é fornecido.
 */
export function validarAction(caminho, texto, shellcheck) {
  const passos = analisarPassos(texto);
  if (passos.length === 0) {
    return [
      `${caminho}: nenhum passo em \`runs.steps\` — ou o arquivo mudou de forma ` +
        "e este portão parou de enxergá-lo, que é pior do que reprovar",
    ];
  }

  return passos.flatMap((passo) => {
    const onde = `${caminho}:${passo.linha}`;
    return [
      // Sem nome, a interface do Actions rotula o passo pela action e pelo SHA,
      // ilegível justamente quando se procura o que reprovou.
      ...(temChave(passo, "name") ? [] : [`${onde}: passo sem \`name:\``]),
      ...problemasDoUses(passo, onde),
      ...problemasDoRun(passo, onde, shellcheck),
    ];
  });
}

/**
 * O modo sem instalação não pode restaurar o cache implicitamente: o
 * setup-node v6+ detecta `packageManager: npm` mesmo quando `cache` não é
 * informado. O contrato fica aqui, além do teste unitário, para que a CI
 * valide a action versionada e não apenas um fixture.
 */
export function validarSetupNodeSemCache(texto) {
  const passos = analisarPassos(texto);
  const passoSemInstalacao = passos.find((passo) =>
    passo.linhas.some((linha) =>
      /^\s*if:\s*inputs\.install-dependencies\s*!=\s*['"]true['"]\s*$/u.test(
        linha,
      ),
    ),
  );

  if (passoSemInstalacao === undefined) {
    return [
      "setup-node: o ramo `install-dependencies != 'true'` não foi encontrado",
    ];
  }

  if (
    !passoSemInstalacao.linhas.some((linha) =>
      /^\s*package-manager-cache:\s*false\s*$/u.test(linha),
    )
  ) {
    return [
      "setup-node: o ramo sem instalação precisa de `package-manager-cache: false`",
    ];
  }

  return [];
}

/** As actions declaradas em disco, pelo nome do diretório. */
export function actionsEmDisco(raiz = raizDoRepositorio()) {
  const diretorio = join(raiz, DIRETORIO_DE_ACTIONS);
  if (!existsSync(diretorio)) return [];
  return readdirSync(diretorio, { withFileTypes: true })
    .filter((entrada) => entrada.isDirectory())
    .map((entrada) => entrada.name)
    .sort();
}

/** As actions locais que os workflows realmente chamam. */
export function actionsReferenciadas(workflows) {
  const nomes = new Set();
  for (const texto of workflows.values()) {
    for (const [, nome] of texto.matchAll(
      /uses:\s*\.\/\.github\/actions\/([A-Za-z0-9._-]+)/gu,
    )) {
      if (nome !== undefined) nomes.add(nome);
    }
  }
  return [...nomes].sort();
}

/** Diretórios que o `dependabot.yml` declara em `github-actions`, sem a barra. */
export function diretoriosDoDependabot(texto) {
  const linhas = texto.split("\n");
  const diretorios = new Set();
  let dentroDoBloco = false;

  for (const linha of linhas) {
    if (/^\s*-\s*package-ecosystem:/u.test(linha)) {
      dentroDoBloco = /package-ecosystem:\s*["']?github-actions["']?\s*$/u.test(
        linha,
      );
      continue;
    }
    if (!dentroDoBloco) continue;
    for (const [, valor] of linha.matchAll(/["']([^"']+)["']/gu)) {
      if (valor?.startsWith("/") === true) {
        diretorios.add(valor.replace(/^\/+/u, "").replace(/\/+$/u, ""));
      }
    }
  }

  return [...diretorios].sort();
}

/**
 * Toda action em disco é chamada por algum workflow, toda action chamada
 * existe, e todo diretório de action está no `dependabot.yml` — senão o SHA
 * lá dentro congela em silêncio.
 */
export function validarCobertura({ emDisco, referenciadas, dependabot }) {
  const problemas = [];

  for (const nome of referenciadas.filter((nome) => !emDisco.includes(nome))) {
    problemas.push(
      `${DIRETORIO_DE_WORKFLOWS}: chama \`./${DIRETORIO_DE_ACTIONS}/${nome}\`, que não existe`,
    );
  }

  for (const nome of emDisco) {
    if (!referenciadas.includes(nome)) {
      problemas.push(
        `${DIRETORIO_DE_ACTIONS}/${nome}: nenhum workflow chama esta action`,
      );
    }
    if (!dependabot.includes(`${DIRETORIO_DE_ACTIONS}/${nome}`)) {
      problemas.push(
        `${DEPENDABOT}: sem entrada \`github-actions\` para ` +
          `\`/${DIRETORIO_DE_ACTIONS}/${nome}\` — com \`directory: "/"\` o ` +
          "Dependabot varre `/.github/workflows` e o `action.yml` da raiz, e " +
          "os SHAs desta action ficariam congelados",
      );
    }
  }

  return problemas;
}

const lerWorkflows = (raiz) => {
  const caminho = join(raiz, DIRETORIO_DE_WORKFLOWS);
  if (!existsSync(caminho)) return new Map();
  return new Map(
    readdirSync(caminho)
      .filter((nome) => /\.ya?ml$/u.test(nome))
      .sort()
      .map((nome) => [nome, readFileSync(join(caminho, nome), "utf8")]),
  );
};

/** O `action.yml` (ou `.yaml`) de uma action, quando existe. */
const arquivoDaAction = (nome, raiz) =>
  [
    `${DIRETORIO_DE_ACTIONS}/${nome}/action.yml`,
    `${DIRETORIO_DE_ACTIONS}/${nome}/action.yaml`,
  ].find((candidato) => existsSync(join(raiz, candidato)));

export function executarPortao(raiz = raizDoRepositorio()) {
  const emDisco = actionsEmDisco(raiz);
  const conteudos = emDisco.flatMap((nome) => {
    const caminho = arquivoDaAction(nome, raiz);
    return caminho === undefined
      ? []
      : [{ caminho, texto: readFileSync(join(raiz, caminho), "utf8") }];
  });
  const temRunShell = conteudos.some(({ texto }) =>
    analisarPassos(texto).some(
      (passo) =>
        corpoDoRun(passo) !== undefined && shellDoPasso(passo) !== undefined,
    ),
  );
  const problemas = [
    ...validarCobertura({
      emDisco,
      referenciadas: actionsReferenciadas(lerWorkflows(raiz)),
      dependabot: existsSync(join(raiz, DEPENDABOT))
        ? diretoriosDoDependabot(readFileSync(join(raiz, DEPENDABOT), "utf8"))
        : [],
    }),
    ...emDisco.flatMap((nome) => {
      const caminho = arquivoDaAction(nome, raiz);
      return caminho === undefined
        ? [
            `${DIRETORIO_DE_ACTIONS}/${nome}: sem \`action.yml\` — o diretório existe e a action, não`,
          ]
        : [];
    }),
  ];

  let shellcheck;
  if (temRunShell) {
    try {
      execFileSync("shellcheck", ["--version"], { stdio: "ignore" });
      shellcheck = verificarShellcheck;
    } catch {
      problemas.push(
        "shellcheck ausente ou quebrado — ShellCheck 0.11.0 é exigido porque " +
          "actionlint 1.7.12 não confere " +
          "`run:` dentro de composite actions; instale ShellCheck antes do portão",
      );
    }
  }

  for (const { caminho, texto } of conteudos) {
    problemas.push(...validarAction(caminho, texto, shellcheck));
    if (
      caminho === `${DIRETORIO_DE_ACTIONS}/setup-node/action.yml` ||
      caminho === `${DIRETORIO_DE_ACTIONS}/setup-node/action.yaml`
    ) {
      problemas.push(...validarSetupNodeSemCache(texto));
    }
  }

  if (problemas.length > 0) {
    throw new Error(
      `composite actions fora do contrato:\n${problemas
        .map((problema) => `  - ${problema}`)
        .join("\n")}`,
    );
  }

  return (
    "Composite actions sem achado (metadata do actionlint, SHA fixado, " +
    "`shell:` presente, ShellCheck em `run:` bash/sh, sem " +
    `interpolação em \`run:\`, cobertas pelo Dependabot): ${emDisco.length}.`
  );
}

export function main() {
  try {
    console.log(executarPortao());
  } catch (erro) {
    console.error(erro instanceof Error ? erro.message : String(erro));
    process.exitCode = 1;
  }
}

if (import.meta.main) main();
