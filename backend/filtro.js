/* ====================================================================
   MOTOR CENTRAL DE INTERAÇÃO DOS FILTROS (SISTEMA CGV)
   ==================================================================== */

// ====================================================================
// GUARDA DE LOGIN
// ====================================================================
// Roda ao carregar (incluido no <head> de toda pagina menos o index): barra acesso direto pela URL sem login.
// Centralizado aqui pra nenhuma pagina nova esquecer a verificacao. So na janela de nivel principal (nao em iframe).
if (window.self === window.top) {
    (function verificarLogin() {
        if (localStorage.getItem('logado') !== 'true') {
            // Caminho absoluto: o arquivo roda em paginas na raiz e em subpastas.
            window.location.href = '/index.html';
        }
    })();

    // ====================================================================
    // TEMA CLARO/ESCURO - botao no painel de perfil, salvo em localStorage e aplicado aqui no topo, antes da pagina pintar (evita flash do tema claro).
    // Cobre so popups/paineis compartilhados e modais; tabelas e cards seguem so no tema claro.
    // Menu suspenso da hotbar e tooltip ficam escuros nos dois temas de proposito (sem regra [data-tema="escuro"] em style.css).
    function temaAtual() {
        return localStorage.getItem('tema') === 'escuro' ? 'escuro' : 'claro';
    }
    function aplicarTema(tema) {
        if (tema === 'escuro') {
            document.documentElement.setAttribute('data-tema', 'escuro');
        } else {
            document.documentElement.removeAttribute('data-tema');
        }
        localStorage.setItem('tema', tema);
    }
    function atualizarBotaoTema() {
        const escuro = temaAtual() === 'escuro';
        const iconeSol = document.getElementById('perfil-tema-icone-sol');
        const iconeLua = document.getElementById('perfil-tema-icone-lua');
        const label = document.getElementById('perfil-tema-label');
        if (iconeSol) iconeSol.style.display = escuro ? 'none' : '';
        if (iconeLua) iconeLua.style.display = escuro ? '' : 'none';
        if (label) label.textContent = escuro ? 'Modo claro' : 'Modo escuro';
    }
    function alternarTema() {
        aplicarTema(temaAtual() === 'escuro' ? 'claro' : 'escuro');
        atualizarBotaoTema();
    }
    aplicarTema(temaAtual());

    // Encerra a sessao (local + servidor) e manda pro login; so roda por acao explicita (botao sair).
    function executarLogout() {
        // dbLogout() (hostinger-storage.js) encerra no servidor tambem; cai pro clear direto se a pagina nao incluir esse arquivo.
        if (typeof dbLogout === 'function') {
            dbLogout();
        } else {
            localStorage.removeItem('logado');
            localStorage.removeItem('sessionToken');
        }
        window.location.href = '/index.html'; // caminho absoluto, ver verificarLogin() acima
    }

    // NAO da pra diferenciar fechar a aba de navegar pra outra pagina (pagehide dispara igual): avisar o servidor no fechamento deslogava a cada navegacao. Por isso o 'pulso' abaixo.

    // Pulso: enquanto a aba esta aberta, renova a sessao a cada 20s (login.php?acao=heartbeat); fechar a aba para os pulsos e a sessao expira sozinha
    // (90s, SESSAO_DURACAO_SEGUNDOS em db.php). Pula se HOSTINGER_API_URL/tokenSessaoAtual nao existirem.
    const INTERVALO_PULSO_SESSAO_MS = 20000;
    function enviarPulsoSessao() {
        if (typeof HOSTINGER_API_URL === 'undefined' || typeof tokenSessaoAtual !== 'function') return;
        const token = tokenSessaoAtual();
        if (!token) return;
        fetch(`${HOSTINGER_API_URL}/login.php?acao=heartbeat`, {
            headers: { 'X-Session-Token': token }
        }).then(resp => {
            if (resp.status === 401 && typeof tratarSessaoInvalida === 'function') tratarSessaoInvalida();
        }).catch(() => { /* falha de rede pontual - o proximo pulso tenta de novo */ });
    }

    // Iniciais pro avatar: 'joao.silva' -> 'JS', sem separador ('admin') -> 'AD'; sem usuario guardado -> '?'.
    function iniciaisUsuario(usuario) {
        if (!usuario) return '?';
        const partes = usuario.split(/[.\s_]+/).filter(Boolean);
        if (partes.length >= 2) return (partes[0][0] + partes[1][0]).toUpperCase();
        return usuario.slice(0, 2).toUpperCase();
    }

    // 'joao.silva' -> 'Joao Silva'.
    function nomeExibicaoUsuario(usuario) {
        if (!usuario) return '';
        return usuario.split(/[.\s_]+/).filter(Boolean)
            .map(p => p.charAt(0).toUpperCase() + p.slice(1).toLowerCase())
            .join(' ');
    }

    // Nome de exibicao de cada papel (RH/Gestor/Preposto).
    function rotuloPapel(papel) {
        return { rh: 'RH', gestor: 'Gestor', preposto: 'Preposto' }[papel] || papel;
    }

    function togglePainelPerfil() {
        const painel = document.getElementById('painel-perfil');
        if (painel) painel.classList.toggle('show');
    }

    // Troca o perfil ativo no servidor e, so depois de confirmado, recarrega indo pra Colaboradores (CDV).
    async function trocarPerfil(botaoPapel, novoPapel) {
        if (botaoPapel.disabled) return;
        botaoPapel.disabled = true;
        try {
            await dbAlternarPerfil(novoPapel);
            // O CDV pinta cache antes do fetch e o cache nao sabe de qual papel veio; limpar evita mostrar a lista do papel anterior ate o fetch renovar.
            localStorage.removeItem('dados_planilha_cdv');
            window.location.href = '/colaboradores/funcionarios.html';
        } catch (e) {
            if (typeof mostrarToast === 'function') {
                mostrarToast(e.message || 'Erro ao alternar o perfil.', '#e74c3c');
            }
            botaoPapel.disabled = false;
        }
    }

    // Cria o botao de perfil e o painel (avatar/nome/papel/sair), mesmo padrao do painel de Alertas, direto por JS pra nao repetir HTML em cada pagina.
    document.addEventListener('DOMContentLoaded', () => {
        const usuario = typeof usuarioAtual === 'function' ? usuarioAtual() : '';
        const iniciais = iniciaisUsuario(usuario);
        const nomeExibicao = nomeExibicaoUsuario(usuario);
        const papel = typeof papelAtual === 'function' ? papelAtual() : '';
        // papeisExtraAtual() e papelPrincipalAtual() nao mudam ao alternar a sessao; juntos dao o conjunto de papeis e, tirando o ativo, as opcoes de troca.
        const papeisExtra = typeof papeisExtraAtual === 'function' ? papeisExtraAtual() : [];
        const papelPrincipal = typeof papelPrincipalAtual === 'function' ? papelPrincipalAtual() : papel;
        const outrosPapeis = [...new Set([papelPrincipal, ...papeisExtra])].filter(p => p !== papel);

        const botao = document.createElement('button');
        botao.className = 'btn-perfil';
        botao.title = 'Perfil';
        botao.setAttribute('aria-label', 'Perfil');
        botao.innerHTML = `
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 12c2.7 0 4.9-2.2 4.9-4.9S14.7 2.2 12 2.2 7.1 4.4 7.1 7.1 9.3 12 12 12zm0 2.4c-3.3 0-9.8 1.6-9.8 4.9v1.2c0 .7.5 1.2 1.2 1.2h17.1c.7 0 1.2-.5 1.2-1.2v-1.2c0-3.3-6.5-4.9-9.7-4.9z"></path>
            </svg>
        `;
        botao.addEventListener('click', (e) => { e.stopPropagation(); togglePainelPerfil(); });
        // Vai dentro da pilula .hotbar-util (junto do sino); sem hotbar (ex: index.html) cai no body.
        const hotbarUtil = document.querySelector('.hotbar-util');
        (hotbarUtil || document.body).appendChild(botao);

        const iconeTrocarSvg = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M7 4v13m0 0-3-3m3 3 3-3M17 20V7m0 0 3 3m-3-3-3 3"></path>
            </svg>`;

        // Sem outro papel: texto simples. 1 outro: clique ja troca. 2 outros: abre mini menu.
        let papelHtml;
        if (outrosPapeis.length === 0) {
            papelHtml = `<div class="perfil-papel">${rotuloPapel(papel)}</div>`;
        } else if (outrosPapeis.length === 1) {
            papelHtml = `<button class="perfil-papel perfil-papel-clicavel" type="button"
                                  title="Clique para mudar para ${rotuloPapel(outrosPapeis[0])}">
                             ${rotuloPapel(papel)} ${iconeTrocarSvg}
                         </button>`;
        } else {
            papelHtml = `<div class="perfil-papel-wrapper">
                             <button class="perfil-papel perfil-papel-clicavel" type="button" title="Clique para escolher outro perfil">
                                 ${rotuloPapel(papel)} ${iconeTrocarSvg}
                             </button>
                             <div class="perfil-papel-opcoes">
                                 ${outrosPapeis.map(p => `<button type="button" class="perfil-papel-opcao" data-papel="${p}">${rotuloPapel(p)}</button>`).join('')}
                             </div>
                         </div>`;
        }

        const temaEscuro = temaAtual() === 'escuro';
        const painel = document.createElement('div');
        painel.className = 'painel-perfil';
        painel.id = 'painel-perfil';
        painel.innerHTML = `
            <div class="perfil-avatar-grande">${iniciais}</div>
            <div class="perfil-nome">${nomeExibicao}</div>
            ${papelHtml}
            <button class="perfil-btn-tema" type="button" onclick="alternarTema()">
                <svg id="perfil-tema-icone-sol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:${temaEscuro ? 'none' : ''};">
                    <circle cx="12" cy="12" r="4"></circle>
                    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"></path>
                </svg>
                <svg id="perfil-tema-icone-lua" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:${temaEscuro ? '' : 'none'};">
                    <path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"></path>
                </svg>
                <span id="perfil-tema-label">${temaEscuro ? 'Modo claro' : 'Modo escuro'}</span>
            </button>
            <button class="perfil-btn-sair" type="button">Sair</button>
        `;
        painel.querySelector('.perfil-btn-sair').addEventListener('click', executarLogout);
        const botaoPapel = painel.querySelector('.perfil-papel-clicavel');
        if (botaoPapel && outrosPapeis.length === 1) {
            botaoPapel.addEventListener('click', () => trocarPerfil(botaoPapel, outrosPapeis[0]));
        } else if (botaoPapel && outrosPapeis.length > 1) {
            botaoPapel.addEventListener('click', (e) => {
                e.stopPropagation();
                painel.querySelector('.perfil-papel-opcoes').classList.toggle('show');
            });
            painel.querySelectorAll('.perfil-papel-opcao').forEach(opcao => {
                opcao.addEventListener('click', () => trocarPerfil(opcao, opcao.dataset.papel));
            });
        }
        document.body.appendChild(painel);

        // Fecha ao clicar fora (botao ou painel); centralizado aqui pra valer em qualquer pagina.
        document.addEventListener('click', (e) => {
            if (!painel.classList.contains('show')) return;
            if (painel.contains(e.target) || botao.contains(e.target)) return;
            painel.classList.remove('show');
        });

        // So no DOMContentLoaded hostinger-storage.js ja carregou (HOSTINGER_API_URL, tokenSessaoAtual). Primeiro pulso imediato + um a cada 20s.
        enviarPulsoSessao();
        setInterval(enviarPulsoSessao, INTERVALO_PULSO_SESSAO_MS);
    });

    // Caixa de notificacao do servidor em qualquer pagina com sino (ver carregarNotificacoesServidor).
    document.addEventListener('DOMContentLoaded', () => {
        if (document.getElementById('lista-notificacoes')) carregarNotificacoesServidor();
    });

    // Modal global de confirmacao de substituto de Desligamento (so Preposto, em qualquer pagina). Injeta o modal vazio 1 vez
    // e so mostra conteudo se houver pendencia (verificarPendenciaDesligamento).
    document.addEventListener('DOMContentLoaded', () => {
        if (typeof souPreposto !== 'function' || !souPreposto()) return;

        document.body.insertAdjacentHTML('beforeend', `
            <div id="modal-confirmacao-desligamento-overlay" class="modal-overlay">
                <div class="modal-content" style="width:420px;" onclick="event.stopPropagation()">
                    <div class="modal-header">
                        <div class="modal-header-titulo">
                            <h3>Confirmar substituição</h3>
                        </div>
                    </div>
                    <div id="confirmacao-desligamento-corpo" style="display:flex; flex-direction:column; gap:14px;"></div>
                </div>
            </div>
        `);

        // Depois da sessao pronta, senao tokenSessaoAtual()/HOSTINGER_API_URL podem nao existir.
        verificarPendenciaDesligamento();
    });

    // Aviso de rotina noturna atrasada/com erro (backend/api/saude-sync.php): so RH/Administrador, 1 vez por sessao.
    // confirm() em vez de alert() pra oferecer baixar: OK baixa um .txt com os detalhes, Cancelar so fecha.
    document.addEventListener('DOMContentLoaded', async () => {
        const papel = typeof papelAtual === 'function' ? papelAtual() : '';
        if (papel !== 'rh' && papel !== 'administrador') return;
        if (sessionStorage.getItem('avisoSaudeSyncMostrado') === '1') return;

        try {
            const resp = await fetch(`${HOSTINGER_API_URL}/saude-sync.php`, {
                headers: { 'X-Session-Token': tokenSessaoAtual() }
            });
            if (!resp.ok) return; // falha de rede/sessao - nao marca como mostrado, tenta de novo na proxima pagina
            const corpo = await resp.json();
            const problemas = corpo.problemas || [];
            sessionStorage.setItem('avisoSaudeSyncMostrado', '1');
            if (problemas.length === 0) return;

            // Na caixa vai so o comeco de cada detalhe - o completo fica no arquivo.
            const curto = d => d.length > 300 ? d.slice(0, 300) + '… (continua no arquivo)' : d;
            const linhas = problemas.map(p => `• ${p.rotulo}: ${curto(p.detalhe)}`).join('\n');
            const baixar = confirm(`${problemas.length} rotina(s) automática(s) com problema:\n\n${linhas}\n\nClique em OK para baixar um arquivo com os detalhes, ou em Cancelar para apenas fechar.`);
            if (!baixar) return;

            const agora = new Date();
            const texto = [
                'CGV - rotinas automáticas que precisam de atenção',
                `Gerado em ${agora.toLocaleString('pt-BR')}`,
                '',
                // O texto tecnico original (quando o motivo foi traduzido -
                // ver motivoDoErroRotina em saude-sync.php) so vai no arquivo.
                ...problemas.map(p => `${p.rotulo}\n${p.detalhe}\n` + (p.detalhe_tecnico ? `Detalhe técnico: ${p.detalhe_tecnico}\n` : ''))
            ].join('\n').replace(/\n/g, '\r\n');
            const link = document.createElement('a');
            // BOM: sem ele o Bloco de Notas pode abrir os acentos trocados.
            link.href = URL.createObjectURL(new Blob(['\ufeff' + texto], { type: 'text/plain;charset=utf-8' }));
            link.download = `cgv-avisos-${agora.toLocaleDateString('pt-BR').split('/').reverse().join('-')}.txt`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(link.href), 1000);
        } catch (e) {
            // Sem rede/CORS - nunca quebra a pagina por causa de um aviso, so nao avisa agora.
        }
    });

    // Injeta o modal de 'Notificacao Cobertura' uma unica vez, pro item do menu COBERTURAS abrir em qualquer pagina.
    document.addEventListener('DOMContentLoaded', () => {
        document.body.insertAdjacentHTML('beforeend', `
            <div id="modal-notificacao-cobertura" class="modal-notificacao-cobertura" onclick="fecharModalNotificacaoFora(event)">
                <div class="modal-notificacao-content" onclick="event.stopPropagation()">

                    <div class="modal-notificacao-header">
                        <h3 class="modal-notificacao-titulo">Notificação de cobertura</h3>
                        <div class="switch-container">
                            <span onmouseenter="mostrarTooltip('COBERTURA', this)" onmouseleave="esconderTooltip()">COB</span>
                            <label class="switch">
                                <input type="checkbox" id="toggle-tipo-notificacao">
                                <span class="slider"></span>
                            </label>
                            <span onmouseenter="mostrarTooltip('CONTRATO', this)" onmouseleave="esconderTooltip()">CTR</span>
                        </div>
                        <button class="btn-fechar" onclick="fecharModalNotificacao()">&times;</button>
                    </div>

                    <div class="modal-notificacao-body">

                        <div id="view-volante" class="container-duplo">

                            <div class="coluna-form" style="position: relative;">
                                <div class="form-group">
                                    <label>Responsável</label>
                                    <input type="text" id="input-resp" class="input-padrao" placeholder="Nome do responsavel" oninput="atualizarPreview()">
                                </div>

                                <div class="form-group">
                                    <label>Escala</label>
                                    <input type="text" id="input-escala" class="input-padrao" placeholder="Ex: 12x36, 5x2..." oninput="atualizarPreview()">
                                </div>

                                <div class="form-row-duplo">
                                    <div class="form-group">
                                        <label>Início</label>
                                        <input type="text" id="input-inicio" class="input-padrao" placeholder="Data e/ou hora de inicio" oninput="atualizarPreview()">
                                    </div>

                                    <div class="form-group">
                                        <label>Término</label>
                                        <input type="text" id="input-termino" class="input-padrao" placeholder="Data e/ou hora de termino" oninput="atualizarPreview()">
                                    </div>
                                </div>

                                <div style="margin-top: auto; display: flex;">
                                    <button class="btn-copiar" style="flex: 1; background-color: #2980b9;" onclick="verificarLista()">Verificar lista</button>
                                </div>
                            </div>

                            <div class="coluna-preview" id="view-preview-box">
                                <div class="coluna-preview-rotulo">Mensagem gerada</div>
                                <div id="texto-preview" class="caixa-preview"></div>

                                <div style="margin-top: auto; display: flex;">
                                    <button class="btn-copiar" style="flex: 1;" onclick="copiarTextoPreview()">Copiar mensagem</button>
                                </div>
                            </div>

                            <div class="coluna-preview" id="view-lista-box" style="display: none;">
                                <h4>Contratos</h4>
                                <div id="tabela-lista-container" style="flex: 1; background-color: #fff; border-radius: 5px; overflow: auto; margin-bottom: 15px; border: 1px solid #e2e2e2; box-sizing: border-box;">
                                </div>

                                <div style="display: flex; gap: 10px;">
                                    <button class="btn-lista-add" onclick="adicionarLinhaTabela()" title="Adicionar Linha em Branco">➕</button>
                                    <button class="btn-lista-remove" onclick="removerLinhaTabela()" title="Excluir linha: clique aqui e depois na linha que quer excluir">➖</button>
                                    <button class="btn-lista-salvar" onclick="salvarLista()">💾 SALVAR</button>
                                    <button class="btn-lista-cancelar" onclick="cancelarLista()">✖ CANCELAR</button>
                                </div>
                            </div>
                        </div>

                        <div id="view-contrato" style="display: none; flex-direction: column; width: 100%; height: 100%; gap: 20px;">

                            <div class="coluna-form" style="flex: none; padding: 20px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label>Colaborador</label>
                                    <input type="text" id="input-matricula-contrato" class="input-padrao" autocomplete="off" placeholder="Digite a matricula ou o nome..." oninput="buscarColaboradorContrato(this.value)">
                                    <div id="notif-resultados-busca" class="notif-resultados-busca"></div>
                                </div>
                            </div>

                            <div class="coluna-preview" style="flex: 1;">
                                <div class="coluna-preview-rotulo">Mensagem final gerada</div>
                                <div id="texto-preview-contrato" class="caixa-preview"></div>
                                <div style="margin-top: auto; display: flex;">
                                    <button class="btn-copiar" style="flex: 1;" onclick="copiarTextoPreviewContrato()">Copiar mensagem</button>
                                </div>
                            </div>

                        </div>

                    </div>
                </div>
            </div>
        `);

        const toggle = document.getElementById('toggle-tipo-notificacao');
        if (toggle) {
            toggle.addEventListener('change', function(e) {
                if (e.target.checked) {
                    document.getElementById('view-volante').style.display = 'none';
                    document.getElementById('view-contrato').style.display = 'flex';
                } else {
                    document.getElementById('view-volante').style.display = 'flex';
                    document.getElementById('view-contrato').style.display = 'none';
                }
            });
        }
    });
}

// ====================================================================
// MODAL GLOBAL DE CONFIRMACAO DE SUBSTITUTO DE DESLIGAMENTO
// ====================================================================
// Ficam FORA do bloco 'if (window.self === window.top)' de proposito: os botoes chamam responderPendenciaDesligamento via onclick inline, e funcao async dentro de bloco nao vira global.
// Afastamento (licenca/INSS indeterminado, motivo 'afastamento_indeterminado') segue a mesma logica do Desligamento; o modal checa os dois endpoints em sequencia e carrega o 'tipo' (texto e endpoint dos botoes).
function endpointPendenciaSubstituicao(tipo) {
    return tipo === 'afastamento_indeterminado' ? 'afastamento-confirmacao.php' : 'desligamento-confirmacao.php';
}

async function buscarPendenciaSubstituicao(tipo) {
    try {
        const resp = await fetch(`${HOSTINGER_API_URL}/${endpointPendenciaSubstituicao(tipo)}?acao=pendente`, {
            headers: { 'X-Session-Token': tokenSessaoAtual() },
        });
        return await resp.json().catch(() => ({}));
    } catch (e) {
        console.error(`Erro ao verificar pendencia de ${tipo}:`, e);
        return null;
    }
}

async function verificarPendenciaDesligamento() {
    let dados = await buscarPendenciaSubstituicao('desligamento');
    let tipo = 'desligamento';
    if (!dados || !dados.pendente) {
        dados = await buscarPendenciaSubstituicao('afastamento_indeterminado');
        tipo = 'afastamento_indeterminado';
    }
    if (!dados || !dados.pendente) return;

    renderizarPendenciaDesligamento(dados.pendente, tipo);
    document.getElementById('modal-confirmacao-desligamento-overlay').style.display = 'flex';
}

function renderizarPendenciaDesligamento(pendente, tipo) {
    const corpo = document.getElementById('confirmacao-desligamento-corpo');
    const ccExibicao = typeof formatarCentroCusto === 'function' ? formatarCentroCusto(pendente.centro_custo) : pendente.centro_custo;
    const situacaoTitular = tipo === 'afastamento_indeterminado' ? 'está afastado(a)' : 'foi desligado(a)';

    if (pendente.candidatos.length === 1) {
        const c = pendente.candidatos[0];
        corpo.innerHTML = `
            <p style="margin:0; font-size:13.5px; line-height:1.5;">
                Um colaborador novo apareceu no centro de custo <b>${escapeHtml(ccExibicao)}</b>,
                onde <b>${escapeHtml(pendente.nome_titular)}</b> ${situacaoTitular}:
            </p>
            <div class="add-resultado-item" style="cursor:default;">
                <b>${escapeHtml(c.matricula)}</b> - ${escapeHtml(c.nome)}
                <div class="add-resultado-detalhe">${escapeHtml(c.funcao || '')}</div>
            </div>
            <p style="margin:0; font-size:13.5px;">Essa pessoa é a substituição?</p>
            <div style="display:flex; gap:10px;">
                <button class="btn-salvar" style="flex:1; padding:11px; border:none; border-radius:5px; cursor:pointer; font-weight:bold; background:#e74c3c;"
                        onclick="responderPendenciaDesligamento('${pendente.solicitacao_cobertura_id}', null, '${tipo}')">Não</button>
                <button class="btn-salvar" style="flex:1; padding:11px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;"
                        onclick="responderPendenciaDesligamento('${pendente.solicitacao_cobertura_id}', '${escapeHtml(c.matricula)}', '${tipo}')">Sim</button>
            </div>
        `;
        return;
    }

    const itens = pendente.candidatos.map(c => `
        <label class="add-resultado-item" style="display:flex; align-items:center; gap:10px; cursor:pointer;">
            <input type="radio" name="candidato-desligamento" value="${escapeHtml(c.matricula)}">
            <span><b>${escapeHtml(c.matricula)}</b> - ${escapeHtml(c.nome)}
                <div class="add-resultado-detalhe">${escapeHtml(c.funcao || '')}</div>
            </span>
        </label>
    `).join('');

    corpo.innerHTML = `
        <p style="margin:0; font-size:13.5px; line-height:1.5;">
            Mais de um colaborador novo apareceu no centro de custo <b>${escapeHtml(ccExibicao)}</b>,
            onde <b>${escapeHtml(pendente.nome_titular)}</b> ${situacaoTitular}. Alguma dessas pessoas é a substituição?
        </p>
        <div style="display:flex; flex-direction:column; gap:8px;">${itens}</div>
        <div style="display:flex; gap:10px;">
            <button class="btn-salvar" style="flex:1; padding:11px; border:none; border-radius:5px; cursor:pointer; font-weight:bold; background:#e74c3c;"
                    onclick="responderPendenciaDesligamento('${pendente.solicitacao_cobertura_id}', null, '${tipo}')">Nenhuma dessas pessoas</button>
            <button class="btn-salvar" style="flex:1; padding:11px; border:none; border-radius:5px; cursor:pointer; font-weight:bold;"
                    onclick="confirmarSelecaoPendenciaDesligamento('${pendente.solicitacao_cobertura_id}', '${tipo}')">Confirmar</button>
        </div>
    `;
}

function confirmarSelecaoPendenciaDesligamento(solicitacaoId, tipo) {
    const marcado = document.querySelector('input[name="candidato-desligamento"]:checked');
    if (!marcado) {
        mostrarToast('Escolha uma pessoa ou "Nenhuma dessas pessoas".', '#e74c3c');
        return;
    }
    responderPendenciaDesligamento(solicitacaoId, marcado.value, tipo);
}

async function responderPendenciaDesligamento(solicitacaoId, matricula, tipo) {
    try {
        const resp = await fetch(`${HOSTINGER_API_URL}/${endpointPendenciaSubstituicao(tipo)}?acao=confirmar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
            body: JSON.stringify({ solicitacao_cobertura_id: solicitacaoId, matricula }),
        });
        const corpo = await resp.json().catch(() => ({}));
        if (!resp.ok || corpo.error) throw new Error(corpo.error || 'Falha ao responder.');

        mostrarToast(matricula ? 'Substituição confirmada!' : 'Ok, mantendo a vaga em aberto.', '#27ae60');
    } catch (e) {
        mostrarToast(e.message || 'Erro ao responder.', '#e74c3c');
    }

    // Verifica de novo: se houver outra vaga pendente, mostra a proxima sem recarregar.
    document.getElementById('modal-confirmacao-desligamento-overlay').style.display = 'none';
    verificarPendenciaDesligamento();
}

// ====================================================================
// REDIMENSIONAR O PAINEL DO FILTRO (arrasta a alca no canto inferior direito)
// ====================================================================
// Delegado no document: o dropdown e recriado a cada import/troca de planilha, e um listener preso na alca antiga sumiria.
// Largura e altura livres; o 'resize' nativo do CSS nao faz os dois eixos de forma confiavel em layout flex.
(function ativarRedimensionamentoFiltro() {
    const LARGURA_MINIMA_PX = 220;
    // Minimo 340: as secoes fixas do painel somam ~246px que nunca encolhem; abaixo disso sobrepunham texto. Sobra ~94px pra lista.
    const ALTURA_MINIMA_PX = 340;
    let dropdownAtual = null;
    let larguraInicial = 0;
    let alturaInicial = 0;
    let xInicial = 0;
    let yInicial = 0;

    document.addEventListener('mousedown', (e) => {
        const alca = e.target.closest('.filtro-resize-handle');
        if (!alca) return;
        dropdownAtual = alca.closest('.multi-select-dropdown');
        if (!dropdownAtual) return;

        e.preventDefault();
        const rect = dropdownAtual.getBoundingClientRect();
        larguraInicial = rect.width;
        alturaInicial = rect.height;
        xInicial = e.clientX;
        yInicial = e.clientY;

        // max-width/max-height do CSS sao so um teto antes do usuario redimensionar.
        dropdownAtual.style.maxWidth = 'none';
        dropdownAtual.style.maxHeight = 'none';
        document.body.style.userSelect = 'none';
    });

    document.addEventListener('mousemove', (e) => {
        if (!dropdownAtual) return;
        const novaLargura = Math.max(LARGURA_MINIMA_PX, larguraInicial + (e.clientX - xInicial));
        const novaAltura = Math.max(ALTURA_MINIMA_PX, alturaInicial + (e.clientY - yInicial));
        dropdownAtual.style.width = novaLargura + 'px';
        dropdownAtual.style.height = novaAltura + 'px';
    });

    document.addEventListener('mouseup', () => {
        dropdownAtual = null;
        document.body.style.userSelect = '';
    });
})();

// ====================================================================
// MODAL DE NOTIFICACAO DE COBERTURA (compartilhado entre todas as paginas)
// ====================================================================
// Aberto pelo item 'Notificacao' do menu COBERTURAS, em qualquer pagina.
async function abrirModalNotificacao() {
    document.getElementById('view-lista-box').style.display = 'none';
    document.getElementById('view-preview-box').style.display = 'flex';

    document.getElementById('modal-notificacao-cobertura').style.display = 'flex';

    // Rotina dupla que pre-carrega na memoria tudo que vira preenchimento magico do texto
    const carregando = Promise.allSettled([carregarBancoNotificacao(), carregarBancoDadosVolantes()]);

    atualizarPreview();
    atualizarPreviewContrato();

    // Refaz o texto quando as listas chegam do servidor (senao fica sem contrato/telefone ate mexer em outro campo).
    await carregando;
    atualizarPreview();
    atualizarPreviewContrato();
}

function fecharModalNotificacao() {
    document.getElementById('modal-notificacao-cobertura').style.display = 'none';
}

function fecharModalNotificacaoFora(event) {
    if (event.target.id === 'modal-notificacao-cobertura') fecharModalNotificacao();
}

// ----------------------------------------------------
// TELA VOLANTE (Motor de substituicao visual dos textos com os dados do input)
// ----------------------------------------------------
function atualizarPreview() {
    const respDigitado = document.getElementById('input-resp').value || '';
    const escala = document.getElementById('input-escala').value || '';
    const inicio = document.getElementById('input-inicio').value || '';
    const termino = document.getElementById('input-termino').value || '';

    let contratoEncontrado = '';
    let telefoneEncontrado = '';
    let localizacaoEncontrada = '';

    // Mapeador Oculto: Se o Responsavel digitado der algum match no banco em Nuvem, puxa o resto pronto
    if (respDigitado.trim() !== '' && dadosNotificacaoVolante && dadosNotificacaoVolante.length > 1) {
        const busca = normalizarTexto(respDigitado.trim());

        for (let i = 1; i < dadosNotificacaoVolante.length; i++) {
            const linha = dadosNotificacaoVolante[i];
            const nomeBanco = normalizarTexto(linha[1] || '');

            if (nomeBanco && (nomeBanco === busca || nomeBanco.includes(busca))) {
                contratoEncontrado = linha[0] || '';
                telefoneEncontrado = linha[2] || '';
                localizacaoEncontrada = linha[3] || '';
                break;
            }
        }
    }

    const previewBox = document.getElementById('texto-preview');
    if (previewBox) previewBox.innerText = textoAlertaCobertura({
        contrato: contratoEncontrado, responsavel: respDigitado, telefone: telefoneEncontrado,
        localizacao: localizacaoEncontrada, inicio, termino, escala
    });
}

// Mensagem PARA O COLABORADOR (dados do contrato). Tambem usada pelo WhatsApp de coberturas/previsao.html, que manda a nota do
// Preposto da ferias (d.nota) embaixo do contrato.
function textoAlertaCobertura(d) {
    return `⚠️ ALERTA IMPORTANTE ⚠️

Contrato: ${d.contrato}${d.nota ? `\nNota: ${d.nota}` : ''}
Responsavel: ${d.responsavel}
Telefone: ${d.telefone}
Localizacao: ${d.localizacao}
Inicio da Cobertura: ${d.inicio}
Termino da Cobertura: ${d.termino}
Escala: ${d.escala}

Instrucoes a Seguir:

1. Entre em contato conosco e com o responsavel informado acima.
2. Certifique-se de bater os pontos, pois sao essenciais para o registro da cobertura.
3. Se houver qualquer informacao adicional vinda do contrato, comunique-nos imediatamente.

Agradecemos sua atencao e colaboracao.`;
}

function copiarTextoPreview() {
    const texto = document.getElementById('texto-preview').innerText;
    navigator.clipboard.writeText(texto).then(() => {
        adicionarNotificacaoUpload("Mensagem copiada para a area de transferencia!", "#27ae60");
    }).catch(err => {
        console.error('Erro ao copiar texto: ', err);
    });
}

// ----------------------------------------------------
// TELA CONTRATO (Consulta massiva em todos as bases do CDV atras de uma pessoa)
// ----------------------------------------------------
let dadosVolantesGeral = []; // Lista de registros (objetos) vindos da tabela "colaboradores"

async function carregarBancoDadosVolantes() {
    try {
        // escopo=irrestrito: RH/Gestor precisam achar qualquer colaborador (so respeitado pra rh/gestor, ver dados.php); Preposto segue restrito a propria Equipe.
        dadosVolantesGeral = await dbListar('colaboradores', 'escopo=irrestrito');
    } catch (e) {
        console.error("Erro ao carregar colaboradores:", e);
        dadosVolantesGeral = [];
    }
}

// Colaborador escolhido na busca (matricula ou nome); a pre-visualizacao usa este registro (ver selecionarColaboradorContrato).
let colaboradorContratoSelecionado = null;

function buscarColaboradorContrato(termo) {
    colaboradorContratoSelecionado = null;
    const resultadosDiv = document.getElementById('notif-resultados-busca');
    const termoNormalizado = normalizarTexto(termo || '');

    if (!termoNormalizado) {
        resultadosDiv.innerHTML = '';
        resultadosDiv.classList.remove('mostrando');
        atualizarPreviewContrato();
        return;
    }

    const encontrados = (dadosVolantesGeral || []).filter(r =>
        normalizarTexto(r.matricula || '').includes(termoNormalizado)
        || normalizarTexto(r.nome || '').includes(termoNormalizado)
    ).slice(0, 8);

    resultadosDiv.classList.add('mostrando');
    if (encontrados.length === 0) {
        resultadosDiv.innerHTML = '<div class="notif-resultado-item notif-resultado-vazio">Nenhum colaborador encontrado.</div>';
    } else {
        resultadosDiv.innerHTML = '';
        encontrados.forEach(reg => {
            const item = document.createElement('div');
            item.className = 'notif-resultado-item';
            const ccExibicao = reg.centro_custo ? formatarCentroCusto(reg.centro_custo) : '';
            item.innerHTML = `<b>${escapeHtml(reg.matricula)}</b> - ${escapeHtml(reg.nome || '')}
                <div class="notif-resultado-detalhe">${escapeHtml(reg.funcao || '')}${ccExibicao ? ' &middot; ' + escapeHtml(ccExibicao) : ''}</div>`;
            item.onclick = () => selecionarColaboradorContrato(reg);
            resultadosDiv.appendChild(item);
        });
    }

    atualizarPreviewContrato();
}

function selecionarColaboradorContrato(reg) {
    colaboradorContratoSelecionado = reg;
    document.getElementById('input-matricula-contrato').value = `${reg.matricula} - ${reg.nome || ''}`;

    const resultadosDiv = document.getElementById('notif-resultados-busca');
    resultadosDiv.innerHTML = '';
    resultadosDiv.classList.remove('mostrando');

    atualizarPreviewContrato();
}

function atualizarPreviewContrato() {
    const previewBox = document.getElementById('texto-preview-contrato');
    if (previewBox) previewBox.innerText = textoDadosVolante(colaboradorContratoSelecionado);
}

// Mensagem PARA O CONTRATO (dados do volante). Tambem usada pelo WhatsApp de coberturas/previsao.html.
function textoDadosVolante(reg) {
    const matricula = reg ? (reg.matricula || "") : "";
    const nome = reg ? (reg.nome || "") : "";
    const admissao = reg ? formatarExibicao(reg.data_admissao, "DATA") : "";
    const salario = reg ? formatarExibicao(reg.salario, "SALARIO") : "";
    const funcao = reg ? (reg.funcao || "") : "";
    const cc = reg ? (reg.centro_custo || "") : "";
    const numero = reg ? (reg.contato || "") : "";

    return `DADOS DO VOLANTE

🟡 Matricula: ${matricula}
🟡 Nome completo: ${nome}
🟡 Data de admissao: ${admissao}
🟡 Salario: ${salario}
🟡 Funcao: ${funcao}
🟡 Centro de custo: ${cc}

📱 Numero: ${numero}`;
}

function copiarTextoPreviewContrato() {
    const texto = document.getElementById('texto-preview-contrato').innerText;
    navigator.clipboard.writeText(texto).then(() => {
        adicionarNotificacaoUpload("DADOS DO VOLANTE copiados para a area de transferencia!", "#27ae60");
    }).catch(err => {
        console.error('Erro ao copiar texto: ', err);
    });
}

// ==========================================
// BANCO DE DADOS DIRETO NO SITE (Excel do menu VOLANTE)
// ==========================================
let dadosNotificacaoVolante = [];
let idsNotificacaoVolante = []; // ids da tabela contratos_notificacao, na mesma ordem das linhas

async function carregarBancoNotificacao() {
    try {
        const linhas = await dbListar('contratos_notificacao');
        dadosNotificacaoVolante = [["CONTRATO", "RESPONSAVEL", "TELEFONE", "LOCALIZACAO"]];
        linhas.forEach(reg => {
            dadosNotificacaoVolante.push([reg.contrato || '', reg.responsavel || '', reg.telefone || '', reg.localizacao || '']);
        });
        idsNotificacaoVolante = linhas.map(reg => reg.id);
        return true;
    } catch (e) {
        console.error("Erro ao carregar banco de notificacao:", e);
        dadosNotificacaoVolante = [["CONTRATO", "RESPONSAVEL", "TELEFONE", "LOCALIZACAO"]];
        idsNotificacaoVolante = [];
        return false; // lista vazia por erro, nao por nao ter contrato (a Previsao nao cria contrato em cima disso)
    }
}

async function verificarLista() {
    const toast = mostrarToastProgresso('Preparando lista do servidor...');

    try {
        if (!dadosNotificacaoVolante || dadosNotificacaoVolante.length === 0) {
            await carregarBancoNotificacao();
        }
        // Busca da celula CONTRATO: carrega (ou tenta de novo, se a carga anterior falhou e deixou o mapa vazio).
        if (!_mapaDescricaoCentroCusto || Object.keys(_mapaDescricaoCentroCusto).length === 0) await garantirDescricoesCentroCusto();

        renderizarTabelaNotificacao();

        document.getElementById('view-preview-box').style.display = 'none';
        document.getElementById('view-lista-box').style.display = 'flex';
        finalizarToastProgresso(toast, 'Lista carregada.', '#27ae60', 1500);

    } catch (e) {
        console.error("Erro ao ler lista:", e);
        finalizarToastProgresso(toast, 'Erro ao preparar a lista do servidor.', '#e74c3c', 4000);
    }
}

function renderizarTabelaNotificacao() {
    const container = document.getElementById('tabela-lista-container');
    const header = dadosNotificacaoVolante[0] || ["CONTRATO", "RESPONSAVEL", "TELEFONE", "LOCALIZACAO"];
    const linhas = dadosNotificacaoVolante.slice(1);

    container.innerHTML = '';
    document.getElementById('view-lista-box').classList.remove('modo-excluir-linha');
    const tabela = document.createElement('table');
    tabela.className = 'tabela-editavel';

    const thead = document.createElement('thead');
    const trHead = document.createElement('tr');
    [header[0], header[1], header[2], header[3] || "LOCALIZACAO"].forEach(texto => {
        const th = document.createElement('th');
        th.textContent = texto;
        trHead.appendChild(th);
    });
    thead.appendChild(trHead);
    tabela.appendChild(thead);

    const tbody = document.createElement('tbody');
    tbody.id = 'tbody-lista';
    tbody.onclick = (evento) => {
        const caixa = document.getElementById('view-lista-box');
        const tr = evento.target.closest('tr');
        if (!tr || !caixa.classList.contains('modo-excluir-linha')) return;
        tr.remove();
        caixa.classList.remove('modo-excluir-linha');
    };
    // textContent/value (nao innerHTML): contrato/responsavel/telefone/
    // localizacao sao campos editaveis pelo usuario.
    linhas.forEach(row => {
        const tr = document.createElement('tr');
        for (let colIndex = 0; colIndex < 4; colIndex++) {
            const td = document.createElement('td');
            const input = document.createElement('input');
            input.type = 'text';
            input.value = row[colIndex] !== undefined ? String(row[colIndex]) : '';
            td.appendChild(input);
            tr.appendChild(td);
            if (colIndex === 0) ligarBuscaContratoLista(input);
        }
        tbody.appendChild(tr);
    });
    tabela.appendChild(tbody);

    container.appendChild(tabela);
}

function adicionarLinhaTabela() {
    const tbody = document.getElementById('tbody-lista');
    if (!tbody) return;
    const tr = document.createElement('tr');
    for (let i = 0; i < 4; i++) {
        const td = document.createElement('td');
        const input = document.createElement('input');
        input.type = 'text';
        td.appendChild(input);
        tr.appendChild(td);
        if (i === 0) ligarBuscaContratoLista(input);
    }
    tbody.appendChild(tr);

    const container = document.getElementById('tabela-lista-container');
    container.scrollTop = container.scrollHeight;
}

// Celula CONTRATO: busca por codigo ou nome do centro de custo, com lista igual a do Adicionar colaborador. So aceita um
// centro da lista, gravado como "Nome - Codigo" (o aviso da Previsao acha o contrato pelo codigo); digitou sem escolher,
// volta o que estava. Linha antiga digitada a mao fica como esta ate alguem trocar.
// Uma lista so, solta no body (position:fixed) logo abaixo da celula: dentro da tabela com rolagem ela ficava cortada e
// com a largura da coluna. Rolar a tabela/pagina ou redimensionar a janela leva a lista junto (posicionarListaContrato);
// celula rolada pra fora da area visivel da tabela fecha a lista (senao ela ficava solta, por cima do SALVAR).
let listaContratoNotificacao = null;
let posicionarListaContrato = () => {};
function ligarBuscaContratoLista(input) {
    let anterior = input.value;
    input.title = anterior;
    input.autocomplete = 'off';
    input.placeholder = 'Código ou nome do contrato';
    const mostrar = () => {
        if (!listaContratoNotificacao) {
            listaContratoNotificacao = document.createElement('div');
            listaContratoNotificacao.className = 'notif-resultados-busca';
            listaContratoNotificacao.style.cssText = 'position:fixed; z-index:10002; margin:0; text-align:left;';
            document.body.appendChild(listaContratoNotificacao);
            document.addEventListener('scroll', e => {
                if (e.target !== listaContratoNotificacao && listaContratoNotificacao.classList.contains('mostrando')) posicionarListaContrato();
            }, true);
            window.addEventListener('resize', () => {
                if (listaContratoNotificacao.classList.contains('mostrando')) posicionarListaContrato();
            });
        }
        const lista = listaContratoNotificacao;
        const busca = normalizarTexto(input.value);
        const mapa = _mapaDescricaoCentroCusto || {};
        const achados = Object.keys(mapa).filter(c => normalizarTexto(`${c} ${mapa[c]}`).includes(busca))
            .sort((a, b) => String(mapa[a]).localeCompare(String(mapa[b]))).slice(0, 30);
        lista.innerHTML = achados.length ? '' : '<div class="notif-resultado-item notif-resultado-vazio">Nenhum contrato encontrado.</div>';
        achados.forEach(c => {
            const item = document.createElement('div');
            item.className = 'notif-resultado-item';
            item.innerHTML = `<b>${escapeHtml(c)}</b> - ${escapeHtml(mapa[c] || '')}`;
            item.onmousedown = e => { // mousedown: antes do blur da caixa fechar a lista
                e.preventDefault();
                input.value = input.title = anterior = `${mapa[c]} - ${c}`;
                input.blur();
            };
            lista.appendChild(item);
        });
        // Abaixo da celula (acima, se nao couber), pelo menos 360px de largura, sem passar da borda da janela.
        posicionarListaContrato = () => {
            const r = input.getBoundingClientRect();
            const area = input.closest('#tabela-lista-container')?.getBoundingClientRect();
            const topo = Math.max(area?.top ?? 0, input.closest('table')?.querySelector('th')?.getBoundingClientRect().bottom ?? 0); // th fixo (sticky)
            if (area && (r.bottom <= topo || r.top >= area.bottom)) { input.blur(); return; }
            const largura = Math.min(Math.max(r.width, 360), window.innerWidth - 16);
            lista.style.width = largura + 'px';
            lista.style.left = Math.max(8, Math.min(r.left, window.innerWidth - largura - 8)) + 'px';
            const cabeAbaixo = window.innerHeight - r.bottom >= 230 || window.innerHeight - r.bottom >= r.top;
            lista.style.top = cabeAbaixo ? (r.bottom + 2) + 'px' : '';
            lista.style.bottom = cabeAbaixo ? '' : (window.innerHeight - r.top + 2) + 'px';
        };
        lista.classList.add('mostrando');
        posicionarListaContrato();
    };
    input.onfocus = () => { input.select(); mostrar(); };
    input.oninput = mostrar;
    input.onblur = () => { listaContratoNotificacao?.classList.remove('mostrando'); input.value = anterior; };
    input.onkeydown = e => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        listaContratoNotificacao?.querySelector('.notif-resultado-item:not(.notif-resultado-vazio)')?.dispatchEvent(new MouseEvent('mousedown', { cancelable: true }));
    };
}

// Botao 'excluir linha': liga o modo de selecao; o clique numa linha exclui e desliga (tbody.onclick em renderizarTabelaNotificacao); clicar no botao de novo desiste.
function removerLinhaTabela() {
    const tbody = document.getElementById('tbody-lista');
    if (!tbody || !tbody.lastElementChild) return;
    document.getElementById('view-lista-box').classList.toggle('modo-excluir-linha');
}

async function salvarLista() {
    const toast = mostrarToastProgresso('Salvando atualizacoes na nuvem...');

    try {
        let novaMatriz = [["CONTRATO", "RESPONSAVEL", "TELEFONE", "LOCALIZACAO"]];
        const rows = document.querySelectorAll('#tbody-lista tr');

        rows.forEach(tr => {
            const inputs = tr.querySelectorAll('input');
            if (inputs.length === 4) {
                novaMatriz.push([inputs[0].value, inputs[1].value, inputs[2].value, inputs[3].value]);
            }
        });

        // Grava a lista nova primeiro, em lote (tudo ou nada), e so depois apaga as antigas: o pior caso e sobrar linha duplicada, e os ids antigos ficam guardados pra proxima tentativa.
        const registros = novaMatriz.slice(1)
            .filter(linha => linha.some(valor => valor))
            .map(([contrato, responsavel, telefone, localizacao]) => ({ contrato, responsavel, telefone, localizacao }));
        const idsAntigos = idsNotificacaoVolante;
        const { ids: novosIds } = await dbSalvarLote('contratos_notificacao', registros);
        idsNotificacaoVolante = [...idsAntigos, ...novosIds];

        if (idsAntigos.length > 0) {
            await dbRemover('contratos_notificacao', idsAntigos);
        }
        idsNotificacaoVolante = novosIds;

        dadosNotificacaoVolante = novaMatriz;
        adicionarNotificacaoUpload("Lista salva e atualizada com sucesso!", "#27ae60");

        cancelarLista();
        atualizarPreview();
        finalizarToastProgresso(toast, 'Lista salva e atualizada com sucesso!', '#27ae60', 2500);

    } catch (e) {
        console.error("Erro ao salvar lista:", e);
        adicionarNotificacaoUpload("Erro ao salvar a lista.", "#e74c3c");
        finalizarToastProgresso(toast, 'Erro ao salvar a lista.', '#e74c3c', 4000);
    }
}

function cancelarLista() {
    document.getElementById('view-lista-box').style.display = 'none';
    document.getElementById('view-preview-box').style.display = 'flex';
}

// Versao compartilhada de formatarExibicao, so pra paginas sem a propria (CDV e SOLICITACAO tem as suas, que substituem esta).
function formatarExibicao(valor, col) {
    if (valor === "" || valor === null || valor === undefined) return "";
    const norm = normalizarTexto(col);

    if (valor instanceof Date || norm.includes("DATA")) {
        let d;
        if (typeof valor === 'number') {
            const utcDate = new Date((valor - 25569) * 86400 * 1000);
            d = new Date(utcDate.getUTCFullYear(), utcDate.getUTCMonth(), utcDate.getUTCDate());
        } else if (typeof valor === 'string') {
            const txt = valor.trim();
            if (txt.includes('/')) {
                const p = txt.split('/');
                if (p.length === 3) d = new Date(p[2], p[1] - 1, p[0]);
                else d = new Date(txt);
            } else {
                d = new Date(txt);
            }
        } else {
            d = new Date(valor);
        }

        if (!d || isNaN(d.getTime())) return valor;
        return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`;
    }

    if (norm.includes("SALARIO") || norm.includes("VALOR")) {
        let num;
        if (typeof valor === 'number') {
            num = valor;
        } else {
            let texto = String(valor).replace(/R\$/gi, '').trim();
            if (texto.includes(',')) {
                texto = texto.replace(/\./g, '').replace(',', '.');
            }
            num = parseFloat(texto);
        }
        return isNaN(num) ? valor : num.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }
    return valor;
}

// ====================================================================
// TOOLTIP FLUTUANTE COMPARTILHADO (ex: codigo da funcao ao passar o mouse)
// ====================================================================
// position: fixed no <body>, igual ao menu de filtro, pra nao ser cortado pelo overflow:hidden da tabela.
function mostrarTooltip(texto, elementoAlvo) {
    let tip = document.getElementById('tooltip-flutuante');
    if (!tip) {
        tip = document.createElement('div');
        tip.id = 'tooltip-flutuante';
        tip.className = 'tooltip-flutuante';
        document.body.appendChild(tip);
    }
    tip.textContent = texto;
    tip.style.display = 'block';

    const rect = elementoAlvo.getBoundingClientRect();
    tip.style.left = rect.left + 'px';
    tip.style.top = (rect.bottom + 6) + 'px';
}

function esconderTooltip() {
    const tip = document.getElementById('tooltip-flutuante');
    if (tip) tip.style.display = 'none';
}


// Em tela estreita a tabela (cabecalho + corpo, elementos separados) rola na horizontal; sincroniza a rolagem nos dois sentidos pra colunas nao desalinharem (origemAtual evita loop).
function sincronizarRolagemHorizontalTabelas() {
    document.querySelectorAll('.tabela-visualizacao').forEach(container => {
        const cabecalho = container.querySelector(':scope > .tabela-cabecalho-fixo');
        const corpo = container.querySelector(':scope > .tabela-corpo-rolavel');
        if (!cabecalho || !corpo || cabecalho.dataset.rolagemSincronizada) return;
        cabecalho.dataset.rolagemSincronizada = '1';

        let origemAtual = null;
        corpo.addEventListener('scroll', () => {
            if (origemAtual === 'cabecalho') return;
            origemAtual = 'corpo';
            cabecalho.scrollLeft = corpo.scrollLeft;
            origemAtual = null;
        });
        cabecalho.addEventListener('scroll', () => {
            if (origemAtual === 'corpo') return;
            origemAtual = 'cabecalho';
            corpo.scrollLeft = cabecalho.scrollLeft;
            origemAtual = null;
        });
    });
}
window.addEventListener('load', sincronizarRolagemHorizontalTabelas);

// Mapa central das cores de linha; a bolinha do filtro vem sempre daqui. Cor nova = uma entrada aqui.
const MAPA_CORES_LINHA = {
    '': { cor: '#ffffff', label: 'Normal' },
    'linha-aviso': { cor: '#d4b8e3', label: 'Aviso (Roxo)' },
    'linha-transferencia': { cor: '#eaf4fc', label: 'Transf (Azul)' },
    'linha-experiencia': { cor: '#ffcccc', label: 'Exp (Vermelho)' },
    'linha-duplicada': { cor: '#edebd7', label: 'Duplicados' }
};

// Gera uma bolinha de filtro por classe de cor presente nos dados (cor vinda de MAPA_CORES_LINHA).
function gerarBolinhasCor(coresAtivas) {
    let html = '';
    coresAtivas.forEach(classe => {
        const info = MAPA_CORES_LINHA[classe];
        if (!info) return; // classe sem cor mapeada ainda - ignora silenciosamente
        html += `<div class="color-swatch" style="background-color: ${info.cor};" title="${info.label}" onclick="aplicarOrdenacaoCor('${classe}')"></div>`;
    });
    return html;
}

// Abre e fecha os menus dropdown de classificação A-Z e caixas de busca
function toggleDropdown(col) {
    const dd = document.getElementById(`dropdown-${col}`);
    const isShow = dd.classList.contains('show');

    // Mata as que tao aberta menu (e devolve cada uma pro lugar de origem)
    document.querySelectorAll('.multi-select-dropdown').forEach(d => {
        d.classList.remove('show');
        d.style.position = '';
        d.style.top = '';
        d.style.left = '';
        if (d._paiOriginal && d.parentElement !== d._paiOriginal) {
            d._paiOriginal.appendChild(d);
        }
    });

    // Abre se estava morta
    if (!isShow) {
        // Guarda de onde o menu veio, pra devolver quando fechar
        if (!dd._paiOriginal) dd._paiOriginal = dd.parentElement;

        // Ancora pela celula (th) inteira, nao pelo botao do funil (encostado na direita, abriria o painel sobre a proxima coluna).
        const celula = dd._paiOriginal.closest('th');
        const botao = dd._paiOriginal.querySelector('.multi-select-button');
        const rect = celula ? celula.getBoundingClientRect()
            : (botao ? botao.getBoundingClientRect() : dd.getBoundingClientRect());

        // Move o menu pro final do <body>: ancestral com sticky/transform prende position:fixed no proprio contexto, deixando o menu sob o cabecalho de outras colunas.
        document.body.appendChild(dd);

        dd.classList.add('show');
        dd.style.position = 'fixed';
        dd.style.top = (rect.bottom + 2) + 'px';
        dd.style.left = rect.left + 'px';

        const inp = dd.querySelector('.filter-search-input');
        if (inp) {
            inp.value = '';
            inp.focus();
        }
        filtrarListaInterna(col, '');
    }
}

// ====================================================================
// FILTRO HIERARQUICO DE DATAS (Ano > Mes > Dia, ao estilo Excel)
// ====================================================================

const MESES_FILTRO = ["Janeiro", "Fevereiro", "Marco", "Abril", "Maio", "Junho",
                       "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];

// Decide se uma coluna deve ganhar o filtro hierarquico: quando a maioria dos
// valores unicos exibidos e uma data no formato DD/MM/AAAA.
function ehColunaDeData(valoresUnicos) {
    if (!valoresUnicos || valoresUnicos.length === 0) return false;
    const comData = valoresUnicos.filter(v => /^\d{2}\/\d{2}\/\d{4}$/.test(v));
    return comData.length > 0 && (comData.length / valoresUnicos.length) >= 0.8;
}

// Monta a arvore Ano -> Mes -> [{dia, valor}], a partir da lista de valores
// unicos ja formatados como DD/MM/AAAA.
function agruparValoresPorData(valoresUnicos) {
    const arvore = {};
    valoresUnicos.forEach(val => {
        const m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(val);
        if (!m) return;
        const dia = parseInt(m[1], 10);
        const nomeMes = MESES_FILTRO[parseInt(m[2], 10) - 1] || m[2];
        const ano = m[3];
        if (!arvore[ano]) arvore[ano] = {};
        if (!arvore[ano][nomeMes]) arvore[ano][nomeMes] = [];
        arvore[ano][nomeMes].push({ dia, valor: val });
    });
    return arvore;
}

// Grupos (ano/mes) expandidos; fica fora do DOM porque a lista e reconstruida a cada clique de filtro.
const gruposExpandidosFiltro = new Set();

function chaveGrupoFiltro(col, ...partes) {
    return col + '|' + partes.join('|');
}

// Alterna a visibilidade dos filhos de um grupo (Ano ou Mes) sem mexer na selecao
function toggleExpandirGrupoFiltro(chave, container, seta, event) {
    if (event) event.stopPropagation();
    const abrir = !container.classList.contains('expandido');
    container.classList.toggle('expandido', abrir);
    seta.classList.toggle('aberta', abrir);
    if (abrir) gruposExpandidosFiltro.add(chave);
    else gruposExpandidosFiltro.delete(chave);
}

// Marca/desmarca todos os valores de um grupo; so atualiza a selecao pendente (ver confirmarFiltro), mas atualiza os checkboxes-folha na hora.
function toggleGrupoFiltro(col, valoresDoGrupo, marcar) {
    filtrosAtivos[col] ||= []; // coluna que a pagina esqueceu de inicializar nao trava o filtro
    valoresDoGrupo.forEach(val => {
        const idx = filtrosAtivos[col].indexOf(val);
        if (marcar && idx === -1) filtrosAtivos[col].push(val);
        else if (!marcar && idx > -1) filtrosAtivos[col].splice(idx, 1);
    });

    const container = document.getElementById(`items-${col}`);
    if (container) {
        const valoresSet = new Set(valoresDoGrupo);
        container.querySelectorAll('.multi-select-item input').forEach(input => {
            if (valoresSet.has(input.value)) input.checked = marcar;
        });
    }
}

// 'vazio' | 'parcial' | 'total' - quantos dos valores do grupo estao marcados
function estadoGrupoFiltro(col, valoresDoGrupo) {
    filtrosAtivos[col] ||= []; // coluna que a pagina esqueceu de inicializar nao trava o filtro
    const marcados = valoresDoGrupo.filter(v => filtrosAtivos[col].includes(v)).length;
    if (marcados === 0) return 'vazio';
    if (marcados === valoresDoGrupo.length) return 'total';
    return 'parcial';
}

// Linha de grupo (Ano ou Mes): checkbox, seta de expandir e container dos filhos. 'chave' (ex: "DATA ADMIS.|2025|Janeiro") lembra se estava expandido.
function criarGrupoFiltro(col, rotulo, valoresDoGrupo, chave) {
    const grupo = document.createElement('div');
    grupo.className = 'filtro-data-grupo';

    const linha = document.createElement('div');
    linha.className = 'filtro-data-grupo-linha';

    const jaExpandido = gruposExpandidosFiltro.has(chave);

    const seta = document.createElement('span');
    seta.className = 'filtro-data-seta' + (jaExpandido ? ' aberta' : '');
    seta.textContent = '▸';

    const label = document.createElement('label');
    const chk = document.createElement('input');
    chk.type = 'checkbox';
    const estado = estadoGrupoFiltro(col, valoresDoGrupo);
    chk.checked = estado === 'total';
    chk.indeterminate = estado === 'parcial';
    chk.addEventListener('change', () => toggleGrupoFiltro(col, valoresDoGrupo, chk.checked));
    label.appendChild(chk);
    label.appendChild(document.createTextNode(' ' + rotulo));

    linha.appendChild(seta);
    linha.appendChild(label);

    const filhos = document.createElement('div');
    filhos.className = 'filtro-data-filhos' + (jaExpandido ? ' expandido' : '');
    filhos.dataset.chaveGrupo = chave;

    seta.addEventListener('click', (e) => toggleExpandirGrupoFiltro(chave, filhos, seta, e));
    linha.addEventListener('click', (e) => {
        if (e.target === seta) return;
        if (e.target === chk || e.target === label) return;
        toggleExpandirGrupoFiltro(chave, filhos, seta, e);
    });

    grupo.appendChild(linha);
    grupo.appendChild(filhos);
    return { grupo, filhos };
}

// Cria a linha de uma data exata (folha da arvore), igual a um item normal
// da lista simples.
// Monta via DOM (nao innerHTML): 'val' vem de planilha importada e poderia injetar HTML/JS.
function criarItemFiltro(col, val) {
    filtrosAtivos[col] ||= []; // coluna que a pagina esqueceu de inicializar nao trava o filtro
    const div = document.createElement('div');
    div.className = 'multi-select-item';

    const label = document.createElement('label');
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.value = val;
    input.checked = filtrosAtivos[col].includes(val);
    input.addEventListener('change', () => toggleFiltro(col, val));

    label.appendChild(input);
    label.appendChild(document.createTextNode(val));
    div.appendChild(label);
    return div;
}

// Troca o rotulo dos botoes de ordenacao em colunas de data ('Mais antigo -> Mais novo'); so se os botoes existirem na pagina.
function atualizarRotulosOrdenacao(col, ehData) {
    const btnAsc = document.getElementById(`btn-sort-asc-${col}`);
    const btnDesc = document.getElementById(`btn-sort-desc-${col}`);
    if (btnAsc) btnAsc.textContent = ehData ? '📅 Mais antigo → Mais novo' : '⬇️ A a Z / Menor para Maior';
    if (btnDesc) btnDesc.textContent = ehData ? '📅 Mais novo → Mais antigo' : '⬆️ Z a A / Maior para Menor';
}

// Ponto de entrada usado por todas as paginas: renderiza a lista de opcoes de
// um dropdown de filtro dentro de "container". Se os valores parecerem datas
// (DD/MM/AAAA), desenha a arvore Ano > Mes > Dia (o ano so aparece se houver
// mais de um ano diferente nos dados); senao, desenha a lista simples de sempre.
function renderizarListaFiltro(col, valoresUnicos, container) {
    container.innerHTML = '';

    const ehData = ehColunaDeData(valoresUnicos);
    atualizarRotulosOrdenacao(col, ehData);

    if (!ehData) {
        valoresUnicos.forEach(val => container.appendChild(criarItemFiltro(col, val)));
        return;
    }

    const arvore = agruparValoresPorData(valoresUnicos);
    const anos = Object.keys(arvore).sort();
    const mostrarAno = anos.length > 1;

    anos.forEach(ano => {
        const mesesDoAno = arvore[ano];
        const nomesMeses = Object.keys(mesesDoAno).sort((a, b) => MESES_FILTRO.indexOf(a) - MESES_FILTRO.indexOf(b));
        const valoresDoAno = nomesMeses.flatMap(m => mesesDoAno[m].map(d => d.valor));

        const containerMeses = (() => {
            if (!mostrarAno) return container;
            const { grupo, filhos } = criarGrupoFiltro(col, ano, valoresDoAno, chaveGrupoFiltro(col, ano));
            container.appendChild(grupo);
            return filhos;
        })();

        nomesMeses.forEach(nomeMes => {
            const diasDoMes = mesesDoAno[nomeMes].slice().sort((a, b) => a.dia - b.dia);
            const valoresDoMes = diasDoMes.map(d => d.valor);

            const chaveMes = mostrarAno ? chaveGrupoFiltro(col, ano, nomeMes) : chaveGrupoFiltro(col, nomeMes);
            const { grupo: grupoMes, filhos: containerDias } = criarGrupoFiltro(col, nomeMes, valoresDoMes, chaveMes);
            containerMeses.appendChild(grupoMes);

            diasDoMes.forEach(({ valor }) => containerDias.appendChild(criarItemFiltro(col, valor)));
        });
    });

    // Valor que nao e data (ex: '-') numa coluna de datas fica fora da arvore: sem isto, nao dava pra filtrar por ele.
    valoresUnicos.filter(v => !/^\d{2}\/\d{2}\/\d{4}$/.test(v)).forEach(val => container.appendChild(criarItemFiltro(col, val)));
}

// Esconde os checkboxes que não condizem com o termo digitado na busca interna
function filtrarListaInterna(col, termo) {
    const busca = termo.toLowerCase();
    const container = document.getElementById(`items-${col}`);
    if (!container) return;

    container.querySelectorAll('.multi-select-item').forEach(item => {
        item.style.display = item.innerText.toLowerCase().includes(busca) ? 'flex' : 'none';
    });

    // Na arvore de datas (Ano/Mes), mostra e expande so os grupos que tem
    // algum dia visivel pra busca digitada; esconde os que nao tem nenhum.
    container.querySelectorAll('.filtro-data-grupo').forEach(grupo => {
        const temItemVisivel = Array.from(grupo.querySelectorAll('.multi-select-item'))
            .some(item => item.style.display !== 'none');
        grupo.style.display = (busca === '' || temItemVisivel) ? '' : 'none';

        const filhos = grupo.querySelector(':scope > .filtro-data-filhos');
        const seta = grupo.querySelector(':scope > .filtro-data-grupo-linha > .filtro-data-seta');
        if (!filhos || !seta) return;

        if (busca !== '' && temItemVisivel) {
            // Expansao temporaria so pra mostrar o resultado da busca
            filhos.classList.add('expandido');
            seta.classList.add('aberta');
        } else if (busca === '') {
            // Ao limpar a busca, volta pro estado real (que o usuario escolheu
            // clicando na seta), em vez de forcar fechado
            const expandidoPersistido = gruposExpandidosFiltro.has(filhos.dataset.chaveGrupo);
            filhos.classList.toggle('expandido', expandidoPersistido);
            seta.classList.toggle('aberta', expandidoPersistido);
        }
    });
}

// Seleciona/limpa todos os itens-folha; checkboxes de grupo ficam de fora. So atualiza a selecao pendente, nao filtra (ver confirmarFiltro).
function marcarTodos(col, status) {
    filtrosAtivos[col] ||= []; // coluna que a pagina esqueceu de inicializar nao trava o filtro
    const container = document.getElementById(`items-${col}`);
    if (!container) return;

    container.querySelectorAll('.multi-select-item input').forEach(c => {
        if (c.closest('.multi-select-item').style.display !== 'none') {
            c.checked = status;
            const idx = filtrosAtivos[col].indexOf(c.value);

            if (status && idx === -1) {
                filtrosAtivos[col].push(c.value);
            } else if (!status && idx > -1) {
                filtrosAtivos[col].splice(idx, 1);
            }
        }
    });
}

// Ativa/desativa uma tag; so atualiza a selecao pendente (ver confirmarFiltro).
function toggleFiltro(col, val) {
    filtrosAtivos[col] ||= []; // coluna que a pagina esqueceu de inicializar nao trava o filtro
    const idx = filtrosAtivos[col].indexOf(val);
    if (idx > -1) {
        filtrosAtivos[col].splice(idx, 1);
    } else {
        filtrosAtivos[col].push(val);
    }
}

// 'Aplicar': a selecao pendente vira filtro (aplicarLogica, de cada pagina) e o dropdown volta pro lugar original no DOM.
function confirmarFiltro(col) {
    if (typeof aplicarLogica === 'function') aplicarLogica();

    const dd = document.getElementById(`dropdown-${col}`);
    if (!dd) return;
    dd.classList.remove('show');
    dd.style.position = '';
    dd.style.top = '';
    dd.style.left = '';
    if (dd._paiOriginal && dd.parentElement !== dd._paiOriginal) {
        dd._paiOriginal.appendChild(dd);
    }
}

// Dispara a ordenação alfanumérica
function aplicarOrdenacao(coluna, tipo) {
    ordenacaoAtual.coluna = coluna;
    ordenacaoAtual.tipo = tipo;
    ordenacaoAtual.cor = null;
    ordenacaoAtual.corPrioridade = [];
    aplicarLogica();
    document.querySelectorAll('.multi-select-dropdown').forEach(d => {
        d.classList.remove('show');
    });
}

// Ordena por cor: cada clique joga a cor pro topo da fila de prioridade (clicar numa ja selecionada tira da fila); a mais recente vence.
function aplicarOrdenacaoCor(classeCor) {
    if (!Array.isArray(ordenacaoAtual.corPrioridade)) ordenacaoAtual.corPrioridade = [];

    const idx = ordenacaoAtual.corPrioridade.indexOf(classeCor);
    if (idx > -1) {
        ordenacaoAtual.corPrioridade.splice(idx, 1);
    } else {
        ordenacaoAtual.corPrioridade.unshift(classeCor);
    }

    ordenacaoAtual.coluna = null;
    ordenacaoAtual.cor = classeCor;
    ordenacaoAtual.tipo = ordenacaoAtual.corPrioridade.length > 0 ? 'COR' : null;

    aplicarLogica();
    document.querySelectorAll('.multi-select-dropdown').forEach(d => {
        d.classList.remove('show');
    });
}

// Ordena priorizando as cores na ordem em que o usuario foi clicando (a primeira
// clicada fica no topo, a segunda logo em seguida, etc). Linhas com cores fora da
// fila de prioridade mantem a posicao relativa entre si (sort estavel), no final.
function ordenarPorPrioridadeCor(dados, prioridadeCores) {
    return [...dados].sort((a, b) => {
        const classA = obterClasseCorLinha(a);
        const classB = obterClasseCorLinha(b);
        const idxA = prioridadeCores.indexOf(classA);
        const idxB = prioridadeCores.indexOf(classB);
        const rankA = idxA === -1 ? Infinity : idxA;
        const rankB = idxB === -1 ? Infinity : idxB;
        return rankA - rankB;
    });
}

// Number() (estrito) em vez de parseFloat, que classificaria codigos como '01V001' ou '21MT002' como numeros.
function ehValorNumericoParaOrdenacao(valorLimpo) {
    return valorLimpo.trim() !== "" && !isNaN(Number(valorLimpo));
}

// Comparador generico de ordenacao usado por todas as paginas do sistema: lida
// com datas (dd/mm/aaaa), numeros/moeda e texto, sem confundir codigos
// alfanumericos com numeros.
function compararParaOrdenacao(valA, valB, tipo) {
    valA = String(valA);
    valB = String(valB);

    if (valA === "" && valB !== "") return 1;
    if (valB === "" && valA !== "") return -1;
    if (valA === "" && valB === "") return 0;

    const isDateA = /^\d{2}\/\d{2}\/\d{4}$/.test(valA);
    const isDateB = /^\d{2}\/\d{2}\/\d{4}$/.test(valB);

    if (isDateA && isDateB) {
        const pA = valA.split('/');
        const pB = valB.split('/');
        const dA = new Date(pA[2], pA[1] - 1, pA[0]).getTime();
        const dB = new Date(pB[2], pB[1] - 1, pB[0]).getTime();
        return tipo === 'ASC' ? dA - dB : dB - dA;
    }

    // Ponto so e milhar quando ha virgula ('R$ 1.862,08'); sem virgula e decimal (1862.08).
    const limpar = (v) => {
        const texto = v.replace(/[R$\s]/g, '');
        return texto.includes(',') ? texto.replace(/\./g, '').replace(',', '.') : texto;
    };
    const limpoA = limpar(valA);
    const limpoB = limpar(valB);

    const isNumA = ehValorNumericoParaOrdenacao(limpoA);
    const isNumB = ehValorNumericoParaOrdenacao(limpoB);

    if (isNumA && isNumB) {
        const numA = parseFloat(limpoA);
        const numB = parseFloat(limpoB);
        return tipo === 'ASC' ? numA - numB : numB - numA;
    }

    const baixoA = valA.toLowerCase();
    const baixoB = valB.toLowerCase();
    if (baixoA < baixoB) return tipo === 'ASC' ? -1 : 1;
    if (baixoA > baixoB) return tipo === 'ASC' ? 1 : -1;
    return 0;
}

// ====================================================================
// PERSISTENCIA DE FILTROS ENTRE PAGINAS (cada pagina guarda o seu proprio)
// ====================================================================
function chaveFiltroPersistente() {
    return 'filtros_' + window.location.pathname;
}

// Salva o estado atual de filtros e ordenacao no localStorage, pra sobreviver
// a navegacao pra outra pagina e volta.
function salvarFiltrosPersistentes() {
    try {
        localStorage.setItem(chaveFiltroPersistente(), JSON.stringify({
            filtrosAtivos: filtrosAtivos,
            ordenacaoAtual: ordenacaoAtual
        }));
    } catch (e) { /* localStorage indisponivel - ignora silenciosamente */ }
}

// Restaura os filtros e a ordenacao salvos, mesclando por cima da estrutura que
// a pagina acabou de montar (assim colunas que nao existem mais nessa importacao
// sao ignoradas em vez de quebrar).
function carregarFiltrosPersistentes() {
    try {
        const salvo = localStorage.getItem(chaveFiltroPersistente());
        if (!salvo) return;
        const dados = JSON.parse(salvo);

        if (dados.filtrosAtivos && typeof filtrosAtivos === 'object') {
            Object.keys(filtrosAtivos).forEach(col => {
                if (Array.isArray(dados.filtrosAtivos[col])) {
                    filtrosAtivos[col] = dados.filtrosAtivos[col];
                }
            });
        }

        if (dados.ordenacaoAtual && typeof ordenacaoAtual === 'object') {
            ordenacaoAtual.coluna = dados.ordenacaoAtual.coluna || null;
            ordenacaoAtual.tipo = dados.ordenacaoAtual.tipo || null;
            ordenacaoAtual.cor = dados.ordenacaoAtual.cor || null;
            ordenacaoAtual.corPrioridade = Array.isArray(dados.ordenacaoAtual.corPrioridade)
                ? dados.ordenacaoAtual.corPrioridade
                : [];
        }
    } catch (e) { /* ignora */ }
}

// Remove todas as seleções de filtros ativos e devolve a tabela ao estado original
function resetarFiltros() {
    if (typeof filtrosAtivos === 'object') {
        Object.keys(filtrosAtivos).forEach(col => filtrosAtivos[col] = []);
    }
    ordenacaoAtual.coluna = null;
    ordenacaoAtual.tipo = null;
    ordenacaoAtual.cor = null;
    ordenacaoAtual.corPrioridade = [];
    aplicarLogica();
}

// ====================================================================
// FECHAMENTO AUTOMÁTICO AO CLICAR FORA DOS FILTROS
// ====================================================================
document.addEventListener('click', function(e) {
    // closest (nao matches): o clique pode cair no span interno do botao e fecharia o menu no mesmo clique que o abre.
    if (!e.target.closest('.multi-select-button') && !e.target.closest('.multi-select-dropdown')) {
        document.querySelectorAll('.multi-select-dropdown').forEach(d => {
            d.classList.remove('show');
        });
    }
});

// ====================================================================
// FECHAMENTO AUTOMÁTICO AO CLICAR FORA DO MENU DA HOTBAR (COLABORADORES/
// Igual ao listener acima, mas pro .hotbar-dropdown-content.
document.addEventListener('click', function(e) {
    if (!e.target.matches('.hotbar-btn') && !e.target.closest('.hotbar-dropdown-content')) {
        document.querySelectorAll('.hotbar-dropdown-content').forEach(d => {
            d.classList.remove('show');
        });
    }
});

// ====================================================================
// UTILITARIOS DE TEXTO/NUMERO COMPARTILHADOS ENTRE TODAS AS PAGINAS
// ====================================================================

// Remove acentos, pontos e espacos, e converte pra maiuscula - usado pra
// comparar cabecalhos/valores de forma tolerante a variacao de digitacao.
function normalizarTexto(t) {
    return String(t)
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "")
        .replace(/[.\s]/g, "")
        .toUpperCase();
}

// Escapa um valor pra poder ser inserido com seguranca dentro de HTML
// montado por template string (innerHTML) - tanto em texto quanto dentro de
// atributo (value="..."). Usar sempre que o valor vier de planilha
// importada ou de um campo editavel, pra evitar injecao de HTML/JS (XSS).
// Preferir textContent/innerText direto quando der; isso aqui e pros casos
// em que o HTML e montado como uma string grande e reescrever tudo via DOM
// seria arriscado.
function escapeHtml(valor) {
    return String(valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Remove zeros a esquerda e qualquer sufixo decimal de uma matricula.
function normalizarMatricula(v) {
    return String(v || "").trim().replace(/^0+/, '').split('.')[0];
}

// Formata um numero como moeda brasileira (R$ 0,00). Vazio/nulo vira zero.
function formatarMoeda(v) {
    return (v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

// ====================================================================
// TOASTS FLUTUANTES E PAINEL DE NOTIFICACOES (SINO), COMPARTILHADOS
// ====================================================================

// Altura base (px) dos toasts, reserva pra paginas sem .hotbar; nas demais reposicionarToasts() mede o cabecalho.
let TOAST_TOP_BASE = 75;

function mostrarToast(mensagem, cor = '#006f73') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    reposicionarToasts();

    const toast = document.createElement('div');
    toast.className = 'toast-msg';
    toast.style.borderLeftColor = cor;
    // textContent (nao innerHTML): "mensagem" pode conter o nome de um
    // colaborador vindo de planilha importada (ver gerarNotificacoes).
    const divMsg = document.createElement('div');
    divMsg.textContent = mensagem;
    toast.appendChild(divMsg);
    container.appendChild(toast);

    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
        reposicionarToasts();
    }, 3000);
}

// Mostra (e consome) um toast deixado por outra pagina antes de redirecionar pra ca (ver escopo de Gestor/Preposto abaixo).
function consumirToastPosRedirecionamento() {
    const bruto = sessionStorage.getItem('toast_pos_redirecionamento');
    if (!bruto) return;
    sessionStorage.removeItem('toast_pos_redirecionamento');
    try {
        const { msg, cor } = JSON.parse(bruto);
        if (msg) mostrarToast(msg, cor);
    } catch (e) { /* ignora chave corrompida */ }
}
document.addEventListener('DOMContentLoaded', consumirToastPosRedirecionamento);

// Toast 'vivo' pra operacoes demoradas (upload, importacao, sync): fica com spinner ate ser finalizado.
// Guarde o retorno e passe pra atualizarToastProgresso/finalizarToastProgresso.
function mostrarToastProgresso(mensagem, cor = '#006f73') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    reposicionarToasts();

    const toast = document.createElement('div');
    toast.className = 'toast-msg toast-progresso';
    toast.style.borderLeftColor = cor;
    const spinner = document.createElement('div');
    spinner.className = 'toast-spinner';
    // textContent (nao innerHTML): "mensagem" pode conter dado vindo de
    // planilha importada, mesma cautela do mostrarToast normal.
    const divMsg = document.createElement('div');
    divMsg.textContent = mensagem;
    toast.appendChild(spinner);
    toast.appendChild(divMsg);
    container.appendChild(toast);

    return toast;
}

// Atualiza texto (e cor opcional) de um toast de progresso aberto; sem efeito se ja foi finalizado (toast pode vir undefined).
function atualizarToastProgresso(toast, mensagem, cor) {
    if (!toast || !toast.parentNode) return;
    toast.lastChild.textContent = mensagem;
    if (cor) toast.style.borderLeftColor = cor;
}

// Encerra um toast de progresso: tira o spinner, mostra a mensagem final por duracaoMs e some. Chamar sempre, inclusive no catch, senao o toast fica preso.
function finalizarToastProgresso(toast, mensagemFinal, cor, duracaoMs = 3000) {
    if (!toast || !toast.parentNode) return;
    toast.classList.remove('toast-progresso');
    const spinnerEl = toast.querySelector('.toast-spinner');
    if (spinnerEl) spinnerEl.remove();
    toast.lastChild.textContent = mensagemFinal;
    if (cor) toast.style.borderLeftColor = cor;

    setTimeout(() => {
        toast.classList.add('toast-fechando');
        setTimeout(() => {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
            reposicionarToasts();
        }, 500);
    }, duracaoMs);
}

function reposicionarToasts() {
    const container = document.getElementById('toast-container');
    if (!container) return;

    // Mede a altura real do cabecalho (nav.hotbar) em vez de usar um numero
    // fixo, pra nunca sobrepor o cabecalho mesmo se ele mudar de altura.
    const hotbar = document.querySelector('.hotbar');
    const baseTop = hotbar ? Math.round(hotbar.getBoundingClientRect().bottom) + 10 : TOAST_TOP_BASE;

    const painel = document.getElementById('painel-notificacoes');
    if (painel && painel.classList.contains('show')) {
        const alturaPainel = painel.offsetHeight;
        container.style.top = (baseTop + alturaPainel + 10) + 'px';
    } else {
        container.style.top = baseTop + 'px';
    }
}

function toggleNotificacoes() {
    const painel = document.getElementById('painel-notificacoes');
    painel.classList.toggle('show');
    if (painel.classList.contains('show')) {
        document.getElementById('badge-sino').style.display = 'none';
    }
    reposicionarToasts();
}

// Dispensa um alerta (remove do array salvo em localStorage, por indice, e redesenha). E 'dispensar por agora':
// se gerarNotificacoes recalcular e a condicao persistir, o alerta volta.
function dispensarNotificacao(indice) {
    const alertasStr = localStorage.getItem('notificacoes_sistema');
    const alertas = alertasStr ? JSON.parse(alertasStr) : [];
    alertas.splice(indice, 1);
    localStorage.setItem('notificacoes_sistema', JSON.stringify(alertas));
    renderizarNotificacoes();
}

// ====================================================================
// CAIXA DE NOTIFICACAO DO SERVIDOR (backend/api/notificacoes.php)
// ====================================================================
// Avisos que outra pessoa gerou pra este usuario (ausencia de cobertura, cancelamento de solicitacao ja coberta) ficam no banco;
// busca no carregamento e mostra no sino, mais novo em cima, antes dos alertas locais. O X marca como lido no servidor.
// O balao aparece 1 vez por aviso neste navegador (ids em localStorage).
let notificacoesServidor = [];

function corNotificacaoServidor(tipo) {
    return tipo === 'ausencia' ? '#e74c3c' : tipo === 'cancelamento' ? '#f08231' : '#006f73';
}

async function carregarNotificacoesServidor() {
    try {
        const resp = await fetch(`${HOSTINGER_API_URL}/notificacoes.php`, {
            headers: { 'X-Session-Token': tokenSessaoAtual() },
        });
        if (!resp.ok) return;
        const corpo = await resp.json().catch(() => ({}));
        notificacoesServidor = Array.isArray(corpo.notificacoes) ? corpo.notificacoes : [];
    } catch (e) {
        console.error('Erro ao buscar notificacoes:', e);
        return;
    }
    renderizarNotificacoes();

    let avisadas = [];
    try { avisadas = JSON.parse(localStorage.getItem('notificacoesServidorAvisadas') || '[]'); } catch (e) { avisadas = []; }
    const novas = notificacoesServidor.filter(n => !avisadas.includes(String(n.id)));
    if (novas.length > 3) {
        mostrarToast(`Você tem ${novas.length} avisos novos no sino.`, '#e74c3c');
    } else {
        novas.slice().reverse().forEach(n => mostrarToast(n.mensagem, corNotificacaoServidor(n.tipo)));
    }
    try {
        // So os nao lidos de agora - os lidos nunca voltam, nao precisam ficar guardados.
        localStorage.setItem('notificacoesServidorAvisadas', JSON.stringify(notificacoesServidor.map(n => String(n.id))));
    } catch (e) { /* sem localStorage o balao so se repete, nada quebra */ }
}

async function dispensarNotificacaoServidor(id) {
    notificacoesServidor = notificacoesServidor.filter(n => n.id !== id);
    renderizarNotificacoes();
    try {
        await fetch(`${HOSTINGER_API_URL}/notificacoes.php?acao=lida`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Session-Token': tokenSessaoAtual() },
            body: JSON.stringify({ id }),
        });
    } catch (e) {
        console.error('Erro ao marcar notificacao como lida:', e); // volta na proxima pagina, nada se perde
    }
}

function itemNotificacaoServidor(n) {
    const item = document.createElement('div');
    item.className = 'item-notificacao';
    item.style.position = 'relative';
    item.style.borderLeft = `4px solid ${corNotificacaoServidor(n.tipo)}`;
    // textContent: a mensagem leva nome de colaborador/volante.
    const divMsg = document.createElement('div');
    divMsg.textContent = n.mensagem;
    divMsg.style.paddingRight = '22px';
    const divData = document.createElement('div');
    divData.className = 'data-hora';
    const [data, hora] = String(n.criado_em || '').split(' ');
    const [, mes, dia] = (data || '').split('-');
    divData.textContent = dia ? `${dia}/${mes} às ${(hora || '').slice(0, 5)}` : '';
    const btnDispensar = document.createElement('button');
    btnDispensar.type = 'button';
    btnDispensar.className = 'btn-dispensar-notificacao';
    btnDispensar.title = 'Marcar como lido';
    btnDispensar.innerHTML = '&times;';
    btnDispensar.onclick = (e) => { e.stopPropagation(); dispensarNotificacaoServidor(n.id); };
    item.appendChild(btnDispensar);
    item.appendChild(divMsg);
    item.appendChild(divData);
    return item;
}

function renderizarNotificacoes() {
    const lista = document.getElementById('lista-notificacoes');
    const badge = document.getElementById('badge-sino');

    const alertasStr = localStorage.getItem('notificacoes_sistema');
    const alertas = alertasStr ? JSON.parse(alertasStr) : [];

    lista.innerHTML = '';
    notificacoesServidor.forEach(n => lista.appendChild(itemNotificacaoServidor(n)));
    const total = alertas.length + notificacoesServidor.length;

    if (total === 0) {
        lista.innerHTML = '<div class="notificacao-vazia">Nenhum alerta pendente.</div>';
        badge.style.display = 'none';
    } else {
        alertas.forEach((alerta, indice) => {
            const item = document.createElement('div');
            item.className = 'item-notificacao';
            item.style.position = 'relative';
            // textContent (nao innerHTML): alerta.msg embute o nome de um
            // colaborador vindo de planilha importada.
            const divMsg = document.createElement('div');
            divMsg.textContent = alerta.msg;
            divMsg.style.paddingRight = '22px';
            const divData = document.createElement('div');
            divData.className = 'data-hora';
            divData.textContent = 'Data Alvo: ' + alerta.dataBr;
            const btnDispensar = document.createElement('button');
            btnDispensar.type = 'button';
            btnDispensar.className = 'btn-dispensar-notificacao';
            btnDispensar.title = 'Dispensar este alerta';
            btnDispensar.innerHTML = '&times;';
            btnDispensar.onclick = (e) => { e.stopPropagation(); dispensarNotificacao(indice); };
            item.appendChild(btnDispensar);
            item.appendChild(divMsg);
            item.appendChild(divData);
            lista.appendChild(item);
        });

        const painel = document.getElementById('painel-notificacoes');
        if (!painel.classList.contains('show')) {
            badge.innerText = total;
            badge.style.display = 'flex';
        }
    }
}

// Adiciona uma notificacao "instantanea" (nao gerada por gerarNotificacoes)
// na gaveta do sino, e dispara o toast correspondente. Usado apos acoes do
// usuario como upload/exportacao/importacao.
function adicionarNotificacaoUpload(mensagem, cor = '#006f73') {
    const lista = document.getElementById('lista-notificacoes');
    const badge = document.getElementById('badge-sino');
    const vazia = lista.querySelector('.notificacao-vazia');

    if (vazia) vazia.remove();

    const agora = new Date();
    const horaStr = `${String(agora.getHours()).padStart(2, '0')}:${String(agora.getMinutes()).padStart(2, '0')}`;
    const dataStr = `${String(agora.getDate()).padStart(2, '0')}/${String(agora.getMonth() + 1).padStart(2, '0')}`;

    const item = document.createElement('div');
    item.className = 'item-notificacao';
    item.style.borderLeft = `4px solid ${cor}`;
    // textContent (nao innerHTML): "mensagem" pode incluir dados vindos de
    // planilha (ex: nome de colaborador na mensagem de importacao).
    const divMsg = document.createElement('div');
    divMsg.textContent = mensagem;
    const divData = document.createElement('div');
    divData.className = 'data-hora';
    divData.style.color = '#aaa';
    divData.textContent = `${dataStr} às ${horaStr}`;
    item.appendChild(divMsg);
    item.appendChild(divData);
    lista.prepend(item);

    const painel = document.getElementById('painel-notificacoes');
    if (!painel.classList.contains('show')) {
        let atual = parseInt(badge.innerText) || 0;
        badge.innerText = atual + 1;
        badge.style.display = 'flex';
    }

    mostrarToast(mensagem, cor);
}

// ====================================================================
// MENU SUSPENSO DA HOTBAR (BOTOES DE ACAO NO TOPO DAS PAGINAS)
// ====================================================================
function toggleHotbarDropdown(event, id) {
    event.stopPropagation();
    document.querySelectorAll('.hotbar-dropdown-content').forEach(d => {
        if (d.id !== id) d.classList.remove('show');
    });
    const dropdown = document.getElementById(id);
    if (dropdown) dropdown.classList.toggle('show');
}

// Teclado do dropdown de motivo (.dropdown-motivo, onkeydown do botao): setas abrem o painel e andam pelos itens, Enter/Espaco
// escolhe o item em foco (fechado, abre/fecha como clique), Tab fecha. O Esc fica no listener global abaixo (fecha so o painel).
function tecladoDropdownMotivo(event) {
    const dropdown = event.currentTarget.closest('.dropdown-motivo');
    const itens = [...dropdown.querySelectorAll('.dropdown-motivo-item')];
    const aberto = dropdown.classList.contains('aberto');
    const atual = itens.findIndex(i => i.classList.contains('foco'));
    if (event.key === 'Tab') { dropdown.classList.remove('aberto'); return; }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        let proximo;
        if (!aberto) {
            event.currentTarget.click();
            proximo = Math.max(0, itens.findIndex(i => i.classList.contains('selecionado')));
        } else {
            proximo = atual < 0 ? 0 : (atual + (event.key === 'ArrowDown' ? 1 : -1) + itens.length) % itens.length;
        }
        itens.forEach((item, n) => item.classList.toggle('foco', n === proximo));
        return;
    }
    if ((event.key === 'Enter' || event.key === ' ') && aberto && atual >= 0) {
        event.preventDefault();
        itens[atual].click();
    }
}
// Janela redimensionada com o painel aberto: fecha (no Adicionar colaborador ele e position:fixed e ficaria fora do lugar).
window.addEventListener('resize', () => {
    document.querySelectorAll('.dropdown-motivo.aberto').forEach(d => d.classList.remove('aberto'));
});

// Filtro de coluna aberto (.multi-select-dropdown.show): Enter = "Aplicar" (clica o botao do proprio filtro, com o onclick de
// cada pagina). Foco num campo ou botao de outra coisa (fora do filtro): o Enter e dele.
document.addEventListener('keydown', e => {
    if (e.key !== 'Enter' || e.isComposing) return;
    const botao = [...document.querySelectorAll('.multi-select-dropdown.show .btn-aplicar-filtro')].find(b => b.getClientRects().length > 0);
    if (!botao) return;
    const foco = document.activeElement;
    if (foco && !botao.closest('.multi-select-dropdown').contains(foco) && foco.matches('input, textarea, select, button, [contenteditable="true"]')) return;
    e.preventDefault();
    botao.click();
});

// ============================================================
// TECLA ESC FECHA O QUE ESTIVER ABERTO (compartilhado por todas as paginas)
// ============================================================
// Fecha uma camada por Esc, do mais interno pro mais externo (calendario dentro de modal: 1o Esc fecha o calendario, o 2o o modal).
document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.key !== 'Esc') return;

    // Nao da pra usar "offsetParent !== null" aqui: em elemento com
    // position:fixed - que e o caso dos overlays de modal - offsetParent e
    // sempre null, mesmo com o modal aberto na tela.
    const visivel = el => {
        if (!el) return false;
        const est = getComputedStyle(el);
        if (est.display === 'none' || est.visibility === 'hidden') return false;
        const r = el.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    };
    const temClasseShow = el => el && el.classList.contains('show');

    // 1) Calendario customizado (pode estar por cima de um modal); o da Nova Solicitacao (solicitacao.html) tem id proprio
    const calendario = document.getElementById('calendario-popup');
    if (temClasseShow(calendario)) {
        if (typeof fecharCalendarioCustom === 'function') fecharCalendarioCustom();
        else calendario.classList.remove('show');
        return;
    }
    const calendarioSolic = document.getElementById('calendario-popup-solic');
    if (temClasseShow(calendarioSolic)) {
        calendarioSolic.classList.remove('show');
        return;
    }

    // 2) Dropdown de filtro de coluna aberto
    const dropdown = document.querySelector('.multi-select-dropdown.show');
    if (dropdown) {
        dropdown.classList.remove('show');
        if (dropdown._paiOriginal && dropdown.parentElement !== dropdown._paiOriginal) {
            dropdown._paiOriginal.appendChild(dropdown);
            dropdown.style.position = '';
            dropdown.style.top = '';
            dropdown.style.left = '';
        }
        return;
    }

    // 3) Menu da hotbar aberto
    const menuHotbar = document.querySelector('.hotbar-dropdown-content.show');
    if (menuHotbar) { menuHotbar.classList.remove('show'); return; }

    // 4) Gaveta de notificacoes
    const painel = document.getElementById('painel-notificacoes');
    if (temClasseShow(painel)) { painel.classList.remove('show'); return; }

    // 4b) Dropdown de motivo aberto (Nova Solicitacao, Adicionar colaborador): fecha so o painel, nao o modal (que apagaria o preenchido).
    const dropdownMotivo = document.querySelector('.dropdown-motivo.aberto');
    if (dropdownMotivo) { dropdownMotivo.classList.remove('aberto'); return; }

    // 5) Modais (.modal-overlay): um clique sintetico no overlay aciona o fecharXxxFora(event) de cada modal, sem lista de ids mantida a mao.
    const modalAberto = Array.from(document.querySelectorAll('.modal-overlay')).find(visivel);
    if (modalAberto) { modalAberto.click(); return; }

    // 6) Menu lateral do Vale Transporte (Intermitentes)
    const lateral = document.getElementById('sidebar');
    if (lateral && lateral.classList.contains('aberto') && typeof toggleMenu === 'function') {
        toggleMenu();
    }
});


// ============================================================
// ESCOPO DOS PAPEIS "GESTOR" E "PREPOSTO"
// ============================================================
// Gestor e Preposto veem Colaboradores e Coberturas so dos centros de custo deles (filtro real no servidor, ver dados.php);
// Notificacao e Relatorio (Coberturas) sao RH/Gestor; FOPAG e Beneficios sao RH-only (bloqueados no servidor).
// Isto e so a camada visual: esconde itens da hotbar e tira quem entra direto pela URL.
document.addEventListener('DOMContentLoaded', function () {
    const souGestorAgora = typeof souGestor === 'function' && souGestor();
    const souPrepostoAgora = typeof souPreposto === 'function' && souPreposto();

    // RH sai antes do 'if' abaixo, mas no dropdown COLABORADORES 'Localizacao - Ponto' (item-so-de-menu) fica visivel e o 'Dashboard' escondido por padrao; aqui troca um pelo outro.
    if (typeof souRH === 'function' && souRH()) {
        const itemLocalizacaoPonto = document.getElementById('item-localizacao-ponto');
        if (itemLocalizacaoPonto) itemLocalizacaoPonto.style.display = 'none';
        const itemDashboardGestorRh = document.getElementById('item-dashboard-gestor');
        if (itemDashboardGestorRh) itemDashboardGestorRh.style.display = '';
    }

    if (!souGestorAgora && !souPrepostoAgora) return;

    ['drop-fopag', 'drop-ben'].forEach(id => {
        const dropdown = document.getElementById(id);
        const container = dropdown ? dropdown.closest('.hotbar-dropdown-container') : null;
        if (container) container.style.display = 'none';
    });

    if (souPrepostoAgora) {
        // Dropdown COBERTURAS do Preposto: so 'Solicitacoes' e 'Previsao'; Notificacao e Relatorio somem (identificados pelo href).
        const dropdownCob = document.getElementById('drop-cob');
        const containerCob = dropdownCob ? dropdownCob.closest('.hotbar-dropdown-container') : null;
        if (containerCob) containerCob.style.display = '';
        if (dropdownCob) {
            dropdownCob.querySelectorAll('.item-premium').forEach(item => {
                const href = item.getAttribute('href') || '';
                if (!href.includes('solicitacao.html') && !href.includes('previsao.html')) item.style.display = 'none';
            });
        }

        // 'Localizacao - Ponto' (so-de-menu, sem pagina) fica de fora do Preposto.
        const itemLocalizacaoPonto = document.getElementById('item-localizacao-ponto');
        if (itemLocalizacaoPonto) itemLocalizacaoPonto.style.display = 'none';

        // Legenda das cores de linha por motivo (so existe em funcionarios.html) -
        // some no HTML por padrao pra RH/Gestor nunca verem isso.
        const legendaPreposto = document.getElementById('legenda-preposto-colaboradores');
        if (legendaPreposto) legendaPreposto.style.display = 'flex';

        // Dashboard do proprio contrato (coberturas/dashboard.html, mesma
        // pagina do Gestor/RH - ver dispatch dbDash* la dentro) - escondido
        // por padrao no HTML.
        const itemDashboardPreposto = document.getElementById('item-dashboard-preposto');
        if (itemDashboardPreposto) itemDashboardPreposto.style.display = '';
    } else {
        // 'Dashboard' (item-dashboard-rh) e so do RH, que ve todos os contratos (centrosCustoDoUsuario em dashboard.php); o Gestor usa o item-dashboard-gestor.
        const itemDashboardRh = document.getElementById('item-dashboard-rh');
        if (itemDashboardRh) itemDashboardRh.style.display = 'none';

        // Dashboard do Gestor escondido por padrao no HTML; 'Localizacao - Ponto' some pra dar lugar a ele.
        const itemDashboardGestor = document.getElementById('item-dashboard-gestor');
        if (itemDashboardGestor) itemDashboardGestor.style.display = '';
        const itemLocalizacaoPontoGestor = document.getElementById('item-localizacao-ponto');
        if (itemLocalizacaoPontoGestor) itemLocalizacaoPontoGestor.style.display = 'none';
    }

    const paginaBloqueada = location.pathname.includes('/fopag/')
        || location.pathname.includes('/beneficios/')
        // solicitacao.html, dashboard.html e previsao.html sao as excecoes
        // liberadas pro Preposto dentro de /coberturas/ - "endsWith" pra
        // nao pegar por engano outras paginas terminadas parecido.
        || (souPrepostoAgora && location.pathname.includes('/coberturas/') && !location.pathname.endsWith('/solicitacao.html') && !location.pathname.endsWith('/dashboard.html') && !location.pathname.endsWith('/previsao.html'));
    if (paginaBloqueada) {
        // O toast sai DEPOIS do redirect (via sessionStorage, ver consumirToastPosRedirecionamento), porque a pagina atual vai ser trocada.
        sessionStorage.setItem('toast_pos_redirecionamento', JSON.stringify({
            msg: 'Esta pagina nao faz parte do seu perfil de acesso.',
            cor: '#e74c3c',
        }));
        window.location.replace('../colaboradores/funcionarios.html');
    }
});

// ============================================================
// BOTAO "USUARIOS" (RH/Administrador) - ultimo item da hotbar, depois de
// Beneficios
// ============================================================
// So RH/Administrador gerencia usuarios (souRH() cobre os 2). Injetado por JS, ultimo item da hotbar; caminho absoluto porque filtro.js roda em paginas na raiz e em subpastas.
// 'Logs' fica abaixo de 'Acesso' no dropdown, so pro Administrador (unica diferenca de acesso entre os 2 papeis, ver souAdministrador em hostinger-storage.js).
document.addEventListener('DOMContentLoaded', () => {
    if (typeof souRH !== 'function' || !souRH()) return;

    // Vai dentro da pilula .hotbar-nav.
    const hotbarNav = document.querySelector('.hotbar-nav');
    if (!hotbarNav) return;

    const jaEstouEmUsuarios = /(^|\/)usuarios\.html$/.test(location.pathname);
    const jaEstouEmLogs = /(^|\/)logs\.html$/.test(location.pathname);

    const container = document.createElement('div');
    container.className = 'hotbar-dropdown-container';

    const botao = document.createElement('button');
    botao.className = 'hotbar-btn' + ((jaEstouEmUsuarios || jaEstouEmLogs) ? ' active' : '');
    botao.textContent = 'USUARIOS';
    botao.addEventListener('click', (event) => toggleHotbarDropdown(event, 'drop-acessos'));

    const itemLogs = (typeof souAdministrador === 'function' && souAdministrador())
        ? `<a href="/logs.html" class="item-premium"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M9 12h6M9 15.5h6M9 8.5h3"/></svg><div class="item-textos"><b>Logs</b><span class="item-desc">Historico de acoes do sistema</span></div></a>`
        : '';

    const dropdown = document.createElement('div');
    dropdown.className = 'hotbar-dropdown-content';
    dropdown.id = 'drop-acessos';
    dropdown.innerHTML = `<a href="/usuarios.html" class="item-premium"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="3.3"/><path d="M9.8 13.2 18.5 4.5"/><path d="M15 8.3l2.2 2.2"/><path d="M17.5 5.8l2.2 2.2"/></svg><div class="item-textos"><b>Acesso</b><span class="item-desc">Perfis e permissoes</span></div></a>${itemLogs}`;

    container.appendChild(botao);
    container.appendChild(dropdown);
    hotbarNav.appendChild(container);
});

// ============================================================
// NOME DO CENTRO DE CUSTO (descricao do Protheus via backend/scripts/sync-protheus-centros-custo.php, diario as 3h), compartilhado por todas as paginas.
// ============================================================

// Cache em memoria, por carregamento de pagina.
let _mapaDescricaoCentroCusto = null;
// Codigos com pelo menos 1 colaborador ativo no Protheus (centros_custo.
// tem_colaborador, marcado toda madrugada por sync-protheus-centros-custo.php)
// - so a busca de contrato da Nova Base usa (colaboradores/funcionarios.html).
let _centrosComColaboradorAtivo = new Set();

const CHAVE_CACHE_DESCRICAO_CENTRO_CUSTO = 'mapa_descricao_centro_custo';

// Le o mapa do localStorage de forma sincrona pra popular _mapaDescricaoCentroCusto a tempo do 'falso carregamento com cache' do CDV
// (senao a 1a pintura mostra so o codigo e troca pra 'Nome - Codigo' depois). Chamar ANTES do primeiro render.
function carregarDescricoesCentroCustoDoCache() {
    try {
        const bruto = localStorage.getItem(CHAVE_CACHE_DESCRICAO_CENTRO_CUSTO);
        if (bruto) _mapaDescricaoCentroCusto = JSON.parse(bruto);
    } catch (e) {
        // Cache corrompido/indisponivel - segue sem, garantirDescricoesCentroCusto() resolve.
    }
}

async function garantirDescricoesCentroCusto() {
    try {
        const linhas = await dbListar('centros_custo');
        const mapa = {};
        linhas.forEach(l => { mapa[String(l.codigo).trim().toUpperCase()] = l.descricao; });
        _mapaDescricaoCentroCusto = mapa;
        _centrosComColaboradorAtivo = new Set(
            linhas.filter(l => Number(l.tem_colaborador) === 1).map(l => String(l.codigo).trim().toUpperCase())
        );
        try {
            localStorage.setItem(CHAVE_CACHE_DESCRICAO_CENTRO_CUSTO, JSON.stringify(mapa));
        } catch (e) {
            // Storage cheio/indisponivel - sem problema, so afeta a instantaneidade da proxima visita.
        }
    } catch (e) {
        console.log("Erro ao buscar descricoes de centro de custo:", e);
        _mapaDescricaoCentroCusto = _mapaDescricaoCentroCusto || {};
    }
}

// "Nome - Codigo" quando o nome e conhecido, so o codigo (sem travessao)
// quando nao e. Idempotente: se o valor ja vier formatado (ex: herdado de
// uma celula ja renderizada em outra tela), devolve como esta.
function formatarCentroCusto(codigoOuValorJaFormatado) {
    const valor = String(codigoOuValorJaFormatado || '').trim();
    if (valor === '') return '';
    if (valor.includes(' - ')) return valor;
    const mapa = _mapaDescricaoCentroCusto || {};
    const nome = mapa[valor.toUpperCase()];
    return nome ? `${nome} - ${valor}` : valor;
}
