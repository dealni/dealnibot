#!/usr/bin/env bash
# Executado depois de unshare + setpriv, antes do comando de teste real.
set -euo pipefail

uid="$(/usr/bin/id -u)"
gid="$(/usr/bin/id -g)"
[[ "$uid" != 0 && "$uid" == "${CHATBOTS_CI_EXPECTED_UID:?}" ]]
[[ "$gid" == "${CHATBOTS_CI_EXPECTED_GID:?}" ]]
parent_ns="${CHATBOTS_CI_PARENT_NETNS:?}"
current_ns="$(/usr/bin/readlink /proc/self/ns/net)"
[[ "$parent_ns" =~ ^net:\[[0-9]+\]$ ]]
[[ "$current_ns" =~ ^net:\[[0-9]+\]$ && "$current_ns" != "$parent_ns" ]]
[[ "$#" -gt 0 ]]
printf 'Isolamento confirmado: uid=%s gid=%s netns=%s (pai=%s)\n' "$uid" "$gid" "$current_ns" "$parent_ns"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CHATBOTS_CI_RUNTIME_FILE="$repo_root/src/Support/RuntimeEnvironment.php" \
  php -r 'require getenv("CHATBOTS_CI_RUNTIME_FILE"); echo "Interfaces do namespace: ", implode(",", array_keys(net_get_interfaces() ?: [])), PHP_EOL; exit(\App\Support\RuntimeEnvironment::hasNetworkIsolation() ? 0 : 1);'
exec "$@"
