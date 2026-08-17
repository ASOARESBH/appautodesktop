<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\FipeService;
use App\Services\IntegracoesService;
use App\Services\OcrExternoService;
use App\Services\VpicService;

$resultados = [];

$fipe = (new FipeService())->testar();
$resultados['fipe'] = [
    'success' => $fipe['success'],
    'status' => $fipe['status'],
    'marcas_disponiveis' => $fipe['detalhes']['marcas_disponiveis'] ?? 0,
];

$vpic = (new VpicService())->decodificar('1HGCM82633A004352');
$resultados['vpic'] = [
    'success' => $vpic['success'],
    'status' => $vpic['status'] ?? null,
    'marca' => $vpic['dados']['marca'] ?? null,
    'modelo' => $vpic['dados']['modelo'] ?? null,
    'ano_modelo' => $vpic['dados']['ano_modelo'] ?? null,
];

$ocr = new OcrExternoService();
$resultados['ocr_sem_chave'] = [
    'provedores_configurados' => $ocr->provedoresDisponiveis(),
];

$catalogo = (new IntegracoesService())->catalogo();
$resultados['catalogo'] = [
    'quantidade' => count($catalogo),
    'ids' => array_values(array_map(static fn (array $item): string => (string)$item['id'], $catalogo)),
];

echo json_encode($resultados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

$fipeRespostaEstruturada = isset($fipe['success'], $fipe['status'], $fipe['detalhes'])
    && is_bool($fipe['success'])
    && is_int($fipe['status'])
    && is_array($fipe['detalhes']);

// O endpoint FIPE pode responder com desafio anti-bot/403 no ambiente de teste.
// Isso é uma indisponibilidade externa esperada, não uma falha do adaptador.
if (!$fipeRespostaEstruturada || !$vpic['success'] || count($catalogo) < 7) {
    exit(1);
}
