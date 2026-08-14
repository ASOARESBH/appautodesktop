# Patch — correção do HTTP 500 no login

## Commit de origem

`adc148d` — `fix: corrige erro 500 no login`

## Arquivos a enviar para o diretório raiz da aplicação

Substitua os arquivos existentes, preservando exatamente a estrutura de diretórios:

| Arquivo do patch | Destino no Hostgator |
|---|---|
| `app/Controllers/AuthController.php` | `app/Controllers/AuthController.php` |
| `app/Controllers/AdminController.php` | `app/Controllers/AdminController.php` |
| `app/Controllers/PortalController.php` | `app/Controllers/PortalController.php` |
| `app/Controllers/PortalVeiculosController.php` | `app/Controllers/PortalVeiculosController.php` |
| `app/Controllers/VeiculosController.php` | `app/Controllers/VeiculosController.php` |
| `app/bootstrap.php` | `app/bootstrap.php` |

## Procedimento de publicação

Faça um backup dos seis arquivos atuais no servidor antes da substituição. Depois envie os arquivos descompactados para `/home2/inlaud99/erp.appauto.com.br/`, mantendo a estrutura de diretórios. Não envie `.env`, logs, arquivos de teste ou a base temporária criada na validação local.

Em seguida, teste em janela anônima `GET /login`, um login inválido, o login válido e `GET /logout`. O login administrativo deve redirecionar para `/admin/dashboard` sem HTTP 500.

> O arquivo `app/bootstrap.php` preserva o detalhe da inicialização nos logs e mostra apenas uma mensagem genérica ao usuário em produção. Mantenha `APP_ENV=prod` e `display_errors` desabilitado no servidor.
