# DealniBot

**Assistente pausado.** O `@DealniBot` mantém um aviso de pausa em conversas
privadas no Telegram.

O assistente de grupo participa quando alguém o menciona ou responde a uma
de suas mensagens. Usa o OpenRouter para conversar, interpretar imagens e
transcrever áudios. A memória é temporária e cada grupo tem uma cota diária.

O código usa PHP 8.5, PostgreSQL, Redis e Monolog. Para testar uma instância
própria, execute o bot em long polling no terminal. Os modelos de instalação
usam Nginx e PHP-FPM com webhook.

## Rodar localmente

Instale os [pré-requisitos](docs/OPERACAO.md#pré-requisitos), clone o
repositório e prepare as dependências e a configuração local.

```bash
git clone https://github.com/dealni/dealnibot-publico.git
cd dealnibot-publico
composer setup:dev
bash scripts/install/configurar_credenciais.sh
```

Use um bot de desenvolvimento próprio. O guia de setup descreve como preencher
as credenciais, preparar o PostgreSQL e o Redis e executar `php bot.php`.
[Setup local](docs/OPERACAO.md#setup-local).

## Validar

Execute `composer test` para rodar a suíte com credenciais sintéticas e
serviços descartáveis. O `pre-push` também valida o carregamento de
`inclusos/config.php`. Em um checkout sem esse arquivo, o gerador do CI cria
uma configuração descartável para executar o portão completo.

```bash
if [ ! -e inclusos/config.php ] && [ ! -L inclusos/config.php ]; then
    (umask 077; CI=true php scripts/quality/prepare_ci_config.php)
fi
bash scripts/quality/with_local_config.sh composer check:all
```

Os portões usam um checkout Git. O guia de testes lista as ferramentas
exigidas e explica o isolamento de rede. [Guia de testes](docs/TESTES.md).

## Documentação

- [Visão geral](docs/VISAO_GERAL.md), [funcionalidades](docs/FUNCIONALIDADES.md)
  e [arquitetura](docs/ARQUITETURA.md)
- [Dados](docs/DADOS.md), [integrações](docs/INTEGRACOES.md) e
  [segurança](docs/SEGURANCA.md)
- [Operação](docs/OPERACAO.md), [testes](docs/TESTES.md),
  [convenções](docs/CONVENCOES.md) e [referências](docs/REFERENCIAS.md)

## Licença

Código autoral proprietário. Todos os direitos reservados.

A classe Telegram e os traits derivados de TelegramBotPHP incluem código
distribuído sob licença MIT. [Aviso do TelegramBotPHP](LICENSES/TelegramBotPHP.txt).
