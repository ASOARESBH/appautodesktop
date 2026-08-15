<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use RuntimeException;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * Pipeline local e síncrono de OCR para documentos do Portal.
 *
 * Os arquivos originais permanecem fora do document root. O serviço não
 * registra texto OCR, identificação civil ou caminho de arquivos nos logs.
 */
final class DocumentoOcrService
{
    public const MAX_BYTES = 10485760; // 10 MiB

    private const MIME_TO_EXTENSION = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    private string $projectRoot;
    private string $storageRoot;

    public function __construct()
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->storageRoot = $this->projectRoot . '/storage/documentos';
    }

    /** @return array{tesseract: bool, portugues: bool, pdftoppm: bool, imagemagick: bool} */
    public function capacidades(): array
    {
        $tesseract = $this->localizarComando('tesseract');
        $idiomas = $tesseract === '' ? [] : $this->idiomasTesseract($tesseract);

        return [
            'tesseract' => $tesseract !== '',
            'portugues' => in_array('por', $idiomas, true),
            'pdftoppm' => $this->localizarComando('pdftoppm') !== '',
            'imagemagick' => $this->localizarComando('magick') !== '' || $this->localizarComando('convert') !== '',
        ];
    }

    /** @return array{mime: string, extensao: string, tamanho: int, nome_original: string} */
    public function validarUpload(array $arquivo): array
    {
        $erro = (int)($arquivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($erro !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->mensagemErroUpload($erro));
        }

        $tmp = (string)($arquivo['tmp_name'] ?? '');
        $tamanho = (int)($arquivo['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp) || $tamanho <= 0) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }
        if ($tamanho > self::MAX_BYTES) {
            throw new RuntimeException('O documento deve ter no máximo 10 MB.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        if (!isset(self::MIME_TO_EXTENSION[$mime])) {
            throw new RuntimeException('Envie somente PDF, JPG ou PNG válidos.');
        }

        $nomeOriginal = basename((string)($arquivo['name'] ?? 'documento'));
        $extensaoCliente = strtolower((string)pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $extensaoSegura = self::MIME_TO_EXTENSION[$mime];
        if ($extensaoCliente !== '' && !in_array($extensaoCliente, [$extensaoSegura, 'jpeg'], true)) {
            throw new RuntimeException('A extensão do arquivo não corresponde ao seu tipo real.');
        }

        return [
            'mime' => $mime,
            'extensao' => $extensaoSegura,
            'tamanho' => $tamanho,
            'nome_original' => $nomeOriginal,
        ];
    }

    /**
     * Armazena o original em storage privado e devolve metadados persistíveis.
     * @return array{caminho: string, caminho_absoluto: string, mime: string, extensao: string, tamanho: int, nome_original: string}
     */
    public function armazenarUpload(array $arquivo, int $usuarioId, ?int $veiculoId): array
    {
        $meta = $this->validarUpload($arquivo);
        $segmentoVeiculo = $veiculoId !== null && $veiculoId > 0 ? (string)$veiculoId : 'pessoal';
        $diretorio = $this->storageRoot . '/' . $usuarioId . '/' . $segmentoVeiculo;
        $this->criarDiretorio($diretorio, 0700);

        $nomeSeguro = bin2hex(random_bytes(18)) . '.' . $meta['extensao'];
        $destino = $diretorio . '/' . $nomeSeguro;
        if (!move_uploaded_file((string)$arquivo['tmp_name'], $destino)) {
            throw new RuntimeException('Não foi possível armazenar o documento de forma segura.');
        }
        @chmod($destino, 0600);

        $meta['caminho'] = 'documentos/' . $usuarioId . '/' . $segmentoVeiculo . '/' . $nomeSeguro;
        $meta['caminho_absoluto'] = $destino;
        return $meta;
    }

    /**
     * Analisa o arquivo já armazenado. Falhas do motor OCR retornam status
     * controlado para que o upload e o preenchimento manual continuem possíveis.
     *
     * @return array{status: string, mensagem: string, texto: string, confianca: ?float, dados: array, campos_encontrados: array}
     */
    public function analisarArquivo(string $caminhoAbsoluto, string $mime, string $tipo): array
    {
        $tipo = strtolower(trim($tipo));
        if (!in_array($tipo, ['crlv', 'cnh'], true)) {
            return [
                'status' => 'pendente',
                'mensagem' => 'OCR disponível apenas para CRLV e CNH.',
                'texto' => '',
                'confianca' => null,
                'dados' => [],
                'campos_encontrados' => [],
            ];
        }

        $capacidade = $this->capacidades();
        if (!$capacidade['tesseract'] || !$capacidade['portugues']) {
            Logger::warning('OCR local indisponível no ambiente', [
                'tesseract' => $capacidade['tesseract'] ? 'sim' : 'não',
                'idioma_por' => $capacidade['portugues'] ? 'sim' : 'não',
            ]);
            return [
                'status' => 'indisponivel',
                'mensagem' => 'OCR local indisponível neste servidor. O documento foi salvo e pode ser preenchido manualmente.',
                'texto' => '',
                'confianca' => null,
                'dados' => [],
                'campos_encontrados' => [],
            ];
        }
        if ($mime === 'application/pdf' && !$capacidade['pdftoppm']) {
            return [
                'status' => 'indisponivel',
                'mensagem' => 'O servidor não possui conversor de PDF configurado para OCR.',
                'texto' => '',
                'confianca' => null,
                'dados' => [],
                'campos_encontrados' => [],
            ];
        }

        $trabalho = $this->projectRoot . '/storage/tmp/ocr/' . bin2hex(random_bytes(12));
        $this->criarDiretorio($trabalho, 0700);

        try {
            $imagem = $this->converterPrimeiraPagina($caminhoAbsoluto, $mime, $trabalho);
            $imagemPreparada = $this->preprocessarImagem($imagem, $trabalho, $capacidade['imagemagick']);
            $resultado = $this->executarTesseract($imagemPreparada);

            if (!$resultado['success']) {
                Logger::warning('OCR local não concluiu a leitura', ['tipo' => $tipo, 'motivo' => $resultado['motivo']]);
                return [
                    'status' => 'erro',
                    'mensagem' => 'Não foi possível processar o texto do documento. Revise a imagem ou preencha manualmente.',
                    'texto' => '',
                    'confianca' => null,
                    'dados' => [],
                    'campos_encontrados' => [],
                ];
            }

            $dados = $tipo === 'crlv'
                ? $this->extrairCrlv($resultado['texto'])
                : $this->extrairCnh($resultado['texto']);
            $campos = array_keys(array_filter($dados, static fn ($valor): bool => $valor !== null && $valor !== ''));
            $status = count($campos) >= 4 ? 'sucesso' : 'parcial';

            Logger::info('OCR local concluído', [
                'tipo' => $tipo,
                'status' => $status,
                'campos_extraidos' => count($campos),
                'confianca_disponivel' => $resultado['confianca'] !== null ? 'sim' : 'não',
            ]);

            return [
                'status' => $status,
                'mensagem' => $status === 'sucesso'
                    ? 'Dados extraídos. Revise as informações antes de salvar.'
                    : 'O texto foi lido parcialmente. Revise e complete os campos manualmente.',
                'texto' => $resultado['texto'],
                'confianca' => $resultado['confianca'],
                'dados' => $dados,
                'campos_encontrados' => $campos,
            ];
        } finally {
            $this->removerDiretorio($trabalho);
        }
    }

    public function caminhoAbsoluto(string $caminhoRelativo): string
    {
        $caminhoRelativo = ltrim(str_replace('\\', '/', $caminhoRelativo), '/');
        if (!str_starts_with($caminhoRelativo, 'documentos/') || str_contains($caminhoRelativo, '..')) {
            throw new RuntimeException('Caminho de documento inválido.');
        }
        return $this->projectRoot . '/storage/' . $caminhoRelativo;
    }

    /** @return array{success: bool, texto: string, confianca: ?float, motivo: string} */
    private function executarTesseract(string $imagem): array
    {
        $binario = $this->localizarComando('tesseract');
        try {
            $texto = (new TesseractOCR($imagem))
                ->executable($binario)
                ->lang('por')
                ->psm(6)
                ->run(30);

            $tsv = (new TesseractOCR($imagem))
                ->executable($binario)
                ->lang('por')
                ->psm(6)
                ->tsv()
                ->run(30);

            return [
                'success' => trim($texto) !== '',
                'texto' => $texto,
                'confianca' => $this->mediaConfiancaTsv($tsv),
                'motivo' => trim($texto) === '' ? 'texto_vazio' : '',
            ];
        } catch (\Throwable $erro) {
            return ['success' => false, 'texto' => '', 'confianca' => null, 'motivo' => 'falha_motor'];
        }
    }

    private function converterPrimeiraPagina(string $arquivo, string $mime, string $trabalho): string
    {
        if ($mime !== 'application/pdf') {
            return $arquivo;
        }

        $pdftoppm = $this->localizarComando('pdftoppm');
        $destinoBase = $trabalho . '/pagina';
        $comando = escapeshellcmd($pdftoppm)
            . ' -f 1 -l 1 -r 300 -png -singlefile '
            . escapeshellarg($arquivo) . ' ' . escapeshellarg($destinoBase) . ' 2>/dev/null';
        exec($comando, $saida, $codigo);

        $imagem = $destinoBase . '.png';
        if ($codigo !== 0 || !is_file($imagem) || filesize($imagem) === 0) {
            throw new RuntimeException('Não foi possível converter a primeira página do PDF.');
        }
        return $imagem;
    }

    private function preprocessarImagem(string $imagem, string $trabalho, bool $temImagemagick): string
    {
        if (!$temImagemagick) {
            return $imagem;
        }

        $convert = $this->localizarComando('magick');
        $usaMagick = $convert !== '';
        if (!$usaMagick) {
            $convert = $this->localizarComando('convert');
        }
        if ($convert === '') {
            return $imagem;
        }

        $destino = $trabalho . '/preprocessado.png';
        $prefixo = escapeshellcmd($convert) . ($usaMagick ? ' convert' : '');
        $comando = $prefixo . ' ' . escapeshellarg($imagem)
            . ' -auto-orient -colorspace Gray -contrast-stretch 0x8 -threshold 58% -despeckle '
            . escapeshellarg($destino) . ' 2>/dev/null';
        exec($comando, $saida, $codigo);

        return $codigo === 0 && is_file($destino) && filesize($destino) > 0 ? $destino : $imagem;
    }

    /** @return array<string, mixed> */
    private function extrairCrlv(string $texto): array
    {
        $normalizado = $this->normalizarTexto($texto);
        $placa = $this->primeiro('/\b([A-Z]{3}(?:-?[0-9]{4}|[0-9][A-Z][0-9]{2}))\b/', $normalizado);
        $chassi = $this->somenteAlfanumerico($this->proximoRotulo($normalizado, ['CHASSI', 'N.? CHASSI'], '/([A-Z0-9 ]{17,25})/'));
        if ($chassi !== null) {
            $chassi = substr($chassi, 0, 17);
        }

        $anos = $this->primeiro('/ANO(?:\s+DE)?\s*(?:FABRICACAO|FAB)?[^0-9]{0,24}(19[0-9]{2}|20[0-9]{2})\D{0,12}(19[0-9]{2}|20[0-9]{2})/', $normalizado, true);
        $marcaModelo = $this->proximoRotulo($normalizado, ['MARCA\/?MODELO', 'MARCA E MODELO'], '/([A-Z0-9 .\/\-]{3,100})/');

        return $this->limparDados([
            'placa' => $placa ? str_replace('-', '', $placa) : null,
            'renavam' => $this->somenteDigitos($this->proximoRotulo($normalizado, ['RENAVAM', 'N.? RENAVAM'], '/([0-9 .\-]{9,18})/'), [9, 11]),
            'chassi' => $chassi !== null && strlen($chassi) === 17 ? $chassi : null,
            'marca_modelo' => $marcaModelo,
            'ano_fabricacao' => is_array($anos) ? $anos[1] : null,
            'ano_modelo' => is_array($anos) ? $anos[2] : null,
            'cor' => $this->proximoRotulo($normalizado, ['COR(?: PREDOMINANTE)?'], '/([A-Z]{3,30})/'),
            'categoria' => $this->proximoRotulo($normalizado, ['CATEGORIA'], '/([A-Z ]{3,40})/'),
            'municipio' => $this->proximoRotulo($normalizado, ['MUNICIPIO'], '/([A-Z .\-]{3,80})/'),
            'uf' => $this->proximoRotulo($normalizado, ['\bUF\b', 'ESTADO'], '/([A-Z]{2})/'),
            'data_emissao' => $this->proximoRotulo($normalizado, ['EMISSAO', 'DATA DE EMISSAO'], '/([0-3][0-9]\/[0-1][0-9]\/[12][0-9]{3})/'),
            // Dados pessoais: mantidos no registro privado, nunca enviados a logs.
            'proprietario_documento' => $this->somenteDigitos($this->proximoRotulo($normalizado, ['CPF', 'CNPJ'], '/([0-9.\/\- ]{11,22})/'), [11, 14]),
            'proprietario_nome' => $this->proximoRotulo($normalizado, ['PROPRIETARIO', 'NOME'], '/([A-Z ]{3,100})/'),
        ]);
    }

    /** @return array<string, mixed> */
    private function extrairCnh(string $texto): array
    {
        $normalizado = $this->normalizarTexto($texto);
        return $this->limparDados([
            'numero_cnh' => $this->somenteDigitos($this->proximoRotulo($normalizado, ['N.? REGISTRO', 'REGISTRO', 'N.? HABILITACAO', 'N.?'], '/([0-9 .\-]{9,18})/'), [9, 11]),
            'nome' => $this->proximoRotulo($normalizado, ['NOME', 'IDENTIDADE'], '/([A-Z ]{3,100})/'),
            'cpf' => $this->somenteDigitos($this->proximoRotulo($normalizado, ['CPF'], '/([0-9.\- ]{11,16})/'), [11]),
            'data_nascimento' => $this->proximoRotulo($normalizado, ['NASCIMENTO', 'DATA NASC'], '/([0-3][0-9]\/[0-1][0-9]\/[12][0-9]{3})/'),
            'validade' => $this->proximoRotulo($normalizado, ['VALIDADE', 'VALIDA ATE'], '/([0-3][0-9]\/[0-1][0-9]\/[12][0-9]{3})/'),
            'categoria_cnh' => $this->proximoRotulo($normalizado, ['CATEGORIA', '\bCAT\b'], '/\b([A-E](?:\s*[A-E])?)\b/'),
            'data_emissao' => $this->proximoRotulo($normalizado, ['EMISSAO', '1.? HABILITACAO'], '/([0-3][0-9]\/[0-1][0-9]\/[12][0-9]{3})/'),
        ]);
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = mb_strtoupper($texto, 'UTF-8');
        $semAcento = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);
        return preg_replace('/[ \t]+/', ' ', (string)$semAcento) ?? '';
    }

    private function proximoRotulo(string $texto, array $rotulos, string $padraoValor): ?string
    {
        foreach ($rotulos as $rotulo) {
            $padrao = '/(?:' . $rotulo . ')[\s:\-]{0,12}' . trim($padraoValor, '/') . '/i';
            if (preg_match($padrao, $texto, $encontrado)) {
                return trim((string)end($encontrado));
            }
        }
        return null;
    }

    private function primeiro(string $padrao, string $texto, bool $todos = false): string|array|null
    {
        if (!preg_match($padrao, $texto, $encontrado)) {
            return null;
        }
        return $todos ? $encontrado : ($encontrado[1] ?? null);
    }

    private function somenteDigitos(?string $valor, array $tamanhos): ?string
    {
        if ($valor === null) {
            return null;
        }
        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        return in_array(strlen($digitos), $tamanhos, true) ? $digitos : null;
    }

    private function somenteAlfanumerico(?string $valor): ?string
    {
        return $valor === null ? null : preg_replace('/[^A-Z0-9]/', '', $valor);
    }

    /** @return array<string, mixed> */
    private function limparDados(array $dados): array
    {
        foreach ($dados as $chave => $valor) {
            if (is_string($valor)) {
                $valor = trim($valor, " \t\n\r\0\x0B:-");
            }
            $dados[$chave] = $valor === '' ? null : $valor;
        }
        return $dados;
    }

    private function mediaConfiancaTsv(string $tsv): ?float
    {
        $linhas = preg_split('/\r?\n/', trim($tsv)) ?: [];
        if (count($linhas) < 2) {
            return null;
        }
        $cabecalho = str_getcsv(array_shift($linhas), "\t");
        $indice = array_search('conf', $cabecalho, true);
        if ($indice === false) {
            return null;
        }
        $valores = [];
        foreach ($linhas as $linha) {
            $colunas = str_getcsv($linha, "\t");
            $confianca = isset($colunas[$indice]) ? (float)$colunas[$indice] : -1;
            if ($confianca >= 0) {
                $valores[] = $confianca;
            }
        }
        return $valores === [] ? null : round(array_sum($valores) / count($valores), 2);
    }

    /** @return list<string> */
    private function idiomasTesseract(string $binario): array
    {
        if (!function_exists('exec')) {
            return [];
        }
        exec(escapeshellcmd($binario) . ' --list-langs 2>/dev/null', $saida, $codigo);
        if ($codigo !== 0) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $saida), static fn (string $linha): bool => $linha !== '' && !str_starts_with($linha, 'List of available')));
    }

    private function localizarComando(string $nome): string
    {
        if (!function_exists('exec')) {
            return '';
        }
        $saida = [];
        $codigo = 1;
        exec('command -v ' . escapeshellarg($nome) . ' 2>/dev/null', $saida, $codigo);
        return $codigo === 0 && !empty($saida[0]) ? trim($saida[0]) : '';
    }

    private function criarDiretorio(string $diretorio, int $permissao): void
    {
        if (!is_dir($diretorio) && !mkdir($diretorio, $permissao, true) && !is_dir($diretorio)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento do documento.');
        }
        @chmod($diretorio, $permissao);
    }

    private function removerDiretorio(string $diretorio): void
    {
        if (!is_dir($diretorio)) {
            return;
        }
        foreach (scandir($diretorio) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $caminho = $diretorio . '/' . $item;
            is_dir($caminho) ? $this->removerDiretorio($caminho) : @unlink($caminho);
        }
        @rmdir($diretorio);
    }

    private function mensagemErroUpload(int $erro): string
    {
        return match ($erro) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O documento excede o limite permitido de 10 MB.',
            UPLOAD_ERR_PARTIAL => 'O envio do documento foi interrompido. Tente novamente.',
            UPLOAD_ERR_NO_FILE => 'Selecione um documento para enviar.',
            default => 'Não foi possível receber o documento enviado.',
        };
    }
}
