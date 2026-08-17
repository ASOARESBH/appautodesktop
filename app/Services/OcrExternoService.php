<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;

/**
 * Orquestrador de OCR externo para placa e documentos.
 *
 * Nenhuma chave é exposta ao cliente. A ordem dos provedores vem do ambiente:
 * OCR_PROVIDER_ORDER=cloudmersive,ocrspace,pixlab
 */
final class OcrExternoService
{
    private const CONNECT_TIMEOUT = 3;
    private const REQUEST_TIMEOUT = 15;
    private const MAX_BYTES = 10485760;

    /**
     * @return array{success: bool, provider: string, dados: array, tentativas: array, message?: string}
     */
    public function analisarUpload(array $arquivo, string $modo = 'documento'): array
    {
        $meta = $this->validarUpload($arquivo);
        $provedores = $this->provedoresConfigurados();
        $tentativas = [];

        foreach ($provedores as $nome) {
            if ($modo === 'placa' && $nome !== 'cloudmersive') {
                continue;
            }
            $tentativas[] = $nome;
            $resultado = $modo === 'placa'
                ? $this->lerPlacaCloudmersive($arquivo['tmp_name'], $meta['mime'])
                : $this->ocrDocumento($nome, $arquivo['tmp_name'], $meta['mime']);

            if ($resultado['success']) {
                Logger::info('OCR externo concluído', [
                    'provedor' => $nome,
                    'modo' => $modo,
                    'campos' => count($resultado['dados']),
                ]);
                $resultado['provider'] = $nome;
                $resultado['tentativas'] = $tentativas;
                return $resultado;
            }

            Logger::warning('OCR externo sem resultado', [
                'provedor' => $nome,
                'modo' => $modo,
                'status_http' => $resultado['status'],
                'erro_tecnico' => $resultado['erro'] !== '' ? 'sim' : 'não',
            ]);
        }

        return [
            'success' => false,
            'provider' => 'nenhum',
            'dados' => [],
            'tentativas' => $tentativas,
            'message' => 'Nenhum provedor OCR configurado retornou dados. Faça o preenchimento manual.',
        ];
    }

    /**
     * @return array{success: bool, status: int, detalhes: array, message?: string}
     */
    public function testar(string $provedor, ?array $arquivo = null, string $modo = 'documento', string $imagemUrl = ''): array
    {
        $provedor = strtolower(trim($provedor));
        if (in_array($provedor, ['cloudmersive', 'ocrspace', 'pixlab'], true) && $this->chaveDo($provedor) === '') {
            return [
                'success' => false,
                'status' => 503,
                'detalhes' => [],
                'message' => 'Este provedor OCR não está configurado no ambiente.',
            ];
        }
        if ($provedor === 'cloudmersive' && $arquivo !== null) {
            $meta = $this->validarUpload($arquivo);
            $resultado = $modo === 'placa'
                ? $this->lerPlacaCloudmersive($arquivo['tmp_name'], $meta['mime'])
                : $this->cloudmersiveTexto($arquivo['tmp_name'], $meta['mime']);
            return [
                'success' => $resultado['success'],
                'status' => $resultado['status'],
                'detalhes' => $this->resumoResultado($resultado),
                'message' => $resultado['success'] ? null : 'Cloudmersive não retornou dados aproveitáveis.',
            ];
        }

        if (in_array($provedor, ['ocrspace', 'pixlab'], true)) {
            if ($arquivo !== null) {
                $meta = $this->validarUpload($arquivo);
                $resultado = $this->ocrDocumento($provedor, $arquivo['tmp_name'], $meta['mime']);
            } elseif ($imagemUrl !== '') {
                $resultado = $this->ocrDocumentoUrl($provedor, $imagemUrl);
            } else {
                return [
                    'success' => false,
                    'status' => 422,
                    'detalhes' => [],
                    'message' => 'Envie uma imagem ou informe uma URL de imagem para testar este provedor.',
                ];
            }

            return [
                'success' => $resultado['success'],
                'status' => $resultado['status'],
                'detalhes' => $this->resumoResultado($resultado),
                'message' => $resultado['success'] ? null : 'O provedor não retornou texto aproveitável.',
            ];
        }

        return [
            'success' => false,
            'status' => 422,
            'detalhes' => [],
            'message' => 'Provedor OCR não reconhecido ou sem configuração de teste.',
        ];
    }

    /** @return array<int, string> */
    public function provedoresDisponiveis(): array
    {
        $configurados = [];
        foreach (['cloudmersive', 'ocrspace', 'pixlab'] as $nome) {
            if ($this->chaveDo($nome) !== '') {
                $configurados[] = $nome;
            }
        }
        return $configurados;
    }

    /** @return array{mime: string, extensao: string, tamanho: int} */
    private function validarUpload(array $arquivo): array
    {
        $erro = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp = (string)($arquivo['tmp_name'] ?? '');
        $tamanho = (int)($arquivo['size'] ?? 0);

        if ($erro !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || $tamanho <= 0) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }
        if ($tamanho > self::MAX_BYTES) {
            throw new RuntimeException('A imagem deve ter no máximo 10 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }

        $permitidos = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/bmp' => 'bmp',
            'application/pdf' => 'pdf',
        ];
        if (!isset($permitidos[$mime])) {
            throw new RuntimeException('Envie somente JPG, PNG, BMP ou PDF válidos.');
        }

        return ['mime' => $mime, 'extensao' => $permitidos[$mime], 'tamanho' => $tamanho];
    }

    /** @return array<int, string> */
    private function provedoresConfigurados(): array
    {
        $ordem = $this->env('OCR_PROVIDER_ORDER');
        $nomes = $ordem === '' ? ['cloudmersive', 'ocrspace', 'pixlab'] : explode(',', strtolower($ordem));
        $resultado = [];
        foreach ($nomes as $nome) {
            $nome = trim($nome);
            if (!in_array($nome, ['cloudmersive', 'ocrspace', 'pixlab'], true) || $this->chaveDo($nome) === '') {
                continue;
            }
            if (!in_array($nome, $resultado, true)) {
                $resultado[] = $nome;
            }
        }
        return $resultado;
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function ocrDocumento(string $provedor, string $caminho, string $mime): array
    {
        return match ($provedor) {
            'cloudmersive' => $this->cloudmersiveTexto($caminho, $mime),
            'ocrspace' => $this->ocrSpaceArquivo($caminho, $mime),
            'pixlab' => ['success' => false, 'status' => 422, 'dados' => [], 'erro' => 'PixLab requer URL de imagem neste adaptador'],
            default => ['success' => false, 'status' => 422, 'dados' => [], 'erro' => 'Provedor desconhecido'],
        };
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function ocrDocumentoUrl(string $provedor, string $imagemUrl): array
    {
        if (!filter_var($imagemUrl, FILTER_VALIDATE_URL) || !in_array((string)parse_url($imagemUrl, PHP_URL_SCHEME), ['https'], true)) {
            return ['success' => false, 'status' => 422, 'dados' => [], 'erro' => 'A URL da imagem deve usar HTTPS'];
        }

        return $provedor === 'pixlab'
            ? $this->pixlabUrl($imagemUrl)
            : $this->ocrSpaceUrl($imagemUrl);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function lerPlacaCloudmersive(string $caminho, string $mime): array
    {
        if ($this->chaveDo('cloudmersive') === '') {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Cloudmersive não configurado'];
        }

        $resposta = $this->postArquivo(
            $this->baseUrl('cloudmersive') . '/image/recognize/detect-vehicle-license-plates',
            $caminho,
            $mime,
            ['Apikey: ' . $this->chaveDo('cloudmersive')]
        );
        if (!$resposta['success']) {
            return $resposta;
        }

        $placas = is_array($resposta['dados']['DetectedLicensePlates'] ?? null)
            ? $resposta['dados']['DetectedLicensePlates']
            : [];
        $primeira = is_array($placas[0] ?? null) ? $placas[0] : [];
        $texto = trim((string)($primeira['LicensePlateText_BestMatch'] ?? ''));
        if ($texto === '') {
            return ['success' => false, 'status' => $resposta['status'], 'dados' => [], 'erro' => 'Nenhuma placa detectada'];
        }

        return [
            'success' => true,
            'status' => $resposta['status'],
            'dados' => [
                'placa' => $this->normalizarPlaca($texto),
                'texto_original' => $texto,
                'alternativa' => $primeira['LicensePlateText_RunnerUp'] ?? null,
                'confianca' => $primeira['LicensePlateRecognitionConfidenceLevel'] ?? null,
                'quantidade_detectada' => count($placas),
            ],
            'erro' => '',
        ];
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function cloudmersiveTexto(string $caminho, string $mime = 'application/octet-stream'): array
    {
        $resposta = $this->postArquivo(
            $this->baseUrl('cloudmersive') . '/image/recognize/detect-text/fine',
            $caminho,
            $mime,
            ['Apikey: ' . $this->chaveDo('cloudmersive')]
        );
        if (!$resposta['success']) {
            return $resposta;
        }

        $itens = is_array($resposta['dados']['TextItems'] ?? null) ? $resposta['dados']['TextItems'] : [];
        $textos = [];
        foreach ($itens as $item) {
            if (is_array($item) && trim((string)($item['DetectedText'] ?? '')) !== '') {
                $textos[] = trim((string)$item['DetectedText']);
            }
        }
        return $this->resultadoTexto($resposta['status'], implode("\n", $textos), $itens);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function ocrSpaceArquivo(string $caminho, string $mime): array
    {
        $ch = curl_init($this->env('OCRSPACE_ENDPOINT') ?: 'https://api.ocr.space/parse/image');
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Não foi possível iniciar cURL'];
        }

        $post = [
            'apikey' => $this->chaveDo('ocrspace'),
            'language' => $this->env('OCRSPACE_LANGUAGE') ?: 'por',
            'isOverlayRequired' => 'false',
            'file' => new \CURLFile($caminho, $mime, 'documento'),
        ];
        $resposta = $this->executarPost($ch, $post);
        if (!$resposta['success']) {
            return $resposta;
        }

        $textos = [];
        foreach (($resposta['dados']['ParsedResults'] ?? []) as $item) {
            if (is_array($item) && trim((string)($item['ParsedText'] ?? '')) !== '') {
                $textos[] = trim((string)$item['ParsedText']);
            }
        }
        return $this->resultadoTexto($resposta['status'], implode("\n", $textos), $resposta['dados']);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function ocrSpaceUrl(string $imagemUrl): array
    {
        $endpoint = $this->env('OCRSPACE_URL_ENDPOINT') ?: 'https://api.ocr.space/parse/imageurl';
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Não foi possível iniciar cURL'];
        }
        $resposta = $this->executarPost($ch, [
            'apikey' => $this->chaveDo('ocrspace'),
            'url' => $imagemUrl,
            'language' => $this->env('OCRSPACE_LANGUAGE') ?: 'por',
            'isOverlayRequired' => 'false',
        ]);
        if (!$resposta['success']) {
            return $resposta;
        }
        $textos = [];
        foreach (($resposta['dados']['ParsedResults'] ?? []) as $item) {
            if (is_array($item) && trim((string)($item['ParsedText'] ?? '')) !== '') {
                $textos[] = trim((string)$item['ParsedText']);
            }
        }
        return $this->resultadoTexto($resposta['status'], implode("\n", $textos), $resposta['dados']);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function pixlabUrl(string $imagemUrl): array
    {
        $endpoint = $this->env('PIXLAB_OCR_ENDPOINT') ?: 'https://api.pixlab.io/ocr';
        $query = http_build_query([
            'img' => $imagemUrl,
            'orientation' => 'true',
            'nl' => 'true',
            'lang' => $this->env('PIXLAB_LANGUAGE') ?: 'pt',
            'key' => $this->chaveDo('pixlab'),
        ]);
        $resposta = $this->getJson($endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $query, []);
        if (!$resposta['success']) {
            return $resposta;
        }
        return $this->resultadoTexto(
            $resposta['status'],
            (string)($resposta['dados']['output'] ?? ''),
            $resposta['dados']
        );
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function postArquivo(string $url, string $caminho, string $mime, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Não foi possível iniciar cURL'];
        }
        $post = ['imageFile' => new \CURLFile($caminho, $mime, 'imagem')];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        return $this->executar($ch);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function executarPost(\CurlHandle $ch, array $post): array
    {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        return $this->executar($ch);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function getJson(string $url, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['success' => false, 'status' => 0, 'dados' => [], 'erro' => 'Não foi possível iniciar cURL'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        return $this->executar($ch);
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function executar(\CurlHandle $ch): array
    {
        $corpo = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if (!is_string($corpo) || $status < 200 || $status >= 300) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => $erro];
        }
        $dados = json_decode($corpo, true);
        if (!is_array($dados)) {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => 'Resposta não JSON'];
        }
        return ['success' => true, 'status' => $status, 'dados' => $dados, 'erro' => ''];
    }

    /** @return array{success: bool, status: int, dados: array, erro: string} */
    private function resultadoTexto(int $status, string $texto, array $bruto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return ['success' => false, 'status' => $status, 'dados' => [], 'erro' => 'Nenhum texto reconhecido'];
        }
        return [
            'success' => true,
            'status' => $status,
            'dados' => [
                'texto' => $texto,
                'confianca' => null,
                'campos_encontrados' => $this->camposProvaveis($texto),
                'quantidade_caracteres' => mb_strlen($texto),
                'resumo_provedor' => $this->resumirBruto($bruto),
            ],
            'erro' => '',
        ];
    }

    private function normalizarPlaca(string $placa): string
    {
        return strtoupper((string)preg_replace('/[^A-Z0-9]/i', '', $placa));
    }

    /** @return array<int, string> */
    private function camposProvaveis(string $texto): array
    {
        $campos = [];
        if (preg_match('/\b[A-Z]{3}[0-9]{4}\b|\b[A-Z]{3}[0-9][A-Z][0-9]{2}\b/i', $texto)) {
            $campos[] = 'placa';
        }
        if (preg_match('/\b\d{11}\b/', preg_replace('/\D+/', ' ', $texto))) {
            $campos[] = 'renavam_ou_documento';
        }
        foreach (['marca', 'modelo', 'chassi', 'renavam', 'combustivel', 'cor'] as $campo) {
            if (stripos($texto, $campo) !== false) {
                $campos[] = $campo;
            }
        }
        return array_values(array_unique($campos));
    }

    private function resumirBruto(array $bruto): array
    {
        return [
            'tem_resultado' => $bruto !== [],
            'chaves' => array_slice(array_map('strval', array_keys($bruto)), 0, 30),
        ];
    }

    /** @return array<string, mixed> */
    private function resumoResultado(array $resultado): array
    {
        return [
            'provider' => $resultado['provider'] ?? null,
            'tentativas' => $resultado['tentativas'] ?? [],
            'dados' => $resultado['dados'] ?? [],
        ];
    }

    private function chaveDo(string $provedor): string
    {
        return match ($provedor) {
            'cloudmersive' => $this->env('CLOUDMERSIVE_API_KEY'),
            'ocrspace' => $this->env('OCRSPACE_API_KEY'),
            'pixlab' => $this->env('PIXLAB_API_KEY'),
            default => '',
        };
    }

    private function baseUrl(string $provedor): string
    {
        $url = match ($provedor) {
            'cloudmersive' => $this->env('CLOUDMERSIVE_BASE_URL') ?: 'https://api.cloudmersive.com',
            default => '',
        };
        return rtrim($url, '/');
    }

    private function env(string $chave): string
    {
        $valor = $_ENV[$chave] ?? getenv($chave) ?: '';
        return is_string($valor) ? trim($valor) : '';
    }
}
