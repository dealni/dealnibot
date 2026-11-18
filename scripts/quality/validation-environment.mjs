/**
 * Só o prefixo padrão informado pelo Git, consultado sem override, é removível.
 * A resolução das ferramentas declaradas permanece igual; o diretório Git inteiro
 * entra no fingerprint nos dois ambientes. PATH arbitrário e variáveis de SDK,
 * compilador ou loader continuam relevantes. GIT_EXEC_PATH externo não é elegível.
 */
import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync, realpathSync, statSync } from "node:fs";
import path from "node:path";

function selectedPath(tool, entries) {
  if (tool.includes("/")) return path.resolve(tool);
  for (const entry of entries) {
    const file = path.resolve(entry, tool);
    if (
      existsSync(file) &&
      statSync(file).isFile() &&
      statSync(file).mode & 0o111
    )
      return file;
  }
  return undefined;
}
function selected(tool, entries) {
  const file = selectedPath(tool, entries);
  return file ? realpathSync(file) : undefined;
}
function sameGit(selectedPathname, remainingPathname, runtime, execPath) {
  const selectedGit = realpathSync(selectedPathname);
  const remainingGit = realpathSync(remainingPathname);
  // Origem conhecida, independente de wrappers encontrados no PATH do usuário.
  const systemGit = realpathSync("/usr/bin/git");
  if (execPath(systemGit) !== runtime) return false;
  const implementation =
    process.platform === "darwin"
      ? realpathSync(path.resolve(runtime, "../../bin/git"))
      : systemGit;
  const trustedPaths = [
    "/usr/bin/git",
    implementation,
    path.join(runtime, "git"),
  ];
  if (
    !trustedPaths.includes(selectedPathname) ||
    !trustedPaths.includes(remainingPathname)
  )
    return false;
  if (![systemGit, implementation].includes(remainingGit)) return false;
  const actual = statSync(selectedGit),
    expected = statSync(implementation);
  return (
    selectedGit === implementation ||
    (actual.dev === expected.dev && actual.ino === expected.ino) ||
    // Distribuições Linux podem instalar duas cópias, sem hardlink. A comparação
    // de bytes só vale entre os caminhos confiáveis conferidos acima; o diretório
    // completo do Git também continua no fingerprint da toolchain.
    createHash("sha256").update(readFileSync(selectedGit)).digest("hex") ===
      createHash("sha256").update(readFileSync(implementation)).digest("hex")
  );
}
export function executionContext(tools) {
  const raw = process.env.PATH ?? "";
  const entries = raw.split(path.delimiter);
  const env = { ...process.env };
  delete env.GIT_EXEC_PATH;
  const execPath = (binary) =>
    execFileSync(binary, ["--exec-path"], {
      encoding: "utf8",
      timeout: 10000,
      env,
      stdio: ["ignore", "pipe", "pipe"],
    }).trim();
  let gitRuntime;
  let effectivePath = raw;
  try {
    // Consultar também sem o primeiro diretório evita confiar num prefixo forjado.
    const remaining = entries.slice(1);
    const binary = selected("git", remaining);
    gitRuntime = execPath(selected("git", entries));
    if (
      entries[0] === gitRuntime &&
      binary &&
      execPath(binary) === gitRuntime &&
      sameGit(
        selectedPath("git", entries),
        selectedPath("git", remaining),
        gitRuntime,
        execPath,
      ) &&
      execPath(selected("git", entries)) === gitRuntime &&
      tools.every(
        (tool) =>
          tool === "git" ||
          selected(tool, entries) === selected(tool, remaining),
      )
    )
      effectivePath = remaining.join(path.delimiter);
  } catch {
    // Sem origem verificável, o PATH inteiro continua fazendo parte da identidade.
  }
  return { effectivePath, gitRuntime };
}

const volatile = new Set([
  "_",
  "SHLVL",
  // Identifica a chamada do agente, sem alterar o programa validado.
  "CODEX_TOOL_CALL_ID",
  "QUALITY_VALIDATION_PARENT",
  "PWD",
  "OLDPWD",
  "INIT_CWD",
  "npm_lifecycle_event",
  "npm_lifecycle_script",
  "npm_command",
  "npm_execpath",
  "npm_node_execpath",
  "npm_package_json",
  "GIT_PREFIX",
  "npm_config_user_agent",
  "npm_config_local_prefix",
  "npm_config_node_gyp",
]);

export function environmentIdentity(context) {
  const unsupported = [
    "BASH_ENV",
    "ENV",
    "NODE_OPTIONS",
    "NODE_PATH",
    "PHPRC",
    "PHP_INI_SCAN_DIR",
    "COMPOSER_HOME",
    "NPM_CONFIG_USERCONFIG",
    "NPM_CONFIG_GLOBALCONFIG",
    "GIT_CONFIG_GLOBAL",
    "GIT_CONFIG_SYSTEM",
    "GIT_CONFIG_COUNT",
    "XDG_CONFIG_HOME",
    "PYTHONPATH",
    "PYTHONHOME",
    "RUBYOPT",
    "RUBYLIB",
    "PERL5OPT",
    "PERL5LIB",
    "LD_PRELOAD",
    "LD_LIBRARY_PATH",
    "DYLD_INSERT_LIBRARIES",
    "DYLD_LIBRARY_PATH",
    "GITLEAKS_CONFIG",
    "SEMGREP_SETTINGS_FILE",
    "PHP85_BIN",
    "COMPOSER85_BIN",
    "CHATBOTS_PG_BIN",
    "PHP_BINARY_TESTES",
    "CHATBOTS_REDIS_TEST_SOCKET",
    "CHATBOTS_PG_TEST_DSN",
  ];
  if (
    Object.entries(process.env).some(
      ([name, value]) => name.startsWith("NFSE_CERT_") && value,
    )
  )
    throw Object.assign(
      new Error(
        "validação local: certificado externo sem fingerprint; gates completos serão executados.",
      ),
      { code: "VALIDATION_UNCACHEABLE" },
    );
  if (
    process.env.GIT_EXEC_PATH &&
    process.env.GIT_EXEC_PATH !== context.gitRuntime
  )
    throw new Error("Git externo sem fingerprint: reuso desativado");
  if (unsupported.some((name) => process.env[name]))
    throw new Error("configuração externa sem fingerprint: reuso desativado");
  const npmPaths = new Set();
  let directory = process.cwd();
  while (true) {
    npmPaths.add(path.join(directory, "node_modules/.bin"));
    const parent = path.dirname(directory);
    if (parent === directory) break;
    directory = parent;
  }
  try {
    npmPaths.add(
      path.resolve(
        path.dirname(
          selected("npm", (process.env.PATH ?? "").split(path.delimiter)),
        ),
        "../node_modules/@npmcli/run-script/lib/node-gyp-bin",
      ),
    );
  } catch {
    /* npm ausente já impede toolchain quando declarada */
  }
  const effectivePath = [
    ...new Set(
      context.effectivePath
        .split(path.delimiter)
        .filter((entry) => !npmPaths.has(entry)),
    ),
  ].join(path.delimiter);
  const environment = { ...process.env, CI: "1", PATH: effectivePath };
  // NO_COLOR é booleano por presença não vazia; Lefthook converte 1 em true.
  if (environment.NO_COLOR) environment.NO_COLOR = "1";
  if (environment.GIT_EXEC_PATH === context.gitRuntime)
    delete environment.GIT_EXEC_PATH;
  return createHash("sha256")
    .update(
      JSON.stringify(
        Object.entries(environment)
          .filter(
            ([name]) =>
              !volatile.has(name) &&
              !name.startsWith("npm_package_") &&
              !name.startsWith("npm_config_") &&
              !["NODE", "COLOR", "EDITOR"].includes(name),
          )
          .sort(([a], [b]) => a.localeCompare(b)),
      ),
    )
    .digest("hex");
}
