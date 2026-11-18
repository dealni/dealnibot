import { execFileSync, spawnSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  mkdtempSync,
  readdirSync,
  rmSync,
  symlinkSync,
  writeFileSync,
} from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
const evidence = fileURLToPath(
  new URL("./validation-evidence.mjs", import.meta.url),
);
const runner = fileURLToPath(
  new URL("./validation-runner.mjs", import.meta.url),
);
const prepush = fileURLToPath(new URL("./pre-push.mjs", import.meta.url));
// Overrides do processo pai não pertencem ao fixture; cada caso negativo injeta os seus em run().
const environment = Object.fromEntries(
  Object.entries(process.env).filter(
    ([key]) =>
      !/^(?:GIT_|NFSE_CERT_|NPM_CONFIG_)/iu.test(key) &&
      ![
        "BASH_ENV",
        "ENV",
        "NODE_OPTIONS",
        "NODE_PATH",
        "PHPRC",
        "PHP_INI_SCAN_DIR",
        "COMPOSER_HOME",
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
      ].includes(key),
  ),
);
// O launcher do macOS consulta xcrun a cada chamada; o fixture usa o mesmo Git real diretamente.
const directGit =
  process.platform === "darwin"
    ? path.resolve(
        execFileSync("git", ["--exec-path"], {
          encoding: "utf8",
          env: environment,
        }).trim(),
        "../../bin/git",
      )
    : undefined;

function fixture(t, { realNpm = false, realTools = false } = {}) {
  const root = mkdtempSync(path.join(os.tmpdir(), "validation-evidence-"));
  const repo = path.join(root, "repo");
  const bin = path.join(root, "bin");
  mkdirSync(bin);
  if (directGit && existsSync(directGit))
    symlinkSync(directGit, path.join(bin, "git"));
  const quote = (value) => `'${value.replaceAll("'", "'\"'\"'")}'`;
  const config = path.join(root, "npm-config.json");
  writeFileSync(
    config,
    JSON.stringify({
      userconfig: path.join(root, "user.npmrc"),
      globalconfig: path.join(root, "global.npmrc"),
      "node-options": null,
      "script-shell": null,
    }),
  );
  if (!realNpm)
    writeFileSync(path.join(bin, "npm"), `#!/bin/sh\ncat ${quote(config)}\n`, {
      mode: 0o755,
    });
  const extensionDirectory = path.join(root, "extensions");
  mkdirSync(extensionDirectory);
  const phpProfile = path.join(root, "php-profile.json");
  writeFileSync(phpProfile, JSON.stringify([extensionDirectory, "", ""]));
  writeFileSync(
    path.join(bin, "php"),
    `#!/bin/sh\nif [ "$1" = '--ini' ]; then printf 'Loaded Configuration File: (none)\\nScan for additional .ini files in: (none)\\n'; else cat ${quote(phpProfile)}; fi\n`,
    { mode: 0o755 },
  );
  writeFileSync(
    path.join(bin, "fixture-tool"),
    "#!/bin/sh\nprintf 'fixture-tool 1.0\\n'\n",
    { mode: 0o755 },
  );
  const fixtureEnvironment = {
    ...environment,
    PATH: `${bin}${path.delimiter}${environment.PATH ?? ""}`,
  };

  const git = (...args) =>
    execFileSync("git", ["-C", repo, ...args], {
      encoding: "utf8",
      env: fixtureEnvironment,
      stdio: ["ignore", "pipe", "pipe"],
    }).trim();
  execFileSync("git", ["init", "--quiet", "--template=", repo], {
    env: fixtureEnvironment,
  });
  t.after(() => rmSync(root, { recursive: true, force: true }));
  git("config", "user.name", "Fixture");
  git("config", "user.email", "fixture@example.invalid");
  git("config", "core.hooksPath", "/dev/null");
  git("config", "commit.gpgsign", "false");
  mkdirSync(path.join(repo, "scripts/quality"), { recursive: true });
  writeFileSync(path.join(repo, ".gitignore"), "ignored/\noutput/\n");
  writeFileSync(path.join(repo, "source.txt"), "source");
  mkdirSync(path.join(repo, "ignored"));
  writeFileSync(path.join(repo, "ignored/config"), "secret-initial");
  const gate = [process.execPath, "-e", "console.log('GATE_EXECUTED')"];
  const manifest = {
    version: 1,
    revision: "fixture-v1",
    reuse: [{ command: gate, proof: "check:all" }],
    executions: [{ command: gate, proves: ["check:all"] }],
    issuers: ["source.txt", "scripts/quality/validation-runner.mjs"],
    equivalence: { contract: "fixture gate exact" },
    artifacts: ["output"],
    tools: realTools ? ["node", "npm", "git"] : ["fixture-tool"],
  };
  writeFileSync(
    path.join(repo, "scripts/quality/validation-plan.json"),
    JSON.stringify(manifest),
  );
  git("add", ".");
  git("commit", "--quiet", "-m", "fixture inicial");
  const head = git("rev-parse", "HEAD");
  const code = `import {withValidationEvidence,reuseValidation} from ${JSON.stringify(new URL(`file://${evidence}`).href)};`;
  const run = (script, env = {}) =>
    spawnSync(process.execPath, ["--input-type=module", "-e", code + script], {
      cwd: repo,
      encoding: "utf8",
      env: { ...fixtureEnvironment, ...env },
    });
  const approve = (body = "", env = {}) =>
    run(
      `await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},()=>{${body}});`,
      env,
    );
  const reuse = (env = {}) =>
    run(
      `console.log('REUSED='+reuseValidation(${JSON.stringify(gate)}));`,
      env,
    );
  const receipt = () =>
    path.join(
      repo,
      ".git/quality-validation",
      readdirSync(path.join(repo, ".git/quality-validation"))[0],
    );
  return {
    repo,
    git,
    head,
    gate,
    approve,
    reuse,
    run,
    receipt,
    root,
    bin,
    environment: fixtureEnvironment,
  };
}

export { evidence, runner, prepush, fixture };
