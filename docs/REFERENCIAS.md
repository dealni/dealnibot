# Referências — DealniBot

Catálogo do que o bot usa, com versão e link oficial. As versões vêm de
`composer.json`, `composer.lock`, `package.json`, `tools/phpcpd/composer.lock` e
dos workflows; em divergência, o manifesto vence. Como cada peça é usada está
em [INTEGRACOES.md](INTEGRACOES.md) e [TESTES.md](TESTES.md#portões-de-qualidade).

## Integrações externas

| Serviço | Papel | Doc oficial |
|---|---|---|
| Telegram Bot API | webhook, `getUpdates`, `sendMessage`, `sendChatAction`, download de arquivo | <https://core.telegram.org/bots/api> · [FAQ e limites](https://core.telegram.org/bots/faq) |
| OpenRouter | `chat/completions` e `audio/transcriptions` | <https://openrouter.ai/docs> · [modelos](https://openrouter.ai/models) |
| OpenAI GPT-6 Luna (via OpenRouter) | `openai/gpt-6-luna`: texto, áudio transcrito e visão | <https://openrouter.ai/models/openai/gpt-6-luna> |
| OpenAI Whisper (via OpenRouter) | `openai/whisper-large-v3-turbo`: transcrição | <https://openrouter.ai/models/openai/whisper-large-v3-turbo> |
| PostgreSQL | estado durável | <https://www.postgresql.org/docs/17/index.html> · [PDO_PGSQL](https://www.php.net/manual/en/ref.pdo-pgsql.php) · [locks explícitos](https://www.postgresql.org/docs/17/explicit-locking.html) |
| Redis | cota, admissão e memória | <https://redis.io/docs/latest/> · [EVAL/Lua](https://redis.io/docs/latest/develop/programmability/eval-intro/) · [SET](https://redis.io/docs/latest/commands/set/) · [Cluster e hash slots](https://redis.io/docs/latest/operate/oss_and_stack/management/scaling/) |

Política do OpenRouter: [seleção de provedor](https://openrouter.ai/docs/guides/routing/provider-selection),
[logging do provedor](https://openrouter.ai/docs/guides/privacy/provider-logging)
e [coleta de dados](https://openrouter.ai/docs/guides/privacy/data-collection).
O contrato adotado está em [INTEGRACOES.md](INTEGRACOES.md#política-de-dados-da-ia).

Borda: [Cloudflare Full (strict)](https://developers.cloudflare.com/ssl/origin-configuration/ssl-modes/full-strict/)
e [DNSSEC](https://developers.cloudflare.com/dns/dnssec/).

## Runtime e plataforma

| Componente | Versão | Doc oficial |
|---|---|---|
| PHP | `>=8.5.12 <8.6` (`config.platform.php` 8.5.12) | <https://www.php.net/docs.php> · [suporte](https://www.php.net/supported-versions.php) · [changelog](https://www.php.net/ChangeLog-8.php) |
| PHP-FPM | 8.5, pool próprio | <https://www.php.net/manual/en/install.fpm.configuration.php> |
| Nginx | a do host | <https://nginx.org/en/docs/> |
| PostgreSQL | 16+ (workflows configurados para 16 e 17) | <https://www.postgresql.org/docs/17/index.html> · [versões](https://www.postgresql.org/support/versioning/) |
| Redis | 2.6.12+, instância dedicada | <https://redis.io/docs/latest/> |
| cron (Debian/Ubuntu) | a do host | <https://man7.org/linux/man-pages/man5/crontab.5.html> · <https://sources.debian.org/src/cron/> |
| Node / npm | 24.22.1 (`.node-version`, `engines` `>=24.22.1 <25`) / 12.2.1 (`packageManager`) | <https://nodejs.org/docs/latest-v24.x/api/> · [calendário LTS](https://github.com/nodejs/release#release-schedule) |

A política de suporte do Node é a do [calendário oficial](https://github.com/nodejs/Release#release-schedule).
O pino local não é uma promessa de suporte de versões futuras.

## Dependências

### Produção

| Pacote | Manifesto | Lock | Doc oficial |
| --- | --- | --- | --- |
| `monolog/monolog` | `^3` | 3.12.1 | <https://github.com/Seldaek/monolog/blob/main/doc/01-usage.md> |
| `psr/log` | `^3` | 3.0.2 | <https://www.php-fig.org/psr/psr-3/> |

Extensões exigidas: `ctype`, `curl`, `filter`, `json`, `mbstring`, `pdo`,
`pdo_pgsql`, `redis` (`^6.3`, [phpredis](https://github.com/phpredis/phpredis))
([manual](https://www.php.net/manual/en/extensions.alphabetical.php)).

### Desenvolvimento — Composer

| Pacote | Manifesto | Lock | Doc oficial |
| --- | --- | --- | --- |
| `phpunit/phpunit` | `^13.4` | 13.4.4 | <https://docs.phpunit.de/> |
| `phpstan/phpstan` | `^2.3` | 2.3.1 | <https://phpstan.org/user-guide/getting-started> |
| `phpstan/phpstan-strict-rules` | `^2.1` | 2.1.0 | <https://github.com/phpstan/phpstan-strict-rules> |
| `phpstan/phpstan-phpunit` | `^2.1` | 2.1.1 | <https://github.com/phpstan/phpstan-phpunit> |
| `phpstan/phpstan-deprecation-rules` | `^2.0` | 2.0.5 | <https://github.com/phpstan/phpstan-deprecation-rules> |
| `friendsofphp/php-cs-fixer` | `^3.95` | 3.95.27 | <https://cs.symfony.com/> |
| `rector/rector` | `^2.7` | 2.7.0 | <https://getrector.com/documentation> |
| `maglnet/composer-require-checker` | `^4.24` | 4.24.0 | <https://github.com/maglnet/ComposerRequireChecker> |
| `icanhazstring/composer-unused` | `^0.9` | 0.9.6 | <https://github.com/composer-unused/composer-unused> |
| `editorconfig-checker/editorconfig-checker` | `^11.0` | 11.0.0 | <https://github.com/editorconfig-checker/editorconfig-checker.php> |

### Desenvolvimento — npm

| Pacote | Versão | Doc oficial |
| --- | --- | --- |
| `@commitlint/cli` | 21.2.4 | <https://commitlint.js.org/reference/cli.html> |
| `@commitlint/config-conventional` | 21.2.4 | <https://github.com/conventional-changelog/commitlint/tree/v21.2.4/%40commitlint/config-conventional> |
| `lefthook` | 2.2.2 | <https://lefthook.dev/installation/node/> |

## Ferramental fora do manifesto

| Ferramenta | Versão | Doc oficial |
|---|---|---|
| Composer | 2.10.3 | <https://getcomposer.org/doc/> |
| ShellCheck | 0.11.0 | <https://github.com/koalaman/shellcheck/releases/tag/v0.11.0> |
| actionlint | 1.7.12 | <https://github.com/rhysd/actionlint/blob/v1.7.12/docs/usage.md> |
| Semgrep CE | 1.182.1 | <https://docs.semgrep.dev/deployment/oss-deployment> |
| gitleaks | 8.30.1 (exigida por `check_gitleaks.sh`) | <https://github.com/gitleaks/gitleaks/releases/tag/v8.30.1> |
| PHPCPD (`systemsdk/phpcpd`, em `tools/phpcpd/`) | `^9.2` (lock v9.2.0) | <https://github.com/systemsdk/phpcpd> |
| `sandbox-exec` / `unshare` | a do sistema | <https://man7.org/linux/man-pages/man1/unshare.1.html> |
| `jq` | a da máquina | <https://jqlang.github.io/jq/manual/> |
| `git` | a da máquina | <https://git-scm.com/docs> |
| `pg_dump` / `pg_restore` | a do PostgreSQL do host | <https://www.postgresql.org/docs/17/app-pgdump.html> · <https://www.postgresql.org/docs/17/app-pgrestore.html> |
| systemd (`ExecMainStartTimestamp`) | v248+ | <https://www.freedesktop.org/software/systemd/man/latest/systemctl.html> |
| `flock` (util-linux) | a do host | <https://man7.org/linux/man-pages/man1/flock.1.html> |

O PHPCPD 9.2 considera operadores e outros tokens de um caractere na detecção.
As duplicações reveladas pela migração foram extraídas para helpers comuns de
configuração Telegram, transporte de IA e memória. O teto continua zero.
[Notas oficiais da versão 9.2](https://github.com/systemsdk/phpcpd/releases/tag/v9.2.0).

**PHPMD ficou de fora.** A última estável emite depreciações no PHP 8.5 (a
suíte reprova por depreciação), a linha 3 não tem release e o parser do pdepend
falha em parte do código procedural sem reprovar. Os achados úteis
(`UnusedLocalVariable`, `UnusedFormalParameter`) o PHPStan já cobre. Reavaliar
quando sair uma 3.0 estável (<https://phpmd.org/>).

## CI

A detecção de rede isolada usa [net_get_interfaces](https://www.php.net/manual/en/function.net-get-interfaces.php).
A [implementação no PHP 8.5](https://github.com/php/php-src/blob/PHP-8.5/ext/standard/net.c#L247-L289)
consulta `getifaddrs`, que enumera as interfaces da namespace do processo.
O caminho `/sys/class/net` pode refletir uma montagem anterior à troca de namespace.

O [setup-php fixado neste repositório](https://github.com/shivammathur/setup-php/blob/eb7c497e18156a6bbabfef1d3a82760b9eda3962/src/scripts/linux.sh#L187-L198)
seleciona a alternativa `php-cgi`; o
[php-builder](https://github.com/shivammathur/php-builder/blob/6ded9a19e2a2b2db49d35b45e4dd2746c55f96d6/README.md#sapi-support)
fornece CGI entre os SAPIs padrão. O preflight local valida o executável e sua
versão antes dos testes, sem depender de outro pacote PHP pelo apt.

O job do Semgrep seleciona Python `3.14` via [actions/setup-python](https://github.com/actions/setup-python) `v7.1.0`
antes de criar seu venv. Isso mantém a instalação nas wheels compatíveis; a
versão do Python padrão do runner não decide o ambiente do scanner.

Veja o mapa de workflows em [TESTES.md](TESTES.md#ci) e a
[documentação do GitHub Actions](https://docs.github.com/pt/actions).

As actions e SHAs exatos são os de `.github/workflows/` e
`.github/actions/setup-node/action.yml`. Consulte os projetos oficiais:
[checkout](https://github.com/actions/checkout),
[setup-node](https://github.com/actions/setup-node),
[setup-python](https://github.com/actions/setup-python),
[setup-php](https://github.com/shivammathur/setup-php),
[cache](https://github.com/actions/cache) e
[upload-artifact](https://github.com/actions/upload-artifact).

Nos workflows: `php-version: '8.5'`, `tools: composer:2.10.3`, gitleaks,
ShellCheck e actionlint baixados com SHA-256 conferido, Semgrep num `venv` com
versão exata e PostgreSQL 16 e 17 instalado no runner, sem contêiner.

O Dependabot (`.github/dependabot.yml`,
<https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference>)
tem seis entradas: `npm` e `composer` na raiz (semanal), `composer` em
`tools/phpcpd` (mensal) e `github-actions` na raiz, em
`.github/actions/setup-node` e `.github/actions/setup-postgres` (semanal).

## Normas e referências complementares

| Norma | Onde se aplica | Referência |
|---|---|---|
| PSR-12 | estilo do PHP | <https://www.php-fig.org/psr/psr-12/> |
| PSR-4 | autoload de `src/` | <https://www.php-fig.org/psr/psr-4/> |
| PSR-3 | interface de log | <https://www.php-fig.org/psr/psr-3/> |
| Conventional Commits | mensagens de commit | <https://www.conventionalcommits.org/pt-br/v1.0.0/> |
| EditorConfig | whitespace | <https://editorconfig.org/> |
| RFC 3339 | campo `ts` do log | <https://www.rfc-editor.org/rfc/rfc3339> |
| LGPD | contatos e transferência internacional | <https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm> |
