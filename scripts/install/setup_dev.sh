#!/usr/bin/env bash

# Prepara somente este checkout para desenvolvimento. Não instala pacotes do SO,
# não inicia serviços e não altera configuração global da máquina.

set -euo pipefail

if (($# != 0)); then
    echo "Uso: setup_dev.sh (sem argumentos). O setup não instala npm global." >&2
    exit 2
fi

project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_dir"

for comando in php composer node npm; do
    if ! command -v "$comando" >/dev/null 2>&1; then
        echo "Dependência ausente: $comando. Instale os pré-requisitos descritos em docs/OPERACAO.md." >&2
        exit 1
    fi
done

if ! php -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);'; then
    echo "PHP 8.5.12 ou superior na linha 8.5 é obrigatório. Encontrado: $(php --version | head -n 1)" >&2
    exit 1
fi

# shellcheck disable=SC1091 # o helper é resolvido a partir da raiz do checkout.
source scripts/install/verificar_node_runtime.sh

composer install --no-interaction --prefer-dist --no-progress
npm ci
composer install -d tools/phpcpd --no-interaction --prefer-dist --no-progress

echo "Checkout de desenvolvimento preparado. Configure inclusos/config.php separadamente se precisar executar o bot."
