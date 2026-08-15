# OCR Local de Documentos — AppAuto

> **Escopo:** CRLV e CNH enviados pelo usuário autenticado no Portal de Veículos.

## Arquitetura implementada

O AppAuto executa OCR de forma **local**, sem enviar a imagem para uma IA ou API externa. O fluxo valida PDF/JPG/PNG até 10 MB, armazena o original fora do document root, converte somente a primeira página de PDF em 300 DPI, tenta pré-processamento de imagem e executa Tesseract em português com PSM 6. O wrapper `thiagoalessio/tesseract_ocr` integra o executável ao PHP.[1] [2]

| Etapa | Implementação | Comportamento de falha |
|---|---|---|
| Upload | `finfo`, allowlist PDF/JPG/PNG, máximo de 10 MB e nome aleatório | O arquivo é recusado com mensagem segura. |
| Armazenamento | `storage/documentos/{usuario_id}/{veiculo_id}` com diretórios privados | O documento não é salvo no document root. |
| PDF | `pdftoppm -f 1 -l 1 -r 300 -png` | OCR fica indisponível para PDF, mas o documento pode ser salvo. |
| Pré-processamento | ImageMagick: escala de cinza, contraste, threshold e despeckle | A imagem original segue para OCR quando a ferramenta não estiver disponível. |
| OCR | Tesseract, idioma `por`, PSM 6 e TSV para confiança | O documento permanece salvo com status controlado. |
| Persistência | `veiculo_documentos` + migration 003 | Salva texto, confiança, JSON serializado, status e erro técnico genérico. |

## Dados extraídos

| Documento | Dados técnicos | Dados pessoais restritos |
|---|---|---|
| CRLV | Placa, RENAVAM, chassi, marca/modelo, anos, cor, categoria, município, UF e data de emissão | CPF/CNPJ e nome do proprietário. |
| CNH | Número/registro, datas de nascimento, validade e emissão, categoria | Nome e CPF. |

Os dados pessoais são mantidos somente no registro privado do documento; não aparecem na prévia de tela e não entram nos logs. A leitura é assistiva: o usuário deve revisar qualquer campo antes de usar ou salvar.

## Requisitos da Hostgator

A hospedagem compartilhada precisa permitir `exec` e disponibilizar os binários abaixo no PATH do PHP. O Tesseract utiliza arquivos de idioma para o reconhecimento; a consulta `tesseract --list-langs` deve mostrar `por`.[1]

```text
Tesseract:  tesseract
Idioma:      por
PDF:         pdftoppm       (necessário para arquivos PDF)
Imagem:      magick ou convert  (opcional, melhora a leitura)
```

Se o plano não permitir `exec` ou não tiver `tesseract`/`por`, o AppAuto não apresenta erro 500: o upload persiste e o status fica `indisponivel`, permitindo o preenchimento manual. A Hostgator deve habilitar/instalar esses binários ou oferecer um plano que os disponibilize para o pipeline local funcionar integralmente.

## Publicação

1. Publique o commit que contém o serviço, a migration, as rotas, a interface e `vendor/`.
2. Importe **uma única vez** `database/migrations/003_documentos_ocr.sql` depois da migration 002.
3. Garanta escrita do usuário PHP em `storage/documentos` e `storage/tmp/ocr`; o serviço cria os diretórios quando possível.
4. Confirme que `storage/` não é um diretório público e que o document root continua sendo `public/`.
5. Faça upload de um CRLV JPG/PNG e de um CRLV PDF, execute a prévia e confirme o status/extração.
6. Teste o download com o dono do veículo e depois com outra conta; a segunda conta deve receber 404.

## Rotas

| Rota | Método | Proteção | Finalidade |
|---|---|---|---|
| `/portal/documentos/salvar` | POST | Sessão + CSRF + veículo do usuário | Guarda original, executa OCR e persiste metadados. |
| `/portal/documentos/api/analisar-ocr` | POST | Sessão + CSRF + limite simples por sessão | Prévia AJAX sem persistir o arquivo de prévia. |
| `/portal/documentos/{id}/baixar` | GET | Sessão + `usuario_id` do documento | Download autorizado de arquivo privado. |

## Referências

[1]: https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html "Tesseract — Command Line Usage"
[2]: https://github.com/thiagoalessio/tesseract-ocr-for-php "Tesseract OCR for PHP"
[3]: https://manpages.debian.org/experimental/poppler-utils/pdftoppm.1.en.html "pdftoppm(1) — Poppler"
