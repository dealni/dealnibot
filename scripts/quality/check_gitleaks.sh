#!/usr/bin/env bash

# Varre o HISTÓRICO INTEIRO do repositório atrás de segredos, localmente.
#
# Por que existe: o pre-push é a validação real deste repositório, e a varredura
# de segredos do histórico não pode ficar dependendo de um runner remoto. Uma
# chave commitada e apagada no commit seguinte continua válida no provedor e
# continua legível por quem clonou; só a varredura do histórico pega isso.
#
# Diferença para o hook de pre-commit: lá a varredura é do ÍNDICE (`--staged`),
# barata e de cada commit. Aqui é `--log-opts='--all --full-history'`, a mesma
# forma da varredura agendada, e por isso este gate vive no `check:all`.
#
# Ausência de ferramenta REPROVA. Deixar passar em silêncio transformaria o
# portão em decoração: o repositório passaria a "estar limpo" só porque ninguém
# olhou. A mensagem abaixo diz exatamente como instalar.
#
# Achado NOVO reprova. Falso positivo se resolve com allowlist em .gitleaks.toml;
# segredo que JÁ vazou e foi rotacionado se registra em .gitleaksignore, por
# fingerprint. Fixture de teste não entra em allowlist: troque o valor ou gere-o
# em tempo de execução.

set -euo pipefail

# --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
# Versão esperada do gitleaks, a mesma fixada no job de segredos dos workflows.
GITLEAKS_VERSAO_ESPERADA='8.30.1'
# --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

# O Git exporta estas variáveis quando chama um hook; limpá-las faz toda leitura
# abaixo resolver o worktree atual e o seu próprio índice.
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_OBJECT_DIRECTORY \
    GIT_ALTERNATE_OBJECT_DIRECTORIES GIT_COMMON_DIR GIT_NAMESPACE \
    GIT_CEILING_DIRECTORIES GIT_PREFIX

raiz=$(git rev-parse --show-toplevel)
cd "$raiz"

if ! command -v gitleaks >/dev/null 2>&1; then
    echo "check:gitleaks: gitleaks não encontrado no PATH." >&2
    echo "    Instale com: brew install gitleaks" >&2
    echo "    Versão esperada: ${GITLEAKS_VERSAO_ESPERADA} (a mesma dos workflows)." >&2
    echo "    Este portão não passa sem a ferramenta: sem varredura não há prova." >&2
    exit 1
fi

versao=$(gitleaks version 2>/dev/null || true)
if [ "$versao" != "$GITLEAKS_VERSAO_ESPERADA" ]; then
    echo "check:gitleaks: gitleaks ${versao:-desconhecido} instalado, ${GITLEAKS_VERSAO_ESPERADA} esperado." >&2
    echo "    A varredura continua; alinhe a versão para reproduzir o resultado dos workflows." >&2
fi

# Contraprova do detector: uma versão sã precisa disparar a regra padrão de
# token do GitHub. O valor é sintético, montado em duas partes para não parecer
# um segredo no próprio arquivo, e a saída é descartada.
prefixo_sonda='ghp_'
sufixo_sonda='4gK9mQ2xV7pL5zR8cN1tS6hD3wF0yJbUeA7s'
set +e
printf 'probe = "%s%s"\n' "$prefixo_sonda" "$sufixo_sonda" \
    | gitleaks stdin --redact --no-banner --log-level warn >/dev/null 2>&1
codigo_sonda=$?
set -e
if [ "$codigo_sonda" -ne 1 ]; then
    echo "check:gitleaks: o gitleaks não detectou o segredo sintético de contraprova." >&2
    echo "    Um detector que não acha nada aprova qualquer coisa; corrija a instalação." >&2
    exit 1
fi

# --redact para o valor achado nunca ir parar em log ou terminal compartilhado.
if ! gitleaks git . --log-opts='--all --full-history' --redact --no-banner --log-level warn; then
    echo "check:gitleaks: o gitleaks encontrou segredo no histórico." >&2
    echo "    Falso positivo: allowlist em .gitleaks.toml." >&2
    echo "    Segredo real: rotacione a credencial e registre o fingerprint em .gitleaksignore." >&2
    echo "    Fixture de teste: troque o valor ou gere-o em tempo de execução." >&2
    exit 1
fi

echo "Segredos: histórico completo varrido pelo gitleaks, sem achados novos."
