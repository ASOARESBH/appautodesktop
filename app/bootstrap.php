<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Logger;
use App\Core\Router;
use Dotenv\Dotenv;

$logger = new Logger();

$logger->bootstrap("=== BOOTSTRAP INICIADO ===", [
    'timestamp' => date('Y-m-d H:i:s'),
    'environment' => $_ENV['APP_ENV'] ?? 'dev'
]);

/*
|--------------------------------------------------------------------------
| Carregar variáveis de ambiente (.env)
|--------------------------------------------------------------------------
| Usando createImmutable:
| - Variáveis disponíveis em $_ENV e $_SERVER
| - NÃO usar getenv() para validação
*/
try {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->load();
    $logger->bootstrap("Arquivo .env carregado com sucesso");
} catch (\Exception $e) {
    $logger->bootstrap("Erro ao carregar .env", [
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
        echo '<!DOCTYPE html><html lang="pt-BR"><body><h1>Erro temporário</h1>'
           . '<p>Não foi possível iniciar o sistema. Tente novamente em instantes.</p></body></html>';
    } else {
        echo "Erro ao carregar arquivo .env: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
    }
    exit();
}

/*
|--------------------------------------------------------------------------
| Validar variáveis de ambiente críticas do banco
|--------------------------------------------------------------------------
*/
$requiredEnvVars = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME'];

foreach ($requiredEnvVars as $var) {
    if (empty($_ENV[$var] ?? null)) {
        $logger->bootstrap("Variável de ambiente crítica não configurada", [
            'variable' => $var
        ]);
        http_response_code(500);
        if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
            echo '<!DOCTYPE html><html lang="pt-BR"><body><h1>Erro temporário</h1>'
               . '<p>Não foi possível iniciar o sistema. Tente novamente em instantes.</p></body></html>';
        } else {
            echo "Variável de ambiente crítica não configurada: " . htmlspecialchars($var, ENT_QUOTES, 'UTF-8') . "\n";
        }
        exit();
    }
}

$logger->bootstrap("Variáveis de ambiente validadas com sucesso", [
    'variables' => $requiredEnvVars
]);

/*
|--------------------------------------------------------------------------
| Configuração de ambiente (DEV / PROD)
|--------------------------------------------------------------------------
*/
if (($_ENV['APP_ENV'] ?? 'dev') === 'prod') {
    $logger->bootstrap("Modo PRODUÇÃO ativado");
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

    set_error_handler(function ($severity, $message, $file, $line) {
        // Ignorar E_DEPRECATED (8192) e E_STRICT (2048) em produção
        if ($severity & (E_DEPRECATED | E_STRICT | E_USER_DEPRECATED)) {
            return false; // Deixar o PHP lidar normalmente (suprimir)
        }
        Logger::error("PHP Error", [
            'severity' => $severity,
            'message' => $message,
            'file' => $file,
            'line' => $line
        ]);
        return true;
    });

    set_exception_handler(function ($exception) {
        Logger::error("Exceção não capturada", [
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
        http_response_code(500);
        echo "Ocorreu um erro inesperado. Por favor, tente novamente mais tarde.";
    });
} else {
    $logger->bootstrap("Modo DESENVOLVIMENTO ativado");
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

/*
|--------------------------------------------------------------------------
| Timezone
|--------------------------------------------------------------------------
*/
date_default_timezone_set('America/Sao_Paulo');
$logger->bootstrap("Timezone configurado para America/Sao_Paulo");

/*
|--------------------------------------------------------------------------
| Sessão segura
|--------------------------------------------------------------------------
*/
ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);

session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'secure' => (($_ENV['APP_ENV'] ?? 'dev') === 'prod'),
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();

$logger->bootstrap("Sessão iniciada com sucesso", [
    'session_id' => session_id(),
    'session_status' => session_status()
]);

/*
|--------------------------------------------------------------------------
| Middleware global (timeout de sessão)
|--------------------------------------------------------------------------
*/
try {
    (new \App\Middlewares\SessionTimeoutMiddleware())->handle();
    $logger->bootstrap("SessionTimeoutMiddleware executado com sucesso");
} catch (\Exception $e) {
    $logger->bootstrap("Erro ao executar SessionTimeoutMiddleware", [
        'error' => $e->getMessage()
    ]);
}

/*
|--------------------------------------------------------------------------
| CSRF Token
|--------------------------------------------------------------------------
*/
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $logger->bootstrap("CSRF Token gerado", [
        'token_length' => strlen($_SESSION['csrf_token'])
    ]);
} else {
    $logger->bootstrap("CSRF Token já existe na sessão");
}

/*
|--------------------------------------------------------------------------
| Rotas
|--------------------------------------------------------------------------
*/
try {
    require_once dirname(__DIR__) . '/routes/web.php';
    $logger->bootstrap("Arquivo de rotas carregado com sucesso");
} catch (\Exception $e) {
    $logger->bootstrap("Erro ao carregar arquivo de rotas", [
        'error' => $e->getMessage()
    ]);
    http_response_code(500);
    if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
        echo '<!DOCTYPE html><html lang="pt-BR"><body><h1>Erro temporário</h1>'
           . '<p>Não foi possível iniciar o sistema. Tente novamente em instantes.</p></body></html>';
    } else {
        echo "Erro ao carregar rotas: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
    }
    exit();
}

/*
|--------------------------------------------------------------------------
| Dispatcher
|--------------------------------------------------------------------------
*/
$logger->bootstrap("Iniciando dispatch de rotas");
Router::dispatch();
$logger->bootstrap("=== BOOTSTRAP CONCLUÍDO ===");
