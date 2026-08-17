# Integrações externas do APP AUTO

**Status:** implementado no repositório, aguardando configuração de chaves e validação autenticada no ambiente de produção.

## Objetivo

O APP AUTO agora possui uma camada administrativa para diagnosticar e testar as integrações automotivas definidas como prioritárias: FIPE, Google ML Kit, Cloudmersive, OCR.Space, PixLab, NHTSA/vPIC e as fontes brasileiras de consulta de placa já existentes.

A tela está disponível em `/admin/configuracoes`. O endereço `/admin/` também foi registrado para abrir o dashboard administrativo, corrigindo o 404 observado no ambiente publicado quando a URL é acessada com barra final.

> As chaves de terceiros permanecem exclusivamente no `.env` do servidor. O painel administrativo informa apenas o estado configurado/pendente e nunca devolve valores secretos ao navegador.

## Arquitetura

| Componente | Responsabilidade |
|---|---|
| `FipeService` | Consulta FIPE v2 por marca, modelo, ano e preço; cache local de 24 horas; token opcional. |
| `VpicService` | Decodificação complementar de VIN pela vPIC/NHTSA. |
| `OcrExternoService` | Upload validado, leitura de placa Cloudmersive, OCR de documentos e fallback configurável. |
| `IntegracoesService` | Fachada usada pelo admin para catálogo, status e testes sanitizados. |
| `ConsultaPlacaService` | Fallback existente de API Placas, APIBrasil e PlacaAPI; enriquecimento FIPE opt-in. |
| `AdminController` | Renderiza a configuração e expõe o endpoint POST protegido por sessão admin e CSRF. |
| `VeiculosController` e `PortalVeiculosController` | Usam OCR externo quando configurado e mantêm Tesseract local como fallback. |

O fluxo de OCR é deliberadamente diferente do ML Kit. O **Google ML Kit roda no cliente Android** e não é chamado pelo PHP. O painel apenas registra o indicador `ML_KIT_ENABLED` e documenta a integração para o aplicativo. Para o servidor, a ordem de OCR é configurada por `OCR_PROVIDER_ORDER`.

## Variáveis de ambiente

Copie as variáveis de `.env.example` para o `.env` de produção e preencha somente os provedores contratados.

| Integração | Variáveis | Obrigatoriedade |
|---|---|---|
| FIPE v2 | `FIPE_API_BASE_URL`, `FIPE_API_TOKEN`, `FIPE_AUTO_ENRICH` | `FIPE_API_TOKEN` é opcional no limite público; `FIPE_AUTO_ENRICH=false` é o padrão seguro. |
| Cloudmersive | `CLOUDMERSIVE_API_KEY`, `CLOUDMERSIVE_BASE_URL` | Chave necessária para leitura de placa e OCR de imagem. |
| OCR.Space | `OCRSPACE_API_KEY`, `OCRSPACE_ENDPOINT`, `OCRSPACE_URL_ENDPOINT`, `OCRSPACE_LANGUAGE` | Chave necessária para OCR geral; endpoints separados para upload e URL. |
| PixLab | `PIXLAB_API_KEY`, `PIXLAB_OCR_ENDPOINT`, `PIXLAB_LANGUAGE` | Chave necessária; o adaptador de teste usa URL HTTPS. |
| Orquestração OCR | `OCR_PROVIDER_ORDER` | Exemplo: `cloudmersive,ocrspace,pixlab`. |
| Android | `ML_KIT_ENABLED` | Apenas indicador de habilitação do cliente Android. |

As fontes brasileiras de placa continuam usando as variáveis já documentadas em `docs/CONSULTA-PLACAS.md`: `APIPLACAS_TOKEN`, `APIBRASIL_*` e `PLACAAPI_*`.

## Uso do painel

O administrador deve abrir `/admin/configuracoes`, confirmar o estado de cada provedor e usar o formulário de teste. Para FIPE, o teste sem marca/modelo/ano verifica a conectividade; quando esses três campos são preenchidos, o sistema executa a busca de marca, modelo, ano e preço. Para vPIC, deve ser informado um VIN de 17 caracteres; o painel devolve somente campos técnicos.

Para Cloudmersive e OCR.Space, o administrador pode enviar uma imagem de teste de até 10 MB. Para PixLab, deve ser usada uma URL HTTPS temporária, pois o contrato implementado neste primeiro estágio usa o endpoint por URL. O modo `placa` chama exclusivamente a detecção de placa Cloudmersive e não faz fallback silencioso para OCR genérico.

Os testes devolvem apenas `success`, mensagem, fonte, quantidade de campos, chaves resumidas da resposta e dados técnicos necessários à validação. Não são logados arquivo, imagem, placa, VIN, nome de proprietário, token ou senha.

## Fluxo de produção

A consulta de placa existente continua sendo a primeira fonte de dados brasileiros. O FIPE não consulta placa; ele só é acionado automaticamente quando `FIPE_AUTO_ENRICH=true`, a resposta de placa não contém FIPE e existem marca, modelo e ano modelo válidos. A consulta FIPE usa cache local de 24 horas para reduzir latência e chamadas repetidas.

O OCR do cadastro legado e do Portal de Veículos tenta os provedores externos configurados e, em caso de indisponibilidade, mantém o Tesseract local ou o preenchimento manual. Todo upload externo é validado por MIME real e limitado a 10 MB. O resultado de OCR é assistivo: o usuário deve revisar os campos antes de salvar.

## Limitações e decisões

A FIPE v2 documenta limite de requisições não autenticadas e pode responder com bloqueio anti-bot, `403` ou `429`; o adaptador trata essas respostas como indisponibilidade e não gera erro fatal. No smoke test local de 17 de agosto de 2026, a FIPE respondeu `403` com desafio Cloudflare, enquanto o vPIC respondeu `200` e devolveu Honda Accord 2003 para o VIN de exemplo. A validação definitiva da FIPE deve ser feita no Hostgator com o host/token configurados e o botão de teste do admin.

O Cloudmersive é tratado como piloto de leitura de placas brasileiras, não como garantia de acurácia. Devem ser testadas placas antigas, Mercosul, baixa iluminação, inclinação, reflexos e imagens com múltiplos veículos. OCR.Space e PixLab permanecem como fallback/alternativas para documentos até que o APP AUTO tenha métricas próprias de precisão e custo.

O vPIC/NHTSA é uma fonte norte-americana complementar por VIN. Ele não deve preencher Renavam, situação nacional, proprietário, município brasileiro, preço FIPE ou validar placa.

## Validação antes do deploy

| Verificação | Resultado local |
|---|---|
| `php -l` nos arquivos alterados | Aprovado, sem erros de sintaxe. |
| Catálogo administrativo | Aprovado, sete integrações listadas. |
| vPIC com VIN público de exemplo | Aprovado, HTTP 200. |
| FIPE v2 | Adaptador estruturado; endpoint respondeu HTTP 403 por desafio anti-bot no sandbox. |
| OCR sem chaves | Aprovado, nenhum provedor é chamado e o catálogo informa pendência. |
| Git diff/check | Sem erro de whitespace detectado. |

No Hostgator, publique `vendor/` e os arquivos novos, copie as variáveis para `.env`, confirme permissão de escrita em `storage/cache/integracoes/fipe`, abra `/admin/configuracoes` e teste cada provedor contratado. Depois, faça um teste autenticado de OCR com imagem sem dados pessoais reais e uma consulta de placa de homologação.

## Referências oficiais

[1]: https://deividfortuna.github.io/fipe/v2/ "FIPE API v2 — documentação OpenAPI"

[2]: https://vpic.nhtsa.dot.gov/api/ "NHTSA vPIC API — documentação oficial"

[3]: https://api.cloudmersive.com/docs/image.asp "Cloudmersive Image Recognition API — documentação oficial"

[4]: https://ocr.space/ocrapi "OCR.Space — documentação oficial da API"

[5]: https://developers.google.com/ml-kit/vision/text-recognition/v2 "Google ML Kit — Text Recognition v2"

[6]: https://fipe.api.br/ "FIPE API — portal oficial e planos de acesso"
