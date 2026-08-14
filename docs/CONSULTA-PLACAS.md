# Consulta de Placas — AppAuto

> **Status:** implementada com fallback entre fontes configuráveis, normalização de dados técnicos e preenchimento do cadastro de veículo.

## Objetivo

A consulta automática recebe uma placa antiga (`ABC1234`) ou Mercosul (`ABC1D23`), normaliza a resposta de cada fonte e devolve apenas dados técnicos úteis ao cadastro. O mecanismo não grava nem exibe dados de proprietário, CPF, endereço, telefone, e-mail ou outros dados pessoais.

| Ordem | Fonte | Condição para uso | Dados esperados | Papel no fallback |
|---|---|---|---|---|
| 1 | API Placas / WDAPI2 | `APIPLACAS_TOKEN` configurado | Marca, modelo, versão, anos, cor, situação, UF, município, dados técnicos e possíveis correspondências FIPE. | Fonte enriquecida prioritária. |
| 2 | APIBrasil | Token, `DeviceToken`, endpoint e modalidade configurados. | Dados definidos pelo produto veicular contratado. | Fonte alternativa, sem contrato presumido no código. |
| 3 | PlacaAPI | Endpoint contratado, usuário e senha configurados. | Dados técnicos do veículo conforme o contrato. | Terceira fonte configurável. |
| 4 | Parallelum FIPE | Disponível sem chave, após identificação de marca/modelo/ano. | Catálogo FIPE. | Enriquecimento futuro; não consulta placa diretamente. |

Quando uma fonte falha, expira, retorna HTTP fora de 2xx ou não devolve dados técnicos aproveitáveis, a consulta segue para a próxima fonte configurada. Quando uma resposta contém pelo menos dez campos técnicos normalizados, o AppAuto encerra a sequência para reduzir latência e consumo de créditos.

## Configuração no `.env`

Copie os nomes abaixo de `.env.example` para o `.env` de produção e preencha somente as fontes contratadas. Nunca versione chaves reais.

```dotenv
APIPLACAS_TOKEN=

APIBRASIL_TOKEN=
APIBRASIL_DEVICE_TOKEN=
APIBRASIL_VEHICLE_ENDPOINT=
APIBRASIL_VEHICLE_TYPE=

PLACAAPI_ENDPOINT=
PLACAAPI_USERNAME=
PLACAAPI_PASSWORD=
```

A variável `PLACAAPI_ENDPOINT` deve usar os placeholders aceitos pelo AppAuto: `{placa}`, `{usuario}` e `{senha}`. Isso evita incorporar qualquer credencial na aplicação.

## Contrato de resposta interno

As rotas retornam campos normalizados como `placa`, `placa_formatada`, `formato_placa`, `marca`, `modelo`, `submodelo`, `versao`, `ano_fabricacao`, `ano_modelo`, `cor`, `combustivel`, `municipio`, `uf`, `situacao`, `categoria`, `tipo_veiculo`, `carroceria`, `cilindradas`, `eixos`, `capacidade_passageiros`, `peso_bruto_total`, `fipe`, `dados_tecnicos`, `fonte` e `fontes_tentadas`.

O chassi é ignorado quando a fonte o retorna mascarado. O RENAVAM não é inferido por fonte externa. O usuário deve revisar os campos antes de salvar o veículo.

## Rotas cobertas

| Rota | Contrato |
|---|---|
| `GET /portal/veiculos/api/consultar-placa?placa=...` | Objeto plano normalizado para o formulário do Portal. |
| `GET /veiculos/api/consultar-placa?placa=...` | `{ success, fonte, fontes_tentadas, dados }` para o fluxo legado. |

## Operação e diagnóstico

O serviço usa TLS validado, timeout de conexão de 3 segundos e timeout total de 5 segundos por provedor. Logs registram apenas o nome do provedor, status HTTP, ocorrência de erro técnico e quantidade de campos técnicos; não registram tokens, senha ou a placa consultada.

Se nenhuma chave estiver configurada, o formulário permanece utilizável e informa que a consulta automática precisa ser configurada. Se todas as fontes configuradas falharem, o formulário permite preenchimento manual.

## Fontes

A API Placas documenta endpoint GET por placa com token e informa que campos adicionais/FIPE podem estar ausentes em determinadas consultas.[1] A APIBrasil divulga autenticação Bearer combinada com `DeviceToken` e catálogo veicular, mas a modalidade e endpoint dependem da contratação.[2] A PlacaAPI divulga cadastro de teste e acesso a dados técnicos por meio de suas interfaces de integração.[3] A BrasilAPI não mantém um endpoint de placa válido no caminho anteriormente usado pelo AppAuto; portanto ele foi removido do fallback.[4]

## Referências

[1]: https://apiplacas.com.br/doc.php "API Placas — Documentação"
[2]: https://apibrasil.io/ "APIBrasil — Plataforma de APIs"
[3]: https://www.placaapi.com/ "PlacaAPI Brasil"
[4]: https://brasilapi.com.br/docs "BrasilAPI — Documentação"
