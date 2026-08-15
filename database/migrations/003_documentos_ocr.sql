-- ============================================================
-- AppAuto SaaS — Migration 003: Documentos privados e OCR local
-- Compatível com MySQL 5.7 / MariaDB / Hostgator
-- Execute UMA única vez, após 002_portal_veiculos.sql.
-- ============================================================

SET NAMES utf8;
SET time_zone = '+00:00';

-- O portal atual associa documentos a veículo. A nulidade permite
-- evolução futura para CNH vinculada somente ao usuário, pois ainda
-- não existe tabela de motoristas no schema AppAuto.
ALTER TABLE veiculo_documentos
    MODIFY veiculo_id INT(11) UNSIGNED NULL,
    MODIFY tipo ENUM(
        'crlv','cnh','seguro','manual','nota_fiscal',
        'financiamento','garantia','recibo','contrato_compra',
        'laudo_cautelar','ipva','outro'
    ) NOT NULL DEFAULT 'outro',
    ADD COLUMN arquivo_nome_original VARCHAR(255) NULL AFTER arquivo,
    ADD COLUMN arquivo_mime VARCHAR(100) NULL AFTER arquivo_nome_original,
    ADD COLUMN arquivo_extensao VARCHAR(10) NULL AFTER arquivo_mime,
    ADD COLUMN arquivo_tamanho INT(11) UNSIGNED NOT NULL DEFAULT 0 AFTER arquivo_extensao,
    ADD COLUMN ocr_texto_bruto LONGTEXT NULL AFTER observacao,
    ADD COLUMN ocr_confianca DECIMAL(5,2) NULL AFTER ocr_texto_bruto,
    ADD COLUMN ocr_dados LONGTEXT NULL AFTER ocr_confianca,
    ADD COLUMN status_ocr ENUM('pendente','processando','sucesso','parcial','erro','indisponivel') NOT NULL DEFAULT 'pendente' AFTER ocr_dados,
    ADD COLUMN ocr_erro VARCHAR(255) NULL AFTER status_ocr,
    ADD COLUMN ocr_processado_em DATETIME NULL AFTER ocr_erro,
    ADD KEY idx_documentos_ocr_status (usuario_id, status_ocr),
    ADD KEY idx_documentos_tipo (usuario_id, tipo);

-- Observação: o texto JSON é armazenado em LONGTEXT propositalmente.
-- Isso mantém compatibilidade com MySQL 5.7 e instalações MariaDB da Hostgator
-- sem depender de diferenças de implementação do tipo JSON.
