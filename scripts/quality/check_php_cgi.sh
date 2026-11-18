#!/usr/bin/env bash
# O setup-php fornece CGI; a suíte usa o binário irmão de PHP_BINARY.
set -euo pipefail

php_cgi="$(php -r 'echo dirname(PHP_BINARY), "/php-cgi";')"
if [[ ! -x "$php_cgi" ]]; then
  printf 'CGI de teste ausente ou não executável: %s\n' "$php_cgi" >&2
  exit 1
fi

cli_version="$(php -r 'echo PHP_VERSION;')"
cgi_banner="$("$php_cgi" -v)"
if [[ "$cgi_banner" != "PHP ${cli_version} (cgi-fcgi)"* ]]; then
  printf 'CGI incompatível com PHP CLI %s: %s\n' "$cli_version" "$cgi_banner" >&2
  exit 1
fi

printf 'CGI de teste validado: %s (PHP %s)\n' "$php_cgi" "$cli_version"
