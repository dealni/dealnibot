#!/usr/bin/env bash

# Roda um job agendado e, se ele falhar, avisa no Telegram.
#
# Uso (dentro de executar_com_ambiente.sh, que já carregou o environment):
#   avisar_se_falhar.sh JOB [--php] COMANDO [ARGUMENTOS...]
#
# O e-mail do cron não sai da VPS (docs/OPERACAO.md#monitoramento-e-alertas),
# então o próprio job avisa: com saída diferente de zero, chama
# scripts/operacao/avisar_falha.php com o nome do job, o código e o STDERR
# capturado, que vira o "fim do log" da mensagem (com credenciais redigidas).
# Com saída zero, o PHP só fala se havia falha anterior ("voltou a funcionar").
#
# O STDERR do job continua saindo no STDERR (e no MAILTO), o STDOUT não é
# tocado e o código de saída é o do job: o aviso nunca muda o resultado.
# `--php` usa o PHP85_BIN do environment com as conferências do wrapper de
# ambiente (absoluto, executável, canônico sem symlink e PHP 8.5 de fato).
# PHP85_BIN reprovado desliga o aviso e recusa o `--php`.

set -euo pipefail

uso='Uso: avisar_se_falhar.sh JOB [--php] COMANDO [ARGUMENTOS...]'
if (($# < 2)) || [[ ! "$1" =~ ^[a-z0-9][a-z0-9-]{0,39}$ ]]; then
    echo "$uso" >&2
    exit 2
fi
job="$1"
shift

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

canonicalizar_caminho_existente() {
    # GNU realpath aceita -e/--; o utilitário do macOS não.
    if [[ "$OSTYPE" == darwin* ]]; then
        realpath "$1"
    else
        realpath -e -- "$1"
    fi
}

# As mesmas conferências do `--php` de executar_com_ambiente.sh.
php85_bin_valido() {
    local canonico
    [[ "$1" == /* && -x "$1" && ! -L "$1" ]] || return 1
    canonico="$(canonicalizar_caminho_existente "$1" 2>/dev/null)" || return 1
    [[ "$canonico" == "$1" ]] || return 1
    "$1" -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);' >/dev/null 2>&1
}

php_bin="${PHP85_BIN:-}"
if ! php85_bin_valido "$php_bin"; then
    echo "PHP85_BIN ausente, não canônico ou não é PHP 8.5 (${php_bin:-<vazio>}); o aviso de falha de $job fica desligado." >&2
    php_bin=""
fi

if [[ "$1" == "--php" ]]; then
    shift
    if (($# == 0)); then
        echo "$uso" >&2
        exit 2
    fi
    if [[ -z "$php_bin" ]]; then
        echo "PHP85_BIN reprovado: $job não roda com --php." >&2
        exit 1
    fi
    set -- "$php_bin" "$@"
fi

log="$(mktemp "${TMPDIR:-/tmp}/aviso-$job.XXXXXX")" || {
    echo "Não foi possível criar o arquivo temporário do aviso; rodando $job sem aviso." >&2
    exec "$@"
}
trap 'rm -f "$log"' EXIT

"$@" 2>"$log" && codigo=0 || codigo=$?
cat "$log" >&2 || true

if [[ -n "$php_bin" ]]; then
    "$php_bin" "$raiz/scripts/operacao/avisar_falha.php" --job="$job" --codigo="$codigo" --log="$log" ||
        echo "Aviso de $job não enviado ao Telegram (veja a mensagem acima)." >&2
fi
exit "$codigo"
