import assert from "node:assert/strict";
import { spawn } from "node:child_process";
import {
  existsSync,
  readdirSync,
  readFileSync,
  writeFileSync,
  utimesSync,
  chmodSync,
} from "node:fs";
import path from "node:path";
import { test } from "node:test";
import {
  currentMarker,
  markerState,
  recoverActive,
} from "./validation-active.mjs";
import { evidence, fixture } from "./validation-fixture.mjs";
const reused = (result) => {
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.includes("REUSED=true");
};
const activeFiles = (f) => {
  const directory = path.join(f.repo, ".git/quality-validation");
  return readdirSync(directory)
    .filter((name) => name.endsWith(".active"))
    .flatMap((name) =>
      readdirSync(path.join(directory, name)).map((file) =>
        path.join(directory, name, file),
      ),
    );
};
async function hold(f, t, name) {
  const ready = path.join(f.root, `${name}.ready`);
  const release = path.join(f.root, `${name}.release`);
  const script = `import {withValidationEvidence} from ${JSON.stringify(new URL(`file://${evidence}`).href)};import * as fs from 'node:fs';await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},async()=>{fs.writeFileSync(${JSON.stringify(ready)},'');while(!fs.existsSync(${JSON.stringify(release)}))await new Promise(r=>setTimeout(r,10))});`;
  const child = spawn(process.execPath, ["--input-type=module", "-e", script], {
    cwd: f.repo,
    env: f.environment,
    stdio: "ignore",
  });
  t.after(() => {
    if (child.exitCode === null) child.kill("SIGKILL");
  });
  const exited = new Promise((resolve) => child.once("exit", resolve));
  const deadline = Date.now() + 10000;
  while (!existsSync(ready)) {
    assert.ok(
      Date.now() < deadline && child.exitCode === null,
      "execução não iniciou",
    );
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
  return { child, exited, release };
}

test("PID vivo, identidade desconhecida e EPERM nunca são órfãos", () => {
  const marker = currentMarker();
  assert.equal(markerState(marker), "live");
  assert.equal(markerState(marker, { identity: () => undefined }), "unknown");
  assert.equal(
    markerState(marker, {
      probe: () => {
        throw Object.assign(Error(), { code: "EPERM" });
      },
      identity: () => "different",
    }),
    "unknown",
  );
  assert.equal(markerState({ pid: process.pid }), "unknown");
  assert.equal(markerState({ ...marker, host: "outro-host" }), "unknown");
  assert.equal(
    markerState(marker, { identity: () => "another-process-start" }),
    "dead",
  );
  assert.equal(
    markerState(marker, {
      probe: () => {
        throw Object.assign(Error(), { code: "ESRCH" });
      },
    }),
    "dead",
  );
});

test("TZ e locale diferentes não transformam processo vivo em órfão", () => {
  const original = { TZ: process.env.TZ, LC_ALL: process.env.LC_ALL };
  try {
    process.env.TZ = "UTC";
    process.env.LC_ALL = "C";
    const marker = currentMarker();
    process.env.TZ = "America/Sao_Paulo";
    process.env.LC_ALL = "pt_BR.UTF-8";
    assert.equal(markerState(marker), "live");
  } finally {
    for (const [key, value] of Object.entries(original)) {
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
});

for (const signal of ["SIGTERM", "SIGKILL"]) {
  test(`${signal} nunca aprova execução interrompida e a próxima rodada recupera marcador`, (t) => {
    const f = fixture(t);
    assert.equal(f.approve().status, 0);
    assert.equal(reused(f.reuse()), true);
    const interrupted = f.run(
      `await withValidationEvidence({proves:['check:all'],issuer:'source.txt'},()=>{process.kill(process.pid,${JSON.stringify(signal)})});`,
    );
    assert.equal(interrupted.signal, signal);
    assert.equal(activeFiles(f).length, 1);
    assert.equal(reused(f.reuse()), false);
    assert.equal(activeFiles(f).length, 0);
    assert.equal(f.approve().status, 0);
    assert.equal(reused(f.reuse()), true);
  });
}

test("marcador vivo antigo não expira nem permite aprovação concorrente", async (t) => {
  const f = fixture(t);
  const held = await hold(f, t, "live");
  const file = activeFiles(f)[0];
  utimesSync(file, new Date(0), new Date(0));
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), false);
  assert.ok(existsSync(file));
  held.child.kill("SIGKILL");
  await held.exited;
  assert.equal(reused(f.reuse()), false);
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), true);
});

test("múltiplos órfãos recuperam juntos e não escondem marcador vivo", async (t) => {
  const f = fixture(t);
  const first = await hold(f, t, "first");
  const second = await hold(f, t, "second");
  assert.equal(activeFiles(f).length, 2);
  first.child.kill("SIGTERM");
  await first.exited;
  assert.equal(reused(f.reuse()), false);
  assert.equal(activeFiles(f).length, 1);
  second.child.kill("SIGKILL");
  await second.exited;
  assert.equal(f.approve().status, 0);
  assert.equal(activeFiles(f).length, 0);
  assert.equal(reused(f.reuse()), true);
});

test("PID reutilizado comprovado recupera; marcador ilegível bloqueia", (t) => {
  const f = fixture(t);
  assert.equal(f.approve().status, 0);
  const directory = `${f.receipt()}.active`;
  const file = path.join(directory, `${process.pid}-reused`);
  writeFileSync(
    file,
    JSON.stringify({ ...currentMarker(), start: "previous-process" }),
  );
  assert.equal(reused(f.reuse()), false);
  assert.equal(existsSync(file), false);
  assert.equal(f.approve().status, 0);
  writeFileSync(file, "{");
  assert.equal(f.approve().status, 0);
  assert.equal(reused(f.reuse()), false);
  assert.equal(readFileSync(file, "utf8"), "{");
});

test("falha de permissão ao revogar prova mantém órfão e bloqueia recibo antigo", (t) => {
  const f = fixture(t);
  assert.equal(f.approve().status, 0);
  const receipt = f.receipt();
  const directory = path.dirname(receipt);
  const marker = path.join(`${receipt}.active`, "2147483647-orphan");
  writeFileSync(marker, "");
  // Falha de revogação é determinística também quando root ignora chmod.
  recoverActive(`${receipt}.active`, () => false);
  assert.equal(existsSync(marker), true);
  assert.equal(existsSync(receipt), true);
  if (process.geteuid?.() !== 0) {
    try {
      chmodSync(`${receipt}.generation`, 0o400);
      chmodSync(directory, 0o500);
      assert.equal(reused(f.reuse()), false);
      assert.equal(existsSync(marker), true);
      assert.equal(existsSync(receipt), true);
    } finally {
      chmodSync(directory, 0o700);
      chmodSync(`${receipt}.generation`, 0o600);
    }
  }
  assert.equal(reused(f.reuse()), false);
  assert.equal(existsSync(marker), false);
});
