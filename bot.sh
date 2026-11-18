#!/bin/bash

# Script para manter o bot Telegram sempre ativo (reinicia em caso de falha)
# Obtém o diretório onde este script está localizado (robusto a qualquer cwd)
dir=$(dirname "$(realpath "$0")")

# Mantém o bot rodando continuamente mesmo se houver falhas
while true; do
  php "$dir/bot.php"
  # Pausa breve para evitar loop quente caso o PHP falhe no boot
  sleep 1
done
