#!/usr/bin/env bash

# Valida o runtime usado pelos hooks antes de executar npm ci. Com
# `--fix-npm`, instala primeiro o pin exato do npm declarado no manifesto; esse
# é o caminho usado pelos instaladores locais, enquanto o CI já o fixa na
# composite action.
# A faixa vem do package.json para que o instalador não fique com uma segunda
# declaração silenciosa do contrato de Node/npm.

set -euo pipefail

fixar_npm=0
case "${1:-}" in
    "") ;;
    --fix-npm) fixar_npm=1 ;;
    *)
        echo "Uso: verificar_node_runtime.sh [--fix-npm]" >&2
        exit 2
        ;;
esac

if ! command -v node >/dev/null 2>&1; then
    echo "❌ Node.js é necessário para os hooks, mas não foi encontrado." >&2
    exit 1
fi
node_version="$(node --version)"

if ! node <<'NODE'
const fs = require("fs");

const pacote = JSON.parse(fs.readFileSync("package.json", "utf8"));
const faixa = pacote.engines && pacote.engines.node;
const partes = /^>=(\d+)\.(\d+)\.(\d+) <(\d+)$/.exec(faixa || "");
const atual = process.versions.node.split(".").map(Number);

if (partes === null || atual.some((parte) => !Number.isInteger(parte))) {
  console.error(`❌ package.json#engines.node ou Node inválido (Node ${process.versions.node}).`);
  process.exit(1);
}

const minimo = partes.slice(1, 4).map(Number);
const maximoMajor = Number(partes[4]);
const maiorOuIgual = atual[0] > minimo[0]
  || (atual[0] === minimo[0] && (atual[1] > minimo[1]
    || (atual[1] === minimo[1] && atual[2] >= minimo[2])));
const menorQueMaximo = atual[0] < maximoMajor;

if (!maiorOuIgual || !menorQueMaximo) {
  console.error(`❌ Node.js ${process.versions.node} fora de ${faixa}.`);
  process.exit(1);
}
NODE
then
    echo "   Instale a linha declarada em package.json#engines.node e rode novamente." >&2
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    echo "❌ npm é necessário para instalar os hooks, mas não foi encontrado." >&2
    exit 1
fi

# O instalador local recebe npm junto com Node. Fixe o mesmo pin que a
# composite action usa no CI antes de qualquer `npm ci`/`npm run`; sem isso,
# `devEngines.packageManager` reprova a própria tentativa de instalar as
# dependências.
npm_version=""
if (( fixar_npm == 1 )); then
    package_manager="$(node -p "require('./package.json').packageManager ?? ''")"
    if [[ ! "$package_manager" =~ ^npm@[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        echo "❌ package.json#packageManager deve ser npm@X.Y.Z; recebido: ${package_manager:-ausente}" >&2
        exit 1
    fi
    npm_pin="${package_manager#npm@}"

    # npm aplica devEngines também a `npm prefix --global` quando o cwd é o
    # checkout. Consulte e atualize o prefixo a partir de um diretório neutro;
    # só depois coloque o binário pinado à frente do PATH do projeto.
    npm_bootstrap_dir="$(mktemp -d)"
    trap 'rm -rf "$npm_bootstrap_dir"' EXIT
    npm_version="$(
        cd "$npm_bootstrap_dir"
        npm --version 2>/dev/null || true
    )"
    if [[ "$npm_version" != "$npm_pin" ]]; then
        echo "Fixando npm ${npm_pin} (encontrado: ${npm_version:-ausente})..."
        npm_prefix="$(
            cd "$npm_bootstrap_dir"
            npm prefix --global 2>/dev/null || true
        )"
        if [[ -z "$npm_prefix" ]]; then
            echo "❌ Não foi possível descobrir o prefixo global do npm." >&2
            exit 1
        fi
        if [[ -w "$npm_prefix" ]]; then
            npm_install=(npm install --global --no-audit --no-fund)
        elif command -v sudo >/dev/null 2>&1; then
            echo "Prefixo global do npm não é gravável; usando sudo para fixar o pin."
            npm_install=(sudo npm install --global --no-audit --no-fund)
        else
            echo "❌ Prefixo global do npm não é gravável e sudo não está disponível: $npm_prefix" >&2
            exit 1
        fi
        (
            cd "$npm_bootstrap_dir"
            "${npm_install[@]}" "npm@${npm_pin}"
        )
        if [[ -n "$npm_prefix" && -x "$npm_prefix/bin/npm" ]]; then
            # Homebrew mantém o npm fornecido pela fórmula Node no PATH antes
            # do prefixo global. Recolocar esse prefixo na frente faz a versão
            # que acabamos de instalar ser efetivamente usada, sem escrever na
            # árvore gerenciada pelo Homebrew.
            export PATH="$npm_prefix/bin:$PATH"
            hash -r 2>/dev/null || true
        fi
        npm_version="$(
            cd "$npm_bootstrap_dir"
            npm --version 2>/dev/null || true
        )"
    fi
    rm -rf "$npm_bootstrap_dir"
    trap - EXIT
else
    npm_version="$(npm --version 2>/dev/null || true)"
fi

# O npm precisa respeitar package.json#engines.npm e o pin exato de
# package.json#packageManager; a composite aplica o mesmo contrato no CI.
if ! NPM_VERSION="$npm_version" node <<'NODE'
const fs = require("fs");

const pacote = JSON.parse(fs.readFileSync("package.json", "utf8"));
const faixa = pacote.engines && pacote.engines.npm;
const partes = /^>=(\d+)\.(\d+)\.(\d+) <(\d+)$/.exec(faixa || "");
const atual = /^(\d+)\.(\d+)\.(\d+)$/.exec(process.env.NPM_VERSION || "");
const pin = /^npm@(\d+)\.(\d+)\.(\d+)$/.exec(pacote.packageManager || "");
const devManager = pacote.devEngines && pacote.devEngines.packageManager;

if (partes === null || atual === null || pin === null) {
  console.error(`❌ npm, packageManager ou faixa inválidos em package.json#engines.npm/packageManager (npm ${process.env.NPM_VERSION || "ausente"}).`);
  process.exit(1);
}
if (devManager !== undefined
    && (devManager.name !== "npm" || devManager.version !== faixa)) {
  console.error("❌ engines.npm e devEngines.packageManager divergem.");
  process.exit(1);
}

const versao = atual.slice(1).map(Number);
const minimo = partes.slice(1, 4).map(Number);
const maiorOuIgual = versao[0] > minimo[0]
  || (versao[0] === minimo[0] && (versao[1] > minimo[1]
    || (versao[1] === minimo[1] && versao[2] >= minimo[2])));
if (!maiorOuIgual || versao[0] >= Number(partes[4])) {
  console.error(`❌ npm ${process.env.NPM_VERSION} fora de ${faixa}.`);
  process.exit(1);
}
if (atual[1] !== pin[1] || atual[2] !== pin[2] || atual[3] !== pin[3]) {
  console.error(`❌ npm ${process.env.NPM_VERSION} diverge de package.json#packageManager (${pacote.packageManager}).`);
  process.exit(1);
}
NODE
then
    echo "   Instale a versão exata declarada em package.json#packageManager e rode novamente." >&2
    exit 1
fi

echo "✅ Node.js ${node_version} e npm ${npm_version} compatíveis com package.json."
