/** Casos de composição de evidências, carregados pela suíte validation-evidence. */
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { test } from "node:test";
import { runner, prepush, fixture } from "./validation-fixture.mjs";
const reused = (result) => {
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.includes("REUSED=true");
};
const actualPlan = JSON.parse(
  readFileSync(new URL("./validation-plan.json", import.meta.url), "utf8"),
);
for (const insideArtifact of [false, true]) {
  test(`outputs operacionais fora da qualidade preservam prova (${insideArtifact ? "artefato pai" : "input ignorado"})`, (t) => {
    const f = fixture(t);
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.operationalOutputs = [
      ".artifacts/release/receipt.json",
      ".artifacts/cache/build-production",
    ];
    if (insideArtifact) manifest.artifacts.push(".artifacts");
    writeFileSync(file, JSON.stringify(manifest));
    writeFileSync(
      path.join(f.repo, ".gitignore"),
      "ignored/\noutput/\n.artifacts/\n",
    );
    mkdirSync(path.join(f.repo, ".artifacts"));
    mkdirSync(path.join(f.repo, ".artifacts/release"));
    mkdirSync(path.join(f.repo, ".artifacts/cache"));
    writeFileSync(path.join(f.repo, ".artifacts/test-output"), "validated");
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "outputs de operação declarados");
    assert.equal(f.approve().status, 0);
    writeFileSync(
      path.join(f.repo, ".artifacts/release/receipt.json"),
      "receipt",
    );
    mkdirSync(path.join(f.repo, ".artifacts/cache/build-production"), {
      recursive: true,
    });
    writeFileSync(
      path.join(f.repo, ".artifacts/cache/build-production/compiled"),
      "production",
    );
    assert.equal(reused(f.reuse()), true);
    writeFileSync(path.join(f.repo, ".artifacts/test-output"), "changed");
    assert.equal(reused(f.reuse()), false);
  });
}
for (const invalid of [".", "../outside", "/tmp/output", ".git/config"]) {
  test(`output operacional inválido ${invalid} não emite aprovação`, (t) => {
    const f = fixture(t);
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.operationalOutputs = [invalid];
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "declaração inválida de output");
    const approval = f.approve();
    // Os bots recusam o contrato inválido; outros projetos apenas dispensam o cache.
    if (approval.status !== 0) assert.match(approval.stderr, /artefato inválido/u);
    assert.equal(reused(f.reuse()), false);
  });
}
for (const complete of actualPlan.reuse.filter(
  (entry) =>
    entry.proof !== "check:all" &&
    actualPlan.reuse.some((base) => base.proof === "check:all"),
)) {
  test(`manifesto real revoga ${complete.proof} quando check:all reprova`, (t) => {
    const f = fixture(t);
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.reuse = actualPlan.reuse;
    manifest.executions = actualPlan.executions;
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "contrato real de coaprovação");
    assert.equal(
      f.run(
        `await withValidationEvidence({proves:['check:all',${JSON.stringify(complete.proof)}],issuer:'source.txt'},()=>{});`,
      ).status,
      0,
    );
    const reuseComplete = () =>
      f.run(
        `console.log('REUSED='+reuseValidation(${JSON.stringify(complete.command)}));`,
      );
    assert.equal(reused(reuseComplete()), true);
    assert.equal(f.approve("throw Error('base reprovada')").status, 1);
    assert.equal(reused(reuseComplete()), false);
  });
}
for (const nested of [false, true]) {
  test(`push ${nested ? "com runner aninhado" : "direto"} emite prova reutilizada pelo próximo push`, (t) => {
    const f = fixture(t);
    const body = [process.execPath, "-e", "console.log('GATE_EXECUTED')"];
    const command = nested
      ? [process.execPath, runner, "--proof", "check:all", "--", ...body]
      : body;
    for (const issuer of ["pre-push.mjs", "validation-runner.mjs"])
      writeFileSync(
        path.join(f.repo, "scripts/quality", issuer),
        "// issuer versionado\n",
      );
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.reuse = [{ command, proof: "check:all" }];
    manifest.executions = [{ command: body, proves: ["check:all"] }];
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "contrato do push");
    const head = f.git("rev-parse", "HEAD");
    const invoke = () =>
      spawnSync(process.execPath, [prepush, ...command], {
        cwd: f.repo,
        env: f.environment,
        encoding: "utf8",
        input: `refs/heads/main ${head} refs/heads/main ${"0".repeat(40)}\n`,
      });
    const first = invoke();
    assert.equal(first.status, 0, first.stderr);
    assert.match(first.stdout, /GATE_EXECUTED/u);
    const second = invoke();
    assert.equal(second.status, 0, second.stderr);
    assert.match(second.stdout, /reutilizando/u);
    assert.doesNotMatch(second.stdout, /GATE_EXECUTED/u);
  });
}

test("revalidar base revoga prova completa coemitida mesmo quando a base falha", (t) => {
  const f = fixture(t);
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  const complete = [...f.gate, "complete"];
  manifest.reuse.push({ command: complete, proof: "check:ci-local" });
  manifest.executions.push({
    command: complete,
    proves: ["check:all", "check:ci-local"],
  });
  writeFileSync(file, JSON.stringify(manifest));
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "provas relacionadas");
  assert.equal(
    f.run(
      `await withValidationEvidence({proves:['check:all','check:ci-local'],issuer:'source.txt'},()=>{});`,
    ).status,
    0,
  );
  const reuse = () =>
    f.run(
      `console.log('REUSED='+reuseValidation(${JSON.stringify(complete)}));`,
    );
  assert.equal(reused(reuse()), true);
  assert.equal(f.approve("throw Error('base reprovada')").status, 1);
  assert.equal(reused(reuse()), false);
});

for (const outcome of [
  "success",
  "failure",
  "base changed",
  "artifact changed",
]) {
  test(`aprovação incremental ${outcome} vincula base e conclusão`, (t) => {
    const f = fixture(t);
    const complete = [...f.gate, "complete"];
    const file = path.join(f.repo, "scripts/quality/validation-plan.json");
    const manifest = JSON.parse(readFileSync(file, "utf8"));
    manifest.reuse.push({ command: complete, proof: "check:ci-local" });
    manifest.executions.push({
      command: complete,
      proves: ["check:all", "check:ci-local"],
    });
    writeFileSync(file, JSON.stringify(manifest));
    f.git("add", ".");
    f.git("commit", "--quiet", "-m", "aprovação incremental");
    assert.equal(f.approve().status, 0);
    if (outcome === "artifact changed") {
      mkdirSync(path.join(f.repo, "output"));
      writeFileSync(path.join(f.repo, "output/file"), "changed");
    }
    const change =
      outcome === "base changed"
        ? `await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},()=>{throw Error('base changed')}).catch(()=>{});`
        : outcome === "failure"
          ? "throw Error('extras failed');"
          : "";
    const result = f.run(
      `await withValidationEvidence({proves:['check:all','check:ci-local'],issuer:'source.txt',reuse:${JSON.stringify(f.gate)}},async({reused})=>{console.log('BOUND='+reused);${change}});`,
    );
    assert.equal(result.status, outcome === "failure" ? 1 : 0, result.stderr);
    assert.match(
      result.stdout,
      new RegExp(`BOUND=${outcome !== "artifact changed"}`),
    );
    const proof = f.run(
      `console.log('REUSED='+reuseValidation(${JSON.stringify(complete)}));`,
    );
    assert.equal(
      reused(proof),
      outcome === "success" || outcome === "artifact changed",
    );
    assert.equal(
      reused(f.reuse()),
      outcome === "success" || outcome === "artifact changed",
    );
  });
}

test("aprovação incremental renova artefatos de base após extras", (t) => {
  const f = fixture(t);
  const complete = [...f.gate, "complete"];
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  manifest.reuse.push({ command: complete, proof: "check:ci-local" });
  manifest.executions.push({
    command: complete,
    proves: ["check:all", "check:ci-local"],
  });
  writeFileSync(file, JSON.stringify(manifest));
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "artefatos incrementais");
  assert.equal(f.approve().status, 0);
  const result = f.run(
    `import * as fs from 'node:fs';await withValidationEvidence({proves:['check:all','check:ci-local'],issuer:'source.txt',reuse:${JSON.stringify(f.gate)}},({reused})=>{if(!reused)throw Error('base não reutilizada');fs.mkdirSync('output');fs.writeFileSync('output/browser','browser result');});`,
  );
  assert.equal(result.status, 0, result.stderr);
  assert.equal(reused(f.reuse()), true);
  assert.equal(
    reused(
      f.run(
        `console.log('REUSED='+reuseValidation(${JSON.stringify(complete)}));`,
      ),
    ),
    true,
  );
});

test("tracking remoto que avança para HEAD já validado preserva aprovação", (t) => {
  const f = fixture(t);
  f.git("update-ref", "refs/remotes/origin/main", f.head);
  f.git("commit", "--quiet", "--allow-empty", "-m", "commit local validado");
  assert.equal(f.approve().status, 0);
  f.git("update-ref", "refs/remotes/origin/main", f.git("rev-parse", "HEAD"));
  assert.equal(reused(f.reuse()), true);
});

test("histórico novo em tracking remoto invalida aprovação", (t) => {
  const f = fixture(t);
  assert.equal(f.approve().status, 0);
  const tree = f.git("rev-parse", "HEAD^{tree}");
  const other = f.git(
    "commit-tree",
    tree,
    "-p",
    f.head,
    "-m",
    "histórico remoto",
  );
  f.git("update-ref", "refs/remotes/origin/other", other);
  assert.equal(reused(f.reuse()), false);
});

test("dependência revogada durante fingerprint final impede reuso da prova completa", (t) => {
  const f = fixture(t);
  const tool = path.join(f.bin, "dependency-probe");
  const enabled = path.join(f.root, "enabled");
  const counter = path.join(f.root, "counter");
  const complete = [...f.gate, "complete"];
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  manifest.tools.push(tool);
  manifest.reuse.push({ command: complete, proof: "check:ci-local" });
  manifest.executions.push({
    command: complete,
    proves: ["check:all", "check:ci-local"],
  });
  writeFileSync(file, JSON.stringify(manifest));
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "dependência da prova completa");
  // O nome é estável; o conteúdo executável participa da identidade antes da aprovação.
  const base = path.join(
    f.repo,
    ".git/quality-validation",
    "5f38c2e5264ae6fe3716635bda1868d6ba83c1c39a763d0513891be5b3a0a9e6.json",
  );
  const probe = `#!${process.execPath}\nimport * as fs from 'node:fs';if(fs.existsSync(${JSON.stringify(enabled)})){const file=${JSON.stringify(counter)};const n=Number(fs.existsSync(file)?fs.readFileSync(file,'utf8'):0)+1;fs.writeFileSync(file,String(n));if(n===2)fs.writeFileSync(${JSON.stringify(`${base}.generation`)},'revoked-only-base')}console.log('dependency-probe 1');`;
  writeFileSync(tool, probe, { mode: 0o755 });
  assert.equal(f.approve().status, 0);
  const receipt = f.receipt();
  // Usa o caminho real sem alterar os bytes do binário após sua aprovação.
  assert.equal(receipt, base);
  assert.equal(
    f.run(
      `await withValidationEvidence({proves:['check:all','check:ci-local'],reuse:${JSON.stringify(f.gate)},issuer:'source.txt'},({reused})=>{if(!reused)throw Error('base indisponível')});`,
    ).status,
    0,
  );
  writeFileSync(enabled, "");
  assert.equal(
    reused(
      f.run(
        `console.log('REUSED='+reuseValidation(${JSON.stringify(complete)}));`,
      ),
    ),
    false,
  );
});

test("composição preserva prazo da base em todas as provas, inclusive a completa", (t) => {
  const f = fixture(t);
  const complete = [...f.gate, "complete"];
  const file = path.join(f.repo, "scripts/quality/validation-plan.json");
  const manifest = JSON.parse(readFileSync(file, "utf8"));
  manifest.reuse.push({ command: complete, proof: "check:ci-local" });
  manifest.executions.push({
    command: complete,
    proves: ["check:all", "check:ci-local"],
  });
  writeFileSync(file, JSON.stringify(manifest));
  f.git("add", ".");
  f.git("commit", "--quiet", "-m", "validade da prova composta");
  assert.equal(f.approve().status, 0);
  const aprovadoEm = Date.now() - 1_700_000;
  const recibo = JSON.parse(readFileSync(f.receipt(), "utf8"));
  writeFileSync(
    f.receipt(),
    JSON.stringify({ ...recibo, completedAt: aprovadoEm }),
  );
  const result = f.run(
    `await withValidationEvidence({proves:['check:all','check:ci-local'],issuer:'source.txt',reuse:${JSON.stringify(f.gate)}},({reused})=>{if(!reused)throw Error('base não reutilizada')});`,
  );
  assert.equal(result.status, 0, result.stderr);
  assert.equal(
    JSON.parse(readFileSync(f.receipt(), "utf8")).completedAt,
    aprovadoEm,
  );
  assert.equal(
    reused(
      f.run(
        `console.log('REUSED='+reuseValidation(${JSON.stringify(complete)}));`,
      ),
    ),
    true,
  );
  const expired = f.run(
    `const now=Date.now();Date.now=()=>now+200_000;console.log('BASE='+reuseValidation(${JSON.stringify(f.gate)}));console.log('COMPLETE='+reuseValidation(${JSON.stringify(complete)}));`,
  );
  assert.equal(expired.status, 0, expired.stderr);
  assert.match(expired.stdout, /BASE=false/u);
  assert.match(expired.stdout, /COMPLETE=false/u);
});
