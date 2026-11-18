#!/usr/bin/env bash
set -euo pipefail
# Ambiente exclusivo das verificações locais, como o ambiente sintético do CI.
# O deploy continua validando as variáveis reais do EnvironmentFile da VPS.
#
# Sem ele o pre-push só ficava verde em quem já tinha a DSN exportada no shell,
# e o hook reprovava por ambiente em vez de reprovar por código.
project_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# A recusa vem ANTES do `cd`: no checkout de produção este wrapper não pode dar
# nem o primeiro passo, porque trocaria o ambiente real por um sintético.
if [[ "$project_dir" == /var/www/* ]]; then
    echo 'Ambiente sintético de qualidade indisponível no diretório de produção.' >&2
    exit 2
fi
cd "$project_dir"

# A identidade é capturada antes dos segredos sintéticos e da DSN efêmera.
# Só os dois agregados completos podem declarar a prova de check:all; outros
# comandos continuam pelo wrapper genérico, sem emitir essa evidência.
if [[ "${1:-}" == "--validation-execution" ]]; then
    shift
    if [[ $# -ne 2 || "${1:-}" != "composer" || ("${2:-}" != "check:all:run" && "${2:-}" != "check:ci-local:run") ]]; then
        echo 'Execução interna de evidência exige composer check:all:run ou check:ci-local:run, sem argumentos extras.' >&2
        exit 2
    fi
elif [[ $# -eq 2 && "${1:-}" == "composer" && ("${2:-}" == "check:all" || "${2:-}" == "check:ci-local") ]]; then
    exec node scripts/quality/validation-runner.mjs --proof check:all -- \
        bash scripts/quality/with_local_config.sh --validation-execution composer "${2}:run"
fi

export CI=1

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
# Este bot não cobra nem paga: não há segredo de gateway para sintetizar aqui.
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

# O cluster levantado aqui fornece DSN/usuário para a validação de configuração
# e nada mais: o `env -u` de DENTRO tira a DSN dele do ambiente dos gates, e
# cada suíte que precisa de banco sobe o seu, vazio, pelo mesmo with_postgres.
env -u CHATBOTS_PG_TEST_DSN bash scripts/quality/with_postgres.sh \
    env -u CHATBOTS_PG_TEST_DSN "$@"
