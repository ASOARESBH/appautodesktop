<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Adaptador HTTP da API FIPE/Parallelum.
 *
 * A API FIPE é usada somente para enriquecimento por marca/modelo/ano.
 * Não é uma fonte de identificação por placa nem de valor definitivo.
 */
final class FipeService
{
    private const BASE_URL = 'https://fipe.parallelum.com.br/api/v2';
    private const CONNECT_TIMEOUT = 3;
    private const REQUEST_TIMEOUT = 5;
    private const CACHE_TTL = 86400;

    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = dirname(__DIR__, 2) . '/storage/cache/integracoes/fipe';
    }

    /**
     * @return array{success: bool, dados: array, message?: string}
     */
    public function consultarVeiculo(
        string $marca,
        string $modelo,
        int $anoModelo,
        string $tipoVeiculo = 'carros'
    ): array {
        $marca = trim($marca);
        $modelo = trim($modelo);
        $tipoVeiculo = in_array($tipoVeiculo, ['carros', 'motos', 'caminhoes'], true)
            ? $tipoVeiculo
            : 'carros';
        $tipoApi = match ($tipoVeiculo) {
            'motos' => 'motorcycles',
            'caminhoes' => 'trucks',
            default => 'cars',
        };

        if ($marca === '' || $modelo === '' || $anoModelo < 1900 || $anoModelo > ((int)date('Y') + 2)) {
            return [
                'success' => false,
                'dados' => [],
                'message' => 'Marca, modelo e ano do veículo são necessários para consultar a FIPE.',
            ];
        }

        $marcas = $this->getJson('/' . $tipoApi . '/brands');
        if (!$marcas['success']) {
            return $this->falha('Não foi possível carregar as marcas da FIPE.', $marcas['status']);
        }

        $marcaEncontrada = $this->encontrarItem($marcas['dados'], $marca, 'name');
        if ($marcaEncontrada === null) {
            return $this->falha('Marca não encontrada na Tabela FIPE.', 200);
        }

        $marcaCodigo = (string)($marcaEncontrada['code'] ?? $marcaEncontrada['codigo'] ?? '');
        if ($marcaCodigo === '') {
            return $this->falha('A marca retornada pela FIPE não possui código válido.', 200);
        }

        $modelos = $this->getJson('/' . $tipoApi . '/brands/' . rawurlencode($marcaCodigo) . '/models');
        if (!$modelos['success']) {
            return $this->falha('Não foi possível carregar os modelos da FIPE.', $modelos['status']);
        }

        $listaModelos = is_array($modelos['dados']['modelos'] ?? null)
            ? $modelos['dados']['modelos']
            : (is_array($modelos['dados']) ? $modelos['dados'] : []);
        $modeloEncontrado = $this->encontrarItem($listaModelos, $modelo, 'name');
        if ($modeloEncontrado === null) {
            return $this->falha('Modelo não encontrado na Tabela FIPE.', 200);
        }

        $modeloCodigo = (string)($modeloEncontrado['code'] ?? $modeloEncontrado['codigo'] ?? '');
        if ($modeloCodigo === '') {
            return $this->falha('O modelo retornado pela FIPE não possui código válido.', 200);
        }

        $anos = $this->getJson('/' . $tipoApi . '/brands/' . rawurlencode($marcaCodigo) . '/models/' . rawurlencode($modeloCodigo) . '/years');
        if (!$anos['success']) {
            return $this->falha('Não foi possível carregar os anos da FIPE.', $anos['status']);
        }

        $anoEncontrado = $this->encontrarAno($anos['dados'], $anoModelo);
        if ($anoEncontrado === null) {
            return $this->falha('Ano do veículo não encontrado na Tabela FIPE.', 200);
        }

        $anoCodigo = (string)($anoEncontrado['codigo'] ?? '');
        if ($anoCodigo === '') {
            return $this->falha('O ano retornado pela FIPE não possui código válido.', 200);
        }

        $preco = $this->getJson('/' . $tipoApi . '/brands/' . rawurlencode($marcaCodigo) . '/models/' . rawurlencode($modeloCodigo) . '/years/' . rawurlencode($anoCodigo));
        if (!$preco['success']) {
            return $this->falha('Não foi possível carregar o preço FIPE.', $preco['status']);
        }

        $dadosOriginais = is_array($preco['dados']) ? $preco['dados'] : [];
        $dados = array_merge($dadosOriginais, [
            'Valor' => $dadosOriginais['price'] ?? $dadosOriginais['Valor'] ?? null,
            'Marca' => $dadosOriginais['brand'] ?? $dadosOriginais['Marca'] ?? null,
            'Modelo' => $dadosOriginais['model'] ?? $dadosOriginais['Modelo'] ?? null,
            'AnoModelo' => $dadosOriginais['modelYear'] ?? $dadosOriginais['AnoModelo'] ?? null,
            'CodigoFipe' => $dadosOriginais['codeFipe'] ?? $dadosOriginais['CodigoFipe'] ?? null,
            'MesReferencia' => $dadosOriginais['referenceMonth'] ?? $dadosOriginais['MesReferencia'] ?? null,
        ]);
        $dados['tipo_consulta'] = $tipoVeiculo;
        $dados['marca_codigo'] = $marcaCodigo;
        $dados['modelo_codigo'] = $modeloCodigo;
        $dados['ano_codigo'] = $anoCodigo;

        Logger::info('Consulta FIPE concluída', [
            'tipo' => $tipoVeiculo,
            'campos' => count(array_filter($dados, static fn ($valor): bool => $valor !== null && $valor !== '')),
        ]);

        return ['success' => true, 'dados' => $dados];
    }

    /**
     * Teste de conectividade sem expor dados ou credenciais.
     * @return array{success: bool, status: int, detalhes: array, message?: string}
     */
    public function testar(): array
    {
        $resposta = $this->getJson('/cars/brands');
        if (!$resposta['success']) {
            return [
                'success' => false,
                'status' => $resposta['status'],
                'detalhes' => [],
                'message' => 'A FIPE não respondeu com uma lista válida de marcas.',
            ];
        }

        return [
            'success' => true,
            'status' => $resposta['status'],
            'detalhes' => [
                'endpoint' => $this->baseUrl(),
                'marcas_disponiveis' => count($resposta['dados']),
                'cache' => 'ativo',
            ],
        ];
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function getJson(string $path): array
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');
        $cacheFile = $this->cacheFile($url);
        if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < self::CACHE_TTL) {
            $cache = json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cache)) {
                return ['success' => true, 'status' => 200, 'dados' => $cache, 'erro' => ''];
            }
        }

        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Extensão cURL indisponível'];
        }

        $ch = curl_init($url);
        $headers = [
            'Accept: application/json',
            'User-Agent: AppAuto/2.2 (+https://erp.appauto.com.br)',
        ];
        $token = $this->env('FIPE_API_TOKEN');
        if ($token !== '') {
            $headers[] = 'X-Subscription-Token: ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
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
            Logger::warning('FIPE indisponível', [
                'status_http' => $status,
                'erro_tecnico' => $erro !== '' ? 'sim' : 'não',
            ]);
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => $erro];
        }

        $dados = json_decode($corpo, true);
        if (!is_array($dados)) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => 'Resposta não JSON'];
        }

        $this->gravarCache($cacheFile, $dados);
        return ['success' => true, 'status' => $status, 'dados' => $dados, 'erro' => ''];
    }

    /** @param array<int, mixed> $itens */
    private function encontrarItem(array $itens, string $alvo, string $campo): ?array
    {
        $alvoNormalizado = $this->normalizarTexto($alvo);
        if ($alvoNormalizado === '') {
            return null;
        }

        $melhor = null;
        $melhorPontuacao = 0;
        foreach ($itens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $valor = $this->normalizarTexto((string)($item[$campo] ?? ''));
            if ($valor === '') {
                continue;
            }
            $pontuacao = $valor === $alvoNormalizado ? 100 : 0;
            if ($pontuacao === 0 && (str_contains($valor, $alvoNormalizado) || str_contains($alvoNormalizado, $valor))) {
                $pontuacao = 75;
            }
            if ($pontuacao === 0) {
                similar_text($valor, $alvoNormalizado, $percentual);
                $pontuacao = (int)$percentual;
            }
            if ($pontuacao > $melhorPontuacao) {
                $melhorPontuacao = $pontuacao;
                $melhor = $item;
            }
        }

        return $melhorPontuacao >= 55 ? $melhor : null;
    }

    /** @param array<int, mixed> $itens */
    private function encontrarAno(array $itens, int $ano): ?array
    {
        foreach ($itens as $item) {
            if (!is_array($item)) {
                continue;
            }
            $nome = (string)($item['nome'] ?? $item['name'] ?? '');
            if ((int)substr($nome, 0, 4) === $ano) {
                return $item;
            }
        }
        return null;
    }

    private function normalizarTexto(string $valor): string
    {
        $valor = trim($valor);
        if (function_exists('iconv')) {
            $convertido = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
            if (is_string($convertido)) {
                $valor = $convertido;
            }
        }
        $valor = strtolower($valor);
        return (string)preg_replace('/[^a-z0-9]+/', '', $valor);
    }

    private function baseUrl(): string
    {
        $base = $this->env('FIPE_API_BASE_URL');
        return rtrim($base !== '' ? $base : self::BASE_URL, '/');
    }

    private function env(string $chave): string
    {
        $valor = $_ENV[$chave] ?? getenv($chave) ?: '';
        return is_string($valor) ? trim($valor) : '';
    }

    private function cacheFile(string $url): string
    {
        return $this->cacheDir . '/' . sha1($url) . '.json';
    }

    private function gravarCache(string $arquivo, array $dados): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0700, true);
        }
        if (is_dir($this->cacheDir)) {
            @file_put_contents($arquivo, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
            @chmod($arquivo, 0600);
        }
    }

    /** @return array{success: false, dados: array, message: string} */
    private function falha(string $mensagem, int $status): array
    {
        Logger::warning('Consulta FIPE sem resultado', [
            'status_http' => $status,
            'motivo' => $mensagem,
        ]);
        return ['success' => false, 'dados' => [], 'message' => $mensagem];
    }
}
