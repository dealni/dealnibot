<?php

declare(strict_types=1);

// Inspeção sem remoção: inventário para decidir a retenção antes de instalá-la.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/config/logs-orcamento.json'), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($config) || !is_int($config['retencao_dias'] ?? null) || !is_int($config['orcamento_bytes'] ?? null) || !is_array($config['arquivos_adicionais'] ?? null)) {
    throw new RuntimeException('Orçamento de logs inválido.');
}
$diretorio = App\Support\LoggerFactory::diretorioLogs();
$total = 0;
$inventario = [];
$arquivos = glob($diretorio . '/*');
foreach ($arquivos === false ? [] : $arquivos as $arquivo) {
    if (!is_file($arquivo) || is_link($arquivo)) {
        continue;
    }
    $nome = basename($arquivo);
    if (preg_match('/^app-\d{4}-\d{2}-\d{2}\.log$/', $nome) !== 1 && !in_array($nome, $config['arquivos_adicionais'], true)) {
        continue;
    }
    $bytes = filesize($arquivo);
    $mtime = filemtime($arquivo);
    if ($bytes === false || $mtime === false) {
        continue;
    }
    $total += $bytes;
    $inventario[] = ['arquivo' => $nome, 'bytes' => $bytes, 'fora_retencao' => $mtime < time() - $config['retencao_dias'] * 86400];
}
echo json_encode(['modo' => 'somente_inspecao', 'total_bytes' => $total, 'orcamento_bytes' => $config['orcamento_bytes'], 'excedido' => $total > $config['orcamento_bytes'], 'arquivos' => $inventario], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
