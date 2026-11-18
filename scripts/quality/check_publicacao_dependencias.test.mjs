import assert from 'node:assert/strict';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const helper = readFileSync(new URL('../deploy/publicacao_dependencias.sh', import.meta.url), 'utf8');
function run(cwd, args, env = process.env) {
  return spawnSync(args[0], args.slice(1), { cwd, env, encoding: 'utf8' });
}
function git(cwd, ...args) {
  const result = run(cwd, ['git', ...args]);
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}
function fixture(body) {
  const dir = realpathSync(mkdtempSync(join(tmpdir(), 'dependencias-fixture-')));
  const project = join(dir, 'bot');
  const privateDir = join(dir, 'privado');
  const bin = join(dir, 'bin');
  for (const path of [project, privateDir, bin]) mkdirSync(path, { mode: 0o700 });
  const mock = (name, source) => writeFileSync(join(bin, name), '#!/bin/bash\nset -e\n' + source, { mode: 0o755 });
  mock('id', 'echo 0\n');
  // Adapta metadados GNU no macOS; permissões e dispositivos são os da fixture.
  mock('stat', `exec python3 -c 'import os,stat,sys
p=sys.argv[-1];s=os.stat(p);fmt=sys.argv[2]
print("0:0:"+oct(stat.S_IMODE(s.st_mode))[2:] if fmt=="%u:%g:%a" else (s.st_dev+1 if os.environ.get("OTHER_DEVICE")==p else s.st_dev))' "$@"\n`);
  mock('realpath', `exec python3 -c 'import os,sys;print(os.path.realpath(sys.argv[-1]))' "$@"\n`);
  mock('mv', `exec python3 -c 'import os,sys
src,dest=sys.argv[-2:]
if dest==os.environ["PROJECT"]+"/vendor" and ((os.path.basename(src)=="vendor" and os.environ.get("FAIL_RENAME")=="1") or (os.path.basename(src)=="vendor.anterior" and os.environ.get("FAIL_RESTORE")=="1")):sys.exit(1)
os.rename(src,dest)' "$@"\n`);
  mock('composer', `printf '%s|%s|%s|%s\\n' "$PWD" "$*" "$COMPOSER_VENDOR_DIR" "$COMPOSER_BIN_DIR" >> "$EVENTS"
[[ "$FAIL_COMPOSER" != "$1" ]] || exit 1
if [[ "$1" == install ]]; then mkdir -p "$COMPOSER_VENDOR_DIR"; printf '%s' novo > "$COMPOSER_VENDOR_DIR/autoload.php"; fi\n`);
  const shellHelper = join(dir, 'helper.sh');
  writeFileSync(shellHelper, helper.replaceAll('/var/lib/chatbots-publicacao', privateDir));
  mkdirSync(join(project, 'scripts/deploy'), { recursive: true });
  writeFileSync(join(project, 'scripts/deploy/deploy.sh'), '# DEPLOY_DEPENDENCIAS_PREPARADAS v1\n');
  writeFileSync(join(project, 'composer.json'), '{"name":"fixture/bot","require":{}}\n');
  writeFileSync(join(project, 'composer.lock'), '{"packages":[],"packages-dev":[]}\n');
  writeFileSync(join(project, '.gitignore'), 'vendor/\ninclusos/config.php\n');
  git(project, 'init', '-q');
  git(project, 'config', 'user.name', 'Fixture');
  git(project, 'config', 'user.email', 'fixture@example.invalid');
  git(project, 'add', '.');
  git(project, 'commit', '-qm', 'fixture');
  const sha = git(project, 'rev-parse', 'HEAD');
  mkdirSync(join(project, 'vendor'));
  writeFileSync(join(project, 'vendor/atual.txt'), 'anterior');
  mkdirSync(join(project, 'inclusos'));
  writeFileSync(join(project, 'inclusos/config.php'), 'segredo fictício não versionado');
  const events = join(dir, 'events');
  writeFileSync(events, '');
  const env = { ...process.env, PATH: bin + ':' + process.env.PATH, HELPER: shellHelper, PROJECT: project, BOT: 'fixture', SHA: sha, EVENTS: events, FAIL_COMPOSER: '', FAIL_RENAME: '', FAIL_RESTORE: '', OTHER_DEVICE: '' };
  const bash = (source, extra = {}) => run(project, ['/bin/bash', '-c', `set -euo pipefail; source "$HELPER"; ${source}`], { ...env, ...extra });
  const prepare = () => {
    const result = bash('umask 077; publicacao_preparar_dependencias "$SHA" "$PROJECT" "$BOT"; printf "%s" "$publicacao_dependencias_dir"');
    assert.equal(result.status, 0, result.stderr);
    return result.stdout;
  };
  try { body({ dir, project, privateDir, events, sha, env, bash, prepare }); }
  finally { rmSync(dir, { recursive: true, force: true }); }
}

test('preparo do SHA alvo preserva checkout/vendor servido e não copia segredos locais', () => {
  fixture(f => {
    const stage = f.prepare();
    assert.equal(git(f.project, 'rev-parse', 'HEAD'), f.sha);
    assert.equal(git(f.project, 'status', '--porcelain'), '');
    assert.equal(readFileSync(join(f.project, 'vendor/atual.txt'), 'utf8'), 'anterior');
    assert.equal(existsSync(join(stage, 'inclusos/config.php')), false);
    assert.equal(existsSync(join(stage, '.git')), false);
    const receipt = readFileSync(join(stage, '.dependencias-prontas'), 'utf8');
    assert.equal(receipt, f.sha + '\n' + git(f.project, 'rev-parse', `${f.sha}:composer.lock`) + '\n');
    const events = readFileSync(f.events, 'utf8').trim().split('\n');
    assert.equal(events.length, 3);
    for (const line of events) {
      assert.ok(line.startsWith(stage + '|'));
      assert.ok(line.endsWith(`|${stage}/vendor|${stage}/vendor/bin`));
      assert.match(line, /--no-plugins/);
    }
    assert.match(events[0], /validate --no-check-publish/);
    assert.match(events[1], /install --no-dev.*--no-scripts/);
    assert.match(events[2], /check-platform-reqs --no-dev/);
    // O root pode chamar com umask 077; só o envelope permanece privado.
    assert.equal(statSync(stage).mode & 0o777, 0o700);
    assert.equal(statSync(join(stage, '.dependencias-prontas')).mode & 0o777, 0o600);
    assert.equal(statSync(join(stage, 'vendor')).mode & 0o777, 0o755);
    assert.equal(statSync(join(stage, 'vendor/autoload.php')).mode & 0o777, 0o644);
  });
});

for (const step of ['validate', 'install', 'check-platform-reqs']) {
  test(`falha em ${step} mantém vendor servido e limpa preparo incompleto`, () => {
    fixture(f => {
      const result = f.bash('trap \'publicacao_limpar_dependencias "$BOT" "$PROJECT"\' EXIT; publicacao_preparar_dependencias "$SHA" "$PROJECT" "$BOT"', { FAIL_COMPOSER: step });
      assert.equal(result.status, 1, result.stderr);
      assert.equal(readFileSync(join(f.project, 'vendor/atual.txt'), 'utf8'), 'anterior');
      assert.equal(run(f.dir, ['find', f.privateDir, '-mindepth', '1']).stdout, '');
    });
  });
}

for (const kind of ['SHA', 'lock', 'manifesto', 'recibo inseguro', 'diretório inseguro', 'symlink diretório', 'symlink recibo', 'symlink vendor', 'autoload ausente', 'outro bot', 'outro filesystem']) {
  test(`consumo recusa ${kind} antes de tocar vendor servido`, () => {
    fixture(f => {
      let stage = f.prepare();
      const env = { STAGE: stage };
      if (kind === 'SHA') writeFileSync(join(stage, '.dependencias-prontas'), '0'.repeat(40) + '\nerrado\n');
      if (kind === 'lock') writeFileSync(join(stage, 'composer.lock'), '{}\n');
      if (kind === 'manifesto') writeFileSync(join(stage, 'composer.json'), '{}\n');
      if (kind === 'recibo inseguro') chmodSync(join(stage, '.dependencias-prontas'), 0o644);
      if (kind === 'diretório inseguro') chmodSync(stage, 0o755);
      if (kind === 'symlink diretório') { const link = join(f.privateDir, 'fixture.dependencias.link'); symlinkSync(stage, link); env.STAGE = link; }
      if (kind === 'symlink recibo') { rmSync(join(stage, '.dependencias-prontas')); symlinkSync(join(stage, 'composer.lock'), join(stage, '.dependencias-prontas')); }
      if (kind === 'symlink vendor') { rmSync(join(stage, 'vendor'), { recursive: true }); symlinkSync(join(f.project, 'vendor'), join(stage, 'vendor')); }
      if (kind === 'autoload ausente') rmSync(join(stage, 'vendor/autoload.php'));
      if (kind === 'outro bot') env.BOT = 'vizinho';
      if (kind === 'outro filesystem') env.OTHER_DEVICE = f.project;
      const result = f.bash('publicacao_ativar_dependencias "$STAGE" "$PROJECT" "$BOT"', env);
      assert.equal(result.status, 1, result.stderr);
      assert.equal(readFileSync(join(f.project, 'vendor/atual.txt'), 'utf8'), 'anterior');
      assert.equal(existsSync(join(f.project, 'vendor/autoload.php')), false);
    });
  });
}

test('ativação usa vendor pronto e conserva anterior até conclusão da publicação', () => {
  fixture(f => {
    const stage = f.prepare();
    const result = f.bash('publicacao_ativar_dependencias "$STAGE" "$PROJECT" "$BOT"', { STAGE: stage });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(readFileSync(join(f.project, 'vendor/autoload.php'), 'utf8'), 'novo');
    assert.equal(readFileSync(join(stage, 'vendor.anterior/atual.txt'), 'utf8'), 'anterior');
    assert.equal(readFileSync(f.events, 'utf8').trim().split('\n').length, 3);
    const clean = f.bash('publicacao_dependencias_dir="$STAGE"; publicacao_limpar_dependencias "$BOT" "$PROJECT"', { STAGE: stage });
    assert.equal(clean.status, 0, clean.stderr);
    assert.equal(existsSync(stage), false);
  });
});

for (const restoreFails of [false, true]) {
  test(`falha ao ativar vendor ${restoreFails ? 'preserva anterior se devolução falhar' : 'devolve vendor anterior'}`, () => {
    fixture(f => {
      const stage = f.prepare();
      const result = f.bash('trap \'publicacao_dependencias_dir="$STAGE"; publicacao_limpar_dependencias "$BOT" "$PROJECT"\' EXIT; publicacao_ativar_dependencias "$STAGE" "$PROJECT" "$BOT"', { STAGE: stage, FAIL_RENAME: '1', FAIL_RESTORE: restoreFails ? '1' : '' });
      assert.equal(result.status, 1, result.stderr);
      const oldVendor = restoreFails ? join(stage, 'vendor.anterior/atual.txt') : join(f.project, 'vendor/atual.txt');
      assert.equal(readFileSync(oldVendor, 'utf8'), 'anterior');
      assert.equal(existsSync(stage), restoreFails);
    });
  });
}

test('deploy exige runtime parado e valida preparo antes de qualquer migration', () => {
  const source = readFileSync(new URL('../deploy/deploy.sh', import.meta.url), 'utf8');
  const stopped = source.indexOf('\npublicacao_exigir_parados\n');
  const validate = source.indexOf('publicacao_dependencias_conferir ');
  const migrate = source.indexOf('\nphp scripts/deploy/migrate.php');
  const consume = source.indexOf('publicacao_ativar_dependencias ');
  assert.ok(stopped > 0 && stopped < validate && validate < migrate && migrate < consume);
  assert.match(source, /DEPLOY_DEPENDENCIAS_PREPARADAS="\$\{DEPLOY_DEPENDENCIAS_PREPARADAS:-\}"/);
});

test('preparo usa o commit futuro aprovado sem avançar o checkout servido', () => {
  fixture(f => {
    writeFileSync(join(f.project, 'composer.json'), '{"name":"fixture/futuro","require":{}}\n');
    git(f.project, 'add', 'composer.json');
    git(f.project, 'commit', '-qm', 'fixture futura');
    const target = git(f.project, 'rev-parse', 'HEAD');
    git(f.project, 'checkout', '--detach', f.sha);
    const result = f.bash('publicacao_preparar_dependencias "$SHA" "$PROJECT" "$BOT"; printf "%s" "$publicacao_dependencias_dir"', { SHA: target });
    assert.equal(result.status, 0, result.stderr);
    assert.equal(JSON.parse(readFileSync(join(result.stdout, 'composer.json'), 'utf8')).name, 'fixture/futuro');
    assert.equal(JSON.parse(readFileSync(join(f.project, 'composer.json'), 'utf8')).name, 'fixture/bot');
    assert.equal(git(f.project, 'rev-parse', 'HEAD'), f.sha);
    assert.equal(readFileSync(join(f.project, 'vendor/atual.txt'), 'utf8'), 'anterior');
  });
});

for (const kind of ['destino sem contrato', 'vendor versionado', 'filesystem diferente']) {
  test(`preparo recusa ${kind} sem instalar dependências`, () => {
    fixture(f => {
      if (kind === 'destino sem contrato') writeFileSync(join(f.project, 'scripts/deploy/deploy.sh'), '# legado\n');
      if (kind === 'vendor versionado') git(f.project, 'add', '--force', 'vendor/atual.txt');
      if (kind !== 'filesystem diferente') {
        git(f.project, 'add', 'scripts/deploy/deploy.sh');
        git(f.project, 'commit', '-qm', 'fixture incompatível');
      }
      const result = f.bash('trap \'publicacao_limpar_dependencias "$BOT" "$PROJECT"\' EXIT; publicacao_preparar_dependencias "$SHA" "$PROJECT" "$BOT"', {
        SHA: git(f.project, 'rev-parse', 'HEAD'), OTHER_DEVICE: kind === 'filesystem diferente' ? f.project : '',
      });
      assert.equal(result.status, 1, result.stderr);
      assert.equal(readFileSync(f.events, 'utf8'), '');
      assert.equal(readFileSync(join(f.project, 'vendor/atual.txt'), 'utf8'), 'anterior');
    });
  });
}


for (const [name, prepared, failure, expected] of [
  ['preparo consumido sem repetir Composer', true, '', 0],
  ['instalação direta confere plataforma', false, '', 0],
  ['instalação direta reprovada interrompe o preparo', false, 'install', 1],
  ['plataforma direta reprovada interrompe o preparo', false, 'check-platform-reqs', 1],
]) {
  test(`plataforma na ativação: ${name}`, () => {
    fixture(f => {
      const source = readFileSync(new URL('../deploy/deploy.sh', import.meta.url), 'utf8');
      const start = source.indexOf('# DEPLOY_DEPENDENCIAS_PREPARADAS v1\n');
      const end = source.indexOf('\nphp scripts/quality/check_env.php', start);
      assert.ok(start > 0 && end > start);
      writeFileSync(join(f.project, 'scripts/deploy/publicacao_dependencias.sh'),
        'publicacao_ativar_dependencias() { printf "%s\\n" consumir >> "$EVENTS"; }\n');
      const script = `project_dir="$PROJECT"
project_name="$BOT"
passos_pos_install=()
composer() {
  printf '%s\\n' "$1" >> "$EVENTS"
  [[ "$FAIL_COMPOSER" != "$1" ]]
}
${source.slice(start, end)}
printf '%s\\n' concluido >> "$EVENTS"
`;
      const result = f.bash(script, {
        DEPLOY_DEPENDENCIAS_PREPARADAS: prepared ? '/fixture/preparo-validado' : '',
        FAIL_COMPOSER: failure,
      });
      assert.equal(result.status, expected, result.stderr);
      const events = readFileSync(f.events, 'utf8').trim().split('\n');
      assert.deepEqual(events, prepared ? ['consumir', 'concluido']
        : failure === 'install' ? ['install']
        : failure === 'check-platform-reqs' ? ['install', 'check-platform-reqs']
        : ['install', 'check-platform-reqs', 'concluido']);
    });
  });
}


test('produção confere somente requisitos sem dependências de desenvolvimento', () => {
  for (const name of ['deploy.sh', 'deploy_verify.sh', 'publicacao_dependencias.sh']) {
    const source = readFileSync(new URL(`../deploy/${name}`, import.meta.url), 'utf8');
    const commands = source.split('\n').filter(line => /(?:COMPOSER_ALLOW_SUPERUSER=1|&&)\s+composer check-platform-reqs\s/.test(line));
    assert.ok(commands.length > 0, name);
    for (const command of commands) assert.match(command, /check-platform-reqs --no-dev --no-plugins/, name);
  }
});
