<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fachada de integrações administráveis do AppAuto.
 * A UI recebe somente status, nomes de variáveis e resultados sanitizados.
 */
final class IntegracoesService
{
    private FipeService $fipe;
    private VpicService $vpic;
    private OcrExternoService $ocr;

    public function __construct()
    {
        $this->fipe = new FipeService();
        $this->vpic = new VpicService();
        $this->ocr = new OcrExternoService();
    }

    /** @return array<int, array<string, mixed>> */
    public function catalogo(): array
    {
        return [
            [
                'id' => 'fipe',
                'nome' => 'FIPE / Parallelum',
                'grupo' => 'Veículos e preços',
                'descricao' => 'Catálogo brasileiro de marcas, modelos, anos e preço de referência.',
                'status' => 'ativo',
                'configurado' => true,
                'variaveis' => [],
                'observacao' => 'Não consulta placa. A integração usa cache e não requer chave para o fluxo básico.',
            ],
            [
                'id' => 'mlkit',
                'nome' => 'Google ML Kit',
                'grupo' => 'OCR local Android',
                'descricao' => 'Reconhecimento local de texto no aplicativo Android, útil para captura assistida.',
                'status' => 'cliente',
                'configurado' => $this->flag('ML_KIT_ENABLED', true),
                'variaveis' => ['ML_KIT_ENABLED'],
                'observacao' => 'Não é chamado pelo PHP. A implementação exige habilitação no aplicativo Android.',
            ],
            [
                'id' => 'cloudmersive',
                'nome' => 'Cloudmersive',
                'grupo' => 'OCR e leitura de placa',
                'descricao' => 'Detecção de placas e OCR de texto em imagens.',
                'status' => 'configurado',
                'configurado' => $this->temEnv('CLOUDMERSIVE_API_KEY'),
                'variaveis' => ['CLOUDMERSIVE_API_KEY', 'CLOUDMERSIVE_BASE_URL'],
                'observacao' => 'Requer chave no servidor. Valide placas antigas e Mercosul antes de produção.',
            ],
            [
                'id' => 'ocrspace',
                'nome' => 'OCR.Space',
                'grupo' => 'OCR de documentos',
                'descricao' => 'OCR geral de imagens e PDFs com resposta JSON.',
                'status' => 'configurado',
                'configurado' => $this->temEnv('OCRSPACE_API_KEY'),
                'variaveis' => ['OCRSPACE_API_KEY', 'OCRSPACE_ENDPOINT', 'OCRSPACE_LANGUAGE'],
                'observacao' => 'Fallback de OCR geral. O plano gratuito possui limite por IP e tamanho de arquivo.',
            ],
            [
                'id' => 'pixlab',
                'nome' => 'PixLab',
                'grupo' => 'OCR de documentos',
                'descricao' => 'OCR em português com texto e bounding boxes, acessado por URL segura.',
                'status' => 'configurado',
                'configurado' => $this->temEnv('PIXLAB_API_KEY'),
                'variaveis' => ['PIXLAB_API_KEY', 'PIXLAB_OCR_ENDPOINT', 'PIXLAB_LANGUAGE'],
                'observacao' => 'Fallback/alternativa comercial. Não tratar como leitor especializado de placa sem teste.',
            ],
            [
                'id' => 'vpic',
                'nome' => 'NHTSA vPIC',
                'grupo' => 'VIN técnico',
                'descricao' => 'Decodificação complementar de VIN e dados técnicos de veículos.',
                'status' => 'ativo',
                'configurado' => true,
                'variaveis' => [],
                'observacao' => 'Fonte norte-americana. Não substitui placa, Renavam ou preço brasileiro.',
            ],
            [
                'id' => 'placa',
                'nome' => 'Fontes de consulta de placa',
                'grupo' => 'Consulta brasileira',
                'descricao' => 'API Placas, APIBrasil e PlacaAPI já suportadas pelo fallback existente.',
                'status' => 'configurado',
                'configurado' => $this->temAlgumaFontePlaca(),
                'variaveis' => ['APIPLACAS_TOKEN', 'APIBRASIL_TOKEN', 'APIBRASIL_DEVICE_TOKEN', 'APIBRASIL_VEHICLE_ENDPOINT', 'PLACAAPI_ENDPOINT'],
                'observacao' => 'Mantém o serviço ConsultaPlacaService como fonte de dados técnicos por placa.',
            ],
        ];
    }

    /**
     * @return array{success: bool, status: int, detalhes: array, message?: string}
     */
    public function testar(string $provedor, array $parametros = []): array
    {
        return match (strtolower(trim($provedor))) {
            'fipe' => $this->testarFipe($parametros),
            'vpic' => $this->testarVpic((string)($parametros['vin'] ?? '')),
            'cloudmersive', 'ocrspace', 'pixlab' => $this->testarOcr($provedor, $parametros),
            'placa' => $this->testarPlaca((string)($parametros['placa'] ?? '')),
            'mlkit' => [
                'success' => true,
                'status' => 200,
                'detalhes' => [
                    'modo' => 'cliente Android',
                    'habilitado' => $this->flag('ML_KIT_ENABLED', true),
                    'mensagem' => 'O ML Kit deve ser validado dentro do aplicativo Android.',
                ],
            ],
            default => [
                'success' => false,
                'status' => 422,
                'detalhes' => [],
                'message' => 'Integração não reconhecida.',
            ],
        };
    }

    private function testarFipe(array $parametros): array
    {
        $marca = trim((string)($parametros['marca'] ?? ''));
        $modelo = trim((string)($parametros['modelo'] ?? ''));
        $ano = (int)($parametros['ano_modelo'] ?? 0);
        if ($marca !== '' && $modelo !== '' && $ano > 0) {
            $resultado = $this->fipe->consultarVeiculo(
                $marca,
                $modelo,
                $ano,
                (string)($parametros['tipo_veiculo'] ?? 'carros')
            );
            return [
                'success' => $resultado['success'],
                'status' => $resultado['success'] ? 200 : 502,
                'detalhes' => $resultado['dados'] ?? [],
                'message' => $resultado['message'] ?? null,
            ];
        }
        return $this->fipe->testar();
    }

    private function testarVpic(string $vin): array
    {
        $resultado = $this->vpic->decodificar($vin);
        return [
            'success' => $resultado['success'],
            'status' => (int)($resultado['status'] ?? ($resultado['success'] ? 200 : 502)),
            'detalhes' => $resultado['dados'] ?? [],
            'message' => $resultado['message'] ?? null,
        ];
    }

    private function testarOcr(string $provedor, array $parametros): array
    {
        $arquivo = is_array($parametros['arquivo'] ?? null) ? $parametros['arquivo'] : null;
        $resultado = $this->ocr->testar(
            $provedor,
            $arquivo,
            (string)($parametros['modo'] ?? 'documento'),
            (string)($parametros['imagem_url'] ?? '')
        );
        return $resultado;
    }

    private function testarPlaca(string $placa): array
    {
        $resultado = (new ConsultaPlacaService())->consultar($placa);
        return [
            'success' => (bool)($resultado['success'] ?? false),
            'status' => ($resultado['success'] ?? false) ? 200 : 502,
            'detalhes' => [
                'fonte' => $resultado['fonte'] ?? 'nenhuma',
                'fontes_tentadas' => $resultado['fontes_tentadas'] ?? [],
                'campos_encontrados' => $resultado['dados']['campos_encontrados'] ?? [],
            ],
            'message' => $resultado['message'] ?? null,
        ];
    }

    private function temAlgumaFontePlaca(): bool
    {
        return $this->temEnv('APIPLACAS_TOKEN')
            || ($this->temEnv('APIBRASIL_TOKEN') && $this->temEnv('APIBRASIL_DEVICE_TOKEN') && $this->temEnv('APIBRASIL_VEHICLE_ENDPOINT'))
            || ($this->temEnv('PLACAAPI_ENDPOINT') && $this->temEnv('PLACAAPI_USERNAME') && $this->temEnv('PLACAAPI_PASSWORD'));
    }

    private function temEnv(string $chave): bool
    {
        $valor = $_ENV[$chave] ?? getenv($chave) ?: '';
        return is_string($valor) && trim($valor) !== '';
    }

    private function flag(string $chave, bool $padrao): bool
    {
        if (!$this->temEnv($chave)) {
            return $padrao;
        }
        return in_array(strtolower((string)($_ENV[$chave] ?? getenv($chave))), ['1', 'true', 'yes', 'on'], true);
    }
}
