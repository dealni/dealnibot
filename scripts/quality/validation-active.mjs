/** Marcadores não expiram: apenas morte ou troca de identidade comprovada os remove. */
import { execFileSync } from "node:child_process";
import { readFileSync, readdirSync, unlinkSync } from "node:fs";
import os from "node:os";
import path from "node:path";

export function processIdentity(pid) {
  try {
    if (process.platform === "linux") {
      const stat = readFileSync(`/proc/${pid}/stat`, "utf8");
      const start = stat.slice(stat.lastIndexOf(")") + 2).split(" ")[19];
      return `${readFileSync("/proc/sys/kernel/random/boot_id", "utf8").trim()}:${start}`;
    }
    if (process.platform !== "darwin") return undefined;
    const boot = execFileSync("/usr/sbin/sysctl", ["-n", "kern.boottime"], {
      encoding: "utf8",
      timeout: 10000,
      env: { ...process.env, TZ: "UTC", LC_ALL: "C" },
      stdio: ["ignore", "pipe", "pipe"],
    }).trim();
    const start = execFileSync(
      "/bin/ps",
      ["-p", String(pid), "-o", "lstart="],
      {
        encoding: "utf8",
        timeout: 10000,
        env: { ...process.env, TZ: "UTC", LC_ALL: "C" },
        stdio: ["ignore", "pipe", "pipe"],
      },
    ).trim();
    // lstart tem resolução de segundo: igualdade sempre bloqueia, nunca prova morte.
    const epoch = boot.match(/sec = (\d+), usec = (\d+)/u);
    return epoch && start ? `${epoch[1]}.${epoch[2]}:${start}` : undefined;
  } catch {
    return undefined;
  }
}
export function currentMarker() {
  return {
    version: 1,
    pid: process.pid,
    host: os.hostname(),
    start: processIdentity(process.pid),
  };
}
export function markerState(
  marker,
  { probe = process.kill, identity = processIdentity } = {},
) {
  if (
    !Number.isSafeInteger(marker.pid) ||
    marker.pid <= 0 ||
    (marker.host !== undefined && marker.host !== os.hostname())
  )
    return "unknown";
  try {
    probe(marker.pid, 0);
  } catch (error) {
    return error.code === "ESRCH" ? "dead" : "unknown";
  }
  if (marker.version !== 1 || typeof marker.start !== "string" || !marker.start)
    return "unknown";
  const start = identity(marker.pid);
  if (!start) return "unknown";
  return start === marker.start ? "live" : "dead";
}
export function recoverActive(directory, invalidate) {
  let names;
  try {
    names = readdirSync(directory);
  } catch (error) {
    if (error.code === "ENOENT") return;
    throw error;
  }
  for (const name of names) {
    const file = path.join(directory, name);
    try {
      const bytes = readFileSync(file, "utf8");
      // Compatibilidade com os marcadores vazios antigos: só ESRCH prova morte.
      const marker = bytes
        ? JSON.parse(bytes)
        : { pid: Number(name.match(/^(\d+)-/u)?.[1]) };
      if (markerState(marker) !== "dead") continue;
      if (invalidate() !== true) continue;
      if (
        readFileSync(file, "utf8") === bytes &&
        markerState(marker) === "dead"
      )
        unlinkSync(file);
    } catch {
      // Arquivo ilegível, corrompido ou identidade indeterminada mantém o bloqueio.
    }
  }
}
