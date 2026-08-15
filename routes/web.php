<?php

use App\Core\Router;

// ============================================================
// APPAUTO SaaS — Rotas Completas v2.0
// ============================================================

// ---- Públicas (sem autenticação) ----
Router::get("/login",           "AuthController@showLoginForm");
Router::post("/login",          "AuthController@login");
Router::get("/cadastro",        "AuthController@showCadastroForm");
Router::post("/cadastro",       "AuthController@cadastrar");
Router::get("/validar-token",   "AuthController@showValidarToken");
Router::post("/validar-token",  "AuthController@validarToken");
Router::get("/reenviar-token",  "AuthController@reenviarToken");
Router::get("/recuperar-senha", "AuthController@showRecuperarSenha");
Router::post("/recuperar-senha","AuthController@recuperarSenha");

// ---- Protegidas (requerem autenticação) ----
Router::group(["middleware" => "Auth"], function () {

    // Home / Dashboard
    Router::get("/",          "HomeController@index");
    Router::get("/dashboard", "DashboardController@index");
    Router::post("/logout",   "AuthController@logout");
    Router::get("/logout",    "AuthController@logout");  // suporte a link GET

    // =========================================================
    // PORTAL DE VEÍCULOS (PF e PJ)
    // =========================================================

    // Dashboard do Portal
    Router::get("/portal/dashboard",         "PortalController@dashboard");

    // Veículos — Portal (usa portal_header.php com sidebar do portal)
    Router::get("/portal/veiculos",                     "PortalVeiculosController@index");
    Router::get("/portal/veiculos/adicionar",           "PortalVeiculosController@showAdicionar");
    Router::post("/portal/veiculos/adicionar",          "PortalVeiculosController@adicionar");
    Router::get("/portal/veiculos/consultar-placa",     "PortalVeiculosController@showConsultarPlaca");
    Router::get("/portal/veiculos/api/consultar-placa", "PortalVeiculosController@apiConsultarPlaca");
    Router::post("/portal/veiculos/api/ocr",            "PortalVeiculosController@apiOCR");
    Router::post("/portal/selecionar-veiculo",          "PortalVeiculosController@selecionarVeiculo");
    Router::get("/portal/veiculos/{id}/editar",         "PortalVeiculosController@showEditar");
    Router::post("/portal/veiculos/{id}/editar",        "PortalVeiculosController@editar");
    Router::post("/portal/veiculos/{id}/excluir",       "PortalVeiculosController@excluir");

    // Compat. rotas antigas de veículos
    Router::get("/veiculos",                     "VeiculosController@index");
    Router::get("/veiculos/adicionar",           "VeiculosController@showAdicionar");
    Router::post("/veiculos/adicionar",          "VeiculosController@adicionar");
    Router::get("/veiculos/consultar-placa",     "VeiculosController@showConsultarPlaca");
    Router::get("/veiculos/api/consultar-placa", "VeiculosController@apiConsultarPlaca");
    Router::post("/veiculos/api/ocr",            "VeiculosController@apiOCR");
    Router::get("/veiculos/{id}",                "VeiculosController@show");
    Router::get("/veiculos/{id}/editar",         "VeiculosController@showEditar");
    Router::post("/veiculos/{id}/editar",        "VeiculosController@editar");
    Router::post("/veiculos/{id}/excluir",       "VeiculosController@excluir");

    // Manutenções
    Router::get("/portal/manutencoes",           "PortalController@manutencoes");
    Router::get("/portal/manutencoes/adicionar", "PortalController@adicionarManutencao");
    Router::post("/portal/manutencoes/salvar",   "PortalController@salvarManutencao");

    // Documentos
    Router::get("/portal/documentos",           "PortalController@documentos");
    Router::get("/portal/documentos/adicionar", "PortalController@adicionarDocumento");
    Router::post("/portal/documentos/salvar",   "PortalController@salvarDocumento");
    Router::post("/portal/documentos/api/analisar-ocr", "PortalController@analisarDocumentoOcr");
    Router::get("/portal/documentos/{id}/baixar", "PortalController@baixarDocumento");

    // Abastecimentos
    Router::get("/portal/abastecimentos",           "PortalController@abastecimentos");
    Router::get("/portal/abastecimentos/adicionar", "PortalController@adicionarAbastecimento");
    Router::post("/portal/abastecimentos/salvar",   "PortalController@salvarAbastecimento");

    // Pneus
    Router::get("/portal/pneus",         "PortalController@pneus");
    Router::post("/portal/pneus/salvar", "PortalController@salvarPneu");

    // Bateria
    Router::get("/portal/bateria",         "PortalController@bateria");
    Router::post("/portal/bateria/salvar", "PortalController@salvarBateria");

    // Seguro
    Router::get("/portal/seguro",         "PortalController@seguro");
    Router::post("/portal/seguro/salvar", "PortalController@salvarSeguro");

    // Custos
    Router::get("/portal/custos", "PortalController@custos");

    // Agenda Inteligente
    Router::get("/portal/agenda",         "PortalController@agenda");
    Router::post("/portal/agenda/salvar", "PortalController@salvarAgenda");

    // Checklist
    Router::get("/portal/checklist",       "PortalController@checklist");
    Router::get("/portal/checklist/novo",  "PortalController@novoChecklist");
    Router::post("/portal/checklist/salvar","PortalController@salvarChecklist");

    // Galeria
    Router::get("/portal/galeria",         "PortalController@galeria");
    Router::post("/portal/galeria/salvar", "PortalController@salvarFoto");

    // Timeline
    Router::get("/portal/timeline", "PortalController@timeline");

    // IPVA / Multas
    Router::get("/portal/ipva", "PortalController@ipva");

    // Relatórios
    Router::get("/portal/relatorios", "PortalController@relatorios");

    // Assistente IA
    Router::get("/portal/ia",        "PortalController@ia");
    Router::post("/portal/ia/chat",  "PortalController@iaChat");

    // Marketplace
    Router::get("/portal/marketplace", "PortalController@marketplace");

    // =========================================================
    // PORTAL MEU NEGÓCIO (PJ)
    // =========================================================
    Router::get("/negocio/dashboard", "NegocioController@dashboard");
    Router::get("/negocio/clientes",  "NegocioController@clientes");
    Router::get("/negocio/servicos",  "NegocioController@servicos");

    // =========================================================
    // PERFIL E CONFIGURAÇÕES
    // =========================================================
    Router::get("/perfil",        "PerfilController@index");
    Router::post("/perfil",       "PerfilController@atualizar");
    Router::get("/configuracoes", "ConfiguracoesController@index");

    // =========================================================
    // PAINEL ADMIN
    // =========================================================
    Router::get("/admin/dashboard",            "AdminController@dashboard");
    Router::get("/admin/clientes/pessoas",     "AdminController@clientesPessoas");
    Router::get("/admin/clientes/negocios",    "AdminController@clientesNegocios");
    Router::get("/admin/acessar-como/{id}",    "AdminController@acessarComo");
    Router::get("/admin/acessar-negocio/{id}", "AdminController@acessarNegocio");
    Router::get("/admin/sair-impersonacao",    "AdminController@sairImpersonacao");
    Router::get("/admin/logs",                 "AdminController@logs");
    Router::get("/admin/configuracoes",        "AdminController@configuracoes");
    Router::get("/admin/usuario/{id}",         "AdminController@verUsuario");
    Router::get("/admin/negocio/{id}",         "AdminController@verNegocio");

    // =========================================================
    // MÓDULOS LEGADOS (compatibilidade com estrutura original)
    // =========================================================
    Router::get("/clientes",                   "ClientesController@index");
    Router::get("/financeiro/contas-a-pagar",  "FinanceiroController@contasAPagar");
    Router::get("/financeiro/contas-a-receber","FinanceiroController@contasAReceber");
    Router::get("/faturamento",                "FaturamentoController@index");
    Router::get("/integracao",                 "IntegracaoController@index");
});
