<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Models\Veiculo;
use App\Services\OcrExternoService;

/**
 * PortalVeiculosController — Módulo de Veículos dentro do Portal de Veículos
 * Usa as views portal/veiculos/* com portal_header.php (sidebar do portal)
 */
class PortalVeiculosController extends Controller
{
    private Veiculo $veiculoModel;
    private \PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->veiculoModel = new Veiculo();
        $this->requireAuth();
    }

    // ----------------------------------------------------------------
    // GET /portal/veiculos
    // ----------------------------------------------------------------
    public function index(): void
    {
        $userId   = $_SESSION['user_id'];
        $veiculos = $this->veiculoModel->listarPorUsuario($userId);
        View::render('portal/veiculos/index', [
            'title'    => 'Meus Veículos',
            'veiculos' => $veiculos,
        ]);
    }

    // ----------------------------------------------------------------
    // GET /portal/veiculos/adicionar
    // ----------------------------------------------------------------
    public function showAdicionar(): void
    {
        View::render('portal/veiculos/adicionar', [
            'title' => 'Adicionar Veículo',
        ]);
    }

    // ----------------------------------------------------------------
    // POST /portal/veiculos/adicionar
    // ----------------------------------------------------------------
    public function adicionar(): void
    {
        $this->requireCsrf();
        $userId = $_SESSION['user_id'];
        $dados  = $_POST;
        $fotos  = $_FILES['fotos'] ?? [];

        $erros = $this->veiculoModel->validar($dados);
        if (!empty($erros)) {
            View::render('portal/veiculos/adicionar', [
                'title'  => 'Adicionar Veículo',
                'erros'  => $erros,
                'dados'  => $dados,
            ]);
            return;
        }

        $id = $this->veiculoModel->criar($userId, $dados);
        if ($id && !empty($fotos['name'][0])) {
            $this->processarFotos($id, $fotos);
        }

        // Selecionar automaticamente o veículo recém-criado
        if ($id) {
            $veiculo = $this->veiculoModel->buscarPorId($id, $userId);
            if ($veiculo) {
                $_SESSION['veiculo_ativo_id']    = $id;
                $_SESSION['veiculo_ativo_placa'] = $veiculo->placa ?? '';
                $_SESSION['veiculo_ativo_modelo']= trim(($veiculo->marca ?? '') . ' ' . ($veiculo->modelo ?? ''));
            }
        }

        Logger::info("Veículo adicionado via portal. ID: {$id}", 'portal_veiculos');
        $this->redir('/portal/veiculos?success=Veículo+adicionado+com+sucesso');
    }

    // ----------------------------------------------------------------
    // GET /portal/veiculos/{id}/editar
    // ----------------------------------------------------------------
    public function showEditar(int $id): void
    {
        $userId  = $_SESSION['user_id'];
        $veiculo = $this->veiculoModel->buscarPorId($id, $userId);
        if (!$veiculo) {
            $this->redir('/portal/veiculos');
            return;
        }
        View::render('portal/veiculos/editar', [
            'title'   => 'Editar Veículo',
            'veiculo' => $veiculo,
        ]);
    }

    // ----------------------------------------------------------------
    // POST /portal/veiculos/{id}/editar
    // ----------------------------------------------------------------
    public function editar(int $id): void
    {
        $this->requireCsrf();
        $userId  = $_SESSION['user_id'];
        $veiculo = $this->veiculoModel->buscarPorId($id, $userId);
        if (!$veiculo) {
            $this->redir('/portal/veiculos');
            return;
        }

        $dados = $_POST;
        $erros = $this->veiculoModel->validar($dados, true);
        if (!empty($erros)) {
            View::render('portal/veiculos/editar', [
                'title'   => 'Editar Veículo',
                'veiculo' => $veiculo,
                'erros'   => $erros,
                'dados'   => $dados,
            ]);
            return;
        }

        $this->veiculoModel->atualizar($id, $userId, $dados);
        Logger::info("Veículo editado via portal. ID: {$id}", 'portal_veiculos');
        $this->redir('/portal/veiculos?success=Veículo+atualizado+com+sucesso');
    }

    // ----------------------------------------------------------------
    // POST /portal/veiculos/{id}/excluir
    // ----------------------------------------------------------------
    public function excluir(int $id): void
    {
        $this->requireCsrf();
        $userId = $_SESSION['user_id'];
        $this->veiculoModel->excluir($id, $userId);

        if (!empty($_SESSION['veiculo_ativo_id']) && (int)$_SESSION['veiculo_ativo_id'] === $id) {
            unset($_SESSION['veiculo_ativo_id'], $_SESSION['veiculo_ativo_placa'], $_SESSION['veiculo_ativo_modelo']);
        }

        Logger::info("Veículo excluído via portal. ID: {$id}", 'portal_veiculos');
        $this->redir('/portal/veiculos?success=Veículo+removido+com+sucesso');
    }

    // ----------------------------------------------------------------
    // GET /portal/veiculos/consultar-placa
    // ----------------------------------------------------------------
    public function showConsultarPlaca(): void
    {
        View::render('portal/veiculos/consultar_placa', [
            'title' => 'Consultar Placa',
        ]);
    }

    // ----------------------------------------------------------------
    // GET /portal/veiculos/api/consultar-placa?placa=ABC1234
    // ----------------------------------------------------------------
    public function apiConsultarPlaca(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $resultado = $this->veiculoModel->consultarPlacaAPI((string)($_GET['placa'] ?? ''));
        if ($resultado['success'] ?? false) {
            $dados = $resultado['dados'] ?? [];
            $dados['fonte'] = $resultado['fonte'] ?? 'provedor externo';
            echo json_encode($dados, JSON_UNESCAPED_UNICODE);
            return;
        }

        $dados = $resultado['dados'] ?? [];
        $dados['aviso'] = $resultado['message'] ?? 'Nenhuma fonte retornou dados técnicos para esta placa.';
        $dados['fontes_tentadas'] = $resultado['fontes_tentadas'] ?? [];
        echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    }

    // ----------------------------------------------------------------
    // POST /portal/veiculos/api/ocr
    // ----------------------------------------------------------------
    public function apiOCR(): void
    {
        $this->requireCsrf();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_FILES['imagem']['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => 'Nenhuma imagem enviada.']);
            return;
        }

        try {
            $resultado = (new OcrExternoService())->analisarUpload($_FILES['imagem'], 'documento');
            if ($resultado['success'] ?? false) {
                echo json_encode([
                    'success' => true,
                    'fonte' => $resultado['provider'] ?? 'ocr_externo',
                    'dados' => $resultado['dados'] ?? [],
                    'aviso' => 'Resultado assistivo. Revise os campos antes de salvar.',
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        } catch (\RuntimeException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
            return;
        } catch (\Throwable $e) {
            Logger::warning('OCR externo do portal falhou; tentando OCR local', [
                'tipo_erro' => get_class($e),
            ]);
        }

        $resultadoLocal = $this->veiculoModel->processarOCR((string)$_FILES['imagem']['tmp_name']);
        if (is_array($resultadoLocal)) {
            $resultadoLocal['fonte'] = 'tesseract_local';
        }
        echo json_encode($resultadoLocal, JSON_UNESCAPED_UNICODE);
    }

    // ----------------------------------------------------------------
    // POST /portal/selecionar-veiculo
    // ----------------------------------------------------------------
    public function selecionarVeiculo(): void
    {
        $this->requireCsrf();
        $userId    = $_SESSION['user_id'];
        $veiculoId = (int)($_POST['veiculo_id'] ?? 0);

        $veiculo = $this->veiculoModel->buscarPorId($veiculoId, $userId);
        if ($veiculo) {
            $_SESSION['veiculo_ativo_id']    = $veiculoId;
            $_SESSION['veiculo_ativo_placa'] = $veiculo->placa ?? '';
            $_SESSION['veiculo_ativo_modelo']= trim(($veiculo->marca ?? '') . ' ' . ($veiculo->modelo ?? ''));
        }

        $this->redir('/portal/dashboard');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------
    private function processarFotos(int $veiculoId, array $files): void
    {
        $dir = __DIR__ . '/../../public/assets/uploads/veiculos/' . $veiculoId . '/';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        foreach ($files['tmp_name'] as $i => $tmp) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $mime = mime_content_type($tmp);
            if (!in_array($mime, $allowed)) continue;

            $ext  = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
            $nome = uniqid('foto_') . '.' . $ext;
            move_uploaded_file($tmp, $dir . $nome);

            $stmt = $this->db->prepare(
                "INSERT INTO veiculo_fotos (veiculo_id, caminho, tipo, criado_em)
                 VALUES (?, ?, 'geral', NOW())"
            );
            $stmt->execute([$veiculoId, '/assets/uploads/veiculos/' . $veiculoId . '/' . $nome]);
        }
    }

    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            $this->redir('/login');
        }
    }

    private function requireCsrf(): void
    {
        $token = (string)($_POST['csrf_token'] ?? $_POST['_csrf'] ?? '');
        $esperado = (string)($_SESSION['csrf_token'] ?? '');
        if ($token === '' || $esperado === '' || !hash_equals($esperado, $token)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

}
