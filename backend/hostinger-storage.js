/* ====================================================================
   HOSTINGER_API_URL - endereco base dos endpoints PHP: sempre a mesma
   origem da pagina (funciona em qualquer dominio). O nome e historico.
*/
const HOSTINGER_API_URL = `${window.location.protocol}//${window.location.host}/backend/api`;

/* Cliente da API (backend/api/dados.php e login.php), usado por todas as paginas. */

// Sessao real emitida pelo servidor no login (db.php, criarSessao/verificarSessao). So existe 1 sessao ativa por usuario:
// um novo login em outro lugar derruba a antiga.
// localStorage (nao sessionStorage): sessionStorage e por aba, e logar de novo derrubaria a sessao da primeira guia;
// localStorage e compartilhado entre guias, entao a 2a guia reaproveita o token.
function tokenSessaoAtual() {
    return localStorage.getItem('sessionToken') || '';
}

// Chamado quando o servidor recusa o token (401: sessao expirada ou derrubada por outro login). Limpa o estado local e volta pro login.
function tratarSessaoInvalida() {
    localStorage.removeItem('logado');
    localStorage.removeItem('sessionToken');
    const jaEstaNoIndex = /(^|\/)index\.html$/.test(window.location.pathname) || window.location.pathname.endsWith('/');
    // Caminho absoluto: o arquivo roda em paginas na raiz e em subpastas.
    if (!jaEstaNoIndex) window.location.href = '/index.html';
}

// Lista todas as linhas de uma tabela. Ex: dbListar('colaboradores')
// "extraQuery" e opcional (ex: "escopo=irrestrito"); o backend pode ignorar conforme o papel (ver dados.php).
async function dbListar(tabela, extraQuery) {
    let url = `${HOSTINGER_API_URL}/dados.php?acao=listar&tabela=${encodeURIComponent(tabela)}`;
    if (extraQuery) url += `&${extraQuery}`;
    const resp = await fetch(url, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao listar "${tabela}" (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

// Ferias do SAF pra tela Previsao: {dados, aviso_saf} (backend/api/previsao-ferias.php).
// consulta: "acao=volantes&id=N"; envio (POST): alteracao de cobertura, mandada ao SAF (acao=alterar).
async function dbPrevisaoFerias(consulta = '', envio = null) {
    const resp = await fetch(`${HOSTINGER_API_URL}/previsao-ferias.php${consulta ? '?' + consulta : ''}`, envio
        ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() }, body: JSON.stringify(envio) }
        : { headers: { 'X-Session-Token': tokenSessaoAtual() } });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao buscar as ferias do SAF (HTTP ${resp.status})`);
    }
    return corpo;
}

// Ferias do SAF do mes atual pra tela Funcionarios: {dados, aviso_saf} (backend/api/ferias-volantes.php).
async function dbFeriasVolantes() {
    const resp = await fetch(`${HOSTINGER_API_URL}/ferias-volantes.php`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao buscar as ferias do SAF (HTTP ${resp.status})`);
    }
    return corpo;
}

// Insere (sem "id"/"matricula") ou atualiza (com "id"/"matricula") um
// registro. Retorna o registro salvo (com o id, se foi criado agora).
async function dbSalvar(tabela, registro) {
    const resp = await fetch(`${HOSTINGER_API_URL}/dados.php?acao=salvar&tabela=${encodeURIComponent(tabela)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify(registro)
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao salvar em "${tabela}" (HTTP ${resp.status})`);
    }
    return corpo;
}

// Salva a Observacao com controle de concorrencia otimista (acao=salvar_observacao_segura em dados.php). Diferente de dbSalvar():
// HTTP 409 aqui e conflito de edicao esperado; {success:false, conflito:true, valor_atual} volta pro chamador decidir.
async function dbSalvarObservacaoSegura(id, valorNovo, valorEsperado) {
    const resp = await fetch(`${HOSTINGER_API_URL}/dados.php?acao=salvar_observacao_segura&tabela=colaboradores`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ id, valor_novo: valorNovo, valor_esperado: valorEsperado })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (resp.status === 409 && corpo.conflito) {
        return corpo;
    }
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao salvar observacao (HTTP ${resp.status})`);
    }
    return corpo;
}

// Remove um ou mais registros pela chave (id, ou matricula em vt_valores).
// Grava varios registros de uma vez. Cada fatia vai numa unica requisicao e numa UMA transacao no servidor: entra tudo ou nada.
// O tamanho da fatia acompanha o limite do backend (LOTE_MAXIMO em dados.php) e o post_max_size do PHP.
const TAMANHO_LOTE = 200;

async function dbSalvarLote(tabela, registros, aoProgredir) {
    if (!Array.isArray(registros) || registros.length === 0) return { ids: [], total: 0 };

    const ids = [];
    for (let inicio = 0; inicio < registros.length; inicio += TAMANHO_LOTE) {
        const fatia = registros.slice(inicio, inicio + TAMANHO_LOTE);

        const resp = await fetch(`${HOSTINGER_API_URL}/dados.php?acao=salvar_lote&tabela=${encodeURIComponent(tabela)}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
            body: JSON.stringify({ registros: fatia })
        });

        if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
        const corpo = await resp.json().catch(() => ({}));
        if (!resp.ok || corpo.error) {
            throw new Error(corpo.error || `Falha ao salvar lote em "${tabela}" (HTTP ${resp.status})`);
        }

        ids.push(...(corpo.ids || []));
        if (typeof aoProgredir === 'function') aoProgredir(ids.length, registros.length);
    }

    return { ids, total: ids.length };
}

// Papel do usuario logado. Sem valor (login antigo) assume 'gestor', o mais restrito.
function papelAtual() {
    return localStorage.getItem('papel') || 'gestor';
}

// Nome de login do usuario logado (ex: "joao.silva"), usado no botao de perfil (filtro.js). '' se a sessao nao tiver.
function usuarioAtual() {
    return localStorage.getItem('usuario') || '';
}

// Outros papeis alem do principal (perfil triplo, alternarPerfilAtivo em db.php); array vazio se so tem um. Usado pelo painel de perfil.
function papeisExtraAtual() {
    try {
        const bruto = JSON.parse(localStorage.getItem('papeisExtra') || '[]');
        return Array.isArray(bruto) ? bruto : [];
    } catch {
        return [];
    }
}

// Papel de CADASTRO (usuarios.papel): capturado no login e nunca sobrescrito por dbAlternarPerfil (papelAtual() muda a cada troca).
// Com papeisExtraAtual() reconstroi o conjunto completo de papeis da pessoa.
function papelPrincipalAtual() {
    return localStorage.getItem('papelPrincipal') || papelAtual();
}

// Administrador tem o mesmo acesso total de RH: souRH() cobre os 2 de proposito. souAdministrador() abaixo
// e so pra unica diferenca real entre os 2 papeis: o botao de Logs.
function souRH() {
    return papelAtual() === 'rh' || papelAtual() === 'administrador';
}

function souAdministrador() {
    return papelAtual() === 'administrador';
}

function souGestor() {
    return papelAtual() === 'gestor';
}

function souPreposto() {
    return papelAtual() === 'preposto';
}

async function dbRemover(tabela, chaves, contexto) {
    const resp = await fetch(`${HOSTINGER_API_URL}/dados.php?acao=remover&tabela=${encodeURIComponent(tabela)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify(contexto ? { chaves, contexto } : { chaves })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao remover de "${tabela}" (HTTP ${resp.status})`);
    }
    return corpo;
}

// Valida usuario/senha direto no servidor (nunca expoe o hash da senha).
// O servidor derruba qualquer sessao anterior do usuario e devolve token novo.
async function dbLogin(usuario, senha) {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ usuario, senha })
    });
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        return { success: false, error: corpo.error || `Falha no login (HTTP ${resp.status})` };
    }
    // Guarda o papel (pra interface esconder/desabilitar), o usuario (avatar/nome) e os papeisExtra (opcao de alternar).
    // papelPrincipal e guardado a parte porque "papel" muda a cada alternancia e este nao pode mudar.
    if (corpo.papel) {
        localStorage.setItem('papel', corpo.papel);
        localStorage.setItem('papelPrincipal', corpo.papel);
    }
    if (corpo.usuario) localStorage.setItem('usuario', corpo.usuario);
    if (Array.isArray(corpo.papeisExtra)) localStorage.setItem('papeisExtra', JSON.stringify(corpo.papeisExtra));
    return corpo;
}

// Troca o papel ativo da sessao (perfil triplo); o servidor recusa papel que a pessoa nao tem.
async function dbAlternarPerfil(papel) {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=alternar_perfil`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ papel })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao alternar o perfil (HTTP ${resp.status})`);
    }
    // Sessao no servidor ja atualizada; guarda aqui tambem pra papelAtual() refletir na hora, antes do reload.
    if (corpo.papel) localStorage.setItem('papel', corpo.papel);
    return corpo;
}

// Encerra a sessao no servidor (botao de sair).
// Best effort: nao trava a navegacao se falhar, o cliente limpa o localStorage de qualquer jeito.
async function dbLogout() {
    const token = tokenSessaoAtual();
    localStorage.removeItem('logado');
    localStorage.removeItem('sessionToken');
    localStorage.removeItem('papel');
    localStorage.removeItem('papelPrincipal');
    localStorage.removeItem('usuario');
    localStorage.removeItem('papeisExtra');
    if (!token) return;
    try {
        await fetch(`${HOSTINGER_API_URL}/login.php?acao=logout`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token })
        });
    } catch (e) { /* ignora - sessao ja foi limpa localmente */ }
}

// Painel de administracao de usuarios (listar sem senha, criar/editar, remover). Usa login.php, nao o dados.php generico;
// exige sessao de RH/Administrador (verificarSessaoAdmin no backend).
async function dbUsuariosListar() {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=listar`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao listar usuarios (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

async function dbUsuariosSalvar(registro) {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=salvar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify(registro)
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao salvar usuario (HTTP ${resp.status})`);
    }
    return corpo;
}

// Centros de custo de Colaboradores, pro seletor de escopo de um Gestor em Gerenciar Usuarios (RH-only, ver login.php).
async function dbUsuariosListarCentrosCusto() {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=listar_centros_custo`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao listar centros de custo (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

// Usuarios do directory USC ainda nao cadastrados no CGV (picker "+ Novo usuario do diretorio"). Sem USC_API_URL o backend responde 503 e o picker mostra mensagem amigavel.
async function dbUsuariosListarDiretorio() {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=listar_diretorio`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao listar diretorio (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

async function dbUsuariosRemover(ids) {
    const resp = await fetch(`${HOSTINGER_API_URL}/login.php?acao=remover`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ ids })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao remover usuario (HTTP ${resp.status})`);
    }
    return corpo;
}

// Designa um volante direto em 1+ linhas de solicitacoes_cobertura (grade de coberturas/solicitacao.html; RH e Gestor). Ver cobertura-solicitacao.php.
// Dia que as ferias do SAF ja pagam a outro volante: o servidor devolve a pergunta (confirmar_ausencia_saf); confirmada, manda de
// novo e a falta do volante das ferias fica registrada (resposta com ausencias_saf: id => {matricula, nome}). Nao confirmada: { cancelado: true }.
async function dbCoberturaSolicitacaoDesignar(ids, matriculaVol, confirmarAusenciaSaf = false) {
    const resp = await fetch(`${HOSTINGER_API_URL}/cobertura-solicitacao.php?acao=designar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ ids, matricula_vol: matriculaVol, confirmar_ausencia_saf: confirmarAusenciaSaf })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (resp.status === 409 && corpo.confirmar_ausencia_saf && !confirmarAusenciaSaf) {
        if (!confirm(corpo.confirmar_ausencia_saf)) return { cancelado: true };
        return dbCoberturaSolicitacaoDesignar(ids, matriculaVol, true);
    }
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao designar cobertura (HTTP ${resp.status})`);
    }
    return corpo;
}

// Base do volante (backend/api/base-colaborador.php): acao 'listar' (GET)
// ou 'salvar' | 'remover' | 'ausencia' (POST com corpo). Devolve o JSON do
// servidor; erro vira Error com a mensagem dele.
async function dbBase(acao, corpo) {
    const resp = await fetch(`${HOSTINGER_API_URL}/base-colaborador.php?acao=${encodeURIComponent(acao)}`, {
        method: corpo ? 'POST' : 'GET',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: corpo ? JSON.stringify(corpo) : undefined,
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok || json.error) {
        throw new Error(json.error || `Falha na base do volante (HTTP ${resp.status})`);
    }
    return json;
}

// Preposto marca que o volante designado nao compareceu hoje (backend/api/
// ausencia-cobertura.php) - matriculaVol = o volante que a tela mostrava, o
// servidor recusa se alguem trocou nesse meio tempo.
// Cobertura de ferias do SAF nao tem id: vai feriasTitular, a matricula de quem esta de ferias.
async function dbMarcarAusenciaCobertura(id, matriculaVol, feriasTitular) {
    const resp = await fetch(`${HOSTINGER_API_URL}/ausencia-cobertura.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ id, matricula_vol: matriculaVol, ferias_titular: feriasTitular })
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao marcar a ausencia de cobertura (HTTP ${resp.status})`);
    }
    return corpo;
}

// Calculo automatico do PFM (backend/api/pfm-calculo.php, regra no topo daquele arquivo). "tipo" e 'volante' ou 'intermitente' (TIPO_PFM de cada pagina).
async function dbPfmCalcular(ano, mes, tipo, salarioMinimo) {
    const params = new URLSearchParams({ ano, mes, tipo, salario_minimo: salarioMinimo });
    const resp = await fetch(`${HOSTINGER_API_URL}/pfm-calculo.php?${params.toString()}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao calcular o PFM (HTTP ${resp.status})`);
    }
    return corpo;
}

// Logs do sistema (logs.html, so Administrador): le a tabela "auditoria" com filtro opcional de usuario/papel/periodo (DD/MM/AAAA).
async function dbLogsListar(usuario, papel, dataInicio, dataFim) {
    const params = new URLSearchParams({ acao: 'listar' });
    if (usuario) params.set('usuario', usuario);
    if (papel) params.set('papel', papel);
    if (dataInicio) params.set('data_inicio', dataInicio);
    if (dataFim) params.set('data_fim', dataFim);
    const resp = await fetch(`${HOSTINGER_API_URL}/logs.php?${params.toString()}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao carregar os logs (HTTP ${resp.status})`);
    }
    return corpo.dados || [];
}

// Salario minimo vigente do Banco Central (salario-minimo.php), so pra pre-preencher o modal do PFM; o usuario confirma antes de calcular.
async function dbSalarioMinimoAtual() {
    const resp = await fetch(`${HOSTINGER_API_URL}/salario-minimo.php`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao buscar o salario minimo (HTTP ${resp.status})`);
    }
    return corpo.valor;
}

// Valor padrao do Vale Alimentacao (valor-va.php). No salvar vai o valor que a tela mostrava (valorAnterior): o servidor recusa se outra pessoa alterou antes.
async function dbValorVa(novoValor, valorAnterior) {
    const salvando = novoValor !== undefined;
    const resp = await fetch(`${HOSTINGER_API_URL}/valor-va.php`, salvando ? {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
        body: JSON.stringify({ valor: novoValor, valor_anterior: valorAnterior })
    } : { headers: { 'X-Session-Token': tokenSessaoAtual() } });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha no valor do Vale Alimentacao (HTTP ${resp.status})`);
    }
    return corpo.valor;
}

// Periodos com cobertura registrada (pfm-periodos.php), pro seletor de ano/mes do PFM. "tipo" e 'volante' ou 'intermitente'.
async function dbPfmPeriodosDisponiveis(tipo) {
    const resp = await fetch(`${HOSTINGER_API_URL}/pfm-periodos.php?tipo=${encodeURIComponent(tipo)}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao buscar os periodos disponiveis (HTTP ${resp.status})`);
    }
    return corpo.periodos; // [{ano, mes}, ...]
}

// ============================================================
// DASHBOARD (coberturas/dashboard.html, dashboard.php) - pagina unica pros 3 papeis. dataInicio/dataFim (DD/MM/AAAA) opcionais (padrao: ultimos 30 dias);
// centrosCusto restringe a um subconjunto do que o usuario ja ve; funcoes so afeta os rankings.
// ============================================================
// "sinal" (AbortSignal, opcional): deixa a tela cancelar pedido que ja nao interessa (ver novaRodadaDash em coberturas/dashboard.html).
async function dbDashboardResumo(dataInicio, dataFim, centrosCusto, funcoes, sinal) {
    const params = new URLSearchParams();
    if (dataInicio) params.set('data_inicio', dataInicio);
    if (dataFim) params.set('data_fim', dataFim);
    (centrosCusto || []).forEach(c => params.append('centro_custo[]', c));
    (funcoes || []).forEach(f => params.append('funcao[]', f));
    const resp = await fetch(`${HOSTINGER_API_URL}/dashboard.php?acao=resumo&${params.toString()}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }, signal: sinal
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao carregar o dashboard (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

// A lista de funcoes so inclui quem aparece no periodo/centro filtrado (ver dashboard.php): chamar de novo sempre que eles mudarem.
async function dbDashboardFuncoes(dataInicio, dataFim, centrosCusto, sinal) {
    const params = new URLSearchParams();
    if (dataInicio) params.set('data_inicio', dataInicio);
    if (dataFim) params.set('data_fim', dataFim);
    (centrosCusto || []).forEach(c => params.append('centro_custo[]', c));
    const resp = await fetch(`${HOSTINGER_API_URL}/dashboard.php?acao=funcoes&${params.toString()}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }, signal: sinal
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao carregar funções (HTTP ${resp.status})`);
    }
    return corpo.dados;
}

async function dbDashboardCentrosCusto(dataInicio, dataFim, sinal) {
    const params = new URLSearchParams();
    if (dataInicio) params.set('data_inicio', dataInicio);
    if (dataFim) params.set('data_fim', dataFim);
    const resp = await fetch(`${HOSTINGER_API_URL}/dashboard.php?acao=centros_custo&${params.toString()}`, {
        headers: { 'X-Session-Token': tokenSessaoAtual() }, signal: sinal
    });
    if (resp.status === 401) { tratarSessaoInvalida(); throw new Error('Sessao expirada'); }
    const corpo = await resp.json().catch(() => ({}));
    if (!resp.ok || corpo.error) {
        throw new Error(corpo.error || `Falha ao carregar centros de custo (HTTP ${resp.status})`);
    }
    return corpo.dados;
}
