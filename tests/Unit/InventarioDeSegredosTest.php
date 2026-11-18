<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Todo segredo que o código exige tem de aparecer no inventário de SEGURANCA.md.
 *
 * Inventário de segredo é o documento que envelhece mais rápido e o que mais
 * caro custa quando está errado: no dia do vazamento, o que não está na lista
 * não é rotacionado. Por isso a lista não é conferida por leitura — as fontes
 * são `inclusos/config.example.php` (as constantes que o host preenche) e
 * `config/env.schema.php` (as variáveis de ambiente); nome novo em qualquer uma
 * delas reprova aqui até entrar no inventário.
 *
 * O valor NUNCA aparece em lugar nenhum — nem aqui, nem lá. O que se inventaria
 * é o nome, onde vive, quem usa, como se rotaciona e como se revoga.
 */
final class InventarioDeSegredosTest extends TestCase
{
    private const DOCUMENTO = 'docs/SEGURANCA.md';

    /**
     * Nome de variável de AMBIENTE que carrega segredo. Deliberadamente amplo:
     * falso positivo custa uma linha de documentação, falso negativo custa uma
     * credencial fora da lista. Para as constantes do `config.example.php` não
     * há filtro nenhum — aquele arquivo inteiro é o modelo do que é secreto.
     */
    private const PADRAO_DE_SEGREDO = '/(TOKEN|SECRET|KEY|PASSWORD|SENHA|CERT)/';

    private static function raiz(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function ler(string $relativo): string
    {
        $conteudo = file_get_contents(self::raiz() . '/' . $relativo);
        self::assertIsString($conteudo, "Não foi possível ler {$relativo}.");

        return $conteudo;
    }

    /**
     * @return list<string>
     */
    private static function segredosDoCodigo(): array
    {
        // Regex, e não include: o `config.example.php` é o MODELO, e as
        // constantes que o host precisa preencher aparecem nele também
        // comentadas — que é exatamente onde um segredo se esconde de um leitor
        // apressado. E `config/` é diretório de script com efeito real, que a
        // suíte proíbe incluir.
        $nomes = [];

        self::assertNotFalse(
            preg_match_all("/define\('([A-Z0-9_]+)'/", self::ler('inclusos/config.example.php'), $constantes),
        );
        foreach ($constantes[1] as $nome) {
            $nomes[$nome] = true;
        }

        self::assertNotFalse(
            preg_match_all("/^\s*'([A-Z0-9_]+)'\s*=>/m", self::ler('config/env.schema.php'), $variaveis),
        );
        foreach ($variaveis[1] as $nome) {
            if (preg_match(self::PADRAO_DE_SEGREDO, $nome) === 1) {
                $nomes[$nome] = true;
            }
        }

        return array_map(strval(...), array_keys($nomes));
    }

    /**
     * A seção do inventário, do título até o próximo título de mesmo nível ou
     * mais alto. O nível do título varia entre os bots; o conteúdo exigido, não.
     */
    private static function inventario(): string
    {
        $documento = self::ler(self::DOCUMENTO);

        self::assertSame(
            1,
            preg_match('/^(#{2,3}) Inventário de segredos\s*$/m', $documento, $titulo, PREG_OFFSET_CAPTURE),
            self::DOCUMENTO . ' perdeu a seção "Inventário de segredos".',
        );

        $inicio = $titulo[0][1];
        $nivel = strlen($titulo[1][0]);
        $resto = substr($documento, $inicio + strlen($titulo[0][0]));

        if (preg_match('/^#{1,' . $nivel . '} /m', $resto, $proximo, PREG_OFFSET_CAPTURE) === 1) {
            return substr($resto, 0, $proximo[0][1]);
        }

        return $resto;
    }

    public function testTodoSegredoExigidoPeloCodigoEstaNoInventario(): void
    {
        $segredos = self::segredosDoCodigo();
        // Guarda do extrator: se as duas fontes deixarem de ser lidas, o teste
        // ficaria verde sem ter verificado nada.
        $this->assertGreaterThanOrEqual(3, count($segredos), 'As fontes de segredo deixaram de ser reconhecidas.');

        $inventario = self::inventario();
        $ausentes = [];
        foreach ($segredos as $segredo) {
            if (!str_contains($inventario, $segredo)) {
                $ausentes[] = $segredo;
            }
        }

        $this->assertSame(
            [],
            $ausentes,
            self::DOCUMENTO . ' não inventaria: ' . implode(', ', $ausentes),
        );
    }

    /**
     * Cada linha do inventário tem de dizer como se rotaciona e como se revoga.
     * Sem isso a tabela vira lista de nomes — e no incidente a pergunta não é
     * "quais segredos existem", é "como eu mato este agora".
     */
    public function testInventarioDizComoRotacionarERevogar(): void
    {
        $inventario = self::inventario();

        $this->assertStringContainsString('| Rotação |', $inventario, 'Falta a coluna de rotação.');
        $this->assertStringContainsString('| Revogação |', $inventario, 'Falta a coluna de revogação.');
    }

    /**
     * Valor nenhum entra no documento. A conferência é grosseira de propósito:
     * qualquer coisa com cara de token literal na seção reprova.
     */
    public function testInventarioNaoCarregaValorDeSegredo(): void
    {
        $inventario = self::inventario();

        $this->assertDoesNotMatchRegularExpression(
            '/\b\d{8,10}:[A-Za-z0-9_-]{30,}\b/',
            $inventario,
            'Há algo com formato de token do Telegram no inventário.',
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bsk-[A-Za-z0-9_-]{20,}\b/',
            $inventario,
            'Há algo com formato de chave da OpenAI no inventário.',
        );
    }
}
