<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Core\Database;
use App\Core\Logger;
use App\Services\DocumentoOcrService;

class PortalController extends Controller
{
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->requireAuth();
    }

    // =========================================================
    // Dashboard do Portal
    // =========================================================
    public function dashboard(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $_SESSION['veiculo_ativo_id'] ?? null;

        // Valores padrão — protegidos contra tabelas inexistentes (migration 002 pode não ter sido importada)
        $totalVeiculos       = 0;
        $totalManutencoes    = 0;
        $totalAbastecimentos = 0;
        $totalDocumentos     = 0;
        $ultimasManutencoes  = [];
        $alertas             = [];
        $scoreVeiculo        = 0;
        $ptsManu = $ptsDocs = $ptsPneus = $ptsBat = $ptsSeg = 0;
        $migrationPendente   = false;

        try {
            $totalVeiculos = $this->count('veiculos', $userId);
        } catch (\Exception $e) {
            Logger::error('Dashboard: tabela veiculos nao existe', ['err' => $e->getMessage()]);
            $migrationPendente = true;
        }

        if (!$migrationPendente) {
            try {
                $totalManutencoes    = $veiculoId ? $this->count('veiculo_manutencoes', $userId, $veiculoId) : 0;
                $totalAbastecimentos = $veiculoId ? $this->count('veiculo_abastecimentos', $userId, $veiculoId) : 0;
                $totalDocumentos     = $veiculoId ? $this->count('veiculo_documentos', $userId, $veiculoId) : 0;
            } catch (\Exception $e) {
                Logger::error('Dashboard: tabelas portal nao existem (migration 002 pendente)', ['err' => $e->getMessage()]);
                $migrationPendente = true;
            }
        }

        if (!$migrationPendente && $veiculoId) {
            try {
                $stmt = $this->db->prepare("
                    SELECT tipo, data_servico, km_servico, valor
                    FROM veiculo_manutencoes
                    WHERE veiculo_id = ? AND usuario_id = ?
                    ORDER BY data_servico DESC LIMIT 5
                ");
                $stmt->execute([$veiculoId, $userId]);
                $ultimasManutencoes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) { /* ignora */ }

            try {
                $stmt2 = $this->db->prepare("SELECT * FROM veiculo_score WHERE veiculo_id = ? LIMIT 1");
                $stmt2->execute([$veiculoId]);
                $scoreRow = $stmt2->fetch(\PDO::FETCH_ASSOC);
                if ($scoreRow) {
                    $scoreVeiculo = $scoreRow['score_total'];
                    $ptsManu  = $scoreRow['pts_manutencao'];
                    $ptsDocs  = $scoreRow['pts_documentos'];
                    $ptsPneus = $scoreRow['pts_pneus'];
                    $ptsBat   = $scoreRow['pts_bateria'];
                    $ptsSeg   = $scoreRow['pts_seguro'];
                }
            } catch (\Exception $e) { /* ignora */ }

            try {
                $stmt3 = $this->db->prepare("
                    SELECT tipo_servico as titulo, descricao
                    FROM veiculo_agenda
                    WHERE veiculo_id = ? AND usuario_id = ? AND concluido = 0
                      AND (data_prevista <= DATE_ADD(NOW(), INTERVAL 7 DAY) OR data_prevista IS NULL)
                    ORDER BY data_prevista ASC LIMIT 5
                ");
                $stmt3->execute([$veiculoId, $userId]);
                $alertas = $stmt3->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) { /* ignora */ }
        }

        View::render('portal/dashboard', compact(
            'totalVeiculos','totalManutencoes','totalAbastecimentos','totalDocumentos',
            'ultimasManutencoes','alertas','scoreVeiculo',
            'ptsManu','ptsDocs','ptsPneus','ptsBat','ptsSeg','migrationPendente'
        ));
    }

    // =========================================================
    // Manutenções
    // =========================================================
    public function manutencoes(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $manutencoes = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_manutencoes
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_servico DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $manutencoes = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/manutencoes/index', compact('manutencoes', 'veiculoId'));
    }

    public function adicionarManutencao(): void
    {
        $veiculoId = $this->getVeiculoAtivo();
        View::render('portal/manutencoes/adicionar', compact('veiculoId'));
    }

    public function salvarManutencao(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) {
            $this->redir('/portal/veiculos');
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_manutencoes
                (veiculo_id, usuario_id, tipo, descricao, data_servico, km_servico,
                 oficina_nome, valor, pecas, observacoes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['tipo'] ?? '',
            $_POST['descricao'] ?? '',
            $_POST['data_servico'] ?? date('Y-m-d'),
            (int)($_POST['km_servico'] ?? 0),
            $_POST['oficina_nome'] ?? '',
            (float)str_replace(',', '.', $_POST['valor'] ?? '0'),
            $_POST['pecas'] ?? '',
            $_POST['observacoes'] ?? '',
        ]);

        $manuId = $this->db->lastInsertId();

        // Registrar custo automaticamente
        $this->registrarCusto($veiculoId, $userId, 'manutencao',
            $_POST['tipo'] ?? 'Manutenção',
            (float)str_replace(',', '.', $_POST['valor'] ?? '0'),
            $_POST['data_servico'] ?? date('Y-m-d'),
            $manuId
        );

        // Registrar na timeline
        $this->registrarTimeline($veiculoId, $userId, 'manutencao',
            $_POST['tipo'] ?? 'Manutenção',
            $_POST['descricao'] ?? '',
            $_POST['data_servico'] ?? date('Y-m-d'),
            (int)($_POST['km_servico'] ?? 0),
            (float)str_replace(',', '.', $_POST['valor'] ?? '0')
        );

        Logger::info("Manutenção cadastrada: veiculo_id={$veiculoId}");
        $this->redir('/portal/manutencoes');
    }

    // =========================================================
    // Documentos
    // =========================================================
    public function documentos(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $documentos = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_documentos
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY criado_em DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $documentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/documentos/index', compact('documentos', 'veiculoId'));
    }

    public function adicionarDocumento(): void
    {
        $veiculoId = $this->getVeiculoAtivo();
        View::render('portal/documentos/adicionar', compact('veiculoId'));
    }

    public function salvarDocumento(): void
    {
        if (!$this->csrfValido()) {
            $this->redir('/portal/documentos/adicionar?erro=csrf');
        }

        $userId = (int)$_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $tipo = strtolower(trim((string)($_POST['tipo'] ?? 'outro')));
        $tiposPermitidos = ['crlv', 'cnh', 'seguro', 'manual', 'nota_fiscal', 'financiamento', 'garantia', 'recibo', 'contrato_compra', 'laudo_cautelar', 'ipva', 'outro'];
        if (!in_array($tipo, $tiposPermitidos, true)) {
            $this->redir('/portal/documentos/adicionar?erro=tipo');
        }
        if (!$veiculoId || !$this->veiculoPertenceAoUsuario($veiculoId, $userId)) {
            $this->redir('/portal/veiculos');
        }

        $servico = new DocumentoOcrService();
        $armazenado = null;
        try {
            $armazenado = $servico->armazenarUpload($_FILES['arquivo'] ?? [], $userId, $veiculoId);
            $ocr = $servico->analisarArquivo($armazenado['caminho_absoluto'], $armazenado['mime'], $tipo);

            $titulo = trim((string)($_POST['titulo'] ?? ''));
            if ($titulo === '') {
                $titulo = strtoupper($tipo) . ' — ' . date('Y');
            }

            $stmt = $this->db->prepare("
                INSERT INTO veiculo_documentos (
                    veiculo_id, usuario_id, tipo, titulo, arquivo, tamanho_kb, observacao,
                    arquivo_nome_original, arquivo_mime, arquivo_extensao, arquivo_tamanho,
                    ocr_texto_bruto, ocr_confianca, ocr_dados, status_ocr, ocr_erro, ocr_processado_em
                ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $veiculoId,
                $userId,
                $tipo,
                mb_substr($titulo, 0, 150),
                $armazenado['caminho'],
                (int)ceil($armazenado['tamanho'] / 1024),
                trim((string)($_POST['observacao'] ?? '')),
                $armazenado['nome_original'],
                $armazenado['mime'],
                $armazenado['extensao'],
                $armazenado['tamanho'],
                $ocr['texto'] !== '' ? $ocr['texto'] : null,
                $ocr['confianca'],
                $ocr['dados'] !== [] ? json_encode($ocr['dados'], JSON_UNESCAPED_UNICODE) : null,
                $ocr['status'],
                $ocr['status'] === 'erro' ? 'Falha controlada no processamento local.' : null,
                in_array($ocr['status'], ['sucesso', 'parcial', 'erro'], true) ? date('Y-m-d H:i:s') : null,
            ]);

            if ($tipo === 'crlv' && ($_POST['aplicar_dados_veiculo'] ?? '1') === '1' && $ocr['dados'] !== []) {
                $this->aplicarDadosCrlvAoVeiculo($veiculoId, $userId, $ocr['dados']);
            }

            Logger::info('Documento cadastrado com processamento OCR', [
                'veiculo_id' => $veiculoId,
                'tipo' => $tipo,
                'status_ocr' => $ocr['status'],
                'campos_extraidos' => count($ocr['campos_encontrados']),
            ]);
            $this->redir('/portal/documentos?ocr=' . rawurlencode($ocr['status']));
        } catch (\Throwable $erro) {
            if (is_array($armazenado) && !empty($armazenado['caminho_absoluto']) && is_file($armazenado['caminho_absoluto'])) {
                @unlink($armazenado['caminho_absoluto']);
            }
            Logger::error('Falha no cadastro de documento', [
                'tipo' => $tipo,
                'classe_erro' => get_class($erro),
            ]);
            $this->redir('/portal/documentos/adicionar?erro=upload');
        }
    }

    /** POST /portal/documentos/api/analisar-ocr */
    public function analisarDocumentoOcr(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (!$this->csrfValido()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Token de segurança inválido.']);
            return;
        }

        $agora = time();
        if (($agora - (int)($_SESSION['ocr_preview_at'] ?? 0)) < 3) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Aguarde alguns segundos antes de analisar novamente.']);
            return;
        }
        $_SESSION['ocr_preview_at'] = $agora;

        try {
            $servico = new DocumentoOcrService();
            $meta = $servico->validarUpload($_FILES['arquivo'] ?? []);
            $ocr = $servico->analisarArquivo((string)$_FILES['arquivo']['tmp_name'], $meta['mime'], (string)($_POST['tipo'] ?? 'outro'));
            echo json_encode([
                'success' => in_array($ocr['status'], ['sucesso', 'parcial'], true),
                'status' => $ocr['status'],
                'message' => $ocr['mensagem'],
                'confidence' => $ocr['confianca'],
                'dados' => $ocr['dados'],
                'campos' => $ocr['campos_encontrados'],
            ], JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $erro) {
            Logger::warning('Falha na prévia OCR de documento', ['classe_erro' => get_class($erro)]);
            http_response_code(422);
            echo json_encode(['success' => false, 'status' => 'erro', 'message' => 'Não foi possível analisar este documento.']);
        }
    }

    /** GET /portal/documentos/{id}/baixar */
    public function baixarDocumento(string $id): void
    {
        $documentoId = (int)$id;
        $stmt = $this->db->prepare('SELECT * FROM veiculo_documentos WHERE id = ? AND usuario_id = ? LIMIT 1');
        $stmt->execute([$documentoId, (int)$_SESSION['user_id']]);
        $documento = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$documento) {
            http_response_code(404);
            exit('Documento não encontrado.');
        }

        try {
            $servico = new DocumentoOcrService();
            $arquivo = $servico->caminhoAbsoluto((string)$documento['arquivo']);
            if (!is_file($arquivo)) {
                http_response_code(404);
                exit('Arquivo não encontrado.');
            }
            $nome = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($documento['arquivo_nome_original'] ?: $documento['titulo'])) ?: 'documento';
            header('Content-Type: ' . ($documento['arquivo_mime'] ?: 'application/octet-stream'));
            header('Content-Length: ' . (string)filesize($arquivo));
            header('Content-Disposition: attachment; filename="' . $nome . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($arquivo);
            exit;
        } catch (\Throwable $erro) {
            Logger::warning('Falha ao disponibilizar documento', ['documento_id' => $documentoId]);
            http_response_code(404);
            exit('Arquivo não encontrado.');
        }
    }

    // =========================================================
    // Abastecimentos
    // =========================================================
    public function abastecimentos(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $abastecimentos = [];
        $mediaConsumo = 0;
        $totalGasto = 0;

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_abastecimentos
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_abast DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $abastecimentos = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Calcular média de consumo
            if (count($abastecimentos) >= 2) {
                $kmTotal = $abastecimentos[0]['km_abastecido'] - end($abastecimentos)['km_abastecido'];
                $litrosTotal = array_sum(array_column($abastecimentos, 'litros'));
                $mediaConsumo = $litrosTotal > 0 ? round($kmTotal / $litrosTotal, 1) : 0;
            }
            $totalGasto = array_sum(array_column($abastecimentos, 'valor_total'));
        }

        View::render('portal/abastecimentos/index', compact('abastecimentos', 'veiculoId', 'mediaConsumo', 'totalGasto'));
    }

    public function adicionarAbastecimento(): void
    {
        $veiculoId = $this->getVeiculoAtivo();
        View::render('portal/abastecimentos/adicionar', compact('veiculoId'));
    }

    public function salvarAbastecimento(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        $litros = (float)str_replace(',', '.', $_POST['litros'] ?? '0');
        $valorLitro = (float)str_replace(',', '.', $_POST['valor_litro'] ?? '0');
        $valorTotal = $litros * $valorLitro;

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_abastecimentos
                (veiculo_id, usuario_id, data_abast, posto_nome, cidade, combustivel,
                 litros, valor_litro, valor_total, km_abastecido, tanque_cheio, observacao)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['data_abast'] ?? date('Y-m-d'),
            $_POST['posto_nome'] ?? '',
            $_POST['cidade'] ?? '',
            $_POST['combustivel'] ?? 'gasolina',
            $litros, $valorLitro, $valorTotal,
            (int)($_POST['km_abastecido'] ?? 0),
            isset($_POST['tanque_cheio']) ? 1 : 0,
            $_POST['observacao'] ?? '',
        ]);

        $this->registrarCusto($veiculoId, $userId, 'combustivel',
            'Abastecimento — ' . ($_POST['posto_nome'] ?? ''),
            $valorTotal, $_POST['data_abast'] ?? date('Y-m-d'),
            $this->db->lastInsertId()
        );

        Logger::info("Abastecimento cadastrado: veiculo_id={$veiculoId}");
        $this->redir('/portal/abastecimentos');
    }

    // =========================================================
    // Pneus
    // =========================================================
    public function pneus(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $pneus = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_pneus
                WHERE veiculo_id = ? AND usuario_id = ? AND ativo = 1
                ORDER BY posicao
            ");
            $stmt->execute([$veiculoId, $userId]);
            $pneus = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/pneus/index', compact('pneus', 'veiculoId'));
    }

    public function salvarPneu(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        // Desativar pneu anterior na mesma posição
        $stmt = $this->db->prepare("
            UPDATE veiculo_pneus SET ativo = 0
            WHERE veiculo_id = ? AND usuario_id = ? AND posicao = ?
        ");
        $stmt->execute([$veiculoId, $userId, $_POST['posicao'] ?? '']);

        $stmt2 = $this->db->prepare("
            INSERT INTO veiculo_pneus
                (veiculo_id, usuario_id, posicao, marca, modelo, medida,
                 data_instalacao, km_instalacao, valor, garantia_meses, vida_util_km, calibragem)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt2->execute([
            $veiculoId, $userId,
            $_POST['posicao'] ?? 'dianteiro_esq',
            $_POST['marca'] ?? '',
            $_POST['modelo'] ?? '',
            $_POST['medida'] ?? '',
            $_POST['data_instalacao'] ?? date('Y-m-d'),
            (int)($_POST['km_instalacao'] ?? 0),
            (float)str_replace(',', '.', $_POST['valor'] ?? '0'),
            (int)($_POST['garantia_meses'] ?? 0),
            (int)($_POST['vida_util_km'] ?? 0),
            (float)($_POST['calibragem'] ?? 32),
        ]);

        Logger::info("Pneu cadastrado: veiculo_id={$veiculoId}");
        $this->redir('/portal/pneus');
    }

    // =========================================================
    // Bateria
    // =========================================================
    public function bateria(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $bateria = null;

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_bateria
                WHERE veiculo_id = ? AND usuario_id = ? AND ativo = 1
                ORDER BY criado_em DESC LIMIT 1
            ");
            $stmt->execute([$veiculoId, $userId]);
            $bateria = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        View::render('portal/bateria/index', compact('bateria', 'veiculoId'));
    }

    public function salvarBateria(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        $this->db->prepare("UPDATE veiculo_bateria SET ativo = 0 WHERE veiculo_id = ? AND usuario_id = ?")
                 ->execute([$veiculoId, $userId]);

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_bateria
                (veiculo_id, usuario_id, marca, modelo, amperagem,
                 data_instalacao, km_instalacao, valor, garantia_meses, vida_util_meses, observacao)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['marca'] ?? '',
            $_POST['modelo'] ?? '',
            $_POST['amperagem'] ?? '',
            $_POST['data_instalacao'] ?? date('Y-m-d'),
            (int)($_POST['km_instalacao'] ?? 0),
            (float)str_replace(',', '.', $_POST['valor'] ?? '0'),
            (int)($_POST['garantia_meses'] ?? 0),
            (int)($_POST['vida_util_meses'] ?? 48),
            $_POST['observacao'] ?? '',
        ]);

        Logger::info("Bateria cadastrada: veiculo_id={$veiculoId}");
        $this->redir('/portal/bateria');
    }

    // =========================================================
    // Seguro
    // =========================================================
    public function seguro(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $seguros = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_seguro
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_vencimento DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $seguros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/seguro/index', compact('seguros', 'veiculoId'));
    }

    public function salvarSeguro(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_seguro
                (veiculo_id, usuario_id, seguradora, apolice, corretor_nome, corretor_tel,
                 assistencia_tel, guincho_tel, data_inicio, data_vencimento, valor_premio, franquia, observacao)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['seguradora'] ?? '',
            $_POST['apolice'] ?? '',
            $_POST['corretor_nome'] ?? '',
            $_POST['corretor_tel'] ?? '',
            $_POST['assistencia_tel'] ?? '',
            $_POST['guincho_tel'] ?? '',
            $_POST['data_inicio'] ?? date('Y-m-d'),
            $_POST['data_vencimento'] ?? date('Y-m-d', strtotime('+1 year')),
            (float)str_replace(',', '.', $_POST['valor_premio'] ?? '0'),
            (float)str_replace(',', '.', $_POST['franquia'] ?? '0'),
            $_POST['observacao'] ?? '',
        ]);

        Logger::info("Seguro cadastrado: veiculo_id={$veiculoId}");
        $this->redir('/portal/seguro');
    }

    // =========================================================
    // Custos
    // =========================================================
    public function custos(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $custos = [];
        $totalPorCategoria = [];
        $totalGeral = 0;
        $anoAtual = date('Y');

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT categoria, SUM(valor) as total
                FROM veiculo_custos
                WHERE veiculo_id = ? AND usuario_id = ?
                  AND YEAR(data_custo) = ?
                GROUP BY categoria
                ORDER BY total DESC
            ");
            $stmt->execute([$veiculoId, $userId, $anoAtual]);
            $totalPorCategoria = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $totalGeral = array_sum(array_column($totalPorCategoria, 'total'));

            $stmt2 = $this->db->prepare("
                SELECT * FROM veiculo_custos
                WHERE veiculo_id = ? AND usuario_id = ?
                  AND YEAR(data_custo) = ?
                ORDER BY data_custo DESC LIMIT 50
            ");
            $stmt2->execute([$veiculoId, $userId, $anoAtual]);
            $custos = $stmt2->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/custos/index', compact('custos', 'totalPorCategoria', 'totalGeral', 'anoAtual', 'veiculoId'));
    }

    // =========================================================
    // Agenda Inteligente
    // =========================================================
    public function agenda(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $agendas = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_agenda
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_prevista ASC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $agendas = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/agenda/index', compact('agendas', 'veiculoId'));
    }

    public function salvarAgenda(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_agenda
                (veiculo_id, usuario_id, tipo_servico, descricao, data_prevista,
                 km_previsto, intervalo_km, intervalo_dias)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['tipo_servico'] ?? '',
            $_POST['descricao'] ?? '',
            !empty($_POST['data_prevista']) ? $_POST['data_prevista'] : null,
            (int)($_POST['km_previsto'] ?? 0),
            (int)($_POST['intervalo_km'] ?? 0),
            (int)($_POST['intervalo_dias'] ?? 0),
        ]);

        Logger::info("Agenda cadastrada: veiculo_id={$veiculoId}");
        $this->redir('/portal/agenda');
    }

    // =========================================================
    // Checklist
    // =========================================================
    public function checklist(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $checklists = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_checklist
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_checklist DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $checklists = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/checklist/index', compact('checklists', 'veiculoId'));
    }

    public function novoChecklist(): void
    {
        $veiculoId = $this->getVeiculoAtivo();
        $tipo = $_GET['tipo'] ?? 'viagem';

        $itensViagem = ['Óleo','Água','Pneus','Estepe','Macaco','Triângulo','Documento','Seguro','Chave Reserva','Lanterna','Kit Primeiros Socorros'];
        $itensPreventivo = ['Motor','Freios','Suspensão','Correia','Velas','Bateria','Ar condicionado','Pneus','Fluido de freio','Óleo de direção'];

        $itens = $tipo === 'viagem' ? $itensViagem : $itensPreventivo;

        View::render('portal/checklist/novo', compact('veiculoId', 'tipo', 'itens'));
    }

    public function salvarChecklist(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId) { $this->redir('/portal/veiculos'); return; }

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_checklist (veiculo_id, usuario_id, tipo, titulo, data_checklist, observacao)
            VALUES (?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['tipo'] ?? 'viagem',
            $_POST['titulo'] ?? 'Checklist ' . date('d/m/Y'),
            $_POST['data_checklist'] ?? date('Y-m-d'),
            $_POST['observacao'] ?? '',
        ]);

        $checklistId = $this->db->lastInsertId();
        $itens = $_POST['itens'] ?? [];
        $marcados = $_POST['marcados'] ?? [];
        $status = $_POST['status'] ?? [];

        foreach ($itens as $i => $item) {
            if (empty($item)) continue;
            $stmtItem = $this->db->prepare("
                INSERT INTO checklist_itens (checklist_id, item, marcado, status)
                VALUES (?,?,?,?)
            ");
            $stmtItem->execute([
                $checklistId, $item,
                isset($marcados[$i]) ? 1 : 0,
                $status[$i] ?? 'ok',
            ]);
        }

        Logger::info("Checklist salvo: veiculo_id={$veiculoId}");
        $this->redir('/portal/checklist');
    }

    // =========================================================
    // Galeria
    // =========================================================
    public function galeria(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $fotos = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_galeria
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_foto DESC, criado_em DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $fotos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/galeria/index', compact('fotos', 'veiculoId'));
    }

    public function salvarFoto(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();

        if (!$veiculoId || empty($_FILES['foto']['name'])) {
            $this->redir('/portal/galeria');
            return;
        }

        $arquivo = $this->uploadArquivo($_FILES['foto'], 'galeria');

        $stmt = $this->db->prepare("
            INSERT INTO veiculo_galeria (veiculo_id, usuario_id, categoria, titulo, arquivo, data_foto, observacao)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $veiculoId, $userId,
            $_POST['categoria'] ?? 'exterior',
            $_POST['titulo'] ?? '',
            $arquivo,
            $_POST['data_foto'] ?? date('Y-m-d'),
            $_POST['observacao'] ?? '',
        ]);

        Logger::info("Foto adicionada à galeria: veiculo_id={$veiculoId}");
        $this->redir('/portal/galeria');
    }

    // =========================================================
    // Timeline
    // =========================================================
    public function timeline(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $eventos = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_timeline
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_evento ASC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $eventos = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/timeline/index', compact('eventos', 'veiculoId'));
    }

    // =========================================================
    // IPVA / Multas
    // =========================================================
    public function ipva(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        $registros = [];

        if ($veiculoId) {
            $stmt = $this->db->prepare("
                SELECT * FROM veiculo_ipva
                WHERE veiculo_id = ? AND usuario_id = ?
                ORDER BY data_vencimento DESC
            ");
            $stmt->execute([$veiculoId, $userId]);
            $registros = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        View::render('portal/ipva/index', compact('registros', 'veiculoId'));
    }

    // =========================================================
    // Relatórios
    // =========================================================
    public function relatorios(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = $this->getVeiculoAtivo();
        View::render('portal/relatorios/index', compact('veiculoId'));
    }

    // =========================================================
    // Assistente IA
    // =========================================================
    public function ia(): void
    {
        $veiculoId = $this->getVeiculoAtivo();
        View::render('portal/ia/index', compact('veiculoId'));
    }

    public function iaChat(): void
    {
        header('Content-Type: application/json');
        $pergunta = trim($_POST['pergunta'] ?? '');

        if (empty($pergunta)) {
            echo json_encode(['resposta' => 'Por favor, faça uma pergunta sobre seu veículo.']);
            exit;
        }

        // Integração com OpenAI via variável de ambiente
        $apiKey = $_ENV['OPENAI_API_KEY'] ?? '';
        if (empty($apiKey)) {
            echo json_encode(['resposta' => 'Assistente IA não configurado. Configure a chave OPENAI_API_KEY no arquivo .env']);
            exit;
        }

        $prompt = "Você é um especialista em mecânica automotiva e assistente do AppAuto. Responda de forma clara e objetiva em português. Pergunta do usuário: {$pergunta}";

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 500,
            ]),
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $resposta = $data['choices'][0]['message']['content'] ?? 'Não foi possível obter resposta da IA.';

        echo json_encode(['resposta' => $resposta]);
        exit;
    }

    // =========================================================
    // Marketplace
    // =========================================================
    public function marketplace(): void
    {
        $stmt = $this->db->prepare("SELECT * FROM marketplace_ofertas WHERE ativo = 1 ORDER BY tipo, titulo");
        $stmt->execute();
        $ofertas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        View::render('portal/marketplace/index', compact('ofertas'));
    }

    // =========================================================
    // Selecionar veículo ativo
    // =========================================================
    public function selecionarVeiculo(): void
    {
        $userId    = $_SESSION['user_id'];
        $veiculoId = (int)($_POST['veiculo_id'] ?? 0);

        if ($veiculoId) {
            $stmt = $this->db->prepare("SELECT id, placa, modelo FROM veiculos WHERE id = ? AND usuario_id = ? LIMIT 1");
            $stmt->execute([$veiculoId, $userId]);
            $veiculo = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($veiculo) {
                $_SESSION['veiculo_ativo_id']    = $veiculo['id'];
                $_SESSION['veiculo_ativo_placa'] = $veiculo['placa'];
                $_SESSION['veiculo_ativo_modelo'] = $veiculo['modelo'] ?? '';
            }
        }

        $this->redir('/portal/dashboard');
    }

    // =========================================================
    // Helpers privados
    // =========================================================
    private function getVeiculoAtivo(): ?int
    {
        return !empty($_SESSION['veiculo_ativo_id']) ? (int)$_SESSION['veiculo_ativo_id'] : null;
    }

    private function count(string $tabela, int $userId, ?int $veiculoId = null): int
    {
        if ($veiculoId) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$tabela} WHERE usuario_id = ? AND veiculo_id = ?");
            $stmt->execute([$userId, $veiculoId]);
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM {$tabela} WHERE usuario_id = ?");
            $stmt->execute([$userId]);
        }
        return (int)$stmt->fetchColumn();
    }

    private function registrarCusto(int $veiculoId, int $userId, string $categoria, string $descricao, float $valor, string $data, int $refId = 0): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO veiculo_custos (veiculo_id, usuario_id, categoria, descricao, valor, data_custo, referencia_id)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([$veiculoId, $userId, $categoria, $descricao, $valor, $data, $refId]);
    }

    private function registrarTimeline(int $veiculoId, int $userId, string $tipo, string $titulo, string $descricao, string $data, int $km, float $valor): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO veiculo_timeline (veiculo_id, usuario_id, tipo, titulo, descricao, data_evento, km_evento, valor)
            VALUES (?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([$veiculoId, $userId, $tipo, $titulo, $descricao, $data, $km, $valor]);
    }

    /**
     * Compatibilidade para a galeria existente. Documentos não usam este helper:
     * seguem para storage privado pelo DocumentoOcrService.
     */
    private function uploadArquivo(array $file, string $pasta): string
    {
        if ($pasta !== 'galeria' || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return '';
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > DocumentoOcrService::MAX_BYTES) {
            return '';
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? (string)finfo_file($finfo, $tmp) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensoes[$mime])) {
            return '';
        }

        $diretorio = __DIR__ . '/../../public/assets/uploads/galeria/';
        if (!is_dir($diretorio) && !mkdir($diretorio, 0755, true) && !is_dir($diretorio)) {
            return '';
        }
        $nome = bin2hex(random_bytes(18)) . '.' . $extensoes[$mime];
        $destino = $diretorio . $nome;
        return move_uploaded_file($tmp, $destino) ? '/assets/uploads/galeria/' . $nome : '';
    }

    private function veiculoPertenceAoUsuario(int $veiculoId, int $usuarioId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM veiculos WHERE id = ? AND usuario_id = ? LIMIT 1');
        $stmt->execute([$veiculoId, $usuarioId]);
        return (bool)$stmt->fetchColumn();
    }

    private function aplicarDadosCrlvAoVeiculo(int $veiculoId, int $usuarioId, array $dados): void
    {
        $campos = [];
        $parametros = [];
        $mapa = [
            'placa' => 'placa',
            'renavam' => 'renavam',
            'chassi' => 'chassi',
            'ano_fabricacao' => 'ano_fabricacao',
            'ano_modelo' => 'ano_modelo',
            'cor' => 'cor',
            'municipio' => 'municipio_emplacamento',
            'uf' => 'uf_emplacamento',
        ];
        foreach ($mapa as $origem => $coluna) {
            if (!empty($dados[$origem])) {
                $campos[] = $coluna . ' = ?';
                $parametros[] = $dados[$origem];
            }
        }
        if ($campos === []) {
            return;
        }

        $parametros[] = $veiculoId;
        $parametros[] = $usuarioId;
        $stmt = $this->db->prepare('UPDATE veiculos SET ' . implode(', ', $campos) . ' WHERE id = ? AND usuario_id = ?');
        $stmt->execute($parametros);
    }

    private function csrfValido(): bool
    {
        $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
        return $token !== '' && hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token);
    }

    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
