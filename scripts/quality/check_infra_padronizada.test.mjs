import "./check_postgres_bin.test.mjs";
import assert from "node:assert/strict";
import { test } from "node:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, readFileSync, writeFileSync, mkdirSync, rmSync, realpathSync, statSync, symlinkSync, chmodSync, readdirSync } from "node:fs";
import { join, resolve } from "node:path";
import { tmpdir } from "node:os";

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
const bot = "dealnibot";
const prefix = "DEALNI";
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

const root = resolve(import.meta.dirname, "../..");
function temporary(run) {
  const dir = realpathSync(mkdtempSync(join(tmpdir(), "bot-infra-")));
  try { return run(dir); } finally { rmSync(dir, { recursive: true, force: true }); }
}
function execute(cmd, args, options = {}) {
  return spawnSync(cmd, args, { encoding: "utf8", cwd: root, ...options });
}
function success(result) {
  assert.equal(result.status, 0, result.stderr + result.stdout);
}
function releaseFixture(dir) {
  mkdirSync(join(dir, "scripts/deploy"), { recursive: true });
  writeFileSync(join(dir, "composer.lock"), "{}");
  writeFileSync(join(dir, ".gitignore"), ".release.json\nsentinela\n");
  let code = readFileSync(join(root, "scripts/deploy/registrar_publicacao.sh"), "utf8");
  code = code.replace(`expected_dir="/var/www/${bot}"`, `expected_dir="${dir}"`)
    .replace(`runtime_group="${bot}"`, `runtime_group="${statSync(dir).gid}"`);
  const script = join(dir, "scripts/deploy/registrar_publicacao.sh");
  writeFileSync(script, code);
  success(execute("git", ["init", "--quiet", dir]));
  success(execute("git", ["-C", dir, "add", "."]));
  success(execute("git", ["-C", dir, "-c", "user.name=Teste", "-c", "user.email=teste@example.invalid",
    "-c", "core.hooksPath=/dev/null", "-c", "commit.gpgsign=false", "commit", "--quiet", "--allow-empty", "-m", "fixture"]));
  return script;
}
test("deploy e rollback publicam carimbo com leitura do grupo e troca de inode", () => temporary((dir) => {
  const script = releaseFixture(dir);
  const file = join(dir, ".release.json");
  let inode;
  for (const origin of ["deploy", "rollback"]) {
    success(execute("bash", [script, file, origin]));
    const metadata = statSync(file);
    assert.equal(metadata.mode & 0o777, 0o640);
    assert.equal(metadata.gid, statSync(dir).gid);
    assert.notEqual(metadata.ino, inode);
    inode = metadata.ino;
    assert.equal(JSON.parse(readFileSync(file, "utf8")).origem, origin);
    chmodSync(file, 0o600);
  }
}));
test("carimbo symlink é recusado sem sobrescrever seu alvo", () => temporary((dir) => {
  const script = releaseFixture(dir);
  const target = join(dir, "sentinela");
  writeFileSync(target, "preservar");
  symlinkSync(target, join(dir, ".release.json"));
  const result = execute("bash", [script, join(dir, ".release.json"), "deploy"]);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /symlink/);
  assert.equal(readFileSync(target, "utf8"), "preservar");
  assert.deepEqual(readdirSync(dir).filter((name) => name.includes(".tmp.")), []);
}));
function fpmFixture(dir) {
  const input = join(dir, "environment");
  const output = join(dir, "fpm.environment");
  let contents = readFileSync(join(root, ".env.example"), "utf8");
  contents = contents.replace(/^REDIS_HOST=.*$/m, "REDIS_HOST=redis-sintetico")
    .replace(/^REDIS_PORT=.*$/m, "REDIS_PORT=6399")
    .replace(/^REDIS_PASSWORD=.*$/m, "REDIS_PASSWORD='senha $literal `texto` \\\" fim'");
  writeFileSync(input, contents);
  const args = [join(root, "scripts/deploy/preparar_fpm.sh"), `--entrada=${input}`, `--saida=${output}`];
  return { input, output, args };
}
test("FPM recebe aliases privados do mesmo ambiente, sem herdar Redis de outro bot", () => temporary((dir) => {
  const { output, args } = fpmFixture(dir);
  const result = execute("bash", args, { env: { ...process.env, REDIS_PORT: "1111", REDIS_PASSWORD: "nao-herdar" } });
  success(result);
  const text = readFileSync(output, "utf8");
  assert.match(text, new RegExp(`^${prefix}_FPM_REDIS_PORT="6399"$`, "m"));
  assert.match(text, new RegExp(`^${prefix}_FPM_REDIS_HOST="redis-sintetico"$`, "m"));
  assert.ok(text.includes("$literal `texto`"));
  assert.ok(!text.includes("nao-herdar"));
  assert.ok(!result.stdout.includes("senha"));
  assert.equal(statSync(output).mode & 0o777, 0o600);
  assert.ok(text.split("\n").filter((line) => line && !line.startsWith("#")).every((line) => line.startsWith(prefix + "_FPM_")));
  const pool = readFileSync(join(root, `deploy/php-fpm/${bot}.conf`), "utf8");
  for (const [, alias] of pool.matchAll(/^env\[[^\]]+\]\s*=\s*\$(\w+)$/gm)) {
    assert.ok(text.includes(alias + "="), `alias sem valor: ${alias}`);
    assert.ok(alias.startsWith(prefix + "_FPM_"));
  }
  success(execute("bash", [...args, "--verificar"]));
  chmodSync(output, 0o644);
  assert.notEqual(execute("bash", [...args, "--verificar"]).status, 0);
}));
test("mudança no ambiente exige nova preparação e falha de validação preserva o anterior", () => temporary((dir) => {
  const { input, output, args } = fpmFixture(dir);
  success(execute("bash", args));
  const before = readFileSync(output, "utf8");
  writeFileSync(input, readFileSync(input, "utf8").replace("6399", "6400"));
  assert.notEqual(execute("bash", [...args, "--verificar"]).status, 0);
  assert.equal(readFileSync(output, "utf8"), before);
  writeFileSync(input, "CONTRATO_INVALIDO=1\n");
  assert.notEqual(execute("bash", args).status, 0);
  assert.equal(readFileSync(output, "utf8"), before);
}));
test("FPM recusa destino symlink e não sobrescreve entrada", () => temporary((dir) => {
  const { input, output, args } = fpmFixture(dir);
  const before = readFileSync(input, "utf8");
  symlinkSync(input, output);
  assert.notEqual(execute("bash", args).status, 0);
  assert.equal(readFileSync(input, "utf8"), before);
}));
test("instalador macOS prepara dependências dos hooks e fixa PHP 8.5", () => {
  const installer = readFileSync(join(root, "scripts/install/instalar_macos.sh"), "utf8");
  assert.match(installer, /brew install php@8\.5 /);
  assert.ok(!installer.includes("install --no-dev"));
  assert.ok(installer.includes('php "$composer_path" install -d tools/phpcpd --no-interaction'));
  assert.ok(installer.indexOf('php "$composer_path" install --no-interaction') < installer.indexOf("    npm ci"));
});

test("Semgrep seleciona Python compatível antes de criar o venv", () => {
  const workflow = readFileSync(join(root, ".github/workflows/security.yml"), "utf8");
  const job = workflow.slice(workflow.indexOf("\n  semgrep:\n"));
  const setup = job.indexOf("uses: actions/setup-python@6d4fd22b20465ccc0f7354476588e8df7c9ca0e0");
  const venv = job.indexOf('python3 -m venv "${RUNNER_TEMP}/semgrep"');
  assert.ok(setup >= 0 && venv > setup, "setup-python precisa preceder a criação do venv");
  assert.match(job.slice(setup, venv), /python-version: ['"]3\.14['"]/);
  assert.match(job, /SEMGREP_VERSION: ['"]1\.182\.1['"]/);
  assert.ok(job.includes('test "$(semgrep --version)" = "${SEMGREP_VERSION}"'));
});
