import assert from "node:assert/strict";
import { test } from "node:test";
import { spawnSync } from "node:child_process";
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync, symlinkSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
const bot = "dealnibot";
const port = "6380";
// Caminhos do host (como em scripts/install/instalar_redis.sh), sem a barra inicial.
const configPath = "etc/dealnibot/redis.conf";
const dataPath = "var/lib/dealnibot/redis";
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

const root = resolve(import.meta.dirname, "../..");
function scenario(run) {
  const dir = mkdtempSync(join(tmpdir(), "bot-redis-linux-"));
  const bin = join(dir, "bin");
  mkdirSync(bin);
  const calls = join(dir, "calls");
  writeFileSync(calls, "");
  const command = (name, body) => writeFileSync(join(bin, name), '#!/bin/bash\n' + body, { mode: 0o755 });
  command("uname", "echo Linux\n");
  command("id", '[[ "${MOCK_MODE:-}" == nouser && $# -gt 1 ]] && exit 1\necho 0\n');
  command("systemctl", `echo "systemctl $*" >> "$MOCK_CALLS"
if [[ "$1" == cat ]]; then [[ "\${MOCK_MODE:-}" == existing ]]; fi
`);
  command("ss", '[[ "${MOCK_MODE:-}" != occupied ]] || echo LISTEN\n');
  command("install", 'echo "install $*" >> "$MOCK_CALLS"\n');
  command("timeout", 'shift; exec "$@"\n');
  command("sleep", 'exit 0\n');
  command("redis-cli", '[[ "${MOCK_MODE:-}" != failed ]] || exit 1\necho PONG\n');
  const config = join(dir, configPath);
  mkdirSync(dirname(config), { recursive: true });
  // Só os destinos de sistema são redirecionados para a fixture; os comandos
  // privilegiados são dublês, mesmo quando o teste roda como root no CI.
  const source = readFileSync(join(root, "scripts/install/instalar_redis.sh"), "utf8")
    .replaceAll("/etc/", dir + "/etc/").replaceAll("/var/lib/", dir + "/var/lib/");
  const script = join(dir, "installer.sh");
  writeFileSync(script, source);
  const exec = (mode = "") => spawnSync("bash", [script], {
    encoding: "utf8", env: { ...process.env, PATH: bin + ":" + process.env.PATH, MOCK_CALLS: calls, MOCK_MODE: mode },
  });
  try { run({ dir, config, calls, exec }); } finally { rmSync(dir, { recursive: true, force: true }); }
}

test("Linux provisiona somente a unidade e os dados exclusivos deste bot", () => scenario(({ calls, exec }) => {
  const result = exec();
  assert.equal(result.status, 0, result.stderr);
  const log = readFileSync(calls, "utf8");
  assert.match(log, new RegExp(String.raw`install .*redis-${bot}\.service`));
  assert.match(log, new RegExp(`install .*${configPath.replaceAll(".", "\\.")}$`, "m"));
  assert.match(log, new RegExp(`install -d .*${dataPath}$`, "m"));
  assert.match(log, new RegExp(String.raw`install .*redis-${bot}\.service\.d/60-isolamento\.conf$`, "m"));
  assert.match(log, new RegExp(String.raw`systemctl enable --now redis-${bot}\.service`));
  assert.doesNotMatch(log, /systemctl (?:stop|restart|disable)|redis-server\.service/);
}));

test("Linux preserva configuração e dados de uma unidade já instalada", () => scenario(({ config, calls, exec }) => {
  writeFileSync(config, "sentinela\n");
  assert.equal(exec("existing").status, 0);
  assert.equal(readFileSync(config, "utf8"), "sentinela\n");
  assert.doesNotMatch(readFileSync(calls, "utf8"), /^install /m);
}));

test("Linux recusa porta ocupada antes de instalar ou iniciar", () => scenario(({ calls, exec }) => {
  const result = exec("occupied");
  assert.equal(result.status, 1);
  assert.match(result.stderr, new RegExp(`Porta ${port} ocupada`));
  assert.doesNotMatch(readFileSync(calls, "utf8"), /install |enable --now/);
}));

test("Linux recusa configuração symlink sem modificar o alvo", () => scenario(({ dir, config, calls, exec }) => {
  const target = join(dir, "sentinela");
  writeFileSync(target, "preservar");
  symlinkSync(target, config);
  assert.equal(exec().status, 1);
  assert.equal(readFileSync(target, "utf8"), "preservar");
  assert.doesNotMatch(readFileSync(calls, "utf8"), /install |enable --now/);
}));

test("Linux recusa instalar sem o usuário que roda a instância", () => scenario(({ calls, exec }) => {
  const result = exec("nouser");
  assert.equal(result.status, 1);
  assert.match(result.stderr, /Usuário .* ausente/);
  assert.doesNotMatch(readFileSync(calls, "utf8"), /install |enable --now/);
}));

test("Linux falha quando a instância não responde", () => scenario(({ exec }) => {
  assert.equal(exec("failed").status, 1);
}));
