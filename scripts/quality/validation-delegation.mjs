/** Delegação transitória: só uma execução ancestral viva pode ser dona da prova. */
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import { markerState } from "./validation-active.mjs";
const KEY = "QUALITY_VALIDATION_PARENT";

function ancestors() {
  const rows = execFileSync("ps", ["-axo", "pid=,ppid="], {
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });
  const parents = new Map(
    rows
      .trim()
      .split("\n")
      .map((row) => row.trim().split(/\s+/u).map(Number)),
  );
  const result = new Set();
  let pid = process.ppid;
  while (pid > 1 && !result.has(pid)) {
    result.add(pid);
    pid = parents.get(pid);
  }
  return result;
}

export function inheritedExecutions(manifest, receiptPath) {
  try {
    const context = JSON.parse(process.env[KEY] ?? "null");
    if (
      !context ||
      context.manifest !== path.resolve(manifest) ||
      !Array.isArray(context.active)
    )
      return [];
    const parents = ancestors();
    return context.active.filter(({ proof, name }) => {
      if (
        typeof proof !== "string" ||
        typeof name !== "string" ||
        !/^\d+-[a-f0-9-]+$/u.test(name)
      )
        return false;
      const receipt = receiptPath(proof);
      if (readFileSync(`${receipt}.generation`, "utf8") !== name) return false;
      const marker = JSON.parse(
        readFileSync(path.join(`${receipt}.active`, name), "utf8"),
      );
      return parents.has(marker.pid) && markerState(marker) === "live";
    });
  } catch {
    return [];
  }
}

export function childEnvironment(manifest, active) {
  return {
    ...process.env,
    [KEY]: JSON.stringify({ manifest: path.resolve(manifest), active }),
  };
}
