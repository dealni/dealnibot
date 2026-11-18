#!/usr/bin/env bash

# Prepara o arquivo antes de o operador preencher qualquer segredo.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

configuracao='inclusos/config.php'
if [ -L "$configuracao" ] || { [ -e "$configuracao" ] && [ ! -f "$configuracao" ]; }; then
    echo "Recusando configuração que não seja um arquivo regular: $configuracao" >&2
    exit 1
fi

# Na VPS o pool lê o arquivo pelo grupo (root:dealnibot, ver instalar_linux.sh).
modo=600
case "$(pwd -P)" in
    /var/www/*) modo=640 ;;
esac

if [ -f "$configuracao" ]; then
    # Reexecução protege o conteúdo existente sem substituir credenciais.
    chmod "$modo" "$configuracao"
else
    # noclobber recusa um destino criado entre a verificação e a abertura.
    (
        umask 077
        set -o noclobber
        cat inclusos/config.example.php > "$configuracao"
    )
fi

echo "Configuração preparada com permissão 0${modo}: $configuracao"
