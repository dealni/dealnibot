// Conventional Commits (`feat:`, `fix:`, `chore:`), com assunto em português
// e no imperativo. O commitlint verifica a estrutura e os desvios verbais que
// são inequívocos; clareza e idioma continuam sendo revisão humana.

const GERUNDIO = /ndo$/i;
// As terminações plurais também podem ser imperativo ("corrijamos", "façamos").
// Só `-ei` é específica o bastante para esta heurística.
const PRIMEIRA_PESSOA_SINGULAR = /ei$/i;

/**
 * Escopos que existem em todo repositório: as áreas transversais (qualidade,
 * documentação, deploy, segurança, testes, dependências, operação, CI,
 * release, hooks) e as camadas que todo produto tem (api, painel, app,
 * backend, bot, ia, banco, infra). O escopo continua OPCIONAL; quando vem, tem
 * de ser um destes ou um dos escopos de produto do bloco de dados abaixo.
 * A lista existe porque o histórico acumulou `quality` ao lado de `qualidade`,
 * `tests` ao lado de `testes` e `ai` ao lado de `ia`: dois nomes para a mesma
 * área partem o histórico ao meio e ninguém filtra por ele.
 */
const ESCOPOS_COMUNS = [
  "qualidade",
  "docs",
  "deploy",
  "seguranca",
  "testes",
  "deps",
  "operacao",
  "ci",
  "release",
  "api",
  "painel",
  "app",
  "backend",
  "bot",
  "ia",
  "banco",
  "infra",
  "hooks",
];

// ---- Dados deste repositório ----

/** Escopos de produto deste repositório, em minúsculas e sem acento. */
const ESCOPOS_DO_REPOSITORIO = ["redis", "webhook"];

// ---- Fim dos dados deste repositório ----

function primeiraPalavra(subject) {
  return (
    String(subject ?? "")
      .trim()
      .split(/\s+/)[0] ?? ""
  );
}

const regrasEmPortugues = {
  rules: {
    "subject-imperativo-portugues": ({ subject }) => {
      const verbo = primeiraPalavra(subject);
      const erro =
        (GERUNDIO.test(verbo) && "gerúndio") ||
        (PRIMEIRA_PESSOA_SINGULAR.test(verbo) && "primeira pessoa");
      return [
        !erro,
        `O assunto começa no imperativo, e "${verbo}" está no ${erro || "tempo errado"}: use "corrige", não "corrigindo" nem "adicionei"`,
      ];
    },
  },
};

export default {
  extends: ["@commitlint/config-conventional"],
  plugins: [regrasEmPortugues],
  rules: {
    // Mantém o teto de 100 caracteres do config-conventional; linhas com URL
    // continuam isentas pela regra oficial, mas saída de comando arbitrária
    // não desativa o limite de legibilidade do corpo e do rodapé.
    "body-max-line-length": [2, "always", 100],
    "footer-max-line-length": [2, "always", 100],
    // Siglas como CSP, RLS, KV e Pix são legítimas no começo do assunto.
    "subject-case": [0, "never"],
    // O `config-conventional` traz `type-case` mas NÃO `scope-case`; sem esta
    // linha, "tipo e escopo em minúsculas" (docs/CONVENCOES.md) só valia para o
    // tipo, e `fix(App): …` passava com exit 0.
    "scope-case": [2, "always", "lower-case"],
    // Escopo opcional, mas de uma lista fechada: `fix(quality)` reprova para
    // não coexistir com `fix(qualidade)`. Escopo novo entra por commit no bloco
    // de dados deste arquivo, junto com o CONVENCOES.md.
    "scope-enum": [2, "always", [...ESCOPOS_COMUNS, ...ESCOPOS_DO_REPOSITORIO]],
    // Em português, `palavra: texto` aparece no corpo sem ser rodapé.
    "footer-leading-blank": [0, "always"],
    "subject-imperativo-portugues": [2, "always"],
  },
};
