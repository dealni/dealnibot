import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  unlinkSync,
  writeFileSync,
  realpathSync,
  symlinkSync,
} from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { test } from "node:test";
import { executionContext } from "./validation-environment.mjs";
import { evidence, fixture } from "./validation-fixture.mjs";
const reused = (result) => {
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.includes("REUSED=true");
};

test("ID efêmero da chamada Codex preserva reuso sem ignorar outras configurações", (t) => {
  const f = fixture(t);
  assert.equal(f.approve("", { CODEX_TOOL_CALL_ID: "call-first" }).status, 0);
  assert.equal(reused(f.reuse({ CODEX_TOOL_CALL_ID: "call-next" })), true);
  assert.equal(
    reused(
      f.reuse({
        CODEX_TOOL_CALL_ID: "call-next",
        CODEX_RUNTIME_PROFILE: "changed",
      }),
    ),
    false,
  );
});

test("NO_COLOR não vazio é booleano; cor habilitada muda a identidade", (t) => {
  const f = fixture(t);
  assert.equal(f.approve("", { NO_COLOR: "1" }).status, 0);
  assert.equal(reused(f.reuse({ NO_COLOR: "true" })), true);
  assert.equal(reused(f.reuse({ NO_COLOR: "" })), false);
});

for (const key of [
  "SDKROOT",
  "CPATH",
  "LIBRARY_PATH",
  "MANPATH",
  "VALIDATION_PROFILE",
]) {
  test(`ambiente relevante ${key} continua invalidando reuso`, (t) => {
    const f = fixture(t);
    assert.equal(f.approve().status, 0);
    assert.equal(reused(f.reuse({ [key]: "changed" })), false);
  });
}

test("GIT_EXEC_PATH externo não emite prova nem ignora mudanças de bytes", (t) => {
  const f = fixture(t);
  const external = path.join(f.root, "custom-git");
  mkdirSync(external);
  const env = { GIT_EXEC_PATH: external };
  assert.equal(f.approve("", env).status, 0);
  assert.equal(reused(f.reuse(env)), false);
  writeFileSync(path.join(external, "git-custom"), "changed");
  assert.equal(reused(f.reuse(env)), false);
});

test("prefixo Git que muda resolução de ferramenta declarada continua no PATH", () => {
  const before = process.env.PATH;
  const core = executionContext(["git"]).gitRuntime;
  assert.ok(core);
  try {
    const remaining = before
      .split(path.delimiter)
      .filter((entry) => entry !== core);
    process.env.PATH = `${core}${path.delimiter}${remaining.join(path.delimiter)}`;
    assert.equal(
      executionContext(["git", "git-add"]).effectivePath,
      process.env.PATH,
    );
  } finally {
    process.env.PATH = before;
  }
});

test("Git e Lefthook reais reutilizam recibo mantendo a mesma toolchain", (t) => {
  const lefthook = fileURLToPath(
    new URL("../../node_modules/.bin/lefthook", import.meta.url),
  );
  assert.ok(existsSync(lefthook), "Lefthook local obrigatório para este teste");
  const f = fixture(t, { realTools: true, realNpm: true });
  // O fixture usa o executável real de Git via symlink. O launcher Apple /usr/bin/git
  // também injeta SDK/compilação e pode recusar reuso quando esse ambiente divergir.
  const actualGit = existsSync(path.join(f.bin, "git"))
    ? realpathSync(path.join(f.bin, "git"))
    : "/usr/bin/git";
  if (existsSync(path.join(f.bin, "git"))) unlinkSync(path.join(f.bin, "git"));
  f.environment.PATH = `${f.bin}${path.delimiter}${path.dirname(actualGit)}${path.delimiter}${f.environment.PATH.split(path.delimiter).slice(1).join(path.delimiter)}`;
  f.environment.NO_COLOR = "1";
  const remote = path.join(f.root, "remote.git");
  const init = spawnSync("git", ["init", "--bare", "--quiet", remote], {
    env: f.environment,
  });
  assert.equal(init.status, 0);
  f.git("remote", "add", "origin", remote);
  f.git("config", "core.hooksPath", ".git/hooks");
  const helper = path.join(f.root, "reuse.mjs");
  writeFileSync(
    helper,
    `import {reuseValidation} from ${JSON.stringify(new URL(`file://${evidence}`).href)};console.log('HOOK_REUSED='+reuseValidation(${JSON.stringify(f.gate)}));`,
  );
  writeFileSync(
    path.join(f.repo, "lefthook.yml"),
    "min_version: 2.1.12\npre-push:\n  jobs:\n    - name: qualidade\n      use_stdin: true\n      script: qualidade.sh\n      runner: sh\n",
  );
  mkdirSync(path.join(f.repo, ".lefthook/pre-push"), { recursive: true });
  writeFileSync(
    path.join(f.repo, ".lefthook/pre-push/qualidade.sh"),
    `#!/bin/sh\nexec '${process.execPath}' '${helper}'\n`,
    { mode: 0o755 },
  );
  mkdirSync(path.join(f.repo, ".git/hooks"));
  writeFileSync(
    path.join(f.repo, ".git/hooks/pre-push"),
    `#!/bin/sh\nexec '${lefthook}' run pre-push "$@"\n`,
    { mode: 0o755 },
  );
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "fixture hook real");
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
  const pushed = spawnSync(
    actualGit,
    ["push", "--dry-run", "origin", "HEAD:refs/heads/test"],
    {
      cwd: f.repo,
      env: f.environment,
      encoding: "utf8",
    },
  );
  assert.equal(pushed.status, 0, pushed.stderr);
  assert.match(pushed.stdout, /HOOK_REUSED=true/u);
  assert.equal(
    spawnSync(actualGit, ["--git-dir", remote, "for-each-ref"], {
      env: f.environment,
      encoding: "utf8",
    }).stdout,
    "",
  );
  assert.equal(
    reused(
      f.reuse({ PATH: `${f.root}${path.delimiter}${f.environment.PATH}` }),
    ),
    false,
  );
});

for (const symlink of [false, true])
  test(`Git custom ${symlink ? "symlink" : "wrapper"} que imita --exec-path não ganha equivalência`, (t) => {
    const f = fixture(t, { realTools: true });
    const runtime = path.join(f.root, "custom-runtime");
    mkdirSync(runtime);
    const git = path.join(f.bin, "git");
    const actual = existsSync(git)
      ? realpathSync(git)
      : realpathSync("/usr/bin/git");
    if (existsSync(git)) unlinkSync(git);
    writeFileSync(
      git,
      `#!/bin/sh
if [ "$1" = '--exec-path' ]; then printf '%s\n' '${runtime}'; else exec '${actual}' "$@"; fi
`,
      { mode: 0o755 },
    );
    if (symlink) symlinkSync(git, path.join(runtime, "git"));
    else
      writeFileSync(
        path.join(runtime, "git"),
        `#!/bin/sh
if [ "$1" = '--version' ]; then printf 'git version DIFFERENT\n'; else exec '${git}' "$@"; fi
`,
        { mode: 0o755 },
      );
    assert.equal(f.approve().status, 0);
    assert.equal(reused(f.reuse()), true);
    assert.equal(
      reused(
        f.reuse({ PATH: `${runtime}${path.delimiter}${f.environment.PATH}` }),
      ),
      false,
    );
  });

test("symlink externo para o mesmo Git preserva PATH bruto", (t) => {
  const f = fixture(t, { realTools: true });
  const externalGit = path.join(f.bin, "git");
  // O fixture já cria o symlink no macOS; Linux também precisa dessa premissa.
  if (!existsSync(externalGit))
    symlinkSync(realpathSync("/usr/bin/git"), externalGit);
  const runtime = executionContext(["git"]).gitRuntime;
  assert.ok(runtime);
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
  assert.equal(
    reused(
      f.reuse({ PATH: `${runtime}${path.delimiter}${f.environment.PATH}` }),
    ),
    false,
  );
});

for (const scenario of [
  "identical-copy",
  "different-bytes",
  "altered-symlink",
  "external-symlink",
  "wrapper",
  "forged-runtime",
  "shadowed-tool",
])
  test(`layout Git Linux valida origem e bytes: ${scenario}`, () => {
    const result = spawnSync(
      process.execPath,
      [
        "--experimental-test-module-mocks",
        fileURLToPath(
          new URL(
            "./validation-environment-linux-fixture.mjs",
            import.meta.url,
          ),
        ),
        scenario,
      ],
      { encoding: "utf8" },
    );
    assert.equal(result.status, 0, result.stderr);
  });
