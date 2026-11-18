#!/usr/bin/env node

/**
 * Audita as licenças de toda entrada versionada no package-lock dos bots.
 * Esta árvore npm contém apenas o ferramental local (Lefthook e Commitlint),
 * mas continua sendo cadeia de fornecimento e segue a mesma política permissiva
 * usada pelos projetos JavaScript da frota.
 */

import { readFileSync } from "node:fs";

const PERMISSIVAS = new Set([
  "0BSD",
  "Apache-2.0",
  "BlueOak-1.0.0",
  "BSD-2-Clause",
  "BSD-3-Clause",
  "CC0-1.0",
  "ISC",
  "MIT",
  "MIT-0",
  "MPL-2.0",
  "Python-2.0",
  "Unlicense",
  "WTFPL",
  "Zlib",
]);

const lock = JSON.parse(readFileSync("package-lock.json", "utf8"));
if (lock === null || typeof lock !== "object" || Array.isArray(lock) || lock.packages === null || typeof lock.packages !== "object" || Array.isArray(lock.packages)) {
  throw new Error("package-lock.json não contém o mapa packages.");
}

const raiz = lock.packages[""];
if (!Object.hasOwn(lock.packages, "") || raiz === null || typeof raiz !== "object"
    || Array.isArray(raiz) || Object.keys(lock.packages).length < 2) {
  throw new Error("package-lock.json não contém a árvore esperada de dependências npm.");
}

const problemas = [];
for (const [caminho, pacote] of Object.entries(lock.packages)) {
  if (caminho === "") continue;
  if (pacote === null || typeof pacote !== "object" || Array.isArray(pacote)) {
    problemas.push(`${caminho}: manifesto inválido`);
    continue;
  }

  const licenca = pacote.license;
  if (typeof licenca !== "string" || !PERMISSIVAS.has(licenca)) {
    problemas.push(`${caminho}: licença ${typeof licenca === "string" ? licenca : "não declarada"}`);
  }
}

if (problemas.length > 0) {
  console.error("Dependências npm fora da política de licenças:\n- " + problemas.join("\n- "));
  process.exit(1);
}

console.log(`Licenças npm válidas: ${Object.keys(lock.packages).length - 1} dependências auditadas.`);
