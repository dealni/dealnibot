import assert from "node:assert/strict";
import { mock } from "node:test";

// Processo isolado: modela o layout Linux sem alterar arquivos do sistema.
const scenario = process.argv[2];
const runtime = "/usr/lib/git-core";
const system = "/usr/bin/git";
const coreGit = `${runtime}/git`;
const fakeRuntime = "/tmp/custom-runtime";
const paths = new Map([
  [system, { real: system, ino: 1, bytes: "git implementation" }],
  [coreGit, { real: coreGit, ino: 2, bytes: "git implementation" }],
  ["/usr/bin/node", { real: "/usr/bin/node", ino: 3 }],
]);
let prefix = runtime;
let remaining = "/usr/bin";
let expected = remaining;
if (scenario === "different-bytes") {
  paths.get(coreGit).bytes = "altered implementation";
} else if (scenario === "altered-symlink") {
  paths.get(coreGit).real = "/tmp/altered-git";
  paths.set("/tmp/altered-git", {
    real: "/tmp/altered-git",
    ino: 4,
    bytes: "altered implementation",
  });
} else if (scenario === "external-symlink" || scenario === "wrapper") {
  remaining = "/tmp/bin:/usr/bin";
  paths.set("/tmp/bin/git", {
    real: scenario === "external-symlink" ? system : "/tmp/bin/git",
    ino: 5,
    bytes: "wrapper",
  });
} else if (scenario === "forged-runtime") {
  prefix = fakeRuntime;
  remaining = "/tmp/bin:/usr/bin";
  for (const file of [`${prefix}/git`, "/tmp/bin/git"])
    paths.set(file, { real: file, ino: 6, bytes: "wrapper" });
} else if (scenario === "shadowed-tool") {
  paths.set(`${runtime}/node`, { real: `${runtime}/node`, ino: 7 });
} else assert.equal(scenario, "identical-copy");

const raw = `${prefix}:${remaining}`;
if (scenario !== "identical-copy") expected = raw;
Object.defineProperty(process, "platform", { value: "linux" });
process.env.PATH = raw;
process.env.GIT_EXEC_PATH = "/untrusted-override";
const file = (name) => {
  assert.ok(paths.has(name), `caminho inesperado: ${name}`);
  return paths.get(name);
};
const reads = [];
mock.module("node:fs", {
  exports: {
    existsSync: (name) => paths.has(name),
    realpathSync: (name) => file(name).real,
    statSync: (name) => ({
      isFile: () => true,
      mode: 0o755,
      dev: 1,
      ino: file(name).ino,
    }),
    readFileSync: (name) => {
      reads.push(name);
      return Buffer.from(file(name).bytes);
    },
  },
});
const queried = [];
mock.module("node:child_process", {
  exports: {
    execFileSync: (binary, args, options) => {
      assert.deepEqual(args, ["--exec-path"]);
      assert.equal(options.env.GIT_EXEC_PATH, undefined);
      queried.push(binary);
      return `${binary === system ? runtime : prefix}\n`;
    },
  },
});
const { executionContext } = await import("./validation-environment.mjs");
assert.equal(executionContext(["git", "node"]).effectivePath, expected);
assert.ok(
  queried.includes(system),
  "origem do sistema consultada sem override",
);
if (scenario === "identical-copy") {
  assert.notEqual(paths.get(coreGit).ino, paths.get(system).ino);
  assert.deepEqual(reads, [coreGit, system]);
} else if (["wrapper", "external-symlink", "forged-runtime"].includes(scenario))
  assert.deepEqual(
    reads,
    [],
    "caminhos não confiáveis não ganham comparação de bytes",
  );
