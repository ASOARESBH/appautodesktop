<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Adaptador da vPIC/NHTSA para decodificação complementar de VIN.
 * A fonte é norte-americana e não substitui dados brasileiros de placa/Renavam.
 */
final class VpicService
{
    private const BASE_URL = 'https://vpic.nhtsa.dot.gov/api';
    private const CONNECT_TIMEOUT = 3;
    private const REQUEST_TIMEOUT = 5;

    /**
     * @return array{success: bool, dados: array, message?: string, status?: int}
     */
    public function decodificar(string $vin): array
    {
        $vin = strtoupper((string)preg_replace('/[^A-Z0-9]/', '', trim($vin)));
        if (!preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
            return [
                'success' => false,
                'dados' => [],
                'message' => 'VIN inválido. Informe 17 caracteres alfanuméricos, sem I, O ou Q.',
                'status' => 422,
            ];
        }

        $resposta = $this->getJson('/vehicles/decodevinvalues/' . rawurlencode($vin) . '?format=json');
        if (!$resposta['success']) {
            return [
                'success' => false,
                'dados' => [],
                'message' => 'A vPIC/NHTSA não respondeu à consulta de VIN.',
                'status' => $resposta['status'],
            ];
        }

        $resultado = $resposta['dados']['Results'][0] ?? null;
        if (!is_array($resultado)) {
            return [
                'success' => false,
                'dados' => [],
                'message' => 'A vPIC/NHTSA não retornou dados para este VIN.',
                'status' => $resposta['status'],
            ];
        }

        $dados = array_filter([
            'vin' => $resultado['VIN'] ?? $vin,
            'marca' => $resultado['Make'] ?? null,
            'modelo' => $resultado['Model'] ?? null,
            'ano_modelo' => $this->ano($resultado['ModelYear'] ?? null),
            'fabricante' => $resultado['Manufacturer'] ?? null,
            'tipo_veiculo' => $resultado['VehicleType'] ?? null,
            'carroceria' => $resultado['BodyClass'] ?? null,
            'combustivel' => $resultado['FuelTypePrimary'] ?? null,
            'motor' => $resultado['EngineModel'] ?? null,
            'cilindradas' => $resultado['DisplacementL'] ?? null,
            'potencia_cv' => $resultado['EngineHP'] ?? null,
            'portas' => $resultado['Doors'] ?? null,
            'transmissao' => $resultado['TransmissionStyle'] ?? null,
            'pais_fabricacao' => $resultado['PlantCountry'] ?? null,
            'mensagem_nhtsa' => $resultado['ErrorText'] ?? null,
        ], static fn ($valor): bool => $valor !== null && $valor !== '');

        Logger::info('Consulta vPIC/NHTSA concluída', [
            'campos_tecnicos' => count($dados),
        ]);

        return [
            'success' => true,
            'dados' => $dados,
            'status' => $resposta['status'],
        ];
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function getJson(string $path): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Extensão cURL indisponível'];
        }

        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: AppAuto/2.2 (+https://erp.appauto.com.br)',
            ],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $corpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if (!is_string($corpo) || $status < 200 || $status >= 300) {
            Logger::warning('vPIC/NHTSA indisponível', [
                'status_http' => $status,
                'erro_tecnico' => $erro !== '' ? 'sim' : 'não',
            ]);
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => $erro];
        }

        $dados = json_decode($corpo, true);
        if (!is_array($dados)) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => 'Resposta não JSON'];
        }
        return ['success' => true, 'status' => $status, 'dados' => $dados, 'erro' => ''];
    }

    private function ano(mixed $valor): ?int
    {
        $ano = (int)$valor;
        return $ano >= 1900 && $ano <= ((int)date('Y') + 2) ? $ano : null;
    }
}
