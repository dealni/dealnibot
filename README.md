# DealniBot

**Projeto descontinuado.** O atendimento original foi encerrado. No Telegram,
o bot apenas informa o encerramento em conversas privadas. O repositório
preserva o código e a documentação como referência.

A aplicação original era um assistente de IA para grupos do Telegram, com
conversa por texto, interpretação de imagens e transcrição de áudio.
Mantinha memória curta e controlava a cota diária de cada grupo.

[Funcionalidades](docs/FUNCIONALIDADES.md) ·
[Arquitetura](docs/ARQUITETURA.md) · [Setup local](docs/OPERACAO.md#setup-local)

## Principais recursos

- Respostas por menção, resposta a uma mensagem do bot ou participação aleatória.
- Texto, imagens e áudio atendidos por integrações via OpenRouter.
- Memória conversacional separada por grupo, tópico e participante.
- Cota diária por grupo e controle de admissão de mídia.
- Recebimento durável de updates, com retentativa de processamento.

**Stack:** PHP 8.5 sem framework · PostgreSQL 16+ · Redis dedicado · Monolog ·
Nginx + PHP-FPM por webhook, com trabalhos de fundo por cron.
Node 24 e npm 12 são ferramentas de desenvolvimento. Consulte as versões em
[REFERENCIAS.md](docs/REFERENCIAS.md).

## Começar

Depois de instalar as dependências, crie o arquivo privado de configuração.

```bash
bash scripts/install/configurar_credenciais.sh
```

O [setup local](docs/OPERACAO.md#setup-local) descreve os pré-requisitos,
a configuração com bot e credenciais próprios, a criação do banco de
desenvolvimento e o início do Redis e do long polling. Os testes usam
configuração sintética e dispensam credenciais de serviços externos.

## Documentação

| Documento | Responsável por |
| --- | --- |
| [VISAO_GERAL.md](docs/VISAO_GERAL.md) | finalidade, glossário e limites do projeto |
| [ARQUITETURA.md](docs/ARQUITETURA.md) | componentes, pastas, fluxos, configuração e decisões |
| [DADOS.md](docs/DADOS.md) | tabelas, migrations, retenção, Redis, backup e restauração |
| [FUNCIONALIDADES.md](docs/FUNCIONALIDADES.md) | gatilhos, regras, mensagens, limites e fluxos críticos |
| [INTEGRACOES.md](docs/INTEGRACOES.md) | Telegram, OpenRouter e Redis |
| [OPERACAO.md](docs/OPERACAO.md) | setup, variáveis, comandos, deploy, rollback, logs e runbooks |
| [SEGURANCA.md](docs/SEGURANCA.md) | ameaças, segredos, superfície exposta, LGPD e política |
| [TESTES.md](docs/TESTES.md) | taxonomia, portões, hooks e CI |
| [CONVENCOES.md](docs/CONVENCOES.md) | regras para alterar o código, commits e documentação |
| [REFERENCIAS.md](docs/REFERENCIAS.md) | versões e links oficiais |

## Validação local

```bash
bash scripts/quality/with_local_config.sh composer check:all
```

Para validar a partir de um ZIP, prepare primeiro um
[checkout Git local](docs/OPERACAO.md#setup-local).

O `pre-push` local usa configuração sintética, PostgreSQL descartável e
verificações offline. Composição, requisitos e auditorias online ficam em
[TESTES.md](docs/TESTES.md#portões-de-qualidade).

## Licença

Código autoral proprietário. Todos os direitos reservados.

A classe Telegram e os traits derivados de TelegramBotPHP incluem código
distribuído sob licença MIT. Consulte o [aviso do TelegramBotPHP](LICENSES/TelegramBotPHP.txt).
