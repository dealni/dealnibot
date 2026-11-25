# Visão geral — DealniBot

O desenho interno está em [ARQUITETURA.md](ARQUITETURA.md).
O comportamento visível está em [FUNCIONALIDADES.md](FUNCIONALIDADES.md).

## O que é

DealniBot é um assistente de IA que vive **dentro de grupos do Telegram**.
Conversa por texto, transcreve áudio e descreve imagem pelo OpenRouter, com uma
memória curta da conversa. Não espera comando: entra quando é **mencionado pelo
nome**, quando alguém **responde a uma mensagem dele** e num **sorteio de 10%**
das demais mensagens do grupo. A persona é fixa: "Dealni, um gato inteligente
do Telegram".

No chat privado a IA não é acionada: o bot registra o contato e orienta a
adicioná-lo a um grupo.

## O problema que resolve

O grupo ganha um participante que responde pergunta, resume o áudio e diz o que
tem na foto, sem ninguém sair do Telegram, criar conta ou aprender comando. Dois
problemas moldam o desenho:

- **Custo sem dono.** Menção, resposta e sorteio não são escolha de uma pessoa
  só, então a cota diária é do **grupo** ([decisão](ARQUITETURA.md#a-cota-é-do-grupo)).
- **Contexto que se perde.** A memória é separada por grupo, tópico e
  participante, e expira em 24 h.

## Para quem

O público é o **grupo**. Não há cadastro, painel nem comando privilegiado:
quem adiciona o bot a um grupo disponibiliza o assistente para todos ali.

## Como se sustenta

O bot foi oferecido gratuitamente. Cada instalação arca com sua infraestrutura
e as chamadas ao OpenRouter. O código controla o uso com uma cota de 100
unidades por grupo por dia e admissão de mídia por janela de 60 s
([detalhes](FUNCIONALIDADES.md#cota-diária-e-admissão-de-mídia)).

## Domínio e glossário

Português no domínio, inglês na infraestrutura
([regra](CONVENCOES.md#nomenclatura-e-idioma)).

| Termo | O que significa aqui |
|---|---|
| **update** | o evento que o Telegram entrega; fora do privado só `message`, `photo`, `voice` e `audio` são atendidos |
| **gatilho** | o motivo de o bot falar: resposta a ele, menção pelo nome ou sorteio |
| **rota** | o comando escolhido: `chatgpt` (texto), `imagem` (foto) ou `audio` (voz/áudio) |
| **sujeito da cota** | quem paga a chamada: o grupo em grupo/supergrupo, o usuário no resto |
| **unidade** | uma chamada paga ao OpenRouter; áudio reserva duas (transcrição e chat) |
| **admissão de mídia** | o portão de rajada anterior ao download de foto ou áudio, por janela de 60 s |
| **memória** | o histórico rolante no Redis, até 8 turnos, TTL de 24 h |
| **tópico** | o `message_thread_id` de um grupo com tópicos; sem tópicos vale `0` |
| **inbox** | o payload do update gravado no PostgreSQL antes do ACK |
| **ACK** | o HTTP 200 devolvido ao Telegram antes de processar o update |
| **canal** | o nome do logger Monolog que separa os assuntos no log |
| **carimbo** | o `.release.json` gravado pelo `scripts/deploy/deploy.sh`, identidade da publicação |
| **script de topo** | arquivo que executa efeitos no `include` (`comandos/`, `config/`, `processos/`) |
| **portão** | verificação que reprova; **relatório** é o que não reprova |
| **ratchet** | portão cujo limite só aperta |
| **sonda** | `/health` (processo vivo) e `/ready` (processo consegue atender) |

## Estado do projeto

O assistente está pausado. Os fluxos acima descrevem o comportamento de uma
instalação própria. O projeto inclui ainda um serviço independente que envia
um aviso de pausa em conversas privadas.
[Aviso de pausa](OPERACAO.md#aviso-de-pausa-no-telegram).

## O que o sistema não faz

| Fora do escopo | Por quê |
|---|---|
| IA no chat privado | cada mensagem seria chamada paga sem cota de grupo |
| Vídeo, documento, sticker, callback, inline query | cairiam no comando de texto com texto vazio |
| Administrador, saldo, cobrança | não há papel privilegiado nem receita |
| Cota configurável | 100 unidades por dia é constante no código |
| Worker residente e serviço systemd residente da aplicação | tarefas agendadas retomam a inbox no PostgreSQL e executam rotinas auxiliares |
| Telemetria e sonda externa | as instalações podem usar log em arquivo, sondas HTTP, monitor local e [avisos opcionais no Telegram](OPERACAO.md#aviso-de-falha-no-telegram) |
| Reversão automática de migration | desfazer é restauração de dados ([DADOS.md](DADOS.md#schema-e-migrations)) |
| Cópia externa e criptografia do backup | ficam a cargo de cada instalação ([DADOS.md](DADOS.md#cópia-externa-e-criptografia)) |
| Teste ponta a ponta contra o Telegram | exigiria bot real e gastaria a chave; existe o smoke somente leitura |
