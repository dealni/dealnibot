#!/usr/bin/env node
import { spawnSync } from "node:child_process";
import {
  executionProofs,
  withValidationEvidence,
} from "./validation-evidence.mjs";
const args = process.argv.slice(2);
let manifest = "scripts/quality/validation-plan.json";
const proves = [];
while (args.length && args[0] !== "--") {
  const option = args.shift();
  const value = args.shift();
  if (!value || !["--manifest", "--proof"].includes(option))
    throw new Error(
      "uso: validation-runner.mjs [--manifest arquivo] [--proof check:all] -- comando argumentos",
    );
  if (option === "--manifest") manifest = value;
  else proves.push(value);
}
if (args.shift() !== "--" || !args.length)
  throw new Error("comando de validação ausente");
try {
  const allowed = executionProofs(args, manifest);
  const approved = proves.filter((proof) => allowed.includes(proof));
  await withValidationEvidence(
    {
      manifest,
      proves: approved,
      issuer: "scripts/quality/validation-runner.mjs",
    },
    ({ env }) => {
      const result = spawnSync(args[0], args.slice(1), {
        stdio: "inherit",
        env: { ...env, CI: "1" },
      });
      if (result.error || result.status !== 0)
        throw new Error("gate de validação reprovou ou não iniciou");
    },
  );
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
