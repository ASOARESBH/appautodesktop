<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Core\View;
use App\Models\Usuario;
use App\Models\Negocio;
use PDO;

/**
 * AdminController — AppAuto SaaS
 * Painel administrativo: dashboard, clientes, impersonação
 */
class AdminController extends Controller
{
    private Usuario $usuarioModel;
    private Negocio $negocioModel;
    private PDO     $db;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->negocioModel = new Negocio();
        $this->db           = Database::getInstance();
    }

    // ----------------------------------------------------------------
    // GET /admin/dashboard
    // ----------------------------------------------------------------
    public function dashboard(): void
    {
        $this->requireAdmin();

        // Estatísticas gerais
        $stats = (object)[
            'total_usuarios' => $this->db->query("SELECT COUNT(*) FROM `usuarios`")->fetchColumn(),
            'total_negocios' => $this->db->query("SELECT COUNT(*) FROM `negocios`")->fetchColumn(),
            'total_veiculos' => $this->db->query("SELECT COUNT(*) FROM `veiculos`")->fetchColumn(),
            'pendentes'      => $this->db->query("SELECT COUNT(*) FROM `usuarios` WHERE `status` = 'pendente'")->fetchColumn(),
        ];

        // Últimos 10 cadastros
        $stmt = $this->db->query(
            "SELECT `id`,`nome_completo`,`email`,`tipo_conta`,`status`,`email_verificado`,`criado_em`
             FROM `usuarios`
             ORDER BY `criado_em` DESC
             LIMIT 10"
        );
        $ultimos = $stmt->fetchAll(PDO::FETCH_OBJ);

        View::render('admin/dashboard', [
            'title'            => 'Dashboard Admin',
            'stats'            => $stats,
            'ultimos_cadastros'=> $ultimos,
        ]);
    }

    // ----------------------------------------------------------------
    // GET /admin/clientes/pessoas
    // ----------------------------------------------------------------
    public function clientesPessoas(): void
    {
        $this->requireAdmin();

        $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina = 20;
        $busca     = trim($_GET['busca'] ?? '');

        if ($busca) {
            $like = '%' . $busca . '%';
            $stmt = $this->db->prepare(
                "SELECT `id`,`nome_completo`,`email`,`cpf`,`telefone`,`status`,`email_verificado`,`criado_em`
                 FROM `usuarios`
                 WHERE `tipo_conta` = 'pessoal'
                   AND (`nome_completo` LIKE :b OR `email` LIKE :b2)
                 ORDER BY `criado_em` DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':b',   $like);
            $stmt->bindValue(':b2',  $like);
            $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
            $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
            $stmt->execute();
            $usuarios = $stmt->fetchAll(PDO::FETCH_OBJ);

            $stmtC = $this->db->prepare(
                "SELECT COUNT(*) FROM `usuarios`
                 WHERE `tipo_conta` = 'pessoal'
                   AND (`nome_completo` LIKE :b OR `email` LIKE :b2)"
            );
            $stmtC->execute([':b' => $like, ':b2' => $like]);
            $total = (int)$stmtC->fetchColumn();
        } else {
            $usuarios = $this->usuarioModel->listarTodos('pessoal', $pagina, $porPagina);
            $total    = $this->usuarioModel->contarTodos('pessoal');
        }

        View::render('admin/clientes_pessoas', [
            'title'       => 'Clientes — Pessoas',
            'breadcrumb'  => '<a href="/admin/dashboard">Admin</a> / Clientes / Pessoas',
            'usuarios'    => $usuarios,
            'total'       => $total,
            'pagina'      => $pagina,
            'totalPaginas'=> ceil($total / $porPagina),
        ]);
    }

    // ----------------------------------------------------------------
    // GET /admin/clientes/negocios
    // ----------------------------------------------------------------
    public function clientesNegocios(): void
    {
        $this->requireAdmin();

        $pagina    = max(1, (int)($_GET['pagina'] ?? 1));
        $porPagina = 20;
        $busca     = trim($_GET['busca'] ?? '');

        if ($busca) {
            $like = '%' . $busca . '%';
            $stmt = $this->db->prepare(
                "SELECT n.*, r.nome AS ramo_nome, r.icone AS ramo_icone,
                        u.nome_completo AS dono_nome, u.email AS dono_email
                 FROM `negocios` n
                 LEFT JOIN `ramos_atividade` r ON r.id = n.ramo_atividade_id
                 LEFT JOIN `usuarios` u ON u.id = n.usuario_id
                 WHERE n.razao_social LIKE :b OR n.cnpj LIKE :b2
                 ORDER BY n.criado_em DESC
                 LIMIT :lim OFFSET :off"
            );
            $stmt->bindValue(':b',   $like);
            $stmt->bindValue(':b2',  $like);
            $stmt->bindValue(':lim', $porPagina, PDO::PARAM_INT);
            $stmt->bindValue(':off', ($pagina - 1) * $porPagina, PDO::PARAM_INT);
            $stmt->execute();
            $negocios = $stmt->fetchAll(PDO::FETCH_OBJ);
            $total    = count($negocios);
        } else {
            $negocios = $this->negocioModel->listarTodos($pagina, $porPagina);
            $total    = $this->negocioModel->contarTodos();
        }

        View::render('admin/clientes_negocios', [
            'title'       => 'Clientes — Negócios',
            'breadcrumb'  => '<a href="/admin/dashboard">Admin</a> / Clientes / Negócios',
            'negocios'    => $negocios,
            'total'       => $total,
            'pagina'      => $pagina,
            'totalPaginas'=> ceil($total / $porPagina),
        ]);
    }

    // ----------------------------------------------------------------
    // GET /admin/acessar-como/{id}  — Impersonação de pessoa
    // ----------------------------------------------------------------
    public function acessarComo(int $id): void
    {
        $this->requireAdmin();

        $usuario = $this->usuarioModel->buscarPorId($id);
        if (!$usuario) {
            $this->redir('/admin/clientes/pessoas');
            return;
        }

        // Salvar contexto admin original
        $_SESSION['admin_original_id']    = $_SESSION['user_id'];
        $_SESSION['admin_original_name']  = $_SESSION['user_name'];
        $_SESSION['admin_original_perfil']= $_SESSION['user_perfil'];

        // Trocar contexto para o usuário impersonado
        $_SESSION['user_id']     = $usuario->id;
        $_SESSION['user_name']   = $usuario->nome_completo;
        $_SESSION['user_email']  = $usuario->email;
        $_SESSION['user_perfil'] = $usuario->perfil;
        $_SESSION['tipo_conta']  = $usuario->tipo_conta;

        Logger::info("Admin impersonou usuário #{$id}: {$usuario->email}");
        $this->redir('/portal/dashboard');
    }

    // ----------------------------------------------------------------
    // GET /admin/acessar-negocio/{id}  — Impersonação de negócio
    // ----------------------------------------------------------------
    public function acessarNegocio(int $id): void
    {
        $this->requireAdmin();

        $negocio = $this->negocioModel->buscarPorId($id);
        if (!$negocio) {
            $this->redir('/admin/clientes/negocios');
            return;
        }

        $usuario = $this->usuarioModel->buscarPorId($negocio->usuario_id);
        if (!$usuario) {
            $this->redir('/admin/clientes/negocios');
            return;
        }

        $_SESSION['admin_original_id']    = $_SESSION['user_id'];
        $_SESSION['admin_original_name']  = $_SESSION['user_name'];
        $_SESSION['admin_original_perfil']= $_SESSION['user_perfil'];
        $_SESSION['impersonated_negocio'] = $id;

        $_SESSION['user_id']     = $usuario->id;
        $_SESSION['user_name']   = $negocio->razao_social;
        $_SESSION['user_email']  = $usuario->email;
        $_SESSION['user_perfil'] = 'admin_negocio';
        $_SESSION['tipo_conta']  = 'negocio';

        Logger::info("Admin acessou negócio #{$id}: {$negocio->razao_social}");
        $this->redir('/negocio/dashboard');
    }

    // ----------------------------------------------------------------
    // GET /admin/sair-impersonacao
    // ----------------------------------------------------------------
    public function sairImpersonacao(): void
    {
        if (!empty($_SESSION['admin_original_id'])) {
            $_SESSION['user_id']     = $_SESSION['admin_original_id'];
            $_SESSION['user_name']   = $_SESSION['admin_original_name'];
            $_SESSION['user_perfil'] = $_SESSION['admin_original_perfil'];
            $_SESSION['tipo_conta']  = 'pessoal';

            unset(
                $_SESSION['admin_original_id'],
                $_SESSION['admin_original_name'],
                $_SESSION['admin_original_perfil'],
                $_SESSION['impersonated_negocio']
            );
        }
        $this->redir('/admin/dashboard');
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------
    protected function requireAdmin(): void
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['user_perfil'] ?? '') !== 'admin') {
            header('Location: /login');
            exit;
        }
    }

}
