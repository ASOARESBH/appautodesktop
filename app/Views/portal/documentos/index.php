<?php
$pageTitle = 'Documentos';
require_once __DIR__ . '/../../layout/portal_header.php';
$tipos = [
    'crlv'=>'CRLV', 'cnh'=>'CNH', 'seguro'=>'Seguro', 'nota_fiscal'=>'Nota Fiscal',
    'laudo_cautelar'=>'Laudo', 'ipva'=>'IPVA', 'manual'=>'Manual', 'outro'=>'Outros'
];
$icones = [
    'crlv'=>'fas fa-id-card', 'cnh'=>'fas fa-id-badge', 'seguro'=>'fas fa-shield-alt',
    'nota_fiscal'=>'fas fa-receipt', 'laudo_cautelar'=>'fas fa-file-medical',
    'ipva'=>'fas fa-file-invoice-dollar', 'outro'=>'fas fa-file-alt'
];
$statusOcr = [
    'sucesso' => ['Leitura concluída', 'success', 'fa-check-circle'],
    'parcial' => ['Leitura parcial', 'warning', 'fa-exclamation-triangle'],
    'pendente' => ['Aguardando leitura', 'secondary', 'fa-clock'],
    'processando' => ['Processando', 'info', 'fa-spinner'],
    'indisponivel' => ['OCR indisponível', 'secondary', 'fa-tools'],
    'erro' => ['Leitura não concluída', 'danger', 'fa-times-circle'],
];
$ocrStatus = (string)($_GET['ocr'] ?? '');
?>

<div class="d-flex justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-folder-open me-2" style="color:#8b5cf6"></i>Documentos do Veículo</h4>
        <small class="text-muted">Arquivos novos ficam protegidos e só podem ser baixados pelo proprietário.</small>
    </div>
    <a href="/portal/documentos/adicionar" class="btn text-white" style="background:#8b5cf6"><i class="fas fa-upload me-2"></i>Enviar Documento</a>
</div>

<?php if ($ocrStatus !== ''): ?>
    <div class="alert alert-<?= $ocrStatus === 'sucesso' ? 'success' : ($ocrStatus === 'parcial' ? 'warning' : 'info') ?>">
        <i class="fas fa-<?= $ocrStatus === 'sucesso' ? 'check-circle' : 'info-circle' ?> me-2"></i>
        <?= $ocrStatus === 'sucesso' ? 'Documento salvo e OCR concluído.' : 'Documento salvo. Revise o status da leitura abaixo.' ?>
    </div>
<?php endif; ?>

<?php if (empty($documentos)): ?>
    <div class="stat-card text-center py-5">
        <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
        <h5 class="text-muted">Nenhum documento cadastrado</h5>
        <a href="/portal/documentos/adicionar" class="btn mt-2 text-white" style="background:#8b5cf6">Enviar Documento</a>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($documentos as $d):
            $icone = $icones[$d['tipo']] ?? 'fas fa-file-alt';
            $label = $tipos[$d['tipo']] ?? ucfirst((string)$d['tipo']);
            $status = $statusOcr[$d['status_ocr'] ?? 'pendente'] ?? $statusOcr['pendente'];
            $ehArquivoLegado = str_starts_with((string)($d['arquivo'] ?? ''), '/assets/');
            $linkArquivo = $ehArquivoLegado
                ? (string)$d['arquivo']
                : '/portal/documentos/' . (int)$d['id'] . '/baixar';
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="stat-card h-100">
                <div class="d-flex align-items-start gap-3">
                    <div style="width:44px;height:44px;background:#f3f0ff;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="<?= htmlspecialchars($icone, ENT_QUOTES, 'UTF-8') ?>" style="color:#8b5cf6;font-size:1.1rem"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-bold text-truncate"><?= htmlspecialchars($d['titulo'] ?: $label, ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="badge" style="background:#f3f0ff;color:#8b5cf6;font-size:.7rem"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if (($d['status_ocr'] ?? '') !== ''): ?>
                            <span class="badge bg-<?= htmlspecialchars($status[1], ENT_QUOTES, 'UTF-8') ?>" style="font-size:.7rem"><i class="fas <?= htmlspecialchars($status[2], ENT_QUOTES, 'UTF-8') ?> me-1"></i><?= htmlspecialchars($status[0], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($d['ocr_confianca'])): ?>
                            <div class="text-muted" style="font-size:.7rem">Confiança OCR: <?= number_format((float)$d['ocr_confianca'], 1, ',', '.') ?>%</div>
                        <?php endif; ?>
                        <?php if (($d['tamanho_kb'] ?? 0) > 0): ?>
                            <div class="text-muted" style="font-size:.7rem"><?= $d['tamanho_kb'] > 1024 ? round($d['tamanho_kb']/1024, 1).' MB' : (int)$d['tamanho_kb'].' KB' ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:.7rem"><?= !empty($d['criado_em']) ? date('d/m/Y', strtotime($d['criado_em'])) : '' ?></div>
                    </div>
                    <?php if (!empty($d['arquivo'])): ?>
                        <a href="<?= htmlspecialchars($linkArquivo, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-secondary" aria-label="Baixar documento">
                            <i class="fas fa-download"></i>
                        </a>
                    <?php endif; ?>
                </div>
                <?php if (!empty($d['observacao'])): ?>
                    <p class="text-muted small mt-2 mb-0"><?= htmlspecialchars($d['observacao'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../layout/portal_footer.php'; ?>
