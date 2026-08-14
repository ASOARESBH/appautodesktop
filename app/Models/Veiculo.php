<?php

namespace App\Models;

use App\Core\Database;
use App\Services\ConsultaPlacaService;
use PDO;

/**
 * Model de Veículo — AppAuto SaaS
 * Cadastro, consulta de placa (API gratuita) e OCR de documento
 */
class Veiculo
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    // ----------------------------------------------------------------
    // Detecção de formato de placa
    // ----------------------------------------------------------------

    /**
     * Detecta se a placa é no formato Mercosul (AAA0A00) ou antigo (AAA0000)
     * Mercosul: 3 letras + 1 dígito + 1 letra + 2 dígitos
     * Antigo:   3 letras + 4 dígitos
     */
    public static function detectarFormatoPlaca(string $placa): string
    {
        $placa = strtoupper(preg_replace('/[^A-Z0-9]/', '', $placa));
        if (preg_match('/^[A-Z]{3}[0-9][A-Z][0-9]{2}$/', $placa)) {
            return 'mercosul';
        }
        if (preg_match('/^[A-Z]{3}[0-9]{4}$/', $placa)) {
            return 'antigo';
        }
        return 'desconhecido';
    }

    /**
     * Normaliza a placa removendo caracteres especiais
     */
    public static function normalizarPlaca(string $placa): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $placa));
    }

    // ----------------------------------------------------------------
    // Consulta de placa com fallback entre provedores configurados
    // ----------------------------------------------------------------

    public function consultarPlacaAPI(string $placa): array
    {
        return (new ConsultaPlacaService())->consultar($placa);
    }

    // ----------------------------------------------------------------
    // CRUD de veículos
    // ----------------------------------------------------------------

    public function buscarPorId(int $id): ?object
    {
        $stmt = $this->db->prepare(
            "SELECT v.*, u.nome_completo AS dono_nome
             FROM `veiculos` v
             LEFT JOIN `usuarios` u ON u.id = v.usuario_id
             WHERE v.id = :id LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_OBJ) ?: null;
    }

    public function listarPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT v.*,
                    (SELECT COUNT(*) FROM `veiculo_fotos` vf WHERE vf.veiculo_id = v.id) AS total_fotos
             FROM `veiculos` v
             WHERE v.usuario_id = :uid
             ORDER BY v.criado_em DESC"
        );
        $stmt->execute([':uid' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    public function criar(array $dados): int
    {
        $placa  = self::normalizarPlaca($dados['placa'] ?? '');
        $formato = self::detectarFormatoPlaca($placa);

        $stmt = $this->db->prepare(
            "INSERT INTO `veiculos`
                (`usuario_id`,`negocio_id`,`placa`,`formato_placa`,`renavam`,`chassi`,
                 `marca`,`modelo`,`versao`,`ano_fabricacao`,`ano_modelo`,
                 `cor`,`combustivel`,`categoria`,`tipo_veiculo`,
                 `municipio_emplacamento`,`uf_emplacamento`,`situacao`,
                 `foto_principal`,`foto_documento`,`observacoes`,`dados_fipe`)
             VALUES
                (:usuario_id,:negocio_id,:placa,:formato_placa,:renavam,:chassi,
                 :marca,:modelo,:versao,:ano_fabricacao,:ano_modelo,
                 :cor,:combustivel,:categoria,:tipo_veiculo,
                 :municipio_emplacamento,:uf_emplacamento,:situacao,
                 :foto_principal,:foto_documento,:observacoes,:dados_fipe)"
        );
        $stmt->execute([
            ':usuario_id'            => $dados['usuario_id'],
            ':negocio_id'            => $dados['negocio_id'] ?? null,
            ':placa'                 => $placa,
            ':formato_placa'         => $formato,
            ':renavam'               => $dados['renavam'] ?? null,
            ':chassi'                => $dados['chassi'] ?? null,
            ':marca'                 => $dados['marca'] ?? null,
            ':modelo'                => $dados['modelo'] ?? null,
            ':versao'                => $dados['versao'] ?? null,
            ':ano_fabricacao'        => $dados['ano_fabricacao'] ?? null,
            ':ano_modelo'            => $dados['ano_modelo'] ?? null,
            ':cor'                   => $dados['cor'] ?? null,
            ':combustivel'           => $dados['combustivel'] ?? null,
            ':categoria'             => $dados['categoria'] ?? null,
            ':tipo_veiculo'          => $dados['tipo_veiculo'] ?? 'carro',
            ':municipio_emplacamento'=> $dados['municipio_emplacamento'] ?? null,
            ':uf_emplacamento'       => $dados['uf_emplacamento'] ?? null,
            ':situacao'              => $dados['situacao'] ?? null,
            ':foto_principal'        => $dados['foto_principal'] ?? null,
            ':foto_documento'        => $dados['foto_documento'] ?? null,
            ':observacoes'           => $dados['observacoes'] ?? null,
            ':dados_fipe'            => isset($dados['dados_fipe']) ? json_encode($dados['dados_fipe']) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function placaExisteParaUsuario(string $placa, int $usuarioId): bool
    {
        $placa = self::normalizarPlaca($placa);
        $stmt  = $this->db->prepare(
            "SELECT COUNT(*) FROM `veiculos` WHERE `placa` = :placa AND `usuario_id` = :uid"
        );
        $stmt->execute([':placa' => $placa, ':uid' => $usuarioId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function salvarFoto(int $veiculoId, string $caminho, string $tipo = 'exterior', bool $principal = false): void
    {
        if ($principal) {
            $this->db->prepare("UPDATE `veiculo_fotos` SET `principal` = 0 WHERE `veiculo_id` = :id")
                     ->execute([':id' => $veiculoId]);
        }
        $stmt = $this->db->prepare(
            "INSERT INTO `veiculo_fotos` (`veiculo_id`,`caminho`,`tipo`,`principal`)
             VALUES (:vid,:caminho,:tipo,:principal)"
        );
        $stmt->execute([
            ':vid'      => $veiculoId,
            ':caminho'  => $caminho,
            ':tipo'     => $tipo,
            ':principal'=> $principal ? 1 : 0,
        ]);
    }

    public function listarFotos(int $veiculoId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM `veiculo_fotos` WHERE `veiculo_id` = :id ORDER BY `principal` DESC, `criado_em` ASC"
        );
        $stmt->execute([':id' => $veiculoId]);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    // ----------------------------------------------------------------
    // Upload de imagem para OCR
    // ----------------------------------------------------------------

    /**
     * Processa imagem de documento via OCR (usando tesseract ou API externa)
     * Retorna array com dados extraídos do documento
     */
    public function processarOCR(string $caminhoImagem): array
    {
        // Tentar Tesseract local se disponível
        $output = [];
        $retval = 0;
        @exec("which tesseract 2>/dev/null", $output, $retval);
        $temTesseract = ($retval === 0 && !empty($output));

        if ($temTesseract) {
            $saida = [];
            @exec("tesseract " . escapeshellarg($caminhoImagem) . " stdout -l por 2>/dev/null", $saida);
            $texto = implode("\n", $saida);
        } else {
            // Fallback: retornar indicação para preenchimento manual
            return [
                'success'  => false,
                'texto'    => '',
                'extraido' => [],
                'message'  => 'OCR não disponível. Preencha os dados manualmente.',
            ];
        }

        // Extrair dados do texto OCR
        $extraido = $this->extrairDadosOCR($texto);

        return [
            'success'  => true,
            'texto'    => $texto,
            'extraido' => $extraido,
        ];
    }

    /**
     * Extrai campos do texto OCR de um CRLV/documento veicular
     */
    private function extrairDadosOCR(string $texto): array
    {
        $dados = [];

        // Placa (formato antigo ou Mercosul)
        if (preg_match('/\b([A-Z]{3}[0-9][A-Z0-9][0-9]{2})\b/', strtoupper($texto), $m)) {
            $dados['placa'] = $m[1];
        }

        // RENAVAM (9 a 11 dígitos)
        if (preg_match('/RENAVAM[:\s]*([0-9]{9,11})/i', $texto, $m)) {
            $dados['renavam'] = $m[1];
        }

        // Chassi (17 caracteres alfanuméricos)
        if (preg_match('/CHASSI[:\s]*([A-Z0-9]{17})/i', $texto, $m)) {
            $dados['chassi'] = $m[1];
        }

        // Ano de fabricação/modelo
        if (preg_match('/ANO[^0-9]*([0-9]{4})[\/\-]([0-9]{4})/i', $texto, $m)) {
            $dados['ano_fabricacao'] = $m[1];
            $dados['ano_modelo']     = $m[2];
        }

        // Cor
        $cores = ['BRANCA','PRETA','PRATA','CINZA','VERMELHA','AZUL','VERDE','AMARELA','MARROM','BEGE','LARANJA','ROXA','VINHO'];
        foreach ($cores as $cor) {
            if (stripos($texto, $cor) !== false) {
                $dados['cor'] = ucfirst(strtolower($cor));
                break;
            }
        }

        // Combustível
        $combustiveis = ['GASOLINA','ETANOL','FLEX','DIESEL','GNV'];
        foreach ($combustiveis as $comb) {
            if (stripos($texto, $comb) !== false) {
                $dados['combustivel'] = strtolower($comb);
                break;
            }
        }

        return $dados;
    }

    // ----------------------------------------------------------------
    // Registrar consulta no histórico
    // ----------------------------------------------------------------

    public function registrarConsulta(string $placa, int $usuarioId = null, string $fonte = 'api', bool $sucesso = false, string $json = ''): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `consultas_placa` (`usuario_id`,`placa`,`fonte`,`resposta_json`,`sucesso`,`ip`)
             VALUES (:uid,:placa,:fonte,:json,:sucesso,:ip)"
        );
        $stmt->execute([
            ':uid'    => $usuarioId,
            ':placa'  => $placa,
            ':fonte'  => $fonte,
            ':json'   => $json,
            ':sucesso'=> $sucesso ? 1 : 0,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

}
