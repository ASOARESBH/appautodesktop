<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Core\View;
use App\Models\Veiculo;

/**
 * VeiculosController — AppAuto SaaS
 * CRUD de veículos, consulta de placa (API) e OCR de documento
 */
class VeiculosController extends Controller
{
    private Veiculo $veiculoModel;

    public function __construct()
    {
        $this->veiculoModel = new Veiculo();
    }

    // ----------------------------------------------------------------
    // GET /veiculos
    // ----------------------------------------------------------------
    public function index(): void
    {
        $this->requireAuth();
        $veiculos = $this->veiculoModel->listarPorUsuario($_SESSION['user_id']);
        View::render('veiculos/index', [
            'title'    => 'Meus Veículos',
            'veiculos' => $veiculos,
        ]);
    }

    // ----------------------------------------------------------------
    // GET /veiculos/adicionar
    // ----------------------------------------------------------------
    public function showAdicionar(): void
    {
        $this->requireAuth();
        View::render('veiculos/adicionar', ['title' => 'Adicionar Veículo']);
    }

    // ----------------------------------------------------------------
    // POST /veiculos/adicionar
    // ----------------------------------------------------------------
    public function adicionar(): void
    {
        $this->requireAuth();

        $placa = Veiculo::normalizarPlaca($_POST['placa'] ?? '');

        if (strlen($placa) < 7) {
            View::render('veiculos/adicionar', [
                'title' => 'Adicionar Veículo',
                'error' => 'Placa inválida.',
            ]);
            return;
        }

        if ($this->veiculoModel->placaExisteParaUsuario($placa, $_SESSION['user_id'])) {
            View::render('veiculos/adicionar', [
                'title' => 'Adicionar Veículo',
                'error' => "A placa {$placa} já está cadastrada na sua conta.",
            ]);
            return;
        }

        $dados = [
            'usuario_id'    => $_SESSION['user_id'],
            'placa'         => $placa,
            'renavam'       => preg_replace('/\D/', '', $_POST['renavam'] ?? ''),
            'chassi'        => strtoupper(trim($_POST['chassi'] ?? '')),
            'marca'         => trim($_POST['marca'] ?? ''),
            'modelo'        => trim($_POST['modelo'] ?? ''),
            'versao'        => trim($_POST['versao'] ?? ''),
            'ano_fabricacao'=> (int)($_POST['ano_fabricacao'] ?? 0) ?: null,
            'ano_modelo'    => (int)($_POST['ano_modelo'] ?? 0) ?: null,
            'cor'           => trim($_POST['cor'] ?? ''),
            'combustivel'   => $_POST['combustivel'] ?? null,
            'categoria'     => $_POST['categoria'] ?? null,
            'tipo_veiculo'  => $_POST['tipo_veiculo'] ?? 'carro',
            'observacoes'   => trim($_POST['observacoes'] ?? ''),
        ];

        $veiculoId = $this->veiculoModel->criar($dados);

        // Processar fotos enviadas
        if (!empty($_FILES['fotos']['name'][0])) {
            $this->processarFotos($veiculoId, $_FILES['fotos']);
        }

        Logger::info("Veículo cadastrado: placa={$placa} por user_id={$_SESSION['user_id']}");
        $this->redir('/veiculos?success=1');
    }

    // ----------------------------------------------------------------
    // GET /veiculos/{id}
    // ----------------------------------------------------------------
    public function show(int $id): void
    {
        $this->requireAuth();
        $veiculo = $this->veiculoModel->buscarPorId($id);

        if (!$veiculo || $veiculo->usuario_id != $_SESSION['user_id']) {
            $this->redir('/veiculos');
            return;
        }

        $fotos = $this->veiculoModel->listarFotos($id);
        View::render('veiculos/show', [
            'title'   => 'Veículo: ' . Veiculo::normalizarPlaca($veiculo->placa),
            'veiculo' => $veiculo,
            'fotos'   => $fotos,
        ]);
    }

    // ----------------------------------------------------------------
    // GET /veiculos/consultar-placa
    // ----------------------------------------------------------------
    public function showConsultarPlaca(): void
    {
        $this->requireAuth();
        View::render('veiculos/consultar_placa', ['title' => 'Consultar Placa']);
    }

    // ----------------------------------------------------------------
    // GET /veiculos/api/consultar-placa?placa=XXX  (AJAX)
    // ----------------------------------------------------------------
    public function apiConsultarPlaca(): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        $placa = Veiculo::normalizarPlaca($_GET['placa'] ?? '');

        if (strlen($placa) < 7) {
            echo json_encode(['success' => false, 'message' => 'Placa inválida.']);
            return;
        }

        $resultado = $this->veiculoModel->consultarPlacaAPI($placa);

        // Registrar consulta
        $this->veiculoModel->registrarConsulta(
            $placa,
            $_SESSION['user_id'],
            $resultado['fonte'] ?? 'api',
            $resultado['success'],
            json_encode($resultado['dados'] ?? [])
        );

        echo json_encode($resultado);
    }

    // ----------------------------------------------------------------
    // POST /veiculos/api/ocr  (AJAX)
    // ----------------------------------------------------------------
    public function apiOCR(): void
    {
        $this->requireAuth();
        header('Content-Type: application/json; charset=utf-8');

        if (empty($_FILES['imagem']['tmp_name'])) {
            echo json_encode(['success' => false, 'message' => 'Nenhuma imagem enviada.']);
            return;
        }

        $tmpPath = $_FILES['imagem']['tmp_name'];
        $resultado = $this->veiculoModel->processarOCR($tmpPath);

        echo json_encode($resultado);
    }

    // ----------------------------------------------------------------
    // Processar upload de fotos
    // ----------------------------------------------------------------
    private function processarFotos(int $veiculoId, array $files): void
    {
        $uploadDir = dirname(__DIR__, 2) . '/public/assets/uploads/veiculos/' . $veiculoId . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $tiposPermitidos = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $principal = true;

        foreach ($files['tmp_name'] as $idx => $tmpName) {
            if (empty($tmpName) || $files['error'][$idx] !== UPLOAD_ERR_OK) continue;

            $mime = mime_content_type($tmpName);
            if (!in_array($mime, $tiposPermitidos)) continue;

            $ext      = pathinfo($files['name'][$idx], PATHINFO_EXTENSION);
            $nomeArq  = uniqid('foto_', true) . '.' . strtolower($ext);
            $destino  = $uploadDir . $nomeArq;

            if (move_uploaded_file($tmpName, $destino)) {
                $caminho = '/assets/uploads/veiculos/' . $veiculoId . '/' . $nomeArq;
                $this->veiculoModel->salvarFoto($veiculoId, $caminho, 'exterior', $principal);
                $principal = false;
            }
        }
    }

    // ----------------------------------------------------------------
    protected function requireAuth(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /login');
            exit;
        }
    }

}
