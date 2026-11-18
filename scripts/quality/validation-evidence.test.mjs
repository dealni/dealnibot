import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import {
  existsSync,
  mkdirSync,
  readFileSync,
  symlinkSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { evidence, runner, prepush, fixture } from "./validation-fixture.mjs";
const reused = (result) => {
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.includes("REUSED=true");
};

for (const kind of [
  "source",
  "ignored config",
  "env",
  "artifact",
  "tool",
  "plan",
  "index flags",
]) {
  test(`mudança de ${kind} invalida aprovação`, (t) => {
    const f = fixture(t);
    assert.equal(f.approve().status, 0);
    let env = {};
    if (kind === "source")
      writeFileSync(path.join(f.repo, "source.txt"), "changed");
    if (kind === "ignored config")
      writeFileSync(path.join(f.repo, "ignored/config"), "changed");
    if (kind === "env") env = { VALIDATION_PROFILE: "changed" };
    if (kind === "artifact") {
      mkdirSync(path.join(f.repo, "output"));
      writeFileSync(path.join(f.repo, "output/file"), "changed");
    }
    if (kind === "tool")
      env = { PATH: `${f.root}${path.delimiter}${f.environment.PATH}` };
    if (kind === "plan")
      writeFileSync(
        path.join(f.repo, "scripts/quality/validation-plan.json"),
        "{}",
      );
    if (kind === "index flags")
      f.git("update-index", "--assume-unchanged", "source.txt");
    assert.equal(reused(f.reuse(env)), false);
  });
}

for (const value of [
  // Valores recentes isolam tipo/integralidade sem reprovar também pelo TTL.
  String(Date.now()),
  Date.now() - 1.5,
  Date.now() + 600000,
]) {
  test(`timestamp inválido ${JSON.stringify(value)} recusa reuso`, (t) => {
    const f = fixture(t);
    f.approve();
    const file = f.receipt();
    const receipt = JSON.parse(readFileSync(file, "utf8"));
    receipt.completedAt = value;
    writeFileSync(file, JSON.stringify(receipt));
    assert.equal(reused(f.reuse()), false);
  });
}

for (const kind of [
  "dirty before",
  "ignored during",
  "source during",
  "failure",
]) {
  test(`${kind} não produz nova prova e falha invalida prova antiga`, (t) => {
    const f = fixture(t);
    f.approve();
    if (kind === "dirty before")
      writeFileSync(path.join(f.repo, "source.txt"), "dirty");
    const body =
      kind === "failure"
        ? 'throw new Error("failure")'
        : kind === "ignored during"
          ? `awaitNoop();`
          : "";
    let script = body;
    if (kind === "ignored during") script = `requireWrite('ignored/config')`;
    if (kind === "source during") script = `requireWrite('source.txt')`;
    script = script.replace(
      /requireWrite\('([^']+)'\)/gu,
      (_, file) => `globalThis.fs.writeFileSync('${file}','changed')`,
    );
    const result = f.run(
      `import * as fs from 'node:fs';globalThis.fs=fs;await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},()=>{${script}});`,
    );
    assert.equal(result.status, kind === "failure" ? 1 : 0, result.stderr);
    assert.equal(reused(f.reuse()), false);
  });
}

for (const key of [
  "NODE_OPTIONS",
  "NODE_PATH",
  "PHPRC",
  "PHP_INI_SCAN_DIR",
  "COMPOSER_HOME",
  "NPM_CONFIG_USERCONFIG",
  "BASH_ENV",
  "GITLEAKS_CONFIG",
  "SEMGREP_SETTINGS_FILE",
  "NFSE_CERT_PFX",
  "npm_config_node_options",
  "npm_config_script_shell",
]) {
  test(`configuração externa ${key} não permite prova`, (t) => {
    const f = fixture(t, { realNpm: key.startsWith("npm_config_") });
    const env = {
      [key]: ["NODE_OPTIONS", "npm_config_node_options"].includes(key)
        ? "--no-warnings"
        : path.join(f.root, "external"),
    };
    assert.equal(f.approve("", env).status, 0);
    assert.equal(reused(f.reuse(env)), false);
  });
}

test("CLI não certifica comando fora do plano versionado", (t) => {
  const f = fixture(t);
  const result = spawnSync(
    process.execPath,
    [runner, "--proof", "check:all", "--", process.execPath, "-e", ""],
    { cwd: f.repo, env: f.environment, encoding: "utf8" },
  );
  assert.equal(result.status, 0, result.stderr);
  assert.equal(existsSync(path.join(f.repo, ".git/quality-validation")), false);
});

test("prepush com aprovação continua recusando outra ref e checkout dirty", (t) => {
  const f = fixture(t);
  f.approve();
  for (const dirty of [false, true]) {
    if (dirty) writeFileSync(path.join(f.repo, "source.txt"), "dirty");
    const result = spawnSync(process.execPath, [prepush, ...f.gate], {
      cwd: f.repo,
      env: f.environment,
      encoding: "utf8",
      input: `refs/heads/main ${dirty ? f.head : "1".repeat(40)} refs/heads/main ${"0".repeat(40)}\n`,
    });
    assert.equal(result.status, 1);
  }
});

test("outras refs do histórico invalidam aprovação de gitleaks", (t) => {
  const f = fixture(t);
  f.approve();
  f.git("update-ref", "refs/heads/outra", f.head);
  assert.equal(reused(f.reuse()), false);
});

test("Git config local também faz parte das entradas", (t) => {
  const f = fixture(t);
  f.approve();
  f.git("config", "fixture.changed", "true");
  assert.equal(reused(f.reuse()), false);
});

test("issuer fora do contrato não emite aprovação", (t) => {
  const f = fixture(t);
  assert.equal(
    f.run(
      `await withValidationEvidence({proves:['check:all'],issuer:'other'},()=>{});`,
    ).status,
    0,
  );
  assert.equal(reused(f.reuse()), false);
});

test("prepush executa gate original quando o recibo não certifica a prova", (t) => {
  const f = fixture(t);
  f.approve();
  const invoke = () =>
    spawnSync(process.execPath, [prepush, ...f.gate], {
      cwd: f.repo,
      env: f.environment,
      encoding: "utf8",
      input: `refs/heads/main ${f.head} refs/heads/main ${"0".repeat(40)}\n`,
    });
  writeFileSync(f.receipt(), "{}");
  const miss = invoke();
  assert.equal(miss.status, 0, miss.stderr);
  assert.doesNotMatch(miss.stdout, /reutilizando/u);
  assert.match(miss.stdout, /GATE_EXECUTED/u);
});

for (const first of ["failure", "success"]) {
  test(`concorrência com ${first} primeiro invalida aprovação após reprovação`, (t) => {
    const f = fixture(t);
    const script = `
      import {spawn} from 'node:child_process';import * as fs from 'node:fs';
      const root=${JSON.stringify(f.root)};
      const prefix=${JSON.stringify(`import {withValidationEvidence,reuseValidation} from ${JSON.stringify(new URL(`file://${evidence}`).href)};import * as fs from 'node:fs';`)};
      const child=(kind)=>new Promise(resolve=>{const body=\`await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},async()=>{fs.writeFileSync(\${JSON.stringify(root+'/')}+kind+'.ready','');while(!fs.existsSync(\${JSON.stringify(root+'/')}+kind+'.release'))await new Promise(r=>setTimeout(r,10));if(kind==='failure')throw Error('failure')});\`;const p=spawn(process.execPath,['--input-type=module','-e',prefix+'const kind='+JSON.stringify(kind)+';'+body],{stdio:'ignore'});p.on('exit',resolve)});
      const wait=async(kind)=>{while(!fs.existsSync(root+'/'+kind+'.ready'))await new Promise(r=>setTimeout(r,10));};
      const first=${JSON.stringify(first)};const second=first==='failure'?'success':'failure';
      const a=child(first);await wait(first);const b=child(second);await wait(second);
      if(reuseValidation(${JSON.stringify(f.gate)}))throw Error('prova consumível durante execução');
      fs.writeFileSync(root+'/success.release','');fs.writeFileSync(root+'/failure.release','');
      await Promise.all([a,b]);console.log('REUSED='+reuseValidation(${JSON.stringify(f.gate)}));`;
    const result = f.run(script);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(reused(result), false);
  });
}

test("rodada offline sem emissão invalida prova anterior mesmo ao reprovar", (t) => {
  const f = fixture(t);
  f.approve();
  assert.equal(reused(f.reuse()), true);
  assert.equal(
    f.run(
      `await withValidationEvidence({proves:['check:all'],issuer:'source.txt',record:false},()=>{throw Error('offline failure')});`,
    ).status,
    1,
  );
  assert.equal(reused(f.reuse()), false);
  assert.equal(
    f.run(
      `await withValidationEvidence({proves:['check:all'],issuer:'source.txt',record:false},()=>{});`,
    ).status,
    0,
  );
  assert.equal(reused(f.reuse()), false);
});

test("ambiente npm run pode emitir prova reutilizada pelo hook direto", (t) => {
  const f = fixture(t, { realNpm: true, realTools: true });
  const approveCode = `import {withValidationEvidence} from ${JSON.stringify(new URL(`file://${evidence}`).href)};await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},()=>{});`;
  writeFileSync(path.join(f.repo, "approve.mjs"), approveCode);
  writeFileSync(
    path.join(f.repo, "package.json"),
    JSON.stringify({ scripts: { approve: "node approve.mjs" } }),
  );
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "fixture npm");
  const result = spawnSync("npm", ["run", "--silent", "approve"], {
    cwd: f.repo,
    env: f.environment,
    encoding: "utf8",
  });
  assert.equal(result.status, 0, result.stderr);
  const head = f.git("rev-parse", "HEAD");
  const hook = spawnSync(process.execPath, [prepush, ...f.gate], {
    cwd: f.repo,
    env: f.environment,
    encoding: "utf8",
    input: `refs/heads/main ${head} refs/heads/main ${"0".repeat(40)}\n`,
  });
  assert.equal(hook.status, 0, hook.stderr);
  assert.match(hook.stdout, /reutilizando check:all/u);
  assert.doesNotMatch(hook.stdout, /GATE_EXECUTED/u);
  assert.doesNotMatch(readFileSync(f.receipt(), "utf8"), /secret-initial/u);
});

test("falha concorrente durante segundo fingerprint impede consumir recibo lido antes", (t) => {
  const f = fixture(t);
  const tool = path.join(f.repo, "version-probe");
  const counter = path.join(f.root, "counter");
  writeFileSync(
    tool,
    `#!${process.execPath}\nimport * as fs from 'node:fs';import {withValidationEvidence} from ${JSON.stringify(new URL(`file://${evidence}`).href)};const file=${JSON.stringify(counter)};const count=Number(fs.existsSync(file)?fs.readFileSync(file,'utf8'):0)+1;fs.writeFileSync(file,String(count));if(count===4){try{await withValidationEvidence({proves:['check:all'],record:false},()=>{throw Error('concurrent failure')})}catch{}}console.log('fixture-version');`,
    { mode: 0o755 },
  );
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  manifest.tools.push(tool);
  writeFileSync(file, JSON.stringify(manifest));
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "fixture version probe");
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), false);
});

test("symlink ignorado pendente preserva identidade do destino", (t) => {
  const f = fixture(t);
  const link = path.join(f.repo, "ignored/link");
  symlinkSync(path.join(f.root, "missing-a"), link);
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
  unlinkSync(link);
  symlinkSync(path.join(f.root, "missing-b"), link);
  assert.equal(reused(f.reuse()), false);
});

test("config PHP com certificado externo indeterminado dispensa recibo e executa gate", (t) => {
  const f = fixture(t);
  mkdirSync(path.join(f.repo, "inclusos"));
  writeFileSync(
    path.join(f.repo, "inclusos/config.php"),
    "<?php define('NFSE_CERT_PFX', getenv('CUSTOM_CERT_FILE'));\n",
  );
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "fixture cert config");
  const result = f.approve();
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /NFSE_CERT_/u);
  assert.equal(reused(f.reuse()), false);
});

for (const action of ["remove", "corrupt"]) {
  test(`recibo ${action} durante fingerprint força o gate original`, (t) => {
    const f = fixture(t);
    const tool = path.join(f.repo, "receipt-revocation-probe");
    const counter = path.join(f.root, "counter");
    const directory = path.join(f.repo, ".git/quality-validation");
    writeFileSync(
      tool,
      `#!${process.execPath}\nimport * as fs from 'node:fs';import path from 'node:path';const file=${JSON.stringify(counter)};const count=Number(fs.existsSync(file)?fs.readFileSync(file,'utf8'):0)+1;fs.writeFileSync(file,String(count));if(count===4){const directory=${JSON.stringify(directory)};const name=fs.readdirSync(directory).find(name=>name.endsWith('.json'));const receipt=path.join(directory,name);${action === "remove" ? "fs.unlinkSync(receipt)" : "fs.writeFileSync(receipt,'{')"}}console.log('fixture-version');`,
      { mode: 0o755 },
    );
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.tools.push(tool);
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "fixture revocation");
    const head = f.git("rev-parse", "HEAD");
    assert.equal(f.approve().status, 0);
    const result = spawnSync(process.execPath, [prepush, ...f.gate], {
      cwd: f.repo,
      env: f.environment,
      encoding: "utf8",
      input: `refs/heads/main ${head} refs/heads/main ${"0".repeat(40)}\n`,
    });
    assert.equal(result.status, 0, result.stderr);
    assert.doesNotMatch(result.stdout, /reutilizando/u);
    assert.match(result.stdout, /GATE_EXECUTED/u);
  });
}

test("inventário ignorado acima de 1 MiB continua elegível para evidência", (t) => {
  const f = fixture(t);
  for (let index = 0; index < 4300; index++)
    writeFileSync(
      path.join(
        f.repo,
        "ignored",
        `${"x".repeat(240)}${String(index).padStart(5, "0")}`,
      ),
      "",
    );
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
});

for (const state of ["missing", "corrupt", "expired"]) {
  test(`recibo ${state} não executa fingerprint caro nem sonda ferramentas`, (t) => {
    const f = fixture(t);
    const tool = path.join(f.repo, "unneeded-version-probe");
    const marker = path.join(f.root, "tool-ran");
    writeFileSync(
      tool,
      `#!${process.execPath}\nimport * as fs from 'node:fs';fs.writeFileSync(${JSON.stringify(marker)},'ran');console.log('fixture-version');`,
      { mode: 0o755 },
    );
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.tools.push(tool);
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "fixture cheap miss");
    if (state !== "missing") {
      f.approve();
      const receipt = f.receipt();
      if (state === "corrupt") writeFileSync(receipt, "{");
      else {
        const value = JSON.parse(readFileSync(receipt, "utf8"));
        value.completedAt = Date.now() - 3600000;
        writeFileSync(receipt, JSON.stringify(value));
      }
      unlinkSync(marker);
    }
    assert.equal(reused(f.reuse()), false);
    assert.equal(existsSync(marker), false);
  });
}

test("npm userconfig externo real invalida prova quando só os bytes mudam", (t) => {
  const f = fixture(t, { realNpm: true });
  const userconfig = path.join(f.root, "external.npmrc");
  writeFileSync(userconfig, "# initial\n");
  const env = { npm_config_userconfig: userconfig };
  assert.equal(f.approve("", env).status, 0);
  assert.equal(reused(f.reuse(env)), true);
  writeFileSync(userconfig, "# changed\n");
  assert.equal(reused(f.reuse(env)), false);
});

test("binário externo com mesma versão invalida prova após mudança de bytes", (t) => {
  const f = fixture(t);
  const tool = path.join(f.bin, "fixture-tool");
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
  writeFileSync(
    tool,
    "#!/bin/sh\n# binary changed with same version output\nprintf 'fixture-tool 1.0\\n'\n",
    { mode: 0o755 },
  );
  assert.equal(reused(f.reuse()), false);
});

import "./validation-composition.test-support.mjs";
