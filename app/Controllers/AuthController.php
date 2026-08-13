<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Logger;
use App\Core\View;
use App\Models\Usuario;
use App\Models\Negocio;

/**
 * AuthController — AppAuto SaaS
 * Gerencia login, cadastro PF/PJ, validação de token e logout
 */
class AuthController extends Controller
{
    private ?Usuario $usuarioModel = null;
    private ?Negocio $negocioModel = null;

    /**
     * Controllers públicos são instanciados antes da action pelo Router. Por
     * isso, a conexão com banco é criada somente na action que realmente usa
     * um model; GET /login permanece disponível para informar falhas de banco.
     */
    public function __construct()
    {
    }

    private function usuarioModel(): Usuario
    {
        if ($this->usuarioModel === null) {
            Logger::debug('AuthController: inicializando model de usuário sob demanda');
            $this->usuarioModel = new Usuario();
        }

        return $this->usuarioModel;
    }

    private function negocioModel(): Negocio
    {
        if ($this->negocioModel === null) {
            Logger::debug('AuthController: inicializando model de negócio sob demanda');
            $this->negocioModel = new Negocio();
        }

        return $this->negocioModel;
    }

    // ----------------------------------------------------------------
    // GET /login
    // ----------------------------------------------------------------
    public function showLoginForm(): void
    {
        if (!empty($_SESSION['user_id'])) {
            // Redirecionar conforme o tipo de usuário já logado
            if (($_SESSION['user_type'] ?? $_SESSION['user_perfil'] ?? '') === 'admin') {
                $this->redir('/admin/dashboard');
            } else {
                $this->redir('/portal/dashboard');
            }
            return;
        }
        View::render('auth/login', ['title' => 'Login']);
    }

    // ----------------------------------------------------------------
    // POST /login
    // ----------------------------------------------------------------
    public function login(): void
    {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $senha = $_POST['senha'] ?? '';

        if (empty($email) || empty($senha)) {
            $this->redir('/login?error=' . urlencode('Preencha e-mail e senha.'));
            return;
        }

        $usuario = $this->usuarioModel()->autenticar($email, $senha);

        if (!$usuario) {
            Logger::warning("Tentativa de login falhou: {$email}");
            $this->redir('/login?error=' . urlencode('E-mail ou senha inválidos.'));
            return;
        }

        if ((int)$usuario->email_verificado === 0) {
            $this->redir('/validar-token?email=' . urlencode($email));
            return;
        }

        session_regenerate_id(true);
        $_SESSION['user_id']       = $usuario->id;
        $_SESSION['user_name']     = $usuario->nome_completo;
        $_SESSION['user_email']    = $usuario->email;
        $_SESSION['user_perfil']   = $usuario->perfil;
        $_SESSION['tipo_conta']    = $usuario->tipo_conta;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

        Logger::info("Login: {$email} (perfil: {$usuario->perfil})");

        if ($usuario->perfil === 'admin') {
            $this->redir('/admin/dashboard');
        } else {
            $this->redir('/portal/dashboard');
        }
    }

    // ----------------------------------------------------------------
    // GET /cadastro
    // ----------------------------------------------------------------
    public function showCadastroForm(): void
    {
        $ramos = $this->negocioModel()->listarRamos();
        View::render('auth/cadastro', [
            'title' => 'Criar Conta',
            'ramos' => $ramos,
        ]);
    }

    // ----------------------------------------------------------------
    // POST /cadastro
    // ----------------------------------------------------------------
    public function cadastrar(): void
    {
        $nome      = trim($_POST['nome_completo'] ?? '');
        $email     = strtolower(trim($_POST['email'] ?? ''));
        $senha     = $_POST['senha'] ?? '';
        $telefone  = preg_replace('/\D/', '', $_POST['telefone'] ?? '');
        $tipoConta = $_POST['tipo_conta']     ?? 'pessoal';
        $tipoDoc   = $_POST['tipo_documento'] ?? 'cpf';
        $cpf       = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $cnpj      = preg_replace('/\D/', '', $_POST['cnpj'] ?? '');
        $ramoId    = (int)($_POST['ramo_atividade_id'] ?? 0);
        $nomeNeg   = trim($_POST['nome_negocio'] ?? '');

        $erros = [];
        if (strlen($nome) < 3)       $erros[] = 'Nome completo inválido.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros[] = 'E-mail inválido.';
        if (strlen($senha) < 8)      $erros[] = 'Senha deve ter ao menos 8 caracteres.';
        if (strlen($telefone) < 10)  $erros[] = 'Telefone inválido.';
        if ($this->usuarioModel()->emailExiste($email)) $erros[] = 'Este e-mail já está cadastrado.';
        if ($tipoDoc === 'cpf' && strlen($cpf) !== 11) $erros[] = 'CPF inválido.';
        if ($tipoConta === 'negocio') {
            if (empty($nomeNeg)) $erros[] = 'Informe o nome do negócio.';
            if ($ramoId <= 0)    $erros[] = 'Selecione o ramo de atividade.';
        }

        if (!empty($erros)) {
            $ramos = $this->negocioModel()->listarRamos();
            View::render('auth/cadastro', [
                'title' => 'Criar Conta',
                'error' => implode(' ', $erros),
                'ramos' => $ramos,
            ]);
            return;
        }

        $token    = Usuario::gerarToken(6);
        $expiraEm = date('Y-m-d H:i:s', strtotime('+30 minutes'));

        $usuarioId = $this->usuarioModel()->criar([
            'nome_completo'   => $nome,
            'email'           => $email,
            'senha'           => $senha,
            'cpf'             => ($tipoDoc === 'cpf') ? $cpf : null,
            'telefone'        => $telefone,
            'tipo_conta'      => $tipoConta,
            'tipo_documento'  => $tipoDoc,
            'token'           => $token,
            'token_expira_em' => $expiraEm,
            'ip'              => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        if ($tipoConta === 'negocio' && $usuarioId > 0) {
            $this->negocioModel()->criar([
                'usuario_id'        => $usuarioId,
                'ramo_atividade_id' => $ramoId,
                'razao_social'      => $nomeNeg,
                'nome_fantasia'     => $nomeNeg,
                'cnpj'              => ($tipoDoc === 'cnpj') ? $cnpj : null,
                'email'             => $email,
            ]);
        }

        $this->enviarEmailToken($email, $nome, $token);
        Logger::info("Cadastro: {$email} (tipo: {$tipoConta})");

        $this->redir('/validar-token?email=' . urlencode($email));
    }

    // ----------------------------------------------------------------
    // GET /validar-token
    // ----------------------------------------------------------------
    public function showValidarToken(): void
    {
        $email = trim($_GET['email'] ?? '');
        View::render('auth/validar_token', [
            'title' => 'Validar E-mail',
            'email' => $email,
        ]);
    }

    // ----------------------------------------------------------------
    // POST /validar-token
    // ----------------------------------------------------------------
    public function validarToken(): void
    {
        $email = trim($_POST['email'] ?? '');
        $token = strtoupper(trim($_POST['token'] ?? ''));

        if (empty($email) || strlen($token) < 6) {
            View::render('auth/validar_token', [
                'title' => 'Validar E-mail',
                'email' => $email,
                'error' => 'Informe o código de validação completo.',
            ]);
            return;
        }

        $ok = $this->usuarioModel()->validarToken($email, $token);

        if (!$ok) {
            View::render('auth/validar_token', [
                'title' => 'Validar E-mail',
                'email' => $email,
                'error' => 'Código inválido ou expirado. Solicite um novo código.',
            ]);
            return;
        }

        Logger::info("E-mail validado: {$email}");
        $this->redir('/login?validated=1&email=' . urlencode($email));
    }

    // ----------------------------------------------------------------
    // GET /reenviar-token
    // ----------------------------------------------------------------
    public function reenviarToken(): void
    {
        $email   = trim($_GET['email'] ?? '');
        $usuario = $this->usuarioModel()->buscarPorEmail($email);

        if ($usuario && (int)$usuario->email_verificado === 0) {
            $token    = Usuario::gerarToken(6);
            $expiraEm = date('Y-m-d H:i:s', strtotime('+30 minutes'));
            $this->usuarioModel()->atualizarToken($usuario->id, $token, $expiraEm);
            $this->enviarEmailToken($email, $usuario->nome_completo, $token);
        }

        $this->redir('/validar-token?email=' . urlencode($email) . '&resent=1');
    }

    // ----------------------------------------------------------------
    // POST /logout  (aceita GET também via rota adicional)
    // ----------------------------------------------------------------
    public function logout(): void
    {
        $email = $_SESSION['user_email'] ?? 'desconhecido';
        Logger::info("Logout: {$email}");

        // Limpar sessão completamente
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();

        header('Location: /login');
        exit();
    }

    // ----------------------------------------------------------------
    // Envio de e-mail com token
    // ----------------------------------------------------------------
    private function enviarEmailToken(string $email, string $nome, string $token): void
    {
        $assunto = "AppAuto — Seu código de verificação: {$token}";
        $corpo   = "Olá, {$nome}!\n\n"
                 . "Seu código de verificação AppAuto é:\n\n"
                 . "   {$token}\n\n"
                 . "Este código expira em 30 minutos.\n"
                 . "Se você não solicitou este cadastro, ignore este e-mail.\n\n"
                 . "— Equipe AppAuto\n"
                 . "https://appauto.com.br";

        $headers  = "From: AppAuto <noreply@appauto.com.br>\r\n";
        $headers .= "Reply-To: suporte@appauto.com.br\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($email, $assunto, $corpo, $headers);
        Logger::debug("TOKEN [{$token}] enviado para: {$email}");
    }

}
