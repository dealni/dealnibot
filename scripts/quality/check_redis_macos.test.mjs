import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, existsSync, rmSync, symlinkSync, realpathSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
const bot = 'dealnibot';
const port = '6380';
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

const script = fileURLToPath(new URL('../install/iniciar_redis_macos.sh', import.meta.url));

function scenario(run) {
  const dir = mkdtempSync(join(tmpdir(), 'redis-macos-test-'));
  const bin = join(dir, 'bin');
  mkdirSync(bin);
  const command = (name, body) => writeFileSync(join(bin, name), '#!/bin/bash\n' + body, { mode: 0o755 });
  command('uname', 'echo "${MOCK_OS:-Darwin}"\n');
  command('sleep', 'exit 0\n');
  command('redis-server', `printf '%s\\n' "$@" > "$MOCK_ARGS"
echo chamada >> "$MOCK_CALLS"
while (($#)); do
  if [[ "$1" == --dir ]]; then printf '%s\\n' "$2" > "$MOCK_STATE"; break; fi
  shift
done
`);
  command('redis-cli', `[[ "\${MOCK_MODE:-}" != failed ]] || exit 1
[[ -f "$MOCK_STATE" || "\${MOCK_MODE:-}" == foreign ]] || exit 1
if [[ "$*" == *PING ]]; then echo PONG
elif [[ "$*" == *'CONFIG GET dir' ]]; then
  echo dir
  if [[ "\${MOCK_MODE:-}" == foreign ]]; then echo /outra-instancia; else cat "$MOCK_STATE"; fi
fi
`);
  const env = {
    ...process.env,
    PATH: bin + ':' + process.env.PATH,
    CHATBOTS_DEV_STATE_DIR: join(dir, 'estado com espaços'),
    MOCK_STATE: join(dir, 'state'),
    MOCK_ARGS: join(dir, 'args'),
    MOCK_CALLS: join(dir, 'calls'),
  };
  const exec = (extra = {}) => spawnSync('/bin/bash', [script], { env: { ...env, ...extra }, encoding: 'utf8' });
  try { run({ dir, env, exec }); } finally { rmSync(dir, { recursive: true, force: true }); }
}

test('inicia a porta exclusiva com dados separados e reutiliza sem reiniciar', () => scenario(({ env, exec }) => {
  const first = exec();
  assert.equal(first.status, 0, first.stderr);
  const args = readFileSync(env.MOCK_ARGS, 'utf8').trim().split('\n');
  assert.equal(args[args.indexOf('--port') + 1], port);
  assert.equal(args[args.indexOf('--bind') + 1], '127.0.0.1');
  assert.equal(args[args.indexOf('--dir') + 1], realpathSync(join(env.CHATBOTS_DEV_STATE_DIR, bot, 'redis')));
  assert.equal(exec().status, 0);
  assert.equal(readFileSync(env.MOCK_CALLS, 'utf8'), 'chamada\n');
}));

test('recusa porta de outra instância sem iniciar ou encerrar serviço', () => scenario(({ env, exec }) => {
  const result = exec({ MOCK_MODE: 'foreign' });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /outra instância/);
  assert.equal(existsSync(env.MOCK_CALLS), false);
}));

test('recusa estado apontado por symlink', () => scenario(({ dir, env, exec }) => {
  symlinkSync(dir, env.CHATBOTS_DEV_STATE_DIR);
  assert.equal(exec().status, 1);
  assert.equal(existsSync(env.MOCK_CALLS), false);
}));

test('propaga falha quando o servidor não fica pronto', () => scenario(({ exec }) => {
  const result = exec({ MOCK_MODE: 'failed' });
  assert.equal(result.status, 1);
  assert.match(result.stderr, /não iniciou/);
}));

test('recusa outro sistema operacional', () => scenario(({ env, exec }) => {
  assert.equal(exec({ MOCK_OS: 'Linux' }).status, 1);
  assert.equal(existsSync(env.MOCK_CALLS), false);
}));

test('instalador macOS usa a instância exclusiva', () => {
  const installer = readFileSync(new URL('../install/instalar_macos.sh', import.meta.url), 'utf8');
  assert.ok(installer.includes('scripts/install/iniciar_redis_macos.sh'));
  assert.ok(!installer.includes('brew services start redis'));
});
