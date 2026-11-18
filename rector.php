<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use Rector\ValueObject\PhpVersion;

/*
 * O Rector aqui é RELATÓRIO, não gate: `composer rector` roda sempre com
 * --dry-run e o CI o executa no job "reports", que não reprova. Ele lê e
 * reescreve arquivos de topo — que não podem ser executados, mas podem ser
 * lidos —, então nunca use `rector process` sem --dry-run neste repositório
 * sem revisar o diff arquivo a arquivo.
 *
 * O objetivo é achar código morto e sintaxe que o 8.5 já substituiu, não
 * modernizar a base inteira. Por isso só dois conjuntos preparados, e nenhum
 * `withPhpSets()`: o pacote de upgrade de versão reescreveria centenas de
 * arquivos legados de uma vez.
 */

// --- DADOS DESTE REPOSITÓRIO (a personalização entra só neste bloco) --------
/**
 * Pastas e entrypoints que o relatório percorre.
 *
 * `vendor/` fica fora da lista, e por isso NÃO aparece no skip abaixo:
 * `reportUnusedSkips()` acusaria um skip que nunca chega a ser exercido.
 * Testes mantêm assinaturas e variáveis aparentemente não usadas para
 * wrappers de stream e scripts carregados via require; o Rector não enxerga
 * esses contratos dinâmicos e produziria falsos positivos, então tests/ e
 * scripts/ também ficam fora — os dois têm portões próprios.
 *
 * @var list<string>
 */
$caminhosDoRelatorio = [
    __DIR__ . '/comandos',
    __DIR__ . '/config',
    __DIR__ . '/inclusos',
    __DIR__ . '/processos',
    __DIR__ . '/src',
];

/**
 * Regras e arquivos poupados por um motivo próprio deste repositório. Cada
 * entrada precisa do comentário que diz por que a sugestão é falso positivo;
 * `reportUnusedSkips()` avisa quando o motivo deixou de existir.
 *
 * Os nomes de regra vêm qualificados para que este bloco seja a única parte
 * do arquivo que muda: o `use` do topo fica só com o que é canônico.
 *
 * @var array<int|string, mixed>
 */
$excecoesDoRelatorio = [
    // Telegram::$updates só é atribuída em getUpdates(). O valor inicial
    // ['ok' => true, 'result' => []] é o que UpdateCount() e serveUpdate()
    // leem em webhook, onde getUpdates() nunca roda: sem ele a propriedade
    // tipada fica não inicializada e a leitura vira Error em produção.
    \Rector\DeadCode\Rector\Property\RemoveDefaultValueFromAssignedPropertyRector::class,
    // Esses entrypoints normalizam explicitamente o retorno do cURL e os
    // testes de contrato exigem que a conversão continue visível.
    __DIR__ . '/config/config_remover.php',
    __DIR__ . '/config/config_webhook.php',
    // O helper precisa permanecer inerte fora da classe: importar o nome
    // no topo é código observável pelos validadores de scripts.
    __DIR__ . '/inclusos/TelegramErrorLogger.php',
    // Funções incluídas isoladamente devem continuar sem imports no topo.
    __DIR__ . '/comandos/funcoes_http_openai.php',
];
// --- FIM DOS DADOS; daqui para baixo o código é canônico --------------------

return RectorConfig::configure()
    ->withPaths($caminhosDoRelatorio)
    ->withPhpVersion(PhpVersion::PHP_85)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withSkip([
        ...$excecoesDoRelatorio,
        // NÃO TIRE ESTA LINHA. O --dry-run imprime o trecho alterado de cada
        // arquivo, e este arquivo é a credencial de produção: sem o skip, o
        // relatório sai com o token do bot em texto claro na saída padrão. Ele
        // é gitignorado, então some no CI e sobra só na máquina de quem roda —
        // que é justamente onde ele é real.
        __DIR__ . '/inclusos/config.php',
        // Acrescenta declare(strict_types=1) em dezenas de arquivos procedurais
        // de uma vez. Não é achado: é mudança de comportamento em massa
        // disfarçada de sugestão, e afogaria o resto do relatório.
        SafeDeclareStrictTypesRector::class,
    ])
    // Um skip que nunca é exercido vira permissão silenciosa: com este aviso,
    // o motivo que acabou aparece no relatório em vez de sumir.
    ->reportUnusedSkips()
    ->withImportNames(importShortClasses: false, removeUnusedImports: false)
    // O cache pertence ao checkout para evitar colisões em execuções paralelas.
    ->withCache(__DIR__ . '/vendor/.rector-cache')
    ->withParallel(maxNumberOfProcess: 2);
