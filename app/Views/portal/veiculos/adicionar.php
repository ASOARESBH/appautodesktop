<?php
$pageTitle = 'Adicionar Veículo';
require_once __DIR__ . '/../../layout/portal_header.php';
?>

<div style="display:flex; align-items:center; gap:12px; margin-bottom:24px;">
    <a href="/portal/veiculos" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i></a>
    <div>
        <h4 style="font-weight:700; margin:0;">Adicionar Veículo</h4>
        <p style="color:var(--text-light); font-size:13px; margin:4px 0 0;">Preencha os dados ou consulte a placa automaticamente</p>
    </div>
</div>

<?php if (!empty($erros)): ?>
<div class="alert alert-danger mb-4">
    <i class="fas fa-exclamation-circle"></i>
    <ul style="margin:0; padding-left:18px;">
        <?php foreach ($erros as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Consulta de placa + OCR -->
<div class="card mb-4">
    <div class="card-header">
        <span class="card-title-sm"><i class="fas fa-search me-2"></i>Consulta Automática</span>
    </div>
    <div class="card-body">
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <!-- Consulta por placa -->
            <div>
                <label class="form-label">Consultar por Placa</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="inputPlacaBusca" class="form-control" placeholder="ABC1D23 ou ABC-1234" maxlength="8"
                           style="font-family:monospace; font-size:16px; font-weight:700; text-transform:uppercase; letter-spacing:2px;">
                    <button type="button" class="btn btn-primary" onclick="consultarPlaca()" id="btnConsultar">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div id="infoFormatoPlaca" style="margin-top:6px; font-size:12px; color:var(--text-light);"></div>
                <div id="resultadoPlaca" style="margin-top:10px;"></div>
            </div>
            <!-- OCR de documento -->
            <div>
                <label class="form-label">Ler Documento (CRLV) via OCR</label>
                <div id="dropZoneOCR" style="border:2px dashed var(--border); border-radius:8px; padding:20px; text-align:center; cursor:pointer; transition:border-color .2s;"
                     onclick="document.getElementById('inputOCR').click()">
                    <i class="fas fa-camera" style="font-size:24px; color:var(--text-light); display:block; margin-bottom:8px;"></i>
                    <span style="font-size:13px; color:var(--text-light);">Clique ou arraste a foto do CRLV</span>
                    <input type="file" id="inputOCR" accept="image/*" style="display:none" onchange="processarOCR(this)">
                </div>
                <div id="previewOCR" class="hidden" style="margin-top:8px;">
                    <img id="imgPreviewOCR" style="max-height:80px; border-radius:6px;">
                </div>
                <div id="statusOCR" style="margin-top:8px; display:none;"></div>
            </div>
        </div>
    </div>
</div>

<!-- Formulário principal -->
<form method="POST" action="/portal/veiculos/adicionar" enctype="multipart/form-data" id="formVeiculo">
    <?php echo \App\Core\View::csrfField(); ?>

    <div class="card mb-4">
        <div class="card-header">
            <span class="card-title-sm"><i class="fas fa-id-card me-2"></i>Identificação</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Placa <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="placa" id="campoPlaca" class="form-control"
                           value="<?php echo htmlspecialchars($dados['placa'] ?? ''); ?>"
                           placeholder="ABC1D23 ou ABC-1234" maxlength="8" required
                           style="font-family:monospace; font-size:16px; font-weight:700; text-transform:uppercase; letter-spacing:2px;"
                           oninput="detectarFormato(this.value, 'infoFormatoForm')">
                    <div id="infoFormatoForm" style="margin-top:4px; font-size:12px;"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">RENAVAM</label>
                    <input type="text" name="renavam" id="campoRenavam" class="form-control"
                           value="<?php echo htmlspecialchars($dados['renavam'] ?? ''); ?>"
                           placeholder="11 dígitos" maxlength="11">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Chassi</label>
                    <input type="text" name="chassi" id="campoChassi" class="form-control"
                           value="<?php echo htmlspecialchars($dados['chassi'] ?? ''); ?>"
                           placeholder="17 caracteres" maxlength="17" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label class="form-label">Cor</label>
                    <select name="cor" id="campoCor" class="form-control">
                        <option value="">Selecione...</option>
                        <?php foreach (['Branca','Preta','Prata','Cinza','Vermelha','Azul','Verde','Amarela','Laranja','Marrom','Bege','Vinho','Dourada','Rosa','Roxa','Outra'] as $cor): ?>
                        <option value="<?php echo $cor; ?>" <?php echo ($dados['cor'] ?? '') === $cor ? 'selected' : ''; ?>><?php echo $cor; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <span class="card-title-sm"><i class="fas fa-car me-2"></i>Dados do Veículo</span>
        </div>
        <div class="card-body">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Marca</label>
                    <input type="text" name="marca" id="campoMarca" class="form-control"
                           value="<?php echo htmlspecialchars($dados['marca'] ?? ''); ?>" placeholder="Ex: Volkswagen">
                </div>
                <div class="form-group">
                    <label class="form-label">Modelo</label>
                    <input type="text" name="modelo" id="campoModelo" class="form-control"
                           value="<?php echo htmlspecialchars($dados['modelo'] ?? ''); ?>" placeholder="Ex: Gol 1.0">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Versão / Submodelo</label>
                    <input type="text" name="versao" id="campoVersao" class="form-control"
                           value="<?php echo htmlspecialchars($dados['versao'] ?? ''); ?>" placeholder="Ex: Trend">
                </div>
                <div class="form-group">
                    <label class="form-label">Combustível</label>
                    <select name="combustivel" id="campoCombustivel" class="form-control">
                        <option value="">Selecione...</option>
                        <?php foreach (['gasolina'=>'Gasolina','etanol'=>'Etanol','flex'=>'Flex','diesel'=>'Diesel','gnv'=>'GNV','eletrico'=>'Elétrico','hibrido'=>'Híbrido'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo ($dados['combustivel'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Ano Fabricação</label>
                    <input type="number" name="ano_fabricacao" id="campoAnoFab" class="form-control"
                           value="<?php echo htmlspecialchars($dados['ano_fabricacao'] ?? ''); ?>"
                           placeholder="<?php echo date('Y'); ?>" min="1900" max="<?php echo date('Y')+1; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Ano Modelo</label>
                    <input type="number" name="ano_modelo" id="campoAnoMod" class="form-control"
                           value="<?php echo htmlspecialchars($dados['ano_modelo'] ?? ''); ?>"
                           placeholder="<?php echo date('Y')+1; ?>" min="1900" max="<?php echo date('Y')+2; ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Categoria</label>
                    <select name="categoria" id="campoCategoria" class="form-control">
                        <option value="">Selecione...</option>
                        <?php foreach (['passeio'=>'Passeio','utilitario'=>'Utilitário','suv'=>'SUV','pickup'=>'Pickup','caminhao'=>'Caminhão','moto'=>'Moto','onibus'=>'Ônibus','van'=>'Van','outro'=>'Outro'] as $v=>$l): ?>
                        <option value="<?php echo $v; ?>" <?php echo ($dados['categoria'] ?? '') === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">KM Atual</label>
                    <input type="number" name="km_atual" class="form-control"
                           value="<?php echo htmlspecialchars($dados['km_atual'] ?? ''); ?>"
                           placeholder="Ex: 45000" min="0">
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Observações</label>
                <textarea name="observacoes" class="form-control" rows="3"
                          placeholder="Anotações sobre o veículo..."><?php echo htmlspecialchars($dados['observacoes'] ?? ''); ?></textarea>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <span class="card-title-sm"><i class="fas fa-images me-2"></i>Fotos</span>
        </div>
        <div class="card-body">
            <input type="file" name="fotos[]" class="form-control" accept="image/*" multiple>
            <p style="font-size:12px; color:var(--text-light); margin-top:6px;">Selecione uma ou mais fotos do veículo (JPG, PNG, WebP)</p>
        </div>
    </div>

    <div style="display:flex; gap:12px; justify-content:flex-end;">
        <a href="/portal/veiculos" class="btn btn-outline">Cancelar</a>
        <button type="submit" class="btn btn-primary" id="btnSalvar">
            <i class="fas fa-save me-1"></i> Salvar Veículo
        </button>
    </div>
</form>

<script>
function detectarFormato(placa, elId) {
    var p = placa.replace(/[^A-Z0-9]/gi,'').toUpperCase();
    var el = document.getElementById(elId);
    if (!el) return;
    if (p.length < 7) { el.innerHTML = ''; return; }
    var isMercosul = /^[A-Z]{3}[0-9][A-Z][0-9]{2}$/.test(p);
    el.innerHTML = isMercosul
        ? '<span style="color:#16a34a; font-weight:600;"><i class="fas fa-check-circle"></i> Placa Mercosul</span>'
        : '<span style="color:#1a56db; font-weight:600;"><i class="fas fa-check-circle"></i> Placa Padrão</span>';
}

function escaparHtml(valor) {
    return String(valor == null ? '' : valor)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function mostrarResultadoConsulta(tipo, mensagem, dados) {
    var icones = {success:'fa-check-circle', warning:'fa-exclamation-triangle', danger:'fa-times-circle', info:'fa-spinner fa-spin'};
    var html = '<div class="alert alert-' + tipo + '"><i class="fas ' + icones[tipo] + '"></i> ' + escaparHtml(mensagem) + '</div>';

    if (dados && !dados.aviso) {
        var itens = [];
        var campos = [
            ['Fonte', dados.fonte], ['Placa', dados.placa_formatada || dados.placa],
            ['Marca', dados.marca], ['Modelo', dados.modelo], ['Versão', dados.versao],
            ['Ano', dados.ano], ['Cor', dados.cor], ['Combustível', dados.combustivel],
            ['Município/UF', [dados.municipio, dados.uf].filter(Boolean).join(' / ')],
            ['Situação', dados.situacao]
        ];
        campos.forEach(function(campo) {
            if (campo[1]) itens.push('<div><strong>' + escaparHtml(campo[0]) + ':</strong> ' + escaparHtml(campo[1]) + '</div>');
        });
        var extras = dados.dados_tecnicos || {};
        Object.keys(extras).forEach(function(chave) {
            if (extras[chave]) itens.push('<div><strong>' + escaparHtml(chave) + ':</strong> ' + escaparHtml(extras[chave]) + '</div>');
        });
        if (dados.fipe && dados.fipe.valor) {
            itens.push('<div><strong>FIPE:</strong> ' + escaparHtml(dados.fipe.valor) + (dados.fipe.referencia ? ' <small>(' + escaparHtml(dados.fipe.referencia) + ')</small>' : '') + '</div>');
        }
        if (itens.length) {
            html += '<div class="card" style="margin-top:10px;"><div class="card-body" style="font-size:12px; display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:6px 16px;">' + itens.join('') + '</div></div>';
        }
    }
    document.getElementById('resultadoPlaca').innerHTML = html;
}

function consultarPlaca() {
    var placa = document.getElementById('inputPlacaBusca').value.replace(/[^A-Z0-9]/gi,'').toUpperCase();
    if (placa.length !== 7) {
        mostrarResultadoConsulta('danger', 'Informe 7 caracteres.');
        return;
    }
    var btn = document.getElementById('btnConsultar');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    mostrarResultadoConsulta('info', 'Consultando fontes disponíveis...');

    fetch('/portal/veiculos/api/consultar-placa?placa=' + encodeURIComponent(placa), {headers:{'Accept':'application/json'}})
        .then(function(r){
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(d){
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i>';
            if (d.erro) {
                mostrarResultadoConsulta('danger', d.erro);
                return;
            }
            if (d.aviso) {
                mostrarResultadoConsulta('warning', d.aviso, d);
                preencherFormulario({placa: d.placa || placa});
                return;
            }
            mostrarResultadoConsulta('success', 'Dados técnicos encontrados. Revise e salve o formulário.', d);
            preencherFormulario(d);
        })
        .catch(function(){
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-search"></i>';
            mostrarResultadoConsulta('danger', 'Erro temporário na consulta. Preencha manualmente.');
        });
}

function selecionarOpcao(id, valor) {
    if (!valor) return;
    var select = document.getElementById(id);
    if (!select) return;
    var esperado = String(valor).toLowerCase();
    for (var i = 0; i < select.options.length; i++) {
        if (select.options[i].value.toLowerCase() === esperado || select.options[i].text.toLowerCase() === esperado) {
            select.selectedIndex = i;
            return;
        }
    }
}

function preencherFormulario(d) {
    if (d.placa)         document.getElementById('campoPlaca').value = d.placa;
    if (d.marca)         document.getElementById('campoMarca').value = d.marca;
    if (d.modelo)        document.getElementById('campoModelo').value = d.modelo;
    if (d.versao)        document.getElementById('campoVersao').value = d.versao;
    if (d.renavam)       document.getElementById('campoRenavam').value = d.renavam;
    if (d.chassi)        document.getElementById('campoChassi').value = d.chassi;
    selecionarOpcao('campoCor', d.cor);
    selecionarOpcao('campoCombustivel', d.combustivel);
    selecionarOpcao('campoCategoria', d.categoria);
    if (d.ano) {
        var anos = String(d.ano).split('/');
        if (anos[0]) document.getElementById('campoAnoFab').value = anos[0].trim();
        if (anos[1]) document.getElementById('campoAnoMod').value = anos[1].trim();
    }
    if (d.ano_fabricacao) document.getElementById('campoAnoFab').value = d.ano_fabricacao;
    if (d.ano_modelo) document.getElementById('campoAnoMod').value = d.ano_modelo;
    document.getElementById('inputPlacaBusca').value = d.placa || '';
    detectarFormato(d.placa || '', 'infoFormatoPlaca');
    detectarFormato(d.placa || '', 'infoFormatoForm');
}

function processarOCR(input) {
    if (!input.files || !input.files[0]) return;
    var reader = new FileReader();
    reader.onload = function(e) {
        document.getElementById('imgPreviewOCR').src = e.target.result;
        document.getElementById('previewOCR').classList.remove('hidden');
    };
    reader.readAsDataURL(input.files[0]);
    var status = document.getElementById('statusOCR');
    status.style.display = 'block';
    status.className = 'alert alert-info';
    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando OCR...';
    var fd = new FormData();
    fd.append('imagem', input.files[0]);
    fd.append('_csrf', document.querySelector('input[name="_csrf"]').value);
    fetch('/portal/veiculos/api/ocr', {method:'POST', body:fd})
        .then(function(r){return r.json();})
        .then(function(d){
            if (d.placa||d.renavam||d.chassi) {
                status.className='alert alert-success';
                status.innerHTML='<i class="fas fa-check-circle"></i> OCR concluído! Dados preenchidos.';
                preencherFormulario(d);
            } else {
                status.className='alert alert-warning';
                status.innerHTML='<i class="fas fa-exclamation-triangle"></i> ' + (d.aviso||'Preencha manualmente.');
            }
        })
        .catch(function(){
            status.className='alert alert-danger';
            status.innerHTML='<i class="fas fa-times-circle"></i> Erro no OCR.';
        });
}

var dz = document.getElementById('dropZoneOCR');
dz.addEventListener('dragover',function(e){e.preventDefault();this.style.borderColor='var(--primary)';});
dz.addEventListener('dragleave',function(){this.style.borderColor='var(--border)';});
dz.addEventListener('drop',function(e){
    e.preventDefault(); this.style.borderColor='var(--border)';
    var f=e.dataTransfer.files;
    if(f.length>0){document.getElementById('inputOCR').files=f;processarOCR(document.getElementById('inputOCR'));}
});

document.getElementById('formVeiculo').addEventListener('submit',function(){
    var btn=document.getElementById('btnSalvar');
    btn.disabled=true;
    btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Salvando...';
});
</script>

<?php require_once __DIR__ . '/../../layout/portal_footer.php'; ?>
