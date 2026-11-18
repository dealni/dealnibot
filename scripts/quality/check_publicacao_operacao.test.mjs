import assert from 'node:assert/strict';
import { mkdtempSync, realpathSync, mkdirSync, writeFileSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync, spawn } from 'node:child_process';
import test from 'node:test';

const helper = readFileSync(new URL('../deploy/publicacao_operacao.sh', import.meta.url), 'utf8');
const agendas = readFileSync(new URL('../deploy/publicacao_agendamentos.sh', import.meta.url), 'utf8');
const wrapper = readFileSync(new URL('../deploy/publicar.sh', import.meta.url), 'utf8');
const oldSha = '1'.repeat(40);
const newSha = '2'.repeat(40);
const phpBinary = spawnSync('php', ['-r', 'echo PHP_BINARY;'], { encoding: 'utf8' }).stdout;

function fixture() {
  const dir = realpathSync(mkdtempSync(join(tmpdir(), 'publicacao-operacao-')));
  const bin = join(dir, 'bin');
  const repo = join(dir, 'bot');
  const locks = join(dir, 'locks');
  const cron = join(dir, 'cron');
  mkdirSync(cron);
  const bot = wrapper.match(/bot_slug="([^"]+)"/)[1];
  writeFileSync(join(cron, bot), '* * * * * bot /fake/command\n');
  mkdirSync(bin); mkdirSync(locks, { mode: 0o700 });
  mkdirSync(join(repo, 'scripts/deploy'), { recursive: true });
  const mock = (name, body) => writeFileSync(join(bin, name), '#!/bin/bash\nset -e\n' + body, { mode: 0o755 });
  mock('id', 'echo 0\n');
  mock('realpath', 'cd "${@: -1}" && pwd -P\n');
  mock('stat', `if [[ "$2" == '%u:%g' ]]; then echo 0:0; exit; fi\nif [[ "$2" == '%d:%i' ]]; then exec python3 -c 'import os,sys; s=os.fstat(9) if sys.argv[1].startswith("/proc/") else os.stat(sys.argv[1]);print(str(s.st_dev)+":"+str(s.st_ino))' "\${@: -1}"; fi\nif [[ "$BAD_MODE" == 1 ]]; then echo 0:0:777; elif [[ -d "\${@: -1}" ]]; then echo 0:0:700; else echo 0:0:600; fi\n`);
  // flock real no descritor herdado; disponível também no macOS via fcntl.
  mock('flock', `exec python3 -c 'import fcntl,sys
try: fcntl.flock(9,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError: sys.exit(1)
import os
if os.environ.get("SWAP_LOCK")=="1":
 p=os.environ["LOCK_TARGET"];os.unlink(p);open(p,"w").close()'\n`);
  mock('git', `echo "git $*" >> "$EVENTS"
case "$*" in
 *'rev-parse --show-toplevel'*) echo "$MOCK_REPO";;
 *'rev-parse HEAD'*) cat "$HEAD_FILE";;
 *'rev-parse --verify'*|*'rev-parse refs/remotes/origin/main'*) echo "$NEW_SHA";;
 *'branch --show-current'*) echo "\${MOCK_BRANCH-main}";;
 *'status --porcelain'*) ;;
 'fetch '*) [[ "$FAIL_STEP" != fetch ]];;
 'merge --ff-only '*) if [[ "$FAIL_STEP" == merge ]]; then exit 1; fi; echo "$NEW_SHA" > "$HEAD_FILE";;
 'merge-base --is-ancestor main '*) [[ "$MAIN_DIVERGED" != 1 ]];;
 'switch main') echo "$MAIN_SHA" > "$HEAD_FILE";;
 *) ;;
esac\n`);
  mock('systemctl', `echo "systemctl $*" >> "$EVENTS"
case "$1" in
 is-active) [[ "\${@: -1}" != "$MISSING_TIMER" && ! -f "$STOPPED" ]];;
 list-units) if [[ "$TIMER_EXTRA" == 1 ]]; then echo extra.timer loaded active; fi;;
 show) if [[ "$*" == *LoadState* ]]; then if [[ "\${@: -1}" == "$MISSING_TIMER" ]]; then echo not-found; elif [[ "$FPM_MISSING" == 1 ]]; then echo not-found; else echo loaded; fi; elif [[ "$*" == *Triggers* ]]; then echo extra.service; elif [[ "$*" == *ExecStart* ]]; then echo "$MOCK_REPO/scripts/job.php"; elif [[ "$*" == *WorkingDirectory* ]]; then echo "$MOCK_REPO"; elif [[ -f "$STOPPED" ]]; then echo inactive; else echo active; fi;;
 stop) touch "$STOPPED"; [[ "$FAIL_STEP" != stop ]];;
 start) rm -f "$STOPPED";;
esac\n`);
  writeFileSync(join(repo, 'scripts/deploy/publicacao_operacao.sh'), helper.replace('/var/lib/chatbots-publicacao', locks));
  writeFileSync(join(repo, 'scripts/deploy/publicacao_agendamentos.sh'), agendas.replaceAll('/var/lib/chatbots-publicacao', locks).replaceAll('/etc/cron.d', cron));
  writeFileSync(join(repo, 'scripts/deploy/publicacao_processos.php'), '<?php echo 0;');
  writeFileSync(join(repo, 'scripts/deploy/publicacao_recuperacao.sh'), readFileSync(new URL('../deploy/publicacao_recuperacao.sh', import.meta.url), 'utf8').replaceAll('/var/lib/chatbots-publicacao', locks));
  writeFileSync(join(repo, 'scripts/deploy/publicacao_bundle.php'), '<?php');
  // Stub explícito: estes testes nunca instalam pacotes nem consultam a rede.
  writeFileSync(join(repo, 'scripts/deploy/publicacao_dependencias.sh'), `
publicacao_preparar_dependencias() {
  echo "preparo $*" >> "$EVENTS"
  [[ ! -f "$STOPPED" ]] || return 9
  composer install --no-dev --no-plugins --no-scripts || return 1
  publicacao_dependencias_dir="$MOCK_LOCKS/$DEPLOY_CONFIRM.dependencias.fixture"
}
publicacao_limpar_dependencias() { echo limpeza_preparo >> "$EVENTS"; }
`);
  mock('composer', 'echo "composer $*" >> "$EVENTS"\n[[ "$FAIL_STEP" != dependencias ]]\n');
  mock('sleep', 'echo "sleep $*" >> "$EVENTS"\n');
  mock('php', 'if [[ "$1" == -r ]]; then exec "$PHP_REAL" "$@"; fi\necho "php $*" >> "$EVENTS"\n[[ -f "$1" ]] || exit 1\nif [[ "$2" == criar ]]; then mkdir -p "$MOCK_LOCKS/$DEPLOY_CONFIRM.recuperacao/scripts/deploy"; touch "$MOCK_LOCKS/$DEPLOY_CONFIRM.recuperacao/scripts/deploy/publicacao_bundle.php"; fi\necho "${BUSY:-0}"\n');
  writeFileSync(join(repo, 'scripts/deploy/publicar.sh'), wrapper.replace(/expected_dir="[^"]+"/, `expected_dir="${repo}"`));
  for (const script of ['deploy', 'deploy_verify']) {
    writeFileSync(join(repo, `scripts/deploy/${script}.sh`), `#!/bin/bash\necho ${script} >> "$EVENTS"\necho "${script}_sem_backup=\${DEPLOY_SEM_BACKUP:-0}" >> "$EVENTS"\n[[ "$FAIL_STEP" != ${script} ]]\n`, { mode: 0o755 });
  }
  writeFileSync(join(repo, 'scripts/deploy/db_backup.sh'), `#!/bin/bash\necho "backup $* head=$(cat "$HEAD_FILE")" >> "$EVENTS"\n[[ ! -f "$STOPPED" ]] || exit 9\n[[ "$FAIL_STEP" != backup ]]\n`, { mode: 0o755 });
  const head = join(dir, 'head'); writeFileSync(head, oldSha + '\n');
  const events = join(dir, 'events'); writeFileSync(events, '');
  return { dir, repo, locks, cron, bot, events, env: { ...process.env, PATH: bin + ':' + process.env.PATH, DEPLOY_CONFIRM: wrapper.match(/bot_slug="([^"]+)"/)[1], EVENTS: events, PHP_REAL: phpBinary, DEPLOY_SEM_BACKUP: '0', MAIN_DIVERGED: "", MAIN_SHA: "3".repeat(40), MOCK_LOCKS: locks, HEAD_FILE: head, NEW_SHA: newSha, MOCK_REPO: repo, STOPPED: join(dir, 'stopped'), BAD_MODE: '', FAIL_STEP: '', TIMER_EXTRA: '', BUSY: '0', FPM_MISSING: '', MISSING_TIMER: '' } };
}

for (const step of ['', 'fetch', 'dependencias', 'backup', 'merge', 'stop', 'deploy', 'deploy_verify']) {
  test(`publicador: ${step || 'sucesso'} preserva a ordem e trata indisponibilidade`, () => {
    const f = fixture();
    try {
      const result = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { encoding: 'utf8', env: { ...f.env, FAIL_STEP: step } });
      const events = readFileSync(f.events, 'utf8');
      assert.equal(result.status, step ? 1 : 0, result.stdout + result.stderr);
      if (['fetch', 'dependencias', 'backup'].includes(step)) {
        assert.doesNotMatch(events, /systemctl (?:stop|start)|sleep |git (?:merge --ff-only|switch main)/);
        assert.ok(readFileSync(join(f.cron, f.bot), 'utf8').includes('/fake/command'));
        assert.doesNotMatch(result.stderr, /disponibilidade anterior restaurada|PUBLICAÇÃO INCOMPLETA/);
      }
      if (['merge', 'stop'].includes(step)) {
        assert.match(events, /systemctl start/);
        assert.match(result.stderr, /disponibilidade anterior restaurada/);
      }
      if (['deploy', 'deploy_verify'].includes(step)) assert.match(result.stderr, /PUBLICAÇÃO INCOMPLETA/);
      if (!step) {
        assert.ok(events.indexOf('composer install') < events.indexOf('backup '));
        assert.ok(events.indexOf('composer install') < events.indexOf('systemctl stop'));
        assert.equal((events.match(/^backup /gm) || []).length, 1);
        assert.match(events, new RegExp(`backup --rotulo=pre-deploy head=${oldSha}`));
        assert.ok(events.indexOf('backup ') < events.indexOf('sleep 65'));
        assert.ok(events.indexOf('backup ') < events.indexOf('systemctl stop'));
        assert.ok(events.indexOf('sleep 65') < events.indexOf('git merge --ff-only'));
        assert.match(events, /deploy_sem_backup=1/);
        assert.match(events, /sleep 65/);
        assert.ok(readFileSync(join(f.cron, f.bot), 'utf8').includes('/fake/command'));
        assert.ok(events.indexOf('systemctl stop') < events.indexOf('git merge --ff-only'));
        assert.ok(events.indexOf('deploy\n') < events.indexOf('systemctl start'));
        assert.ok(events.indexOf('systemctl start') < events.indexOf('deploy_verify'));
      }
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  });
}

test('sem confirmação não chama Git nem systemctl', () => {
  const f = fixture();
  try {
    const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, DEPLOY_CONFIRM: '' }, encoding: 'utf8' });
    assert.equal(r.status, 2); assert.equal(readFileSync(f.events, 'utf8'), '');
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('lock rejeita modo inseguro sem confiar em marcador de ambiente', () => {
  const f = fixture();
  try {
    const r = spawnSync('/bin/bash', ['-c', `source "$HELPER"; publicacao_adquirir_lock bot "$LOCKS"`], { env: { ...f.env, HELPER: join(f.repo, 'scripts/deploy/publicacao_operacao.sh'), LOCKS: f.locks, BAD_MODE: '1', PUBLICACAO_LOCK_HELD: '1' }, encoding: 'utf8' });
    assert.equal(r.status, 1); assert.match(r.stderr, /0700/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('lock exclui outra operação e aceita descritor herdado do mesmo inode', async () => {
  const f = fixture();
  const env = { ...f.env, HELPER: join(f.repo, 'scripts/deploy/publicacao_operacao.sh'), LOCKS: f.locks };
  let child;
  try {
    child = spawn('/bin/bash', ['-c', 'set -e; source "$HELPER"; publicacao_adquirir_lock bot "$LOCKS"; bash -c \'source "$HELPER"; publicacao_adquirir_lock bot "$LOCKS"\'; echo adquirido; read -r fim'], { env, stdio: ['pipe', 'pipe', 'pipe'] });
    await new Promise((resolve, reject) => {
      child.stdout.once('data', resolve);
      child.once('exit', code => { if (code !== null) reject(new Error('Primeiro lock falhou: ' + code)); });
    });
    const second = spawnSync('/bin/bash', ['-c', 'source "$HELPER"; publicacao_adquirir_lock bot "$LOCKS"'], { env, encoding: 'utf8' });
    assert.equal(second.status, 1); assert.match(second.stderr, /andamento/);
    child.stdin.end('fim\n');
    await new Promise(resolve => child.once('exit', resolve));
  } finally {
    child?.kill(); rmSync(f.dir, { recursive: true, force: true });
  }
});

for (const kind of ['cron extra', 'cron relativo', 'timer extra', 'job sem drenar']) {
  test(`agendamento: ${kind} recusa checkout e preserva agenda anterior`, () => {
    const f = fixture();
    try {
      if (kind === 'cron relativo') writeFileSync(join(f.cron, 'extra'), '* * * * * root cd /var/www; php ' + f.bot + '/scripts/job.php\n');
      if (kind === 'cron extra') writeFileSync(join(f.cron, 'extra'), '* * * * * bot ' + f.repo + '/scripts/job.php\n');
      const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { encoding: 'utf8', env: { ...f.env, TIMER_EXTRA: kind === 'timer extra' ? '1' : '', BUSY: kind === 'job sem drenar' ? '1' : '0' } });
      assert.equal(r.status, 1, r.stdout + r.stderr);
      assert.doesNotMatch(readFileSync(f.events, 'utf8'), /git merge --ff-only/);
      assert.ok(readFileSync(join(f.cron, f.bot), 'utf8').includes('/fake/command'));
      assert.match(r.stderr, kind === 'job sem drenar' ? /não drenaram/ : /não (?:coberto|inventariado)/);
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  });
}

test('primeira implantação drena antes de alterar e scanner sobrevive a checkout legado', () => {
  const f = fixture();
  try {
    const script = 'set -euo pipefail; cd "$MOCK_REPO"; project_dir="$MOCK_REPO"; source scripts/deploy/publicacao_operacao.sh; publicacao_timers=(); publicacao_exige_cron=1; publicacao_adquirir_lock "$BOT" "$LOCKS"; publicacao_definir_servicos "$BOT"; publicacao_parar_servicos; rm scripts/deploy/publicacao_operacao.sh scripts/deploy/publicacao_agendamentos.sh scripts/deploy/publicacao_processos.php; publicacao_contar_processos; publicacao_iniciar_servicos; publicacao_restaurar_agendamentos';
    const r = spawnSync('/bin/bash', ['-c', script], { env: { ...f.env, BOT: f.bot, LOCKS: f.locks }, encoding: 'utf8' });
    assert.equal(r.status, 0, r.stdout + r.stderr);
    assert.ok(readFileSync(join(f.cron, f.bot), 'utf8').includes('/fake/command'));
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('publicação recusa fallback para FPM compartilhado', () => {
  const f = fixture();
  try {
    const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, FPM_MISSING: '1' }, encoding: 'utf8' });
    assert.equal(r.status, 1); assert.match(r.stderr, /mestre FPM dedicado/);
    assert.doesNotMatch(readFileSync(f.events, 'utf8'), /systemctl stop/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('troca do inode depois do flock reprova a operação', () => {
  const f = fixture();
  try {
    const r = spawnSync('/bin/bash', ['-c', 'source "$HELPER"; publicacao_adquirir_lock bot "$LOCKS"'], { env: { ...f.env, HELPER: join(f.repo, 'scripts/deploy/publicacao_operacao.sh'), LOCKS: f.locks, SWAP_LOCK: '1', LOCK_TARGET: join(f.locks, 'bot.lock') }, encoding: 'utf8' });
    assert.equal(r.status, 1); assert.match(r.stderr, /mudou durante/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('timer inventariado volta somente depois de pronta a publicação', () => {
  const f = fixture();
  try {
    const path = join(f.repo, 'scripts/deploy/publicar.sh');
    writeFileSync(path, readFileSync(path, 'utf8').replace(/publicacao_timers=\([^)]*\)/, 'publicacao_timers=(extra.timer)'));
    const r = spawnSync('/bin/bash', [path, '--esperado=' + newSha], { env: { ...f.env, TIMER_EXTRA: '1' }, encoding: 'utf8' });
    assert.equal(r.status, 0, r.stdout + r.stderr);
    const events = readFileSync(f.events, 'utf8');
    assert.ok(events.indexOf('systemctl stop extra.timer') < events.indexOf('git merge --ff-only'));
    assert.ok(events.indexOf('deploy_verify') < events.indexOf('systemctl start extra.timer'));
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('bootstrap privado usa checkout explícito antigo sem atualizar fora do lock', () => {
  const f = fixture();
  try {
    const tools = join(f.dir, 'bootstrap/scripts/deploy');
    mkdirSync(tools, { recursive: true });
    for (const name of ['publicar.sh', 'publicacao_operacao.sh', 'publicacao_agendamentos.sh', 'publicacao_processos.php', 'publicacao_recuperacao.sh', 'publicacao_bundle.php', 'publicacao_dependencias.sh']) {
      writeFileSync(join(tools, name), readFileSync(join(f.repo, 'scripts/deploy', name)));
    }
    for (const name of ['publicacao_operacao.sh', 'publicacao_agendamentos.sh', 'publicacao_processos.php']) rmSync(join(f.repo, 'scripts/deploy', name));
    const r = spawnSync('/bin/bash', [join(tools, 'publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, RELEASE_PROJECT_DIR: f.repo }, encoding: 'utf8' });
    assert.equal(r.status, 0, r.stdout + r.stderr);
    const events = readFileSync(f.events, 'utf8');
    assert.ok(events.indexOf('systemctl stop') < events.indexOf('git merge --ff-only'));
    assert.match(events, /sleep 65/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

for (const divergent of [false, true]) {
  test(`publicação após rollback em detached ${divergent ? 'recusa main divergente' : 'troca main somente depois da drenagem'}`, () => {
    const f = fixture();
    try {
      writeFileSync(join(f.repo, '.release.json'), JSON.stringify({ origem: 'rollback', commit: oldSha, checkout: f.repo, bot: f.bot }));
      const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, MOCK_BRANCH: '', MAIN_DIVERGED: divergent ? '1' : '' }, encoding: 'utf8' });
      const events = readFileSync(f.events, 'utf8');
      assert.equal(r.status, divergent ? 1 : 0, r.stdout + r.stderr);
      if (divergent) assert.doesNotMatch(events, /systemctl stop/);
      else {
        assert.ok(events.indexOf('systemctl stop') < events.indexOf('git switch main'));
        assert.ok(events.indexOf('git switch main') < events.indexOf('git merge --ff-only'));
        assert.match(events, new RegExp(`${newSha} ${oldSha} deploy`));
      }
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  });
}
test('detached sem carimbo correspondente recusa antes de parar runtime', () => {
  const f = fixture();
  try {
    writeFileSync(join(f.repo, '.release.json'), JSON.stringify({ origem: 'rollback', commit: newSha, checkout: f.repo, bot: f.bot }));
    const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, MOCK_BRANCH: '' }, encoding: 'utf8' });
    assert.equal(r.status, 1); assert.doesNotMatch(readFileSync(f.events, 'utf8'), /systemctl stop/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

test('falha anterior à troca preserva suspensão herdada de operação incompleta', () => {
  const f = fixture();
  try {
    const cronPath = join(f.cron, f.bot);
    const suspended = join(f.locks, f.bot + '.cron.suspenso');
    writeFileSync(suspended, readFileSync(cronPath)); rmSync(cronPath);
    writeFileSync(join(f.locks, f.bot + '.timers.suspensos'), '', { mode: 0o600 });
    const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, FAIL_STEP: 'fetch' }, encoding: 'utf8' });
    assert.equal(r.status, 1);
    assert.ok(readFileSync(suspended, 'utf8').includes('/fake/command'));
    assert.doesNotMatch(readFileSync(f.events, 'utf8'), /systemctl start/);
    assert.equal(readFileSync(join(f.locks, f.bot + '.timers.suspensos'), 'utf8'), '');
    assert.doesNotMatch(r.stderr, /disponibilidade anterior restaurada/);
  } finally { rmSync(f.dir, { recursive: true, force: true }); }
});

for (const optional of [true, false]) {
  test(`timer ausente ${optional ? 'opcional é ignorado' : 'obrigatório recusa antes de parar'}`, () => {
    const f = fixture();
    try {
      const path = join(f.repo, 'scripts/deploy/publicar.sh');
      let text = readFileSync(path, 'utf8').replace(/publicacao_timers=\([^)]*\)/, 'publicacao_timers=(extra.timer)');
      if (optional) text = text.replace(/publicacao_timers_opcionais=\([^)]*\)/, 'publicacao_timers_opcionais=(extra.timer)');
      writeFileSync(path, text);
      const r = spawnSync('/bin/bash', [path, '--esperado=' + newSha], { env: { ...f.env, MISSING_TIMER: 'extra.timer' }, encoding: 'utf8' });
      assert.equal(r.status, optional ? 0 : 1, r.stdout + r.stderr);
      const events = readFileSync(f.events, 'utf8');
      assert.doesNotMatch(events, /systemctl stop extra.timer/);
      if (!optional) {
        assert.match(r.stderr, /Timer obrigatório ausente/);
        assert.doesNotMatch(events, /systemctl stop/);
        assert.doesNotMatch(events, /git merge --ff-only/);
      }
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  });
}

for (const skip of ['1', '2']) {
  test(`backup antecipado: dispensa somente com DEPLOY_SEM_BACKUP=1 (valor ${skip})`, () => {
    const f = fixture();
    try {
      const r = spawnSync('/bin/bash', [join(f.repo, 'scripts/deploy/publicar.sh'), '--esperado=' + newSha], { env: { ...f.env, DEPLOY_SEM_BACKUP: skip }, encoding: 'utf8' });
      assert.equal(r.status, 0, r.stdout + r.stderr);
      const events = readFileSync(f.events, 'utf8');
      assert.equal((events.match(/^backup /gm) || []).length, skip === '1' ? 0 : 1);
      assert.match(events, /deploy_sem_backup=1/);
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  });
}

test('preparador direto mantém backup próprio e respeita dispensa explícita', () => {
  const source = readFileSync(new URL('../deploy/deploy.sh', import.meta.url), 'utf8');
  const block = source.match(/if \[\[ "\$\{DEPLOY_SEM_BACKUP:-0\}" != "1" \]\]; then\n    bash scripts\/deploy\/db_backup\.sh --rotulo=pre-deploy\nfi/);
  assert.ok(block, 'O preparo direto precisa manter a cópia padrão.');
  for (const skip of ['0', '1']) {
    const f = fixture();
    try {
      const r = spawnSync('/bin/bash', ['-c', block[0]], { cwd: f.repo, env: { ...f.env, DEPLOY_SEM_BACKUP: skip }, encoding: 'utf8' });
      assert.equal(r.status, 0, r.stdout + r.stderr);
      assert.equal((readFileSync(f.events, 'utf8').match(/^backup /gm) || []).length, skip === '1' ? 0 : 1);
    } finally { rmSync(f.dir, { recursive: true, force: true }); }
  }
});
