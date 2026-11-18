import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtempSync, realpathSync, mkdirSync, readFileSync, writeFileSync, cpSync, chmodSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, basename } from 'node:path';
import { spawnSync } from 'node:child_process';
const root = resolve(import.meta.dirname, '../..');
const files = ['rollback.sh', 'deploy_verify.sh', 'registrar_publicacao.sh', 'publicacao_operacao.sh', 'publicacao_agendamentos.sh', 'publicacao_processos.php', 'publicacao_recuperacao.sh', 'publicacao_bundle.php', 'recuperar_publicacao.sh'];
function fixture(body) {
  const dir = realpathSync(mkdtempSync(join(tmpdir(), 'bundle-fixture-')));
  const locks = join(dir, 'privado'); const projects = join(dir, 'checkouts'); const bot = basename(root);
  const project = join(projects, bot); mkdirSync(locks, { mode: 0o700 }); mkdirSync(join(project, 'scripts/deploy'), { recursive: true }); mkdirSync(join(project, 'scripts/quality'), { recursive: true });
  for (const file of files) cpSync(join(root, 'scripts/deploy', file), join(project, 'scripts/deploy', file));
  cpSync(join(root, 'scripts/quality/validar_sonda_publicada.php'), join(project, 'scripts/quality/validar_sonda_publicada.php'));
  const manager = join(project, 'scripts/deploy/publicacao_bundle.php');
  writeFileSync(manager, readFileSync(manager, 'utf8').replace("const RAIZ_BUNDLES = '/var/lib/chatbots-publicacao';", `const RAIZ_BUNDLES = '${locks}';`).replace("const RAIZ_CHECKOUTS = '/var/www';", `const RAIZ_CHECKOUTS = '${projects}';`).replace('const UID_BUNDLE = 0;', `const UID_BUNDLE = ${process.getuid()};`));
  const run = (...args) => spawnSync('php', [manager, ...args], { encoding: 'utf8' });
  try { body({ dir, locks, project, bot, run, bundle: join(locks, bot + '.recuperacao') }); } finally { rmSync(dir, { recursive: true, force: true }); }
}
const a = '1'.repeat(40); const b = '2'.repeat(40);
function create(f) { const r = f.run('criar', f.bot, f.project, b, a, 'rollback', f.project); assert.equal(r.status, 0, r.stderr); }
test('bundle só retoma operação incompleta e preserva SHA de origem/alvo', () => fixture((f) => {
  create(f); assert.equal(f.run('validar', f.bot).status, 1);
  assert.equal(f.run('estado', f.bot, 'incompleta').status, 0);
  const r = f.run('validar', f.bot); assert.equal(r.status, 0, r.stderr);
  assert.equal(r.stdout, `${f.project}\n${b}\n${a}\nrollback\n`);
  const manifest = JSON.parse(readFileSync(join(f.bundle, 'manifesto.json'), 'utf8'));
  assert.equal(Object.keys(manifest.arquivos).length, 10);
  assert.ok(Object.keys(manifest.arquivos).every((file) => !/environment|config\.php|\.env/.test(file)));
  assert.equal(f.run('criar', f.bot, f.project, b, a, 'rollback', f.project).status, 1);
}));
for (const alteration of ['conteúdo', 'permissões', 'alvo', 'caminho']) {
  test(`bundle recusa divergência de ${alteration}`, () => fixture((f) => {
    create(f); assert.equal(f.run('estado', f.bot, 'incompleta').status, 0);
    const file = join(f.bundle, 'scripts/deploy/rollback.sh');
    if (alteration === 'conteúdo') writeFileSync(file, 'adulterado');
    if (alteration === 'permissões') chmodSync(file, 0o755);
    if (['alvo', 'caminho'].includes(alteration)) {
      const path = join(f.bundle, 'manifesto.json'); const data = JSON.parse(readFileSync(path, 'utf8'));
      if (alteration === 'alvo') data.alvo = '--malformado'; else data.projeto += '/..';
      writeFileSync(path, JSON.stringify(data));
    }
    assert.equal(f.run('validar', f.bot).status, 1);
  }));
}
test('destino legado sem helpers novos preserva ferramentas e retomada por bundle', () => fixture((f) => {
  create(f); assert.equal(f.run('estado', f.bot, 'incompleta').status, 0);
  for (const file of files) if (file !== 'publicacao_bundle.php') rmSync(join(f.project, 'scripts/deploy', file));
  assert.equal(f.run('validar', f.bot).status, 0);
  assert.ok(readFileSync(join(f.bundle, 'scripts/deploy/rollback.sh'), 'utf8').includes('--retomar-incompleto'));
}));
test('bundle concluído permite nova operação sem sobrescrever ferramenta incompleta', () => fixture((f) => {
  create(f); assert.equal(f.run('estado', f.bot, 'concluida').status, 0); create(f);
  assert.equal(f.run('validar', f.bot).status, 1);
}));
