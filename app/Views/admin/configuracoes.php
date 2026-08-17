<?php require_once dirname(__DIR__) . '/layout/app_header.php'; ?>

<style>
    .integration-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }
    .integration-card {
        border: 1px solid var(--border, #e5e7eb);
        border-radius: 12px;
        padding: 18px;
        background: #fff;
        min-height: 220px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    .integration-card h3 { margin: 0; font-size: 17px; }
    .integration-card p { margin: 0; color: var(--text-muted, #64748b); line-height: 1.5; }
    .integration-meta { display: flex; justify-content: space-between; gap: 8px; align-items: center; }
    .integration-vars { font-family: monospace; font-size: 11px; color: #475569; background: #f8fafc; border-radius: 8px; padding: 9px; word-break: break-word; }
    .integration-note { font-size: 12px; color: #64748b; margin-top: auto !important; }
    .test-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .test-grid .full { grid-column: 1 / -1; }
    .test-actions { display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin-top: 14px; }
    #integration-result { display:none; margin-top: 16px; white-space: pre-wrap; word-break: break-word; font-family: monospace; font-size: 12px; padding: 14px; border-radius: 8px; background:#0f172a; color:#e2e8f0; max-height: 360px; overflow:auto; }
    #integration-result.ok { border-left: 4px solid #22c55e; }
    #integration-result.error { border-left: 4px solid #ef4444; }
    @media (max-width: 720px) {
        .test-grid { grid-template-columns: 1fr; }
        .test-grid .full { grid-column: auto; }
        .integration-card { min-height: auto; }
    }
</style>

<div class="flex justify-between items-center mb-4" style="gap:16px; flex-wrap:wrap;">
    <div>
        <div class="text-muted text-sm"><a href="/admin/dashboard">Admin</a> / Configurações</div>
        <h1 style="margin:6px 0 0;">Integrações automotivas</h1>
        <p class="text-muted" style="margin:6px 0 0;">Central de diagnóstico para FIPE, OCR, leitura de placa, VIN e fontes brasileiras.</p>
    </div>
    <a href="/admin/dashboard" class="btn btn-outline"><i class="fa fa-arrow-left"></i> Voltar ao dashboard</a>
</div>

<div class="card" style="margin-bottom:20px; border-left:4px solid #2563eb;">
    <div class="card-header"><span class="card-title-sm"><i class="fa fa-shield"></i> Configuração segura</span></div>
    <p style="margin:0; line-height:1.6;">As chaves não são editadas nem exibidas neste painel. Configure-as no arquivo <code>.env</code> fora do document root e use os testes abaixo para validar a conectividade. Os resultados são sanitizados e não registram placa, VIN, imagem ou segredo em log.</p>
</div>

<div class="integration-grid">
    <?php foreach (($catalogo ?? []) as $integracao): ?>
        <?php
            $configurado = (bool)($integracao['configurado'] ?? false);
            $status = (string)($integracao['status'] ?? 'configurado');
            $classe = $configurado ? 'badge-success' : ($status === 'cliente' ? 'badge-info' : 'badge-warning');
            $rotulo = $configurado ? ($status === 'cliente' ? 'Cliente' : 'Configurado') : 'Pendente';
        ?>
        <article class="integration-card">
            <div class="integration-meta">
                <h3><?php echo htmlspecialchars((string)($integracao['nome'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h3>
                <span class="badge <?php echo $classe; ?>"><?php echo $rotulo; ?></span>
            </div>
            <div class="text-sm text-muted"><i class="fa fa-tag"></i> <?php echo htmlspecialchars((string)($integracao['grupo'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
            <p><?php echo htmlspecialchars((string)($integracao['descricao'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if (!empty($integracao['variaveis'])): ?>
                <div class="integration-vars"><?php echo htmlspecialchars(implode("\n", $integracao['variaveis']), ENT_QUOTES, 'UTF-8'); ?></div>
            <?php else: ?>
                <div class="integration-vars">Sem chave de servidor</div>
            <?php endif; ?>
            <p class="integration-note"><?php echo htmlspecialchars((string)($integracao['observacao'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
        </article>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header">
        <span class="card-title-sm"><i class="fa fa-flask"></i> Testar integração</span>
    </div>
    <p class="text-muted text-sm" style="margin-top:0; line-height:1.5;">Use arquivos de teste sem dados pessoais reais. Para PixLab, informe uma URL HTTPS temporária da imagem; o provedor não recebe o arquivo diretamente neste fluxo.</p>

    <form id="integration-test-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($csrf ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        <div class="test-grid">
            <div class="form-group">
                <label for="provedor">Integração</label>
                <select name="provedor" id="provedor" class="form-control" required>
                    <option value="fipe">FIPE / Parallelum</option>
                    <option value="mlkit">Google ML Kit</option>
                    <option value="cloudmersive">Cloudmersive</option>
                    <option value="ocrspace">OCR.Space</option>
                    <option value="pixlab">PixLab</option>
                    <option value="vpic">NHTSA vPIC (VIN)</option>
                    <option value="placa">Fontes brasileiras de placa</option>
                </select>
            </div>
            <div class="form-group">
                <label for="modo">Modo do teste OCR</label>
                <select name="modo" id="modo" class="form-control">
                    <option value="documento">OCR de documento</option>
                    <option value="placa">Leitura de placa</option>
                </select>
            </div>
            <div class="form-group">
                <label for="placa">Placa para consulta</label>
                <input type="text" name="placa" id="placa" class="form-control" placeholder="ABC1234 ou ABC1D23" maxlength="8">
            </div>
            <div class="form-group">
                <label for="vin">VIN para vPIC</label>
                <input type="text" name="vin" id="vin" class="form-control" placeholder="17 caracteres" maxlength="17">
            </div>
            <div class="form-group">
                <label for="marca">Marca para FIPE</label>
                <input type="text" name="marca" id="marca" class="form-control" placeholder="Ex.: Volkswagen">
            </div>
            <div class="form-group">
                <label for="modelo">Modelo para FIPE</label>
                <input type="text" name="modelo" id="modelo" class="form-control" placeholder="Ex.: Gol 1.0">
            </div>
            <div class="form-group">
                <label for="ano_modelo">Ano modelo para FIPE</label>
                <input type="number" name="ano_modelo" id="ano_modelo" class="form-control" min="1900" max="2100" placeholder="Ex.: 2020">
            </div>
            <div class="form-group">
                <label for="tipo_veiculo">Tipo FIPE</label>
                <select name="tipo_veiculo" id="tipo_veiculo" class="form-control">
                    <option value="carros">Carros</option>
                    <option value="motos">Motos</option>
                    <option value="caminhoes">Caminhões</option>
                </select>
            </div>
            <div class="form-group full">
                <label for="imagem">Imagem para Cloudmersive/OCR.Space</label>
                <input type="file" name="imagem" id="imagem" class="form-control" accept="image/jpeg,image/png,image/bmp,application/pdf">
            </div>
            <div class="form-group full">
                <label for="imagem_url">URL HTTPS da imagem para OCR.Space/PixLab</label>
                <input type="url" name="imagem_url" id="imagem_url" class="form-control" placeholder="https://...">
            </div>
        </div>
        <div class="test-actions">
            <button type="submit" class="btn btn-primary" id="btn-testar"><i class="fa fa-plug"></i> Executar teste</button>
            <span class="text-muted text-sm">O teste pode demorar alguns segundos conforme o provedor.</span>
        </div>
    </form>
    <pre id="integration-result" aria-live="polite"></pre>
</div>

<div class="card" style="margin-top:20px;">
    <div class="card-header"><span class="card-title-sm"><i class="fa fa-code"></i> Variáveis previstas</span></div>
    <div class="table-responsive">
        <table>
            <thead><tr><th>Grupo</th><th>Variáveis</th><th>Uso</th></tr></thead>
            <tbody>
                <tr><td>FIPE</td><td><code>FIPE_AUTO_ENRICH</code></td><td>Ativa enriquecimento FIPE automático após consulta de placa com marca/modelo/ano.</td></tr>
                <tr><td>Cloudmersive</td><td><code>CLOUDMERSIVE_API_KEY</code>, <code>CLOUDMERSIVE_BASE_URL</code></td><td>Leitura de placas e detecção de texto.</td></tr>
                <tr><td>OCR.Space</td><td><code>OCRSPACE_API_KEY</code>, <code>OCRSPACE_ENDPOINT</code>, <code>OCRSPACE_URL_ENDPOINT</code>, <code>OCRSPACE_LANGUAGE</code></td><td>Fallback de OCR de imagens/PDFs e URL HTTPS.</td></tr>
                <tr><td>PixLab</td><td><code>PIXLAB_API_KEY</code>, <code>PIXLAB_OCR_ENDPOINT</code>, <code>PIXLAB_LANGUAGE</code></td><td>OCR por URL HTTPS, preferencialmente em arquivo temporário protegido.</td></tr>
                <tr><td>Orquestração OCR</td><td><code>OCR_PROVIDER_ORDER</code></td><td>Ordem dos provedores, por exemplo <code>cloudmersive,ocrspace,pixlab</code>.</td></tr>
                <tr><td>Android</td><td><code>ML_KIT_ENABLED</code></td><td>Indicador documental; a chamada ocorre no aplicativo Android, não no PHP.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const form = document.getElementById('integration-test-form');
    const button = document.getElementById('btn-testar');
    const result = document.getElementById('integration-result');
    if (!form || !button || !result) return;

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        button.disabled = true;
        button.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Testando...';
        result.style.display = 'block';
        result.className = '';
        result.textContent = 'Executando teste...';

        try {
            const response = await fetch('/admin/configuracoes/integracoes/testar', {
                method: 'POST',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form),
                credentials: 'same-origin'
            });
            const data = await response.json();
            result.className = data.success ? 'ok' : 'error';
            result.textContent = JSON.stringify(data, null, 2);
        } catch (error) {
            result.className = 'error';
            result.textContent = 'Falha de comunicação com o painel. Tente novamente.';
        } finally {
            button.disabled = false;
            button.innerHTML = '<i class="fa fa-plug"></i> Executar teste';
        }
    });
})();
</script>

<?php require_once dirname(__DIR__) . '/layout/app_footer.php'; ?>
