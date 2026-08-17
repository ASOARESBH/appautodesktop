<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * ConsultaPlacaService
 *
 * Agrega provedores de dados veiculares por placa sem expor dados pessoais.
 * Cada fonte é opcional e só é chamada quando suas credenciais estiverem
 * configuradas no ambiente. Em caso de indisponibilidade, o próximo provedor
 * configurado é tentado automaticamente.
 */
final class ConsultaPlacaService
{
    private const CONNECT_TIMEOUT = 3;
    private const REQUEST_TIMEOUT = 5;

    /**
     * Consulta uma placa em provedores configurados e devolve somente dados
     * técnicos úteis ao cadastro do veículo.
     *
     * @return array{success: bool, fonte: string, fontes_tentadas: array, dados: array, message?: string}
     */
    public function consultar(string $placa): array
    {
        $placa = $this->normalizarPlaca($placa);
        if (!$this->placaValida($placa)) {
            return [
                'success' => false,
                'fonte' => 'nenhuma',
                'fontes_tentadas' => [],
                'dados' => [],
                'message' => 'Placa inválida. Informe o formato ABC1234 ou ABC1D23.',
            ];
        }

        $fontesTentadas = [];
        $fontesComSucesso = [];
        $melhorResultado = $this->dadosBase($placa);
        $melhorPontuacao = 0;
        $temFonteConfigurada = false;

        foreach ($this->provedoresConfigurados($placa) as $nome => $provedor) {
            $temFonteConfigurada = true;
            $resposta = $provedor();
            $fontesTentadas[] = [
                'fonte' => $nome,
                'status' => $resposta['status'],
            ];

            if (!$resposta['success']) {
                Logger::warning('Consulta de placa: provedor indisponível ou sem resultado', [
                    'provedor' => $nome,
                    'status_http' => $resposta['status'],
                    'erro_tecnico' => $resposta['erro'] !== '' ? 'sim' : 'não',
                ]);
                continue;
            }

            $normalizado = $this->normalizarResposta($resposta['dados'], $placa, $nome);
            $pontuacao = $this->pontuarCompletude($normalizado);

            if ($pontuacao === 0) {
                Logger::warning('Consulta de placa: provedor respondeu sem dados técnicos aproveitáveis', [
                    'provedor' => $nome,
                    'status_http' => $resposta['status'],
                ]);
                continue;
            }

            $fontesComSucesso[] = $nome;
            $melhorResultado = $this->mesclarDados($melhorResultado, $normalizado);
            $melhorPontuacao = max($melhorPontuacao, $pontuacao);

            Logger::info('Consulta de placa concluída em provedor externo', [
                'provedor' => $nome,
                'campos_tecnicos' => $pontuacao,
            ]);

            // Uma resposta completa evita consumo desnecessário de créditos em fontes seguintes.
            if ($melhorPontuacao >= 10) {
                break;
            }
        }

        if (!$temFonteConfigurada) {
            return [
                'success' => false,
                'fonte' => 'nenhuma',
                'fontes_tentadas' => [],
                'dados' => $this->dadosBase($placa),
                'message' => 'A consulta automática ainda não foi configurada. Informe as chaves das fontes de placa no ambiente.',
            ];
        }

        if ($melhorPontuacao === 0) {
            return [
                'success' => false,
                'fonte' => 'nenhuma',
                'fontes_tentadas' => $fontesTentadas,
                'dados' => $this->dadosBase($placa),
                'message' => 'Nenhuma fonte configurada retornou dados técnicos para esta placa.',
            ];
        }

        $melhorResultado['fontes_consultadas'] = $fontesComSucesso;
        $melhorResultado['fontes_tentadas'] = $fontesTentadas;

        // O enriquecimento FIPE é opt-in para evitar latência e consumo
        // inesperados em consultas de placa. O resultado usa cache local.
        if ($this->envFlag('FIPE_AUTO_ENRICH', false) && empty($melhorResultado['fipe'])) {
            $anoFipe = (int)($melhorResultado['ano_modelo'] ?? $melhorResultado['ano_fabricacao'] ?? 0);
            if ($anoFipe > 0 && !empty($melhorResultado['marca']) && !empty($melhorResultado['modelo'])) {
                $fipe = (new FipeService())->consultarVeiculo(
                    (string)$melhorResultado['marca'],
                    (string)$melhorResultado['modelo'],
                    $anoFipe,
                    'carros'
                );
                if ($fipe['success'] ?? false) {
                    $melhorResultado['fipe'] = $fipe['dados'];
                    $fontesComSucesso[] = 'fipe';
                    $melhorResultado['fontes_consultadas'] = $fontesComSucesso;
                }
            }
        }

        $melhorResultado['campos_encontrados'] = $this->camposEncontrados($melhorResultado);

        return [
            'success' => true,
            'fonte' => implode(', ', $fontesComSucesso),
            'fontes_tentadas' => $fontesTentadas,
            'dados' => $melhorResultado,
        ];
    }

    /** @return array<string, callable> */
    private function provedoresConfigurados(string $placa): array
    {
        $provedores = [];

        $tokenApiPlacas = $this->env('APIPLACAS_TOKEN');
        if ($tokenApiPlacas !== '') {
            $provedores['api_placas'] = function () use ($placa, $tokenApiPlacas): array {
                $url = 'https://wdapi2.com.br/consulta/' . rawurlencode($placa) . '/' . rawurlencode($tokenApiPlacas);
                return $this->requisicaoJson('GET', $url);
            };
        }

        // A APIBrasil só é habilitada quando o endpoint do produto contratado é informado.
        // Isso evita presumir um contrato que possa ser diferente entre planos/versões.
        $apiBrasilToken = $this->env('APIBRASIL_TOKEN');
        $apiBrasilDeviceToken = $this->env('APIBRASIL_DEVICE_TOKEN');
        $apiBrasilEndpoint = $this->env('APIBRASIL_VEHICLE_ENDPOINT');
        $apiBrasilTipo = $this->env('APIBRASIL_VEHICLE_TYPE');
        if ($apiBrasilToken !== '' && $apiBrasilDeviceToken !== '' && $apiBrasilEndpoint !== '' && $apiBrasilTipo !== '') {
            $provedores['api_brasil'] = function () use ($placa, $apiBrasilToken, $apiBrasilDeviceToken, $apiBrasilEndpoint, $apiBrasilTipo): array {
                return $this->requisicaoJson('POST', $apiBrasilEndpoint, [
                    'Authorization: Bearer ' . $apiBrasilToken,
                    'DeviceToken: ' . $apiBrasilDeviceToken,
                ], [
                    'tipo' => $apiBrasilTipo,
                    'placa' => $placa,
                ]);
            };
        }

        // A PlacaAPI aceita contratos diferentes. Por isso o endpoint deve vir do
        // painel/documentação da conta contratada, sem credencial no código-fonte.
        $placaApiEndpoint = $this->env('PLACAAPI_ENDPOINT');
        $placaApiUsername = $this->env('PLACAAPI_USERNAME');
        $placaApiPassword = $this->env('PLACAAPI_PASSWORD');
        if ($placaApiEndpoint !== '' && $placaApiUsername !== '' && $placaApiPassword !== '') {
            $provedores['placa_api'] = function () use ($placa, $placaApiEndpoint, $placaApiUsername, $placaApiPassword): array {
                $url = strtr($placaApiEndpoint, [
                    '{placa}' => rawurlencode($placa),
                    '{usuario}' => rawurlencode($placaApiUsername),
                    '{senha}' => rawurlencode($placaApiPassword),
                ]);
                return $this->requisicaoJson('GET', $url);
            };
        }

        return $provedores;
    }

    /**
     * @return array{success: bool, status: int, dados: array, erro: string}
     */
    private function requisicaoJson(string $metodo, string $url, array $headers = [], ?array $corpo = null): array
    {
        if (!function_exists('curl_init')) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Extensão cURL indisponível'];
        }

        $ch = curl_init($url);
        $cabecalhos = array_merge([
            'Accept: application/json',
            'User-Agent: AppAuto/2.1 (+https://erp.appauto.com.br)',
        ], $headers);

        $opcoes = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $cabecalhos,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($corpo !== null) {
            $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
            $opcoes[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }

        curl_setopt_array($ch, $opcoes);
        $resposta = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);

        if (!is_string($resposta) || $status < 200 || $status >= 300) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => $erro];
        }

        $dados = json_decode($resposta, true);
        if (!is_array($dados)) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => 'Resposta não JSON'];
        }

        return ['success' => true, 'status' => $status, 'dados' => $dados, 'erro' => ''];
    }

    /** @return array<string, mixed> */
    private function normalizarResposta(array $origem, string $placa, string $fonte): array
    {
        $dadosFonte = $origem;
        foreach (['data', 'result', 'veiculo', 'vehicle', 'response'] as $container) {
            if (is_array($origem[$container] ?? null)) {
                $dadosFonte = array_merge($dadosFonte, $origem[$container]);
            }
        }

        $extra = is_array($dadosFonte['extra'] ?? null) ? $dadosFonte['extra'] : [];
        $fipe = $this->normalizarFipe($dadosFonte['fipe'] ?? $dadosFonte['dados_fipe'] ?? []);

        $dados = $this->dadosBase($placa);
        $dados['fonte_principal'] = $fonte;
        $dados['marca'] = $this->texto($this->encontrar($dadosFonte, $extra, ['marca', 'MARCA', 'make', 'fabricante']));
        $dados['modelo'] = $this->texto($this->encontrar($dadosFonte, $extra, ['modelo', 'MODELO', 'model', 'marcaModelo', 'descricao_modelo']));
        $dados['submodelo'] = $this->texto($this->encontrar($dadosFonte, $extra, ['submodelo', 'SUBMODELO', 'grupo']));
        $dados['versao'] = $this->texto($this->encontrar($dadosFonte, $extra, ['versao', 'VERSAO', 'version', 'texto_modelo']));
        $dados['ano_fabricacao'] = $this->ano($this->encontrar($dadosFonte, $extra, ['ano_fabricacao', 'anoFabricacao', 'ano']));
        $dados['ano_modelo'] = $this->ano($this->encontrar($dadosFonte, $extra, ['ano_modelo', 'anoModelo', 'ano_modelo_fipe']));
        $dados['cor'] = $this->texto($this->encontrar($dadosFonte, $extra, ['cor', 'COR', 'color']));
        $dados['combustivel'] = $this->normalizarCombustivel($this->texto($this->encontrar($dadosFonte, $extra, ['combustivel', 'fuel', 'tipo_combustivel'])));
        $dados['municipio'] = $this->texto($this->encontrar($dadosFonte, $extra, ['municipio', 'cidade']));
        $dados['uf'] = strtoupper((string)$this->texto($this->encontrar($dadosFonte, $extra, ['uf', 'UF', 'uf_placa', 'estado'])));
        $dados['situacao'] = $this->texto($this->encontrar($dadosFonte, $extra, ['situacao', 'situacao_veiculo', 'status']));
        $dados['categoria'] = $this->normalizarCategoria($this->texto($this->encontrar($dadosFonte, $extra, ['categoria', 'segmento', 'sub_segmento'])));
        $dados['tipo_veiculo'] = $this->texto($this->encontrar($dadosFonte, $extra, ['tipo_veiculo', 'tipoVeiculo', 'especie']));
        $dados['origem'] = $this->texto($this->encontrar($dadosFonte, $extra, ['origem', 'nacionalidade']));
        $dados['carroceria'] = $this->texto($this->encontrar($dadosFonte, $extra, ['carroceria', 'tipo_carroceria']));
        $dados['cilindradas'] = $this->texto($this->encontrar($dadosFonte, $extra, ['cilindradas']));
        $dados['eixos'] = $this->texto($this->encontrar($dadosFonte, $extra, ['eixos']));
        $dados['capacidade_passageiros'] = $this->texto($this->encontrar($dadosFonte, $extra, ['quantidade_passageiro', 'capacidade_passageiros']));
        $dados['peso_bruto_total'] = $this->texto($this->encontrar($dadosFonte, $extra, ['peso_bruto_total']));
        $dados['fipe'] = $fipe;

        // Dados mascarados nunca devem preencher campos do formulário.
        $chassi = $this->texto($this->encontrar($dadosFonte, $extra, ['chassi', 'CHASSI', 'vin']));
        $dados['chassi'] = str_contains((string)$chassi, '*') ? null : $chassi;
        $dados['renavam'] = null;

        if ($dados['ano_fabricacao'] && $dados['ano_modelo']) {
            $dados['ano'] = $dados['ano_fabricacao'] . '/' . $dados['ano_modelo'];
        } elseif ($dados['ano_modelo']) {
            $dados['ano'] = (string)$dados['ano_modelo'];
        }

        $dados['dados_tecnicos'] = array_filter([
            'Submodelo' => $dados['submodelo'],
            'Tipo de veículo' => $dados['tipo_veiculo'],
            'Origem' => $dados['origem'],
            'Carroceria' => $dados['carroceria'],
            'Cilindradas' => $dados['cilindradas'],
            'Eixos' => $dados['eixos'],
            'Capacidade de passageiros' => $dados['capacidade_passageiros'],
            'Peso bruto total' => $dados['peso_bruto_total'],
        ], static fn ($valor): bool => $valor !== null && $valor !== '');

        return $dados;
    }

    /** @return array<string, mixed> */
    private function dadosBase(string $placa): array
    {
        return [
            'placa' => $placa,
            'placa_formatada' => preg_match('/^[A-Z]{3}[0-9][A-Z][0-9]{2}$/', $placa)
                ? $placa
                : substr($placa, 0, 3) . '-' . substr($placa, 3),
            'formato_placa' => preg_match('/^[A-Z]{3}[0-9][A-Z][0-9]{2}$/', $placa) ? 'mercosul' : 'padrao',
            'marca' => null,
            'modelo' => null,
            'submodelo' => null,
            'versao' => null,
            'ano' => null,
            'ano_fabricacao' => null,
            'ano_modelo' => null,
            'cor' => null,
            'combustivel' => null,
            'chassi' => null,
            'renavam' => null,
            'municipio' => null,
            'uf' => null,
            'situacao' => null,
            'categoria' => null,
            'tipo_veiculo' => null,
            'origem' => null,
            'carroceria' => null,
            'cilindradas' => null,
            'eixos' => null,
            'capacidade_passageiros' => null,
            'peso_bruto_total' => null,
            'fipe' => [],
            'dados_tecnicos' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function mesclarDados(array $atual, array $novo): array
    {
        foreach ($novo as $chave => $valor) {
            if (in_array($chave, ['fontes_consultadas', 'fontes_tentadas', 'campos_encontrados'], true)) {
                continue;
            }
            if (($atual[$chave] ?? null) === null || $atual[$chave] === '' || $atual[$chave] === []) {
                $atual[$chave] = $valor;
            }
        }

        $atual['dados_tecnicos'] = array_merge($atual['dados_tecnicos'] ?? [], $novo['dados_tecnicos'] ?? []);
        return $atual;
    }

    private function pontuarCompletude(array $dados): int
    {
        $campos = ['marca', 'modelo', 'versao', 'ano_fabricacao', 'ano_modelo', 'cor', 'combustivel', 'municipio', 'uf', 'situacao', 'tipo_veiculo', 'carroceria'];
        $pontuacao = 0;
        foreach ($campos as $campo) {
            if (!empty($dados[$campo])) {
                ++$pontuacao;
            }
        }
        return $pontuacao;
    }

    /** @return list<string> */
    private function camposEncontrados(array $dados): array
    {
        $campos = [];
        foreach ($dados as $chave => $valor) {
            if (!in_array($chave, ['dados_tecnicos', 'fipe', 'fontes_consultadas', 'fontes_tentadas', 'campos_encontrados'], true)
                && $valor !== null && $valor !== '' && $valor !== []) {
                $campos[] = $chave;
            }
        }
        return $campos;
    }

    private function encontrar(array $origem, array $extra, array $chaves): mixed
    {
        foreach ($chaves as $chave) {
            foreach ([$origem, $extra] as $fonte) {
                foreach ($fonte as $campo => $valor) {
                    if (strcasecmp((string)$campo, $chave) === 0 && !is_array($valor)) {
                        return $valor;
                    }
                }
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function normalizarFipe(mixed $fipe): array
    {
        if (!is_array($fipe)) {
            return [];
        }

        $lista = $fipe['dados'] ?? $fipe;
        if (!is_array($lista)) {
            return [];
        }
        if (isset($lista['codigo_fipe'])) {
            $lista = [$lista];
        }

        $melhor = [];
        $maiorScore = -1;
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $score = (int)($item['score'] ?? 0);
            if ($score >= $maiorScore) {
                $maiorScore = $score;
                $melhor = [
                    'codigo' => $item['codigo_fipe'] ?? $item['codigo'] ?? null,
                    'marca' => $item['texto_marca'] ?? $item['marca'] ?? null,
                    'modelo' => $item['texto_modelo'] ?? $item['modelo'] ?? null,
                    'valor' => $item['texto_valor'] ?? $item['valor'] ?? null,
                    'referencia' => $item['mes_referencia'] ?? $item['referencia'] ?? null,
                    'score' => $item['score'] ?? null,
                ];
            }
        }

        return array_filter($melhor, static fn ($valor): bool => $valor !== null && $valor !== '');
    }

    private function normalizarPlaca(string $placa): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $placa));
    }

    private function placaValida(string $placa): bool
    {
        return (bool)preg_match('/^[A-Z]{3}(?:[0-9]{4}|[0-9][A-Z][0-9]{2})$/', $placa);
    }

    private function texto(mixed $valor): ?string
    {
        if (!is_scalar($valor)) {
            return null;
        }
        $texto = trim((string)$valor);
        return $texto !== '' ? $texto : null;
    }

    private function ano(mixed $valor): ?int
    {
        $ano = (int)$valor;
        return $ano >= 1900 && $ano <= ((int)date('Y') + 2) ? $ano : null;
    }

    private function normalizarCombustivel(?string $combustivel): ?string
    {
        if ($combustivel === null) {
            return null;
        }

        $valor = strtolower($combustivel);
        if ((str_contains($valor, 'alcool') || str_contains($valor, 'álcool')) && str_contains($valor, 'gasolina')) {
            return 'flex';
        }

        foreach (['flex', 'gasolina', 'etanol', 'alcool', 'álcool', 'diesel', 'gnv', 'eletrico', 'hibrido'] as $opcao) {
            if (str_contains($valor, $opcao)) {
                return in_array($opcao, ['alcool', 'álcool'], true) ? 'etanol' : $opcao;
            }
        }
        return $valor;
    }

    private function normalizarCategoria(?string $categoria): ?string
    {
        if ($categoria === null) {
            return null;
        }
        $valor = strtolower($categoria);
        foreach (['pickup', 'caminhao', 'onibus', 'moto', 'van', 'suv', 'utilitario', 'passeio'] as $opcao) {
            if (str_contains($valor, $opcao)) {
                return $opcao;
            }
        }
        return null;
    }

    private function env(string $chave): string
    {
        $valor = $_ENV[$chave] ?? getenv($chave) ?: '';
        return is_string($valor) ? trim($valor) : '';
    }

    private function envFlag(string $chave, bool $padrao): bool
    {
        $valor = $this->env($chave);
        if ($valor === '') {
            return $padrao;
        }
        return in_array(strtolower($valor), ['1', 'true', 'yes', 'on'], true);
    }
}
