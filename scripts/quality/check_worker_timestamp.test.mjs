import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const source = readFileSync(new URL('../deploy/deploy_verify.sh', import.meta.url), 'utf8');
const start = source.indexOf('if [[ -n "$worker_process_pattern" ]]; then');
const end = source.indexOf('# Compatibilidade para bot', start);
assert.ok(start > 0 && end > start);
const workerCheck = source.slice(start, end);

function check({ zone = 'America/Sao_Paulo', utc = 'Tue Sep 8 10:58:40 2026', local = 'Tue Sep 8 07:58:40 2026' } = {}) {
  const dir = mkdtempSync(join(tmpdir(), 'worker-timestamp-'));
  const bin = join(dir, 'bin');
  mkdirSync(bin);
  const mock = (name, body) => writeFileSync(join(bin, name), '#!/bin/bash\n' + body, { mode: 0o755 });
  mock('pgrep', 'echo 123\n');
  mock('ps', 'if [[ "$TZ" == UTC && "$LC_ALL" == C ]]; then echo "$MOCK_UTC"; else echo "$MOCK_LOCAL"; fi\n');
  // Converte o mesmo formato inglês sem depender de GNU date no macOS.
  const dateJs = join(dir, 'date.mjs');
  writeFileSync(dateJs, `const value = Date.parse(process.argv[2] + ' UTC');
if (!Number.isFinite(value)) process.exit(1);
console.log(Math.floor(value / 1000));\n`);
  mock('date', 'exec "$NODE_BIN" "$MOCK_DATE_JS" "$3"\n');
  const driver = `set -euo pipefail
worker_process_pattern=/fake/worker.php
worker_screen=''
publicado_epoch=1788865117
falhas=0
aprovar() { echo "ok: $*"; }
reprovar() { echo "falha: $*"; falhas=$((falhas + 1)); }
` + workerCheck + '\nexit "$falhas"\n';
  try {
    return spawnSync('/bin/bash', ['-s'], {
      input: driver,
      encoding: 'utf8',
      env: {
        ...process.env, PATH: bin + ':' + process.env.PATH,
        TZ: zone, LC_ALL: 'C', MOCK_UTC: utc, MOCK_LOCAL: local,
        NODE_BIN: process.execPath, MOCK_DATE_JS: dateJs,
      },
    });
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}

test('worker novo em São Paulo é posterior ao carimbo UTC', () => {
  const result = check();
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /depois da publicação/);
});

test('fuso positivo também não altera a idade do processo', () => {
  const result = check({ zone: 'Asia/Tokyo', local: 'Tue Sep 8 19:58:40 2026' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.match(result.stdout, /10:58:40/);
});

test('worker realmente anterior à publicação continua sendo recusado', () => {
  const result = check({ utc: 'Tue Sep 8 10:58:30 2026' });
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stdout, /ANTES da publicação/);
});

test('data do processo ilegível continua sendo recusada', () => {
  const result = check({ utc: 'indisponível' });
  assert.equal(result.status, 1, result.stdout + result.stderr);
  assert.match(result.stdout, /não foi possível provar/);
});
