<?php
$pageTitle = 'Enviar Documento';
require_once __DIR__ . '/../../layout/portal_header.php';
$erro = (string)($_GET['erro'] ?? '');
$mensagensErro = [
    'csrf' => 'Sua sessão de envio expirou. Atualize a página e tente novamente.',
    'tipo' => 'Selecione um tipo de documento válido.',
    'upload' => 'Não foi possível salvar o documento. Verifique o arquivo e tente novamente.',
];
?>

<div class="d-flex align-items-center gap-3 mb-4">
    <a href="/portal/documentos" class="btn btn-outline-secondary btn-sm" aria-label="Voltar aos documentos"><i class="fas fa-arrow-left"></i></a>
    <div>
        <h4 class="fw-bold mb-0"><i class="fas fa-file-shield me-2" style="color:#8b5cf6"></i>Enviar Documento</h4>
        <small class="text-muted">CRLV e CNH podem ser lidos localmente antes do envio.</small>
    </div>
</div>

<?php if (isset($mensagensErro[$erro])): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($mensagensErro[$erro], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="stat-card h-100">
            <form id="documentoForm" method="POST" action="/portal/documentos/salvar" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" id="csrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="tipoDocumento">Tipo de Documento *</label>
                        <select name="tipo" id="tipoDocumento" class="form-select" required>
                            <option value="crlv">CRLV</option>
                            <option value="cnh">CNH</option>
                            <option value="seguro">Apólice de Seguro</option>
                            <option value="nota_fiscal">Nota Fiscal</option>
                            <option value="laudo_cautelar">Laudo / Vistoria</option>
                            <option value="ipva">IPVA / Licenciamento</option>
                            <option value="outro">Outros</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="tituloDocumento">Título</label>
                        <input type="text" id="tituloDocumento" name="titulo" class="form-control" maxlength="150" placeholder="Ex.: CRLV 2025">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="arquivoDocumento">Arquivo *</label>
                        <input type="file" id="arquivoDocumento" name="arquivo" class="form-control" accept="application/pdf,image/jpeg,image/png,.pdf,.jpg,.jpeg,.png" required>
                        <small class="text-muted">PDF, JPG ou PNG. Tamanho máximo: 10 MB. O arquivo é armazenado fora da área pública.</small>
                    </div>
                    <div class="col-12" id="aplicarDadosBox">
                        <input type="hidden" name="aplicar_dados_veiculo" value="0">
                        <div class="form-check border rounded p-3 bg-light">
                            <input class="form-check-input" type="checkbox" id="aplicarDadosVeiculo" name="aplicar_dados_veiculo" value="1" checked>
                            <label class="form-check-label" for="aplicarDadosVeiculo">
                                <strong>Aplicar dados técnicos ao veículo ativo após salvar</strong><br>
                                <small class="text-muted">Para CRLV, placa, RENAVAM, chassi, anos, cor e local de emplacamento serão atualizados apenas quando identificados.</small>
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="observacaoDocumento">Observações</label>
                        <textarea id="observacaoDocumento" name="observacao" class="form-control" rows="3" maxlength="500" placeholder="Informações adicionais sobre este documento"></textarea>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="button" id="btnAnalisarOcr" class="btn btn-outline-primary"><i class="fas fa-magic me-2"></i>Analisar com OCR</button>
                    <button type="submit" class="btn px-4 text-white" style="background:#8b5cf6"><i class="fas fa-lock me-2"></i>Salvar Documento</button>
                    <a href="/portal/documentos" class="btn btn-outline-secondary">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="stat-card h-100">
            <h6 class="fw-bold"><i class="fas fa-robot me-2" style="color:#8b5cf6"></i>Prévia de leitura OCR</h6>
            <p class="small text-muted">A análise é executada no servidor com Tesseract em português. Sempre revise os dados antes de salvar.</p>
            <div id="ocrResultado" class="alert alert-light border mb-0" role="status">
                Selecione CRLV ou CNH e escolha um arquivo para iniciar a análise.
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var tipo = document.getElementById('tipoDocumento');
    var arquivo = document.getElementById('arquivoDocumento');
    var botao = document.getElementById('btnAnalisarOcr');
    var resultado = document.getElementById('ocrResultado');
    var aplicarBox = document.getElementById('aplicarDadosBox');
    var titulo = document.getElementById('tituloDocumento');

    function escapar(valor) {
        return String(valor == null ? '' : valor)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function atualizarTipo() {
        var compativel = tipo.value === 'crlv' || tipo.value === 'cnh';
        botao.disabled = !compativel;
        aplicarBox.style.display = tipo.value === 'crlv' ? '' : 'none';
        if (!compativel) {
            resultado.className = 'alert alert-light border mb-0';
            resultado.textContent = 'OCR local está disponível para CRLV e CNH. Os demais tipos serão armazenados sem leitura automática.';
        }
    }

    function mostrar(tipoAlerta, mensagem, dados, confidence) {
        var html = '<div class="alert alert-' + tipoAlerta + ' mb-2"><i class="fas ' + (tipoAlerta === 'success' ? 'fa-check-circle' : tipoAlerta === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle') + ' me-2"></i>' + escapar(mensagem) + '</div>';
        if (confidence !== null && confidence !== undefined) {
            html += '<p class="small mb-2"><strong>Confiança média:</strong> ' + escapar(Number(confidence).toFixed(2)) + '%</p>';
        }
        var camposPessoais = ['cpf', 'proprietario_documento', 'proprietario_nome', 'nome'];
        var itens = [];
        Object.keys(dados || {}).forEach(function (chave) {
            if (!dados[chave] || camposPessoais.indexOf(chave) !== -1) return;
            itens.push('<li><strong>' + escapar(chave.replace(/_/g, ' ')) + ':</strong> ' + escapar(dados[chave]) + '</li>');
        });
        if (itens.length) {
            html += '<ul class="small mb-2 ps-3">' + itens.join('') + '</ul>';
        }
        if (Object.keys(dados || {}).some(function (chave) { return camposPessoais.indexOf(chave) !== -1 && dados[chave]; })) {
            html += '<p class="small text-muted mb-0"><i class="fas fa-lock me-1"></i>Campos de identificação pessoal foram extraídos e ficam restritos ao documento salvo.</p>';
        }
        resultado.className = 'mb-0';
        resultado.innerHTML = html;
    }

    botao.addEventListener('click', function () {
        if (!arquivo.files || !arquivo.files[0]) {
            mostrar('warning', 'Selecione um documento antes de iniciar a análise.', {});
            return;
        }
        var dados = new FormData();
        dados.append('arquivo', arquivo.files[0]);
        dados.append('tipo', tipo.value);
        dados.append('csrf_token', document.getElementById('csrfToken').value);
        botao.disabled = true;
        botao.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Analisando...';
        resultado.className = 'alert alert-info mb-0';
        resultado.textContent = 'Convertendo e lendo o documento localmente. Isso pode levar alguns segundos.';

        fetch('/portal/documentos/api/analisar-ocr', {method: 'POST', body: dados, headers: {'Accept': 'application/json'}})
            .then(function (resposta) { return resposta.json().then(function (json) { return {ok: resposta.ok, json: json}; }); })
            .then(function (resposta) {
                var dadosRetorno = resposta.json || {};
                if (!resposta.ok || !dadosRetorno.success) {
                    mostrar(dadosRetorno.status === 'parcial' ? 'warning' : 'warning', dadosRetorno.message || 'Não foi possível concluir a leitura.', dadosRetorno.dados || {}, dadosRetorno.confidence);
                    return;
                }
                mostrar(dadosRetorno.status === 'sucesso' ? 'success' : 'warning', dadosRetorno.message, dadosRetorno.dados || {}, dadosRetorno.confidence);
                if (!titulo.value && tipo.value === 'crlv') titulo.value = 'CRLV ' + new Date().getFullYear();
                if (!titulo.value && tipo.value === 'cnh') titulo.value = 'CNH';
            })
            .catch(function () {
                mostrar('warning', 'Não foi possível comunicar com o OCR. Você ainda pode salvar e preencher manualmente.', {});
            })
            .finally(function () {
                botao.innerHTML = '<i class="fas fa-magic me-2"></i>Analisar com OCR';
                atualizarTipo();
            });
    });

    tipo.addEventListener('change', atualizarTipo);
    atualizarTipo();
}());
</script>

<?php require_once __DIR__ . '/../../layout/portal_footer.php'; ?>
