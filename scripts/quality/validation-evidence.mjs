/** Evidência local opcional; aprovação só após o gate completo e checkout limpo. */
import {
  inheritedExecutions,
  childEnvironment,
} from "./validation-delegation.mjs";
import { randomUUID } from "node:crypto";
import {
  mkdirSync,
  readFileSync,
  readdirSync,
  renameSync,
  unlinkSync,
  writeFileSync,
} from "node:fs";
import path from "node:path";
import { git, hash, manifestAt, snapshot } from "./validation-identity.mjs";
import { currentMarker, recoverActive } from "./validation-active.mjs";
const TTL = 30 * 60 * 1000;
export function executionProofs(
  command,
  manifest = "scripts/quality/validation-plan.json",
) {
  try {
    const entry = manifestAt(manifest).executions?.find(
      (item) => JSON.stringify(item.command) === JSON.stringify(command),
    );
    return Array.isArray(entry?.proves)
      ? entry.proves.filter((proof) => typeof proof === "string")
      : [];
  } catch (error) {
    if (error.code === "VALIDATION_BINDINGS") throw error;
    return [];
  }
}

function receiptPath(proof) {
  const directory = path.resolve(
    git("rev-parse", "--git-path", "quality-validation"),
  );
  return path.join(directory, `${hash(proof)}.json`);
}

function generation(proof) {
  try {
    return readFileSync(`${receiptPath(proof)}.generation`, "utf8");
  } catch {
    return undefined;
  }
}
function activeDirectory(proof) {
  return `${receiptPath(proof)}.active`;
}
function hasActive(proof, own) {
  try {
    return readdirSync(activeDirectory(proof)).some((entry) => entry !== own);
  } catch (error) {
    return error.code !== "ENOENT";
  }
}
function startExecutions(proves) {
  return proves.map((proof) => {
    const directory = activeDirectory(proof);
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    recoverActive(directory, () => invalidate([proof]));
    const name = `${process.pid}-${randomUUID()}`;
    const file = path.join(directory, name);
    writeFileSync(file, JSON.stringify(currentMarker()), {
      flag: "wx",
      mode: 0o600,
    });
    writeFileSync(`${receiptPath(proof)}.generation`, name, { mode: 0o600 });
    removeReceipt(proof);
    return { proof, file, name };
  });
}

function removeReceipt(proof) {
  try {
    unlinkSync(receiptPath(proof));
    return true;
  } catch (error) {
    return error.code === "ENOENT";
  }
}

export function matchingProof(
  command,
  manifest = "scripts/quality/validation-plan.json",
) {
  try {
    return manifestAt(manifest).reuse.find(
      (entry) => JSON.stringify(entry.command) === JSON.stringify(command),
    )?.proof;
  } catch (error) {
    if (error.code === "VALIDATION_BINDINGS") throw error;
    return undefined;
  }
}

function validReceipt(receipt, proof) {
  const age = Date.now() - receipt.completedAt;
  return (
    Number.isSafeInteger(receipt.completedAt) &&
    receipt.completedAt > 0 &&
    receipt.generation === generation(proof) &&
    typeof receipt.generation === "string" &&
    (receipt.dependencies ?? []).every(
      (entry) =>
        typeof entry.proof === "string" &&
        typeof entry.generation === "string" &&
        generation(entry.proof) === entry.generation &&
        !hasActive(entry.proof),
    ) &&
    receipt.version === 1 &&
    receipt.proof === proof &&
    age >= 0 &&
    age <= TTL
  );
}
export function reuseValidation(
  command,
  manifest = "scripts/quality/validation-plan.json",
) {
  const proof = matchingProof(command, manifest);
  if (!proof) return false;
  try {
    recoverActive(activeDirectory(proof), () => invalidate([proof]));
  } catch {
    return false;
  }
  if (hasActive(proof)) return false;
  try {
    const receiptBytes = readFileSync(receiptPath(proof), "utf8");
    const receipt = JSON.parse(receiptBytes);
    if (!validReceipt(receipt, proof)) return false;
    const current = snapshot(manifest);
    if (
      receipt.identity !== current.identity ||
      receipt.artifacts !== current.artifacts
    )
      return false;
    const after = snapshot(manifest);
    if (
      hasActive(proof) ||
      generation(proof) !== receipt.generation ||
      readFileSync(receiptPath(proof), "utf8") !== receiptBytes ||
      !validReceipt(receipt, proof) ||
      after.identity !== current.identity ||
      after.artifacts !== current.artifacts
    )
      return false;
    console.log(
      `validação local: reutilizando ${proof} completo aprovado neste checkout (até 30 minutos).`,
    );
    return true;
  } catch (error) {
    if (error.code === "VALIDATION_UNCACHEABLE") console.log(error.message);
    return false;
  }
}

function prepareEvidence(manifest, proves, issuer) {
  try {
    const before = snapshot(manifest);
    const internal =
      issuer === "scripts/quality/pre-push.mjs" ||
      (issuer === "scripts/quality/validation-runner.mjs" &&
        before.manifest.executions?.length);
    if (
      (!internal && !before.manifest.issuers?.includes(issuer)) ||
      !git("ls-files", "--", issuer ?? "")
    )
      return undefined;
    if (
      proves.some(
        (proof) =>
          !before.manifest.reuse.some((entry) => entry.proof === proof),
      )
    )
      return undefined;
    return before;
  } catch (error) {
    if (error.code === "VALIDATION_UNCACHEABLE") console.log(error.message);
    return undefined;
  }
}
function invalidate(proves) {
  let revoked = true;
  for (const proof of proves) {
    let changed = false;
    try {
      writeFileSync(`${receiptPath(proof)}.generation`, randomUUID(), {
        mode: 0o600,
      });
      changed = true;
    } catch {
      /* O marcador só sai se a geração ou o recibo foi revogado. */
    }
    const removed = removeReceipt(proof);
    revoked = revoked && (changed || removed);
  }
  return revoked;
}
function publishEvidence(
  before,
  active,
  manifest,
  dependencies = [],
  completedAt = Date.now(),
) {
  if (!before) return;
  try {
    const after = snapshot(manifest);
    if (
      before.identity !== after.identity ||
      !dependencies.every(
        (entry) =>
          generation(entry.proof) === entry.generation &&
          !hasActive(entry.proof),
      )
    )
      return;
    for (const { proof, name } of active) {
      if (hasActive(proof, name) || generation(proof) !== name) continue;
      const destination = receiptPath(proof);
      const temporary = `${destination}.${process.pid}.tmp`;
      writeFileSync(
        temporary,
        JSON.stringify({
          version: 1,
          proof,
          dependencies: dependencies.filter((entry) => entry.proof !== proof),
          generation: name,
          completedAt,
          identity: after.identity,
          artifacts: after.artifacts,
        }),
        { mode: 0o600 },
      );
      renameSync(temporary, destination);
    }
  } catch {
    /* cache opcional: sem evidência nova se fingerprint falhar */
  }
}
// Provas emitidas juntas são conservadoramente revogadas juntas ao revalidar.
function relatedProofs(proves, manifest) {
  const related = new Set(proves);
  try {
    const executions = manifestAt(manifest).executions ?? [];
    let changed;
    do {
      changed = false;
      for (const entry of executions) {
        if (!entry.proves?.some((proof) => related.has(proof))) continue;
        for (const proof of entry.proves) {
          if (!related.has(proof)) {
            related.add(proof);
            changed = true;
          }
        }
      }
    } while (changed);
  } catch {
    // Contrato inválido nunca ganha provas; preserva a revogação solicitada.
  }
  return [...related];
}
/** Captura interna: o consumidor não promove um booleano solto a uma prova maior. */
function captureReusable(command, manifest) {
  if (!command || !reuseValidation(command, manifest)) return undefined;
  try {
    const proof = matchingProof(command, manifest);
    const bytes = readFileSync(receiptPath(proof), "utf8");
    const receipt = JSON.parse(bytes);
    const current = snapshot(manifest);
    if (
      !validReceipt(receipt, proof) ||
      hasActive(proof) ||
      receipt.identity !== current.identity ||
      receipt.artifacts !== current.artifacts
    )
      return undefined;
    return {
      proof,
      generation: receipt.generation,
      identity: current.identity,
      artifacts: current.artifacts,
      bytes,
    };
  } catch {
    return undefined;
  }
}

export async function withValidationEvidence(
  {
    manifest = "scripts/quality/validation-plan.json",
    proves = [],
    issuer,
    record = true,
    reuse,
  } = {},
  execute,
) {
  const inherited = inheritedExecutions(manifest, receiptPath).filter((entry) =>
    proves.includes(entry.proof),
  );
  const candidate = record ? captureReusable(reuse, manifest) : undefined;
  const before = record ? prepareEvidence(manifest, proves, issuer) : undefined;
  const cached =
    candidate &&
    proves.includes(candidate.proof) &&
    before?.identity === candidate.identity &&
    before?.artifacts === candidate.artifacts &&
    validReceipt(JSON.parse(candidate.bytes), candidate.proof) &&
    generation(candidate.proof) === candidate.generation &&
    !hasActive(candidate.proof)
      ? candidate
      : undefined;
  const protectedProof = (proof) =>
    inherited.some((entry) => entry.proof === proof) || cached?.proof === proof;
  const owned = proves.filter((proof) => !protectedProof(proof));
  const affected = relatedProofs(proves, manifest);
  let active = [];
  try {
    invalidate(
      relatedProofs(owned, manifest).filter((proof) => !protectedProof(proof)),
    );
    active = startExecutions(owned);
  } catch {
    invalidate(affected);
  }
  try {
    manifestAt(manifest);
    const result = await execute({
      env: childEnvironment(manifest, [...inherited, ...active]),
      reused: Boolean(cached),
    });
    if (typeof result === "number" && result !== 0)
      throw new Error("gate de validação reprovou");
    const dependencies = cached
      ? [{ proof: cached.proof, generation: cached.generation }]
      : [];
    const unchanged =
      !cached ||
      (validReceipt(JSON.parse(cached.bytes), cached.proof) &&
        generation(cached.proof) === cached.generation &&
        readFileSync(receiptPath(cached.proof), "utf8") === cached.bytes);
    if (active.length === owned.length && unchanged)
      publishEvidence(
        before,
        [
          ...active,
          ...(cached ? [{ proof: cached.proof, name: cached.generation }] : []),
        ],
        manifest,
        dependencies,
        cached ? JSON.parse(cached.bytes).completedAt : undefined,
      );
    return result;
  } catch (error) {
    invalidate(affected);
    throw error;
  } finally {
    for (const { file } of active) {
      try {
        unlinkSync(file);
      } catch {
        /* marcador residual impede reuso */
      }
    }
  }
}
