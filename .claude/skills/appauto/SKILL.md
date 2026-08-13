---
name: appauto
description: Memória técnica e procedimento obrigatório do AppAuto, um SaaS automotivo PHP MVC. Use antes de analisar, corrigir, criar, refatorar, testar ou implantar qualquer alteração no repositório AppAuto; cobre rotas, banco, sessões, multi-tenant, veículos, integrações, uploads, segurança e deploy Hostgator.
---

# APPAUTO SKILL

> **Fonte de verdade:** o código atual da branch em uso. Consulte também `docs/APPAUTO-SYSTEM-MAP.md`, mapeado no commit `77bbe18`. Em conflito, priorize o código e atualize o mapa.

## 1. Visão Geral

Trate o AppAuto como um SaaS automotivo em PHP MVC próprio, sem framework, orientado a Hostgator/MySQL 5.7. O produto reúne conta PF/PJ, veículos, portal operacional, administração e uma camada PJ ainda incompleta.

## 2. Objetivo do Sistema

Permita cadastro e acompanhamento de veículos, onboarding de conta PJ e administração de clientes com impersonação controlada.

## 3. Stack

Use PHP 8+, PDO MySQL, Composer/PSR-4, `vlucas/phpdotenv`, sessão PHP, HTML/CSS/JavaScript, Bootstrap/Font Awesome/Chart.js por CDN, cURL e `mail()`. Preserve compatibilidade com MySQL 5.7/MariaDB e charset `utf8`.

## 4. Arquitetura

Siga `public/index.php → app/bootstrap.php → routes/web.php → Router → AuthMiddleware → Controller → Model/PDO → View/layout`. Não introduza framework ou serviço persistente sem decisão explícita.

## 5. Estrutura de Diretórios

| Caminho | Responsabilidade |
|---|---|
| `app/Controllers` | Orquestra rotas e contém regras de domínio atuais. |
| `app/Core` | Router, View, Database, Logger e classes base. |
| `app/Models` | Acesso a usuários, negócios e veículos. |
| `app/Views` | Templates e layouts self-contained/legados. |
| `database` | `schema.sql`, migration 002 e seed. |
| `routes/web.php` | Todas as rotas. |
| `public` | Document root, assets e uploads; não expor scripts sensíveis. |
| `docs/APPAUTO-SYSTEM-MAP.md` | Mapa técnico detalhado e achados. |

## 6. Banco de Dados

Leia primeiro `database/schema.sql` e depois `database/migrations/002_portal_veiculos.sql`. O schema base cria `ramos_atividade`, `usuarios`, `negocios`, `negocio_membros`, `veiculos`, `veiculo_fotos`, `consultas_placa`, `audit_logs`, `email_tokens` e `admin_sessoes`. A migration 002 cria 16 tabelas do Portal de Veículos.

## 7. Entidades

Use `usuarios` como identidade central. Use `negocios` para PJ e `veiculos` para propriedade/escopo operacional. Use tabelas `veiculo_*` para dados derivados, sempre com `veiculo_id` e `usuario_id` quando a tabela os possuir.

## 8. Relacionamentos

Respeite `negocios.usuario_id → usuarios.id`, `negocios.ramo_atividade_id → ramos_atividade.id`, `veiculos.usuario_id → usuarios.id`, `veiculos.negocio_id → negocios.id` e `veiculo_fotos.veiculo_id → veiculos.id`. Não suponha FKs na migration 002: ela possui apenas índices.

## 9. Rotas

Leia `routes/web.php` antes de qualquer mudança. Registre método, rota dinâmica, middleware e action. Não crie link de UI sem rota e não deixe rota apontar para classe, método ou view ausente.

## 10. Controllers

`AuthController`, `AdminController`, `PortalController`, `PortalVeiculosController` e `VeiculosController` são os controllers principais. Trate `PortalController` como alta complexidade: ele mistura SQL, upload e regras de domínio; analise impacto amplo antes de editar.

## 11. Models

Use `Usuario`, `Negocio` e `Veiculo`. Trate `Models/User.php` como legado: ele aponta para tabela `users`, ausente do schema atual. Antes de chamar `Veiculo`, confirme a assinatura real do método; há divergência conhecida com `PortalVeiculosController`.

## 12. Views

Views em `auth/`, `admin/`, `veiculos/`, `negocio/`, `perfil/` e `portal/` são self-contained em `View::render()` e incluem layout. As demais usam layout ERP legado. Escape dados externos com `htmlspecialchars` contextual.

## 13. Autenticação

O login usa e-mail, `password_verify`, usuário ativo e e-mail verificado. O cadastro usa token de seis caracteres por 30 minutos no próprio registro `usuarios`; `email_tokens` não é usado pelo fluxo real.

## 14. Autorização

`AuthMiddleware` só exige `$_SESSION['user_id']`. Use `user_perfil` como chave correta de perfil; o login não grava `user_role`. A guarda privada de `AdminController` é a referência atual para checagem de admin.

## 15. Multi-Tenant

O isolamento real atual é predominantemente por `usuario_id`; queries do portal devem combinar `usuario_id` e `veiculo_id`. Não alegue multi-tenancy completo por negócio: `negocio_membros` não é consumida pelo código atual.

## 16. Veículos

Há dois fluxos: legado (`VeiculosController`) e portal (`PortalVeiculosController`). A fonte estável de métodos é `Models/Veiculo.php`. Não ignore o descompasso: o controller portal chama `validar`, `atualizar`, `excluir` e variantes com `userId` que o model atual não declara.

## 17. APIs

Placa: BrasilAPI e Parallelum; OCR legado: Tesseract opcional; OCR portal: OpenAI Vision; chat: OpenAI Chat Completions; e-mail: `mail()`. Use timeout, validação de resposta, TLS verificado e logs sem segredos.

## 18. Uploads

Uploads ficam em `public/assets/uploads`, aumentando risco. Não ampliar esse padrão sem MIME com finfo, allowlist de extensão, limite de tamanho, nomes aleatórios, autorização de download e bloqueio de execução. O helper de documentos/galeria atual não valida tipo/tamanho.

## 19. Segurança

Antes de deploy, elimine scripts de debug dentro de `public/` e `public/error_log`; são P0. Aplique CSRF a toda rota de escrita. Padronize o campo, pois a View emite `csrf_token` e o CRUD portal exige `_csrf`. Não desabilite verificação TLS.

## 20. Configurações

Use `.env` não versionado. Variáveis confirmadas: `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MAIL_FROM`, `MAIL_FROM_NAME`; IA também requer `OPENAI_API_KEY`, ausente do `.env.example`.

## 21. Dependências

Mantenha `composer.json` e `composer.lock` alinhados. `vendor/` é versionado para deploy sem Composer/SSH na Hostgator. Não remova esse comportamento sem plano de deploy aprovado.

## 22. Fluxos Principais

```text
Cadastro → token por e-mail → validação → login → sessão → portal/admin
Admin → lista de clientes → impersonação → portal → sair impersonação
Usuário → veículo ativo em sessão → módulo portal → dados por usuário/veículo
```

## 23. Regras de Negócio

Use e-mail como identificador central. PF usa `tipo_conta=pessoal`; PJ usa `tipo_conta=negocio` e cria negócio no onboarding. Veículo ativo reside em `veiculo_ativo_*` na sessão. Custos são gerados automaticamente por manutenção e abastecimento.

## 24. Problemas Conhecidos

Consulte o mapa para detalhes. Priorize scripts públicos de debug/log, CSRF não aplicado e incompatível, CRUD portal/model quebrado, rotas para classes/métodos ausentes, views ausentes (`portal/ipva/index`, `veiculos/show`) e recuperação de senha ausente.

## 25. Dívida Técnica

Planeje consolidar os dois módulos de veículos, extrair serviços de `PortalController`, remover placeholders ou completar módulos, eliminar layouts legados e conectar tabelas sem uso.

## 26. Riscos

Classifique como P0: debug/log público, upload inseguro, CSRF e rotas principais quebradas. Classifique como P1: escopo tenant incompleto, TLS desabilitado, contratos de API divergentes e XSS potencial por escape inconsistente.

## 27. Funcionalidades Existentes

Há login, cadastro, token, logout, admin básico, impersonação de pessoa, cadastro legado de veículo, consulta de placa, OCR opcional e módulos parciais do Portal de Veículos.

## 28. Funcionalidades Incompletas

Recuperação de senha, Meu Negócio, perfil, detalhes/admin logs/config, edição/exclusão legado, CRUD portal novo, IPVA view, cálculo de score, notificações, relatórios exportáveis e gestão de marketplace não estão concluídos.

## 29. Decisões Arquiteturais

Preserve PHP MVC leve, PDO com `ERRMODE_EXCEPTION`, MySQL 5.7/utf8 e deploy por FTP/cPanel enquanto não houver decisão formal de migração. Não reintroduza `parent::__construct()` em controllers sem a base o suportar.

## 30. Padrões de Desenvolvimento

Use prepared statements, filtro explícito por `usuario_id`/`veiculo_id`, redirecionamento com `exit`, logs sem PII/segredos, inputs validados, saída escapada e nomenclatura em português do domínio.

## 31. Regras para Alterações

1. Rodar `git status`, confirmar branch e ler esta skill.
2. Mapear rota → controller → model/PDO → tabela → view → sessão → API/upload afetado.
3. Não alterar banco sem migration compatível com MySQL 5.7.
4. Não alterar funcionalidade sem teste de PF, PJ, admin e não autenticado quando aplicável.
5. Atualizar esta skill e o mapa no mesmo commit quando a arquitetura mudar.

## 32. Procedimentos de Teste

Validar sintaxe com PHP 8.3, telas públicas, token, login admin/usuário, timeout, logout, CRUD veículo, seleção de veículo, cada POST com CSRF, impersonação e tentativa de acesso cruzado por ID. Testar uploads inválidos, APIs indisponíveis e banco sem migration 002.

## 33. Deploy

Use `public/` como document root. Envie `vendor/`, crie `.env` fora do document root quando possível, configure `APP_ENV=prod`, proteja `storage/logs`, permita escrita apenas em upload e importe schema/migrations na ordem. Remova todo diagnóstico público antes da publicação.

## 34. Histórico da Skill

| Data | Base | Mudança |
|---|---|---|
| 2026-08-13 | commit `77bbe18` | Skill criada por engenharia reversa estática; detalhes em `docs/APPAUTO-SYSTEM-MAP.md`. |

---

**Regra permanente:** antes de codificar, leia esta skill, consulte Git e analise o impacto. Depois implemente, teste e atualize a memória técnica.
