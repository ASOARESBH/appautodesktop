<?php
namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Models\Veiculo;

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
        header('Content-Type: application/json; charset=utf-8');
        if (empty($_FILES['imagem']['tmp_name'])) {
            echo json_encode(['erro' => 'Nenhuma imagem enviada.']);
            return;
        }

        // OCR via OpenAI Vision (se disponível) ou retorno básico
        $apiKey = getenv('OPENAI_API_KEY');
        if (!$apiKey) {
            echo json_encode(['aviso' => 'OCR não configurado. Preencha os dados manualmente.']);
            return;
        }

        $imgData = base64_encode(file_get_contents($_FILES['imagem']['tmp_name']));
        $mime    = mime_content_type($_FILES['imagem']['tmp_name']);

        $payload = json_encode([
            'model'    => 'gpt-4o-mini',
            'messages' => [[
                'role'    => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Extraia os dados do documento do veículo (CRLV) nesta imagem. Retorne JSON com: placa, renavam, chassi, marca, modelo, ano_fabricacao, ano_modelo, cor, combustivel, categoria. Se não encontrar um campo, deixe null.'],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mime};base64,{$imgData}"]],
                ],
            ]],
            'max_tokens' => 500,
        ]);

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$apiKey}", 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $json = json_decode($resp, true);
        $content = $json['choices'][0]['message']['content'] ?? '';

        // Extrair JSON da resposta
        if (preg_match('/\{.*\}/s', $content, $m)) {
            $dados = json_decode($m[0], true);
            if ($dados) {
                echo json_encode($dados);
                return;
            }
        }

        echo json_encode(['aviso' => 'Não foi possível extrair os dados. Preencha manualmente.']);
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
        $token = $_POST['_csrf'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
            http_response_code(403);
            exit('Token CSRF inválido.');
        }
    }

}
