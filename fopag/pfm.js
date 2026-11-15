// ============================================================
// PFM (Volantes e Intermitente) - logica unica de fopag/pfm-volantes.html e fopag/pfm-intermitente.html.
// Paginas separadas de proposito (processos diferentes): so o HTML e a constante TIPO_PFM vivem nelas; carga, renderizacao, filtros e exportacao ficam aqui.
// ============================================================
let dadosDasAbas = {
    'PERICULOSIDADE': [],
    'INSALUBRIDADE': [],
    'DIF_SALARIO': []
};
let abaAtivaAgora = 'PERICULOSIDADE';

let filtrosAtivos = {};
let colunasExibidas = [];
let ordenacaoAtual = { coluna: null, tipo: null };
let dadosExibidosAgora = [];

function alternarAba(botaoClicado, nomeAba) {
    abaAtivaAgora = nomeAba;

    document.querySelectorAll('.fopag-indicador').forEach(b => b.classList.remove('ativo'));
    botaoClicado.classList.add('ativo');

    renderizarCabecalhosComFiltros();
}

// Numeros da barra segmentada Periculosidade/Insalubridade/Dif Salario: valor TOTAL gasto em cada aba (soma de rubrica_num, o mesmo numero cru da exportacao), nao a quantidade de colaboradores.
function atualizarQuadrosFopag() {
    const elPericul = document.getElementById('stat-fopag-pericul-num');
    const elInsalub = document.getElementById('stat-fopag-insalub-num');
    const elDifsal = document.getElementById('stat-fopag-difsal-num');
    const somarValores = lista => (lista || []).reduce((soma, d) => soma + (Number(d.rubrica_num) || 0), 0);
    if (elPericul) elPericul.textContent = formatarReal(somarValores(dadosDasAbas['PERICULOSIDADE']));
    if (elInsalub) elInsalub.textContent = formatarReal(somarValores(dadosDasAbas['INSALUBRIDADE']));
    if (elDifsal) elDifsal.textContent = formatarReal(somarValores(dadosDasAbas['DIF_SALARIO']));
}

// ============================================================
// SELETOR DE PERIODO (barra da direita, ao lado de Exportar), em dois passos (ano, depois mes). So mostra ano/mes com cobertura registrada pra esse tipo
// (backend/api/pfm-periodos.php, buscada a cada abertura do dropdown). Sem periodo pre-selecionado: o botao fica com "-" ate o usuario escolher ano+mes,
// o que abre o modal de salario minimo (confirmarSalarioMinimo) e so entao busca os dados (pfm-calculo.php via dbPfmCalcular).
// ============================================================
const MESES_FOPAG = ['Janeiro', 'Fevereiro', 'Marco', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
// TIPO_PFM ('volante' ou 'intermitente') vem da pagina que carrega este
// arquivo - e a unica diferenca de logica entre as duas.

let anoSelecionadoPfm = null;
let mesSelecionadoPfm = null; // 1-12
let passoAtualDropdownPfm = 'ano';
let periodosDisponiveisPfm = []; // [{ano, mes}, ...] - carregado a cada abertura do dropdown

function montarDropdownPeriodoPfm() {
    const dropdown = document.getElementById('fopag-mes-dropdown');
    dropdown.innerHTML = '';

    if (periodosDisponiveisPfm.length === 0) {
        const vazio = document.createElement('div');
        vazio.className = 'fopag-mes-dropdown-titulo';
        vazio.textContent = 'Nenhuma solicitação registrada ainda';
        dropdown.appendChild(vazio);
        return;
    }

    // Titulo FORA da lista que rola (.fopag-mes-lista), filho direto do dropdown, senao um mes rolando vazaria por cima dele. Ver ".fopag-mes-dropdown" no style.css.
    const lista = document.createElement('div');
    lista.className = 'fopag-mes-lista';

    if (passoAtualDropdownPfm === 'ano') {
        const titulo = document.createElement('div');
        titulo.className = 'fopag-mes-dropdown-titulo';
        titulo.textContent = 'Selecione o ano';
        dropdown.appendChild(titulo);

        const anos = [...new Set(periodosDisponiveisPfm.map(p => p.ano))]; // ja vem ordenado (mais recente primeiro) do backend
        anos.forEach(ano => {
            const item = document.createElement('div');
            item.className = 'fopag-mes-item' + (ano === anoSelecionadoPfm ? ' ativo' : '');
            item.textContent = ano;
            item.onclick = () => {
                anoSelecionadoPfm = ano;
                passoAtualDropdownPfm = 'mes';
                montarDropdownPeriodoPfm();
            };
            lista.appendChild(item);
        });
    } else {
        const titulo = document.createElement('div');
        titulo.className = 'fopag-mes-dropdown-titulo clicavel';
        titulo.textContent = `< ${anoSelecionadoPfm}`;
        titulo.onclick = () => { passoAtualDropdownPfm = 'ano'; montarDropdownPeriodoPfm(); };
        dropdown.appendChild(titulo);

        const mesesDoAno = periodosDisponiveisPfm
            .filter(p => p.ano === anoSelecionadoPfm)
            .map(p => p.mes)
            .sort((a, b) => a - b);

        mesesDoAno.forEach(mes => {
            const item = document.createElement('div');
            item.className = 'fopag-mes-item' + (mes === mesSelecionadoPfm ? ' ativo' : '');
            item.textContent = MESES_FOPAG[mes - 1];
            item.onclick = () => selecionarPeriodoPfm(mes);
            lista.appendChild(item);
        });
    }

    dropdown.appendChild(lista);
}

function selecionarPeriodoPfm(mes) {
    mesSelecionadoPfm = mes;
    document.getElementById('fopag-mes-label').textContent = `${MESES_FOPAG[mes - 1]} ${anoSelecionadoPfm}`;
    document.getElementById('fopag-mes-dropdown').classList.remove('mostrando');
    abrirModalSalarioMinimo();
}

async function alternarDropdownMesFopag(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('fopag-mes-dropdown');
    if (dropdown.classList.contains('mostrando')) {
        dropdown.classList.remove('mostrando');
        return;
    }
    passoAtualDropdownPfm = 'ano';
    dropdown.innerHTML = '<div class="fopag-mes-dropdown-titulo">Carregando...</div>';
    dropdown.classList.add('mostrando');
    try {
        periodosDisponiveisPfm = await dbPfmPeriodosDisponiveis(TIPO_PFM);
    } catch (e) {
        periodosDisponiveisPfm = [];
        mostrarToast(e.message || 'Erro ao buscar os períodos disponíveis.', '#e74c3c');
    }
    montarDropdownPeriodoPfm();
}

document.addEventListener('click', (event) => {
    const container = document.querySelector('.fopag-mes-container');
    const dropdown = document.getElementById('fopag-mes-dropdown');
    if (dropdown && dropdown.classList.contains('mostrando') && container && !container.contains(event.target)) {
        dropdown.classList.remove('mostrando');
    }
});

// Clicar no ano reconstroi o dropdown, e o clique some do elemento antes de chegar ao listener de fora, que o tomaria por "clique fora" e fecharia o dropdown. Corta a propagacao.
document.getElementById('fopag-mes-dropdown').addEventListener('click', (event) => {
    event.stopPropagation();
});

// ============================================================
// MODAL "SALARIO MINIMO VIGENTE" - abre a cada periodo novo escolhido (nao fica salvo). O campo vem preenchido com o valor oficial (Banco Central, dbSalarioMinimoAtual);
// o usuario confirma ou corrige. Se a busca falhar, cai pro ultimo valor usado.
// ============================================================
const CHAVE_ULTIMO_SALARIO_MINIMO = 'pfm_ultimo_salario_minimo';

async function abrirModalSalarioMinimo() {
    const input = document.getElementById('input-salario-minimo');
    input.value = localStorage.getItem(CHAVE_ULTIMO_SALARIO_MINIMO) || '';
    input.placeholder = 'Buscando valor vigente...';
    document.getElementById('modal-salario-minimo').style.display = 'flex';
    input.focus();

    try {
        const valorAtual = await dbSalarioMinimoAtual();
        input.value = valorAtual;
        input.placeholder = 'Ex: 1518.00';
    } catch (e) {
        input.placeholder = 'Ex: 1518.00';
        mostrarToast('Não consegui buscar o salário mínimo vigente automaticamente - confira o valor.', '#e74c3c');
    }
}

function fecharModalSalarioMinimo() {
    document.getElementById('modal-salario-minimo').style.display = 'none';
}

async function confirmarSalarioMinimo() {
    const valor = parseFloat(document.getElementById('input-salario-minimo').value);
    if (!valor || valor <= 0) {
        mostrarToast('Informe um salário mínimo válido.', '#e74c3c');
        return;
    }
    localStorage.setItem(CHAVE_ULTIMO_SALARIO_MINIMO, String(valor));
    fecharModalSalarioMinimo();
    await carregarDadosPfm(valor);
}

// ============================================================
// CARGA DOS DADOS (backend/api/pfm-calculo.php): so LE as coberturas de solicitacoes_cobertura do periodo, nunca altera nada; mesmo resultado se rodado de novo.
// ============================================================
async function carregarDadosPfm(salarioMinimo) {
    const tbody = document.getElementById('tbody-dados-pfm');
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:40px; color:#777;">Calculando...</td></tr>`;

    try {
        const resposta = await dbPfmCalcular(anoSelecionadoPfm, mesSelecionadoPfm, TIPO_PFM, salarioMinimo);
        const linhas = resposta.dados || [];

        // "rubrica" (texto "R$ x,xx") e pra tela; "rubrica_num" e o valor cru, pra exportarPlanilhaExcel gerar celula numerica contabil em vez de texto.
        dadosDasAbas['PERICULOSIDADE'] = linhas
            .filter(d => d.valor_peric > 0)
            .map(d => ({
                filial: d.filial, cc: d.cc, mat: d.mat, nome: d.nome, funcao: d.funcao,
                salario: d.salario, qtd_dias: d.dias_peric,
                rubrica: formatarReal(d.valor_peric), rubrica_num: d.valor_peric
            }));

        dadosDasAbas['INSALUBRIDADE'] = linhas
            .filter(d => d.valor_insal > 0)
            .map(d => ({
                filial: d.filial, cc: d.cc, mat: d.mat, nome: d.nome, funcao: d.funcao,
                salario: d.salario, qtd_dias: d.dias_insal_total,
                rubrica: formatarReal(d.valor_insal), rubrica_num: d.valor_insal
            }));

        dadosDasAbas['DIF_SALARIO'] = linhas
            .filter(d => d.valor_dif > 0)
            .map(d => ({
                filial: d.filial, cc: d.cc, mat: d.mat, nome: d.nome, funcao: d.funcao,
                salario: d.salario, rubrica: formatarReal(d.valor_dif), rubrica_num: d.valor_dif
            }));

        atualizarQuadrosFopag();
        renderizarCabecalhosComFiltros();
        mostrarToast('Cálculo do período concluído.', '#27ae60');
        // Ferias do SAF ficaram de fora (arquivo do SAF ausente/invalido/antigo).
        if (resposta.aviso_saf) {
            adicionarNotificacaoUpload(resposta.aviso_saf, '#e67e22');
            mostrarToast(resposta.aviso_saf, '#e67e22');
        }

        // Periculosidade e insalubridade sao mutuamente exclusivos: pfm-calculo.php nao paga nenhum dos dois nos dias com as 2 flags (erro de cadastro no Protheus);
        // aqui so avisa, com notificacao persistente (sino) porque exige acao no Protheus.
        const conflitos = resposta.conflitos_adicional || [];
        if (conflitos.length > 0) {
            const nomes = conflitos.map(c => `${c.titular_nome} (${c.titular_mat}, ${c.data})`).join('; ');
            adicionarNotificacaoUpload(
                `Cadastro inconsistente no Protheus: ${conflitos.length} caso(s) com periculosidade E insalubridade marcadas juntas - nenhum dos dois foi pago nesses dias. Corrija no Protheus: ${nomes}`,
                '#e74c3c'
            );
            mostrarToast(`Atenção: ${conflitos.length} colaborador(es) com cadastro inconsistente (veja o sino).`, '#e74c3c');
        }
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:40px; color:#e74c3c;">${e.message || 'Erro ao calcular o período.'}</td></tr>`;
        mostrarToast(e.message || 'Erro ao calcular o período.', '#e74c3c');
    }
}

function obterNomeColunaExibicao(chave) {
    switch(chave) {
        case 'filial': return 'FILIAL';
        case 'cc': return 'CENTRO DE CUSTO';
        case 'mat': return 'MATRICULA';
        case 'nome': return 'NOME';
        case 'funcao': return 'FUNCAO';
        case 'salario': return 'SALARIO';
        case 'qtd_dias': return 'QUANT. DIAS';
        case 'rubrica': return abaAtivaAgora.replace('_', ' ');
        default: return chave.toUpperCase();
    }
}

// Compartilhada com renderizarTabelaPFM (os <td> do corpo precisam da mesma largura dos <th>).
// Cada coluna tem um PESO (nome/funcao 20, as demais 15 em Periculosidade/Insalubridade; iguais em Diferenca Salarial) e a largura e a fatia desse peso no total das colunas exibidas (soma 100%).
function larguraColunaPfm(col) {
    const abaComDias = abaAtivaAgora === 'PERICULOSIDADE' || abaAtivaAgora === 'INSALUBRIDADE';
    const peso = (c) => (abaComDias && c !== 'nome' && c !== 'funcao') ? 15 : 20;
    const total = colunasExibidas.reduce((soma, c) => soma + peso(c), 0);
    return (peso(col) / total * 100).toFixed(4) + '%';
}

function renderizarCabecalhosComFiltros() {
    const headerRow = document.getElementById('table-header-pfm');
    headerRow.innerHTML = '';

    // Sem carregarFiltrosPersistentes() de proposito: o PFM sempre comeca vazio (sem periodo), e restaurar filtro salvo mostraria o chip "1 filtro ativo" sem dados. filtrosAtivos comeca zerado.
    filtrosAtivos = {};

    if (abaAtivaAgora === 'PERICULOSIDADE' || abaAtivaAgora === 'INSALUBRIDADE') {
        colunasExibidas = ['filial', 'cc', 'mat', 'nome', 'funcao', 'salario', 'qtd_dias', 'rubrica'];
    } else {
        colunasExibidas = ['filial', 'cc', 'mat', 'nome', 'funcao', 'salario', 'rubrica'];
    }

    colunasExibidas.forEach(col => {
        filtrosAtivos[col] = [];
        const nomeExibicao = obterNomeColunaExibicao(col);

        const th = document.createElement('th');
        th.style.width = larguraColunaPfm(col);
        th.innerHTML = `
            <div class="th-content">
                <span>${nomeExibicao}</span>
                <div class="multi-select-container">
                    <div class="multi-select-button" onclick="toggleDropdown('${col}')" title="Filtrar">
                        <span id="label-${col}" class="th-filtro-label-oculto">Todos</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 5h16L14 13v6l-4 2v-8L4 5z"/></svg>
                    </div>
                    <div id="dropdown-${col}" class="multi-select-dropdown">
                        <div class="sort-section">
                            <button class="btn-sort" id="btn-sort-asc-${col}" onclick="aplicarOrdenacao('${col}', 'ASC')">A a Z / Menor para Maior</button>
                            <button class="btn-sort" id="btn-sort-desc-${col}" onclick="aplicarOrdenacao('${col}', 'DESC')">Z a A / Maior para Menor</button>
                        </div>
                        <div class="search-box-container">
                            <input type="text" class="filter-search-input" placeholder="Buscar..." onkeyup="filtrarListaInterna('${col}', this.value)">
                        </div>
                        <div class="dropdown-controls">
                            <button class="btn-mini" onclick="marcarTodos('${col}', true)">Todos</button>
                            <button class="btn-mini" onclick="marcarTodos('${col}', false)">Limpar</button>
                        </div>
                        <div id="items-${col}" class="items-list"></div>
                        <div class="dropdown-aplicar-container">
                            <button class="btn-aplicar-filtro" onclick="confirmarFiltro('${col}')">Aplicar</button>
                        </div>
                        <div class="filtro-resize-handle" title="Arraste para redimensionar"></div>
                    </div>
                </div>
            </div>`;
        headerRow.appendChild(th);
    });

    aplicarLogica();
}

// Chip "N filtros ativos" da toolbar (mesmo modelo de colaboradores/funcionarios.html); so aparece com pelo menos 1 coluna filtrada.
function atualizarBarraFiltrosAtivos() {
    const qtd = Object.values(filtrosAtivos).filter(lista => lista.length > 0).length;
    const container = document.getElementById('toolbar-flutuante-pfm');
    if (!container) return;
    if (qtd > 0) {
        document.getElementById('toolbar-filtros-ativos-texto').textContent =
            qtd === 1 ? '1 filtro ativo' : `${qtd} filtros ativos`;
        container.style.display = 'flex';
    } else {
        container.style.display = 'none';
    }
}

function aplicarLogica() {
    const listaMestraAba = dadosDasAbas[abaAtivaAgora] || [];
    const tbody = document.getElementById('tbody-dados-pfm');

    if (listaMestraAba.length === 0) {
        tbody.innerHTML = '';
        dadosExibidosAgora = [];
        atualizarConteudoDropdowns(listaMestraAba);
        atualizarBarraFiltrosAtivos();
        return;
    }

    let resultado = [...listaMestraAba];

    Object.keys(filtrosAtivos).forEach(col => {
        if (filtrosAtivos[col].length > 0) {
            resultado = resultado.filter(item => filtrosAtivos[col].includes(String(item[col]).trim()));
        }
    });

    if (ordenacaoAtual.coluna && ordenacaoAtual.tipo) {
        const col = ordenacaoAtual.coluna;
        resultado.sort((a, b) => compararParaOrdenacao(a[col], b[col], ordenacaoAtual.tipo));
    }

    dadosExibidosAgora = resultado;
    renderizarTabelaPFM(resultado);
    atualizarConteudoDropdowns(listaMestraAba);
    atualizarBarraFiltrosAtivos();
}

function atualizarConteudoDropdowns(listaMestra) {
    colunasExibidas.forEach(col => {
        const container = document.getElementById(`items-${col}`);
        if (!container) return;

        let filtradosMenu = [...listaMestra];

        Object.keys(filtrosAtivos).forEach(c => {
            if (c !== col && filtrosAtivos[c].length > 0) {
                filtradosMenu = filtradosMenu.filter(item => filtrosAtivos[c].includes(String(item[c]).trim()));
            }
        });

        const unicos = [...new Set(filtradosMenu.map(item => String(item[col]).trim()))].filter(v => v !== "").sort();

        renderizarListaFiltro(col, unicos, container);

        const label = document.getElementById(`label-${col}`);
        const qtd = filtrosAtivos[col] ? filtrosAtivos[col].length : 0;
        label.innerText = qtd === 0 ? "Todos" : (qtd === 1 ? filtrosAtivos[col][0] : `${qtd} selecionados`);
        label.closest('.multi-select-container').classList.toggle('tem-filtro', qtd > 0);
    });
}

function formatarReal(v) {
    if (v === undefined || v === null || v === "") return "R$ 0,00";
    if (v === "-") return "-";

    let num;
    if (typeof v === 'number') {
        num = v;
    } else {
        let limpo = String(v).replace(/[R$\s]/gi, '').trim();
        if (limpo === "") return "R$ 0,00";
        if (limpo.includes(',')) {
            let partes = limpo.split(',');
            let inteiro = partes[0].replace(/\./g, '');
            num = parseFloat(inteiro + '.' + partes[1]);
        } else {
            num = parseFloat(limpo);
        }
    }

    if (isNaN(num)) return "R$ 0,00";
    return num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function renderizarTabelaPFM(dados) {
    const tbody = document.getElementById('tbody-dados-pfm');
    tbody.innerHTML = '';

    if (dados.length === 0) {
        const colQtd = colunasExibidas.length;
        tbody.innerHTML = `<tr><td colspan="${colQtd}" style="text-align:center; padding:40px; color:#777;">Nenhum registro corresponde aos filtros ativados.</td></tr>`;
        return;
    }

    // Monta cada celula via textContent (nao innerHTML): nome/funcao vem do cadastro (Protheus). A largura de cada <td> precisa bater com a do <th> (larguraColunaPfm):
    // cabecalho e corpo sao tabelas separadas, cada uma com table-layout:fixed.
    const criarTd = (texto, coluna, className, styleExtra) => {
        const td = document.createElement('td');
        if (className) td.className = className;
        td.style.cssText = `width: ${larguraColunaPfm(coluna)};` + (styleExtra ? ' ' + styleExtra : '');
        td.textContent = texto;
        return td;
    };

    dados.forEach(dado => {
        const tr = document.createElement('tr');

        tr.appendChild(criarTd(dado.filial, 'filial'));
        tr.appendChild(criarTd(formatarCentroCusto(dado.cc), 'cc', 'alinhado-esquerda', 'word-break: break-word;'));
        tr.appendChild(criarTd(dado.mat, 'mat'));
        tr.appendChild(criarTd(dado.nome, 'nome', 'alinhado-esquerda', 'word-break: break-word;'));
        tr.appendChild(criarTd(dado.funcao, 'funcao', 'alinhado-esquerda', 'word-break: break-word;'));

        if (colunasExibidas.includes('salario')) {
            tr.appendChild(criarTd(formatarReal(dado.salario), 'salario'));
        }

        if (colunasExibidas.includes('qtd_dias')) {
            tr.appendChild(criarTd(dado.qtd_dias, 'qtd_dias'));
        }

        tr.appendChild(criarTd(dado.rubrica, 'rubrica', null, 'color: #006f73; font-weight: bold;'));

        tbody.appendChild(tr);
    });
}

// =========================================================================
// FUNCAO EXPORTAR PLANILHA EXCEL
// =========================================================================
function exportarPlanilhaExcel() {
    const listaPeric = dadosDasAbas['PERICULOSIDADE'] || [];
    const listaInsal = dadosDasAbas['INSALUBRIDADE'] || [];
    const listaDif   = dadosDasAbas['DIF_SALARIO'] || [];

    if (listaPeric.length === 0 && listaInsal.length === 0 && listaDif.length === 0) {
        mostrarToast('Nenhum dado calculado disponivel para exportar.', '#e74c3c');
        return;
    }

    if (!confirm("Tem certeza que deseja exportar a planilha?")) return;

    const livro = XLSX.utils.book_new();

    // Colunas de texto saem como string; SALARIO e as rubricas (PERICULOSIDADE/INSALUBRIDADE/DIF SALARIO) saem como NUMERO, e o formato contabil (R$) e aplicado depois na coluna inteira via aplicarFormatoContabil.
    const paraTexto = v => (v === undefined || v === null) ? "" : String(v);
    const paraNumero = v => (v === undefined || v === null || v === "") ? 0 : Number(v);

    // Formato contabil padrao do Excel pra Real ("R$" a esquerda, numero a direita, negativo entre parenteses).
    const FORMATO_CONTABIL = '_-"R$" * #,##0.00_-;-"R$" * #,##0.00_-;_-"R$" * "-"??_-;_-@_-';

    // Aplica o formato contabil em todas as linhas de dado (a partir da
    // linha 2, ja que a 1 e o cabecalho) de uma coluna especifica.
    function aplicarFormatoContabil(sheet, colunaLetra, totalLinhasDado) {
        for (let linha = 2; linha <= totalLinhasDado + 1; linha++) {
            const endereco = `${colunaLetra}${linha}`;
            if (sheet[endereco]) sheet[endereco].z = FORMATO_CONTABIL;
        }
    }

    // Aba 1: PERICULOSIDADE
    const expPeric = listaPeric.map(d => ({
        "FILIAL": paraTexto(d.filial),
        "CENTRO DE CUSTO": paraTexto(d.cc),
        "MATRICULA": paraTexto(d.mat),
        "NOME": paraTexto(d.nome),
        "FUNCAO": paraTexto(d.funcao),
        "SALARIO": paraNumero(d.salario),
        "QUANT. DIAS": paraTexto(d.qtd_dias),
        "PERICULOSIDADE": paraNumero(d.rubrica_num)
    }));
    const sheetPeric = XLSX.utils.json_to_sheet(
        expPeric.length > 0 ? expPeric : [{"FILIAL":"", "CENTRO DE CUSTO":"", "MATRICULA":"", "NOME":"", "FUNCAO":"", "SALARIO":"", "QUANT. DIAS":"", "PERICULOSIDADE":""}]
    );
    if (expPeric.length > 0) {
        aplicarFormatoContabil(sheetPeric, 'F', expPeric.length); // SALARIO
        aplicarFormatoContabil(sheetPeric, 'H', expPeric.length); // PERICULOSIDADE
    }
    XLSX.utils.book_append_sheet(livro, sheetPeric, "PERICULOSIDADE");

    // Aba 2: INSALUBRIDADE
    const expInsal = listaInsal.map(d => ({
        "FILIAL": paraTexto(d.filial),
        "CENTRO DE CUSTO": paraTexto(d.cc),
        "MATRICULA": paraTexto(d.mat),
        "NOME": paraTexto(d.nome),
        "FUNCAO": paraTexto(d.funcao),
        "SALARIO": paraNumero(d.salario),
        "QUANT. DIAS": paraTexto(d.qtd_dias),
        "INSALUBRIDADE": paraNumero(d.rubrica_num)
    }));
    const sheetInsal = XLSX.utils.json_to_sheet(
        expInsal.length > 0 ? expInsal : [{"FILIAL":"", "CENTRO DE CUSTO":"", "MATRICULA":"", "NOME":"", "FUNCAO":"", "SALARIO":"", "QUANT. DIAS":"", "INSALUBRIDADE":""}]
    );
    if (expInsal.length > 0) {
        aplicarFormatoContabil(sheetInsal, 'F', expInsal.length); // SALARIO
        aplicarFormatoContabil(sheetInsal, 'H', expInsal.length); // INSALUBRIDADE
    }
    XLSX.utils.book_append_sheet(livro, sheetInsal, "INSALUBRIDADE");

    // Aba 3: DIF SALARIO
    const expDif = listaDif.map(d => ({
        "FILIAL": paraTexto(d.filial),
        "CENTRO DE CUSTO": paraTexto(d.cc),
        "MATRICULA": paraTexto(d.mat),
        "NOME": paraTexto(d.nome),
        "FUNCAO": paraTexto(d.funcao),
        "SALARIO": paraNumero(d.salario),
        "DIF SALARIO": paraNumero(d.rubrica_num)
    }));
    const sheetDif = XLSX.utils.json_to_sheet(
        expDif.length > 0 ? expDif : [{"FILIAL":"", "CENTRO DE CUSTO":"", "MATRICULA":"", "NOME":"", "FUNCAO":"", "SALARIO":"", "DIF SALARIO":""}]
    );
    if (expDif.length > 0) {
        aplicarFormatoContabil(sheetDif, 'F', expDif.length); // SALARIO
        aplicarFormatoContabil(sheetDif, 'G', expDif.length); // DIF SALARIO
    }
    XLSX.utils.book_append_sheet(livro, sheetDif, "DIF SALARIO");

    // Gera nome com data atual (Exemplo: Relatorio_Geral_PFM_27-06-2026)
    const hoje = new Date();
    const dataArq = `${String(hoje.getDate()).padStart(2,'0')}-${String(hoje.getMonth()+1).padStart(2,'0')}-${hoje.getFullYear()}`;

    XLSX.writeFile(livro, `Relatorio_Geral_PFM_${dataArq}.xlsx`);
    mostrarToast('Planilha unificada gerada com sucesso!', '#27ae60');
}


window.onload = function() {
    document.getElementById('fopag-mes-label').textContent = '-';
    atualizarQuadrosFopag();
    renderizarCabecalhosComFiltros();
    // So le o cache local de descricoes (sync diario, backend/scripts/sync-protheus-centros-custo.php), em segundo plano; a tabela so e desenhada apos o usuario escolher o periodo.
    garantirDescricoesCentroCusto();
};

window.onclick = function(e) {
    // Controle da Hotbar Superior
    if (!e.target.matches('.hotbar-btn') && !e.target.closest('.hotbar-dropdown-content')) {
        document.querySelectorAll('.hotbar-dropdown-content').forEach(l => l.classList.remove('show'));
    }


    // Controle do Sino e Painel de Alertas
    const painel = document.getElementById('painel-notificacoes');
    const btnSino = document.querySelector('.btn-sino');
    if (painel && painel.classList.contains('show')) {
        if (!painel.contains(e.target) && (!btnSino || !btnSino.contains(e.target))) {
            painel.classList.remove('show');
            if (typeof reposicionarToasts === 'function') reposicionarToasts();
        }
    }
};
