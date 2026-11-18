import { createHash } from "node:crypto";
import { execFileSync, spawnSync } from "node:child_process";
import {
  existsSync,
  openSync,
  closeSync,
  readSync,
  lstatSync,
  readFileSync,
  readdirSync,
  readlinkSync,
  realpathSync,
} from "node:fs";
import path from "node:path";
import os from "node:os";
import {
  executionContext,
  environmentIdentity,
} from "./validation-environment.mjs";
const hash = (value) => createHash("sha256").update(value).digest("hex");
const git = (...args) =>
  execFileSync("git", args, {
    encoding: "utf8",
    maxBuffer: 128 * 1024 * 1024,
    stdio: ["ignore", "pipe", "pipe"],
  }).trimEnd();
const jsonHash = (value) => hash(JSON.stringify(value));

function checkoutIdentity() {
  const head = git("rev-parse", "HEAD");
  if (
    git("ls-files", "-v", "-z")
      .split("\0")
      .some((file) => /^[a-zS] /u.test(file))
  )
    throw new Error("flags de índice ocultam alterações");
  if (
    git(
      "status",
      "--porcelain=v1",
      "--untracked-files=all",
      "--ignore-submodules=none",
    ) ||
    git("write-tree") !== git("rev-parse", `${head}^{tree}`)
  )
    throw new Error("checkout sujo");
  return {
    head,
    tree: git("rev-parse", `${head}^{tree}`),
    root: realpathSync(git("rev-parse", "--show-toplevel")),
  };
}

function manifestAt(file) {
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  if (
    manifest.version !== 1 ||
    typeof manifest.revision !== "string" ||
    !manifest.revision ||
    !manifest.equivalence ||
    !Array.isArray(manifest.reuse) ||
    !Array.isArray(manifest.artifacts) ||
    (manifest.operationalOutputs !== undefined &&
      !Array.isArray(manifest.operationalOutputs))
  )
    throw new Error("contrato de validação inválido");
  for (const entry of manifest.reuse) {
    if (
      !entry.proof ||
      !Array.isArray(entry.command) ||
      !entry.command.length ||
      entry.command.some((item) => typeof item !== "string")
    )
      throw new Error("comando de prova inválido");
  }
  for (const entry of [
    ...manifest.artifacts,
    ...(manifest.operationalOutputs ?? []),
  ]) {
    if (
      typeof entry !== "string" ||
      !entry ||
      path.isAbsolute(entry) ||
      entry.split(/[\\/]/u).some((part) => ["", ".", ".."].includes(part)) ||
      entry === ".git" ||
      entry.startsWith(".git/")
    )
      throw new Error("artefato inválido");
  }
  assertPlanBindings(manifest);
  return manifest;
}

function assertPlanBindings(manifest) {
  const bots = /^bots-/u.test(manifest.revision);
  const invalid = (detail) => {
    throw Object.assign(
      new Error(`contrato de equivalência divergente: ${detail}`),
      { code: "VALIDATION_BINDINGS" },
    );
  };
  for (const [field, file, required] of [
    [
      "composerScripts",
      "composer.json",
      bots
        ? [
            "check:all",
            "check:all:run",
            "check:ci-local",
            "check:ci-local:run",
            "test",
          ]
        : [],
    ],
    [
      "npmScripts",
      "package.json",
      bots
        ? ["test:quality-tools", "check:licenses", "check:vulnerabilities"]
        : [],
    ],
  ]) {
    const bindings = manifest.equivalence[field];
    if (bindings === undefined && !bots) continue;
    if (!bindings || typeof bindings !== "object" || Array.isArray(bindings))
      invalid(`${field} ausente ou inválido`);
    for (const name of required)
      if (!Object.hasOwn(bindings, name)) invalid(`${field}.${name} ausente`);
    let scripts;
    try {
      scripts = JSON.parse(readFileSync(file, "utf8")).scripts;
    } catch {
      invalid(`${file} ausente ou inválido`);
    }
    if (!scripts || typeof scripts !== "object" || Array.isArray(scripts))
      invalid(`${file} sem scripts`);
    if (bots && field === "composerScripts") {
      const pending = [...required];
      const visited = new Set();
      while (pending.length) {
        const name = pending.pop();
        if (visited.has(name)) continue;
        visited.add(name);
        if (!Object.hasOwn(bindings, name)) invalid(`${field}.${name} ausente`);
        for (const step of [scripts[name]].flat()) {
          if (
            typeof step === "string" &&
            step.startsWith("@") &&
            Object.hasOwn(scripts, step.slice(1))
          )
            pending.push(step.slice(1));
        }
      }
    }
    for (const [name, expected] of Object.entries(bindings)) {
      if (
        !Object.hasOwn(scripts, name) ||
        JSON.stringify(scripts[name]) !== JSON.stringify(expected)
      )
        invalid(`${file}: ${name}`);
    }
  }
}

function digestContent(digest, file) {
  const descriptor = openSync(file, "r");
  const buffer = Buffer.allocUnsafe(1024 * 1024);
  try {
    let count;
    while ((count = readSync(descriptor, buffer, 0, buffer.length, null)) > 0)
      digest.update(buffer.subarray(0, count));
  } finally {
    closeSync(descriptor);
  }
}
function fileState(file) {
  try {
    return lstatSync(file);
  } catch (error) {
    if (error.code === "ENOENT") return undefined;
    throw error;
  }
}
function digestFiles(files, root, excluded = () => false) {
  const digest = createHash("sha256");
  const seen = new Set();
  function visit(file) {
    const absolute = path.resolve(root, file);
    if (excluded(path.relative(root, absolute).split(path.sep).join("/")))
      return;
    digest.update(JSON.stringify(file));
    const stat = fileState(absolute);
    if (!stat) {
      digest.update("missing");
      return;
    }
    digest.update(String(stat.mode));
    if (stat.isSymbolicLink()) {
      digest.update(readlinkSync(absolute));
      if (!existsSync(absolute)) {
        digest.update("dangling");
        return;
      }
      const target = realpathSync(absolute);
      if (seen.has(target)) return;
      seen.add(target);
      visit(target);
    } else if (stat.isDirectory()) {
      for (const child of readdirSync(absolute).sort())
        visit(path.join(file, child));
    } else if (stat.isFile()) digestContent(digest, absolute);
    else throw new Error("tipo de arquivo sem fingerprint");
  }
  for (const file of [...new Set(files)].sort()) visit(file);
  return digest.digest("hex");
}

function resolveTool(name) {
  if (name.includes("/")) return realpathSync(name);
  for (const directory of (process.env.PATH ?? "").split(path.delimiter)) {
    const candidate = path.join(directory, name);
    if (existsSync(candidate) && lstatSync(candidate).mode & 0o111)
      return realpathSync(candidate);
  }
  throw new Error("ferramenta ausente");
}

function toolCandidates(tool, searchPath) {
  if (tool.includes("/")) return [path.resolve(tool)];
  const candidates = searchPath
    .split(path.delimiter)
    .map((directory) => path.join(directory, tool));
  candidates.push(`/opt/homebrew/bin/${tool}`, `/usr/local/bin/${tool}`);
  if (tool !== "semgrep") return candidates;
  const homes = [
    process.env.PIPX_HOME,
    path.join(os.homedir(), "Library/Application Support/pipx"),
    path.join(os.homedir(), ".local/share/pipx"),
    path.join(os.homedir(), ".local/pipx"),
  ].filter(Boolean);
  for (const home of homes)
    for (const name of ["semgrep-apps", "semgrep-sites", "semgrep"])
      candidates.push(path.join(home, "venvs", name, "bin/semgrep"));
  candidates.push(
    "/opt/homebrew/opt/semgrep/bin/semgrep",
    "/usr/local/opt/semgrep/bin/semgrep",
  );
  return candidates;
}
function toolRoot(binary, tool) {
  const parent = path.resolve(path.dirname(binary), "..");
  if (existsSync(path.join(parent, "pyvenv.cfg"))) return parent;
  if (tool === "npm" && existsSync(path.join(parent, "package.json")))
    return parent;
  return binary;
}
function toolsIdentity(manifest, context) {
  const tools = manifest.tools;
  if (
    !Array.isArray(tools) ||
    !tools.length ||
    tools.some((tool) => typeof tool !== "string")
  )
    throw new Error("ferramentas explícitas obrigatórias");
  return tools.map((tool) => {
    const candidates = [
      ...new Set(toolCandidates(tool, context.effectivePath)),
    ];
    const available = candidates.filter((file) => existsSync(file));
    if (!available.length) throw new Error("ferramenta ausente");
    const binary = realpathSync(available[0]);
    const arguments_ =
      path.basename(tool) === "sandbox-exec"
        ? ["-p", "(version 1)(allow default)", "/usr/bin/true"]
        : tool === "semgrep"
          ? ["scan", "--version", "--disable-version-check", "--metrics=off"]
          : ["openssl", "gitleaks"].includes(tool)
            ? ["version"]
            : ["--version"];
    const result = spawnSync(binary, arguments_, {
      encoding: "utf8",
      timeout: 10000,
      env: { ...process.env, CI: "1", SEMGREP_LOG_FILE: "/dev/null" },
    });
    if (result.status !== 0 || result.error)
      throw new Error("versão da ferramenta indisponível");
    const roots = available.map((file) => toolRoot(realpathSync(file), tool));
    if (tool === "git" && context.gitRuntime) roots.push(context.gitRuntime);
    return [
      tool,
      binary,
      digestFiles([...new Set([...available, ...roots])], "/"),
      hash(result.stdout + result.stderr),
    ];
  });
}

function npmConfiguration() {
  const result = spawnSync(resolveTool("npm"), ["config", "list", "--json"], {
    encoding: "utf8",
    timeout: 10000,
    env: { ...process.env, CI: "1" },
  });
  if (result.status !== 0 || result.error)
    throw new Error("configuração npm indisponível");
  const configuration = JSON.parse(result.stdout);
  if (configuration["node-options"] || configuration["script-shell"])
    throw new Error("loader/shell npm externo: reuso desativado");
  for (const field of [
    "loglevel",
    "color",
    "progress",
    "logs-max",
    "timing",
    "user-agent",
    "node-gyp",
    "global-prefix",
    "local-prefix",
    "global-ignore-file",
  ])
    delete configuration[field];
  return Object.fromEntries(
    Object.entries(configuration).sort(([a], [b]) => a.localeCompare(b)),
  );
}
function externalExtensions(files) {
  const result = [];
  for (const file of files) {
    if (typeof file !== "string" || !file.endsWith(".ini") || !existsSync(file))
      continue;
    const text = readFileSync(file, "utf8");
    for (const match of text.matchAll(
      /^\s*(?:zend_)?extension\s*=\s*["']?(\/[^"';\r\n]+)/gmu,
    ))
      result.push(match[1].trim());
  }
  return result;
}
function externalConfig() {
  const home = os.homedir();
  const npm = npmConfiguration();
  const files = [
    npm.userconfig,
    npm.globalconfig,
    git("rev-parse", "--git-path", "config"),
    git("rev-parse", "--git-path", "config.worktree"),
    git("rev-parse", "--git-path", "info/attributes"),
    git("rev-parse", "--git-path", "info/exclude"),
    git("rev-parse", "--git-path", "shallow"),
    "/etc/gitconfig",
    "/etc/npmrc",
    "/opt/homebrew/etc/npmrc",
    "/usr/local/etc/npmrc",
    path.join(home, ".npmrc"),
    path.join(home, ".gitconfig"),
    path.join(home, ".config/git/config"),
    path.join(home, ".config/composer/config.json"),
    path.join(home, ".composer/config.json"),
  ];
  // PHP's loaded ini and scanned directories can change without a lockfile change.
  try {
    const php = resolveTool("php");
    const result = spawnSync(php, ["--ini"], {
      encoding: "utf8",
      timeout: 10000,
    });
    if (result.status !== 0) throw new Error("php ini indisponível");
    const extensions = spawnSync(
      php,
      [
        "-r",
        "echo json_encode([ini_get('extension_dir'), ini_get('auto_prepend_file'), ini_get('auto_append_file')]);",
      ],
      { encoding: "utf8", timeout: 10000 },
    );
    if (extensions.status !== 0)
      throw new Error("extensões PHP sem fingerprint");
    const [extensionDirectory, prepend, append] = JSON.parse(extensions.stdout);
    if (!path.isAbsolute(extensionDirectory) || prepend || append)
      throw new Error("PHP com loader externo ou extensões sem fingerprint");
    files.push(extensionDirectory);
    for (const match of result.stdout.matchAll(
      /(?:^|[\s,])((?:\/[^\s,]+)\.ini)/gu,
    ))
      files.push(match[1]);
    const directory = result.stdout.match(
      /Scan for additional \.ini files in:\s*(\/[^\n]+)/u,
    )?.[1];
    if (directory) files.push(directory.trim());
  } catch (error) {
    if (
      (process.env.PATH ?? "")
        .split(path.delimiter)
        .some((entry) => existsSync(path.join(entry, "php")))
    )
      throw error;
  }
  files.push(...externalExtensions(files));
  return digestFiles(
    files
      .filter((file) => typeof file === "string")
      .map((file) => path.resolve(file)),
    "/",
  );
}

function snapshot(file) {
  const checkout = checkoutIdentity();
  const manifest = manifestAt(file);
  const phpConfig = path.join(checkout.root, "inclusos/config.php");
  if (
    existsSync(phpConfig) &&
    /NFSE_CERT_/u.test(readFileSync(phpConfig, "utf8"))
  )
    throw Object.assign(
      new Error(
        "validação local: referências NFSE_CERT_ em config.php não têm identidade de certificado comprovada; gates completos serão executados.",
      ),
      { code: "VALIDATION_UNCACHEABLE" },
    );
  const tracked = git("ls-files", "-z").split("\0").filter(Boolean);
  // Todo conteúdo ignorado participa: .env, config.php, node_modules e vendor.
  const ignored = git(
    "ls-files",
    "--others",
    "--ignored",
    "--exclude-standard",
    "-z",
  )
    .split("\0")
    .filter(Boolean);
  const artifact = (entry) =>
    manifest.artifacts.some(
      (prefix) => entry === prefix || entry.startsWith(`${prefix}/`),
    );
  // Recibos e caches de publicação não são insumos nem outputs aprovados pelos testes.
  const operational = (entry) =>
    (manifest.operationalOutputs ?? []).some(
      (prefix) => entry === prefix || entry.startsWith(`${prefix}/`),
    );
  const inputs = digestFiles(
    [
      ...tracked,
      ...ignored.filter((entry) => !artifact(entry) && !operational(entry)),
    ],
    checkout.root,
  );
  const artifacts = digestFiles(manifest.artifacts, checkout.root, operational);
  const context = executionContext(manifest.tools);
  const identity = {
    ...checkout,
    host: [os.platform(), os.arch(), os.release()],
    // Tracking pode avançar para um commit já coberto após o push. Histórico novo
    // continua sendo entrada do gitleaks, independentemente do nome da ref.
    refs: jsonHash([
      git(
        "for-each-ref",
        "--format=%(refname) %(objectname)",
        "refs/heads",
        "refs/tags",
      ),
      git("rev-list", "--all").split("\n").sort(),
    ]),
    inputs,
    tools: toolsIdentity(manifest, context),
    environment: environmentIdentity(context),
    npm: jsonHash(npmConfiguration()),
    external: externalConfig(),
    manifest: hash(readFileSync(file)),
  };
  if (jsonHash(checkoutIdentity()) !== jsonHash(checkout))
    throw new Error("checkout mudou durante fingerprint");
  return {
    debug: Object.fromEntries(
      Object.entries(identity).map(([key, value]) => [key, jsonHash(value)]),
    ),
    identity: jsonHash(identity),
    artifacts,
    manifest,
    checkout,
  };
}

export { git, hash, manifestAt, snapshot };
