// ════════════════════════════════════════════════════════════════════════
// Contador de requisições à API (compartilhado entre os dois painéis)
// ════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    const LIMIT = 290;
    let rateCount    = 0;
    let rateResetIn  = 60;
    let ratePaused   = false;
    let rateStateMsg = '';
    let rateTimer    = null;
    let stateTimer   = null;

    function renderBar() {
        const el    = document.getElementById( 'vit-rate-counter' );
        const label = document.getElementById( 'vit-rate-label' );
        const fill  = document.getElementById( 'vit-rate-fill' );
        const reset = document.getElementById( 'vit-rate-reset' );
        const state = document.getElementById( 'vit-rate-state' );
        if ( ! el ) return;

        el.style.display = '';
        el.className = ratePaused ? 'vit-rate-paused' : '';

        const pct = Math.min( 100, Math.round( rateCount / LIMIT * 100 ) );
        if ( label ) label.textContent = rateCount + '/' + LIMIT + ' req/min';
        if ( fill ) {
            fill.style.width = pct + '%';
            fill.className = pct >= 100 ? 'red' : pct >= 70 ? 'yellow' : 'green';
        }
        if ( reset ) reset.textContent = 'renova em ' + rateResetIn + 's';
        if ( state ) {
            if ( rateStateMsg === 'paused' )   state.textContent = '⏸ Aguardando renovação…';
            else if ( rateStateMsg === 'resumed' ) state.textContent = '▶ Retomado';
            else state.textContent = '';
        }
    }

    function showState( msg ) {
        rateStateMsg = msg;
        renderBar();
        clearTimeout( stateTimer );
        if ( msg === 'resumed' ) {
            stateTimer = setTimeout( () => { rateStateMsg = ''; renderBar(); }, 3000 );
        }
    }

    function tick() {
        rateResetIn = Math.max( 0, rateResetIn - 1 );
        if ( rateResetIn === 0 ) {
            const wasPaused = ratePaused;
            rateCount   = 0;
            ratePaused  = false;
            rateResetIn = 60;
            if ( wasPaused ) showState( 'resumed' );
        }
        renderBar();
    }

    window.VIT_RATE = {
        update: function ( rate ) {
            if ( ! rate ) return;
            const wasPaused = ratePaused;
            rateCount   = rate.count   || 0;
            rateResetIn = rate.reset_in || 0;
            ratePaused  = !! rate.paused;

            if ( ! wasPaused && ratePaused ) showState( 'paused' );
            else if ( wasPaused && ! ratePaused ) showState( 'resumed' );

            renderBar();
            clearInterval( rateTimer );
            rateTimer = setInterval( tick, 1000 );
        },
    };
})();

// ════════════════════════════════════════════════════════════════════════
// Importação em Lote
// ════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    if ( ! window.VIT_BULK ) return;
    const cfg = window.VIT_BULK;

    const root = document.getElementById( 'vit-bulk' );
    if ( ! root ) return;

    const els = {
        start:      root.querySelector( '#vit-start' ),
        total:      root.querySelector( '#vit-count-total' ),
        done:       root.querySelector( '#vit-count-done' ),
        green:      root.querySelector( '#vit-count-green' ),
        yellow:     root.querySelector( '#vit-count-yellow' ),
        red:        root.querySelector( '#vit-count-red' ),
        bar:        root.querySelector( '#vit-bar-fill' ),
        list:       root.querySelector( '#vit-list' ),
        statusLine: root.querySelector( '#vit-status-line' ),
    };

    let running = false;
    let finished = false;

    function call( action, data ) {
        const body = new URLSearchParams();
        body.append( 'action', action );
        body.append( 'nonce', cfg.nonce );
        if ( data ) {
            Object.keys( data ).forEach( k => body.append( k, data[ k ] ) );
        }
        return fetch( cfg.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        } ).then( r => r.json() );
    }

    function setStatus( msg, cls ) {
        if ( ! els.statusLine ) return;
        els.statusLine.textContent = msg || '';
        els.statusLine.className = 'vit-status ' + ( cls || '' );
    }

    function updateProgress( p ) {
        if ( ! p ) return;
        els.total.textContent  = p.total  || 0;
        els.done.textContent   = p.index  || 0;
        els.green.textContent  = ( p.counts && p.counts.green )  || 0;
        els.yellow.textContent = ( p.counts && p.counts.yellow ) || 0;
        els.red.textContent    = ( p.counts && p.counts.red )    || 0;
        const pct = p.total > 0 ? Math.round( ( p.index / p.total ) * 100 ) : 0;
        els.bar.style.width = pct + '%';
    }

    // Recontagem dos chips Verde/Amarelo/Vermelho a partir dos cards no DOM.
    // Chamado após qualquer update que altere a cor de um card (sync refresh, retry, etc.)
    function recomputeCounters() {
        if ( ! els.list ) return;
        els.green.textContent  = els.list.querySelectorAll( '.vit-item-green'  ).length;
        els.yellow.textContent = els.list.querySelectorAll( '.vit-item-yellow' ).length;
        els.red.textContent    = els.list.querySelectorAll( '.vit-item-red'    ).length;
    }

    const LABELS = {
        overall: { green: 'Completo', yellow: 'Parcial', red: 'Falhou' },
        check:   { ok: 'Sim', partial: 'Parcial', fail: 'Não' },
    };

    function buildCheckLine( label, check, render ) {
        const s = check && check.status ? check.status : 'fail';
        return (
            '<div class="vit-check vit-check-' + s + '">' +
                '<b>' + label + '</b> ' +
                '<span class="vit-check-status">' + LABELS.check[ s ] + '</span>' +
                ( render ? ' — ' + render( check ) : '' ) +
            '</div>'
        );
    }

    function fmtTs( item ) {
        if ( item.updated_at ) return item.updated_at.replace( /:\d{2}$/, '' ); // remove segundos
        if ( item.updated ) {
            const d = new Date( item.updated * 1000 );
            return d.toLocaleDateString( 'pt-BR', { day: '2-digit', month: '2-digit' } ) + ' ' +
                   d.toLocaleTimeString( 'pt-BR', { hour: '2-digit', minute: '2-digit' } );
        }
        return '';
    }

    function renderItem( item ) {
        const overall  = item.overall || 'red';
        const checks   = item.checks  || {};
        const info     = checks.info     || {};
        const images   = checks.images   || {};
        const complete = checks.complete || {};
        const ts       = fmtTs( item );

        const infoDetail = () => {
            const present = ( info.present || [] ).length;
            const missing = ( info.missing || [] );
            return present + ' campos' + ( missing.length ? ' • falta: ' + missing.join( ', ' ) : '' );
        };
        const imgDetail = () => {
            const c = images.count || 0;
            const t = images.thumbnail ? 'com thumbnail' : 'sem thumbnail';
            return c + ' imagens • ' + t;
        };
        const completeDetail = () => {
            if ( complete.crm_count ) {
                return ( complete.wp_count || 0 ) + '/' + complete.crm_count + ' campos CRM (' + ( complete.score || 0 ) + '%)';
            }
            return ( complete.score || 0 ) + '/100';
        };

        const canRetry = overall !== 'green';
        const retryBtn = canRetry
            ? '<button type="button" class="button vit-retry" data-code="' + esc( item.code ) + '">Tentar atualizar</button>'
            : '';
        const editBtn = item.edit_url
            ? '<a class="button" target="_blank" rel="noopener" href="' + esc( item.edit_url ) + '">Editar post</a>'
            : '';

        const html =
            '<details class="vit-item vit-item-' + overall + '" data-code="' + esc( item.code ) + '">' +
                '<summary>' +
                    '<span class="vit-badge vit-badge-' + overall + '">' + LABELS.overall[ overall ] + '</span> ' +
                    '<span class="vit-title">' + esc( item.title || ( 'Imóvel ' + item.code ) ) + '</span> ' +
                    '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                    ( ts ? ' <span class="vit-updated" title="Última importação">↻ ' + esc( ts ) + '</span>' : '' ) +
                '</summary>' +
                '<div class="vit-checks">' +
                    buildCheckLine( 'Informações importadas?', info, infoDetail ) +
                    buildCheckLine( 'Imagens importadas?',     images, imgDetail ) +
                    buildCheckLine( 'Imóvel completo?',        complete, completeDetail ) +
                '</div>' +
                '<div class="vit-actions">' + editBtn + ' ' + retryBtn + '</div>' +
            '</details>';

        const wrap = document.createElement( 'div' );
        wrap.innerHTML = html;
        return wrap.firstElementChild;
    }

    function renderOrUpdateItem( item ) {
        if ( ! item || ! item.code ) return;
        const existing = els.list.querySelector( '.vit-item[data-code="' + cssEsc( item.code ) + '"]' );
        const next = renderItem( item );
        if ( existing ) {
            if ( existing.open ) next.open = true;
            existing.replaceWith( next );
        } else {
            els.list.prepend( next );
        }
        recomputeCounters();
    }

    function esc( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }
    function cssEsc( s ) {
        return String( s ).replace( /"/g, '\\"' );
    }

    async function startQueue() {
        if ( running ) return;
        els.start.disabled = true;
        setStatus( 'Buscando lista de imóveis no CRM…', '' );
        const res = await call( 'vit_ajax_start_queue', { force: 1 } );
        if ( ! res || ! res.success ) {
            setStatus( 'Erro: ' + ( ( res && res.data && res.data.msg ) || 'falha ao iniciar' ), 'vit-status-error' );
            els.start.disabled = false;
            return;
        }
        els.total.textContent = res.data.total || 0;
        els.done.textContent  = 0;
        els.green.textContent = 0;
        els.yellow.textContent = 0;
        els.red.textContent    = 0;
        els.list.innerHTML = '';
        running  = true;
        finished = false;
        setStatus( 'Importando ' + ( res.data.total || 0 ) + ' imóveis…', '' );
        loop();
    }

    async function loop() {
        if ( ! running ) return;
        let res;
        try {
            res = await call( 'vit_ajax_process_next' );
        } catch ( e ) {
            setStatus( 'Erro de rede — tentando novamente em 3s…', 'vit-status-error' );
            setTimeout( loop, 3000 );
            return;
        }
        if ( ! res || ! res.success ) {
            setStatus( 'Erro: ' + ( ( res && res.data && res.data.msg ) || 'falha' ), 'vit-status-error' );
            running = false;
            els.start.disabled = false;
            return;
        }
        if ( res.data.rate  ) window.VIT_RATE && window.VIT_RATE.update( res.data.rate );
        if ( res.data.item ) renderOrUpdateItem( res.data.item );
        updateProgress( res.data.progress );
        if ( res.data.done ) {
            running  = false;
            finished = true;
            els.start.disabled = false;
            setStatus( 'Importação concluída.', 'vit-status-done' );
            return;
        }
        setTimeout( loop, 150 );
    }

    async function retry( code ) {
        setStatus( 'Reimportando #' + code + '…', '' );
        const res = await call( 'vit_ajax_retry', { code: code } );
        if ( ! res || ! res.success ) {
            setStatus( 'Erro no retry: ' + ( ( res && res.data && res.data.msg ) || 'falha' ), 'vit-status-error' );
            return;
        }
        if ( res.data.rate  ) window.VIT_RATE && window.VIT_RATE.update( res.data.rate );
        if ( res.data.item ) renderOrUpdateItem( res.data.item );
        updateProgress( res.data.progress );
        setStatus( '#' + code + ' atualizado.', 'vit-status-done' );
    }

    async function hydrate() {
        const res = await call( 'vit_ajax_get_state' );
        if ( ! res || ! res.success ) return;
        const d = res.data || {};
        if ( ! d.initialized ) return;
        updateProgress( d.progress );
        ( d.imports || [] )
            .sort( ( a, b ) => ( a.updated || 0 ) - ( b.updated || 0 ) )
            .forEach( renderOrUpdateItem );
        if ( d.running ) {
            running = true;
            els.start.disabled = true;
            setStatus( 'Retomando importação em andamento…', '' );
            loop();
        } else if ( d.finished_at ) {
            setStatus( 'Importação concluída anteriormente. Clique em Iniciar para re-executar.', 'vit-status-done' );
        }
    }

    root.addEventListener( 'click', function ( e ) {
        const t = e.target;
        if ( ! t ) return;
        if ( t.id === 'vit-start' ) {
            e.preventDefault();
            startQueue();
        } else if ( t.classList && t.classList.contains( 'vit-retry' ) ) {
            e.preventDefault();
            retry( t.getAttribute( 'data-code' ) );
        }
    } );

    hydrate();

    // Bridge para o painel de sync atualizar cards desta seção
    window.VIT_LIST = { renderOrUpdateItem, fmtTs };
})();

// ════════════════════════════════════════════════════════════════════════
// Atualização Geral (Sync CRM ↔ WP)
// ════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';
    if ( ! window.VIT_BULK ) return;
    const cfg = window.VIT_BULK;

    const syncRoot = document.getElementById( 'vit-sync' );
    if ( ! syncRoot ) return;

    const scanBtn  = document.getElementById( 'vit-sync-scan' );
    const statusEl = document.getElementById( 'vit-sync-status' );
    const secNovos       = document.getElementById( 'vit-sync-novos' );
    const secDesativados = document.getElementById( 'vit-sync-desativados' );
    const secForaCrm     = document.getElementById( 'vit-sync-fora-crm' );
    const secAmar        = document.getElementById( 'vit-sync-amarelos' );

    function syncCall( action, data ) {
        const body = new URLSearchParams();
        body.append( 'action', action );
        body.append( 'nonce', cfg.nonce );
        if ( data ) Object.keys( data ).forEach( k => body.append( k, data[ k ] ) );
        return fetch( cfg.ajax_url, { method: 'POST', credentials: 'same-origin', body } ).then( r => r.json() );
    }

    function syncStatus( msg, cls ) {
        statusEl.textContent = msg || '';
        statusEl.className = 'vit-status ' + ( cls || '' );
    }

    function esc( s ) {
        return String( s == null ? '' : s )
            .replace( /&/g, '&amp;' ).replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    // ── Render das 3 seções ──────────────────────────────────────────────

    function renderSyncSection( el, title, items, buildRow, execAction, execLabel ) {
        if ( ! items || items.length === 0 ) {
            el.style.display = 'none';
            return;
        }
        el.style.display = '';
        let html = '<div class="vit-sync-section-inner">';
        html += '<h3>' + esc( title ) + ' <span class="vit-sync-count">(' + items.length + ')</span></h3>';
        html += '<div class="vit-sync-list">' + items.map( buildRow ).join( '' ) + '</div>';
        if ( execAction && execLabel ) {
            html += '<div class="vit-sync-exec"><button type="button" class="button vit-sync-exec-btn" data-action="' + esc( execAction ) + '">' + esc( execLabel ) + '</button></div>';
        }
        html += '</div>';
        el.innerHTML = html;
    }

    function rowNovo( item ) {
        return (
            '<div class="vit-sync-row" data-code="' + esc( item.code ) + '">' +
                '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                '<span class="vit-sync-label">Novo no CRM</span>' +
                '<span class="vit-sync-row-status"></span>' +
                '<button type="button" class="button vit-sync-import-new" data-code="' + esc( item.code ) + '">Importar</button>' +
            '</div>'
        );
    }

    function statusBadgeHtml( status, cls ) {
        if ( ! status ) return '';
        return '<span class="vit-sync-status-badge ' + ( cls || '' ) + '">' + esc( status ) + '</span>';
    }

    function metaHtml( item ) {
        const parts = [ item.categoria, item.cidade ].filter( Boolean );
        return parts.length ? '<span class="vit-sync-meta">' + esc( parts.join( ' · ' ) ) + '</span>' : '';
    }

    function rowDesativadoCrm( item ) {
        // Status CRM real (Vendido, Locado, Suspenso, etc.) + botão de remoção manual
        return (
            '<div class="vit-sync-row" data-post-id="' + esc( item.post_id ) + '">' +
                statusBadgeHtml( item.crm_status || item.wp_status || '?', 'vit-sync-badge-red' ) +
                '<span class="vit-sync-label">' + esc( item.title || item.code ) + '</span>' +
                '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                metaHtml( item ) +
                '<span class="vit-sync-row-status"></span>' +
                '<button type="button" class="button vit-sync-delete" data-post-id="' + esc( item.post_id ) + '" data-code="' + esc( item.code ) + '">Remover do WP</button>' +
            '</div>'
        );
    }

    function rowForaCrm( item ) {
        return (
            '<div class="vit-sync-row" data-post-id="' + esc( item.post_id ) + '">' +
                statusBadgeHtml( item.wp_status || '?', 'vit-sync-badge-yellow' ) +
                '<span class="vit-sync-label">' + esc( item.title || item.code ) + '</span>' +
                '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                metaHtml( item ) +
                '<span class="vit-sync-row-status">Ausente do CRM — verificar manualmente</span>' +
                '<button type="button" class="button vit-sync-delete" data-post-id="' + esc( item.post_id ) + '" data-code="' + esc( item.code ) + '">Remover do WP</button>' +
            '</div>'
        );
    }

    function rowAmarelo( item ) {
        const badge = '<span class="vit-badge vit-badge-' + esc( item.overall ) + '">' + esc( item.overall === 'yellow' ? 'Parcial' : 'Falhou' ) + '</span>';
        return (
            '<div class="vit-sync-row" data-code="' + esc( item.code ) + '">' +
                badge +
                '<span class="vit-sync-label">' + esc( item.title || item.code ) + '</span>' +
                '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                '<span class="vit-sync-row-status"></span>' +
                '<button type="button" class="button vit-sync-refresh" data-code="' + esc( item.code ) + '">Completar</button>' +
            '</div>'
        );
    }

    // ── Ações individuais ────────────────────────────────────────────────

    function rowSetStatus( el, msg, cls ) {
        const s = el.querySelector( '.vit-sync-row-status' );
        if ( s ) { s.textContent = msg; s.className = 'vit-sync-row-status ' + ( cls || '' ); }
    }

    function rowShowLog( row, logLines ) {
        if ( ! logLines || ! logLines.length ) return;
        const key = row.getAttribute( 'data-code' ) || row.getAttribute( 'data-post-id' ) || String( Math.random() );
        const id  = 'vit-log-' + key.replace( /[^a-z0-9]/gi, '_' );
        let logEl = document.getElementById( id );
        if ( ! logEl ) {
            logEl = document.createElement( 'details' );
            logEl.id = id;
            logEl.className = 'vit-sync-row-log';
            row.parentNode.insertBefore( logEl, row.nextSibling );
        }
        const summary = document.createElement( 'summary' );
        summary.textContent = 'Relatório (' + logLines.length + ' linhas)';
        const pre = document.createElement( 'pre' );
        pre.className = 'vit-sync-log-pre';
        pre.textContent = logLines.join( '\n' );
        logEl.innerHTML = '';
        logEl.appendChild( summary );
        logEl.appendChild( pre );
    }

    async function syncImportNew( btn ) {
        const code = btn.getAttribute( 'data-code' );
        const row  = btn.closest( '.vit-sync-row' );
        btn.disabled = true;
        rowSetStatus( row, 'importando…', '' );
        const res = await syncCall( 'vit_ajax_sync_import_new', { code } );
        if ( ! res || ! res.success ) {
            rowSetStatus( row, 'Erro: ' + ( res?.data?.msg || 'falha' ), 'vit-status-error' );
            btn.disabled = false;
            return;
        }
        const d = res.data;
        if ( d.rate ) window.VIT_RATE && window.VIT_RATE.update( d.rate );
        const tsLabel = d.updated_at ? ' — ' + d.updated_at.replace( /:\d{2}$/, '' ) : '';
        rowSetStatus( row, d.overall === 'green' ? 'Importado — verde!' + tsLabel : 'Importado (ainda parcial)' + tsLabel, d.overall === 'green' ? 'vit-status-done' : '' );
        if ( d.log ) rowShowLog( row, d.log );
        if ( window.VIT_LIST ) {
            window.VIT_LIST.renderOrUpdateItem( { ...d, updated: Math.floor( Date.now() / 1000 ) } );
        }
        btn.textContent = 'Reimportar';
        btn.disabled = false;
    }

    async function syncDelete( btn ) {
        const postId = btn.getAttribute( 'data-post-id' );
        const code   = btn.getAttribute( 'data-code' );
        if ( ! confirm( 'Remover imóvel #' + code + ' e todas as suas fotos do WordPress? Esta ação não pode ser desfeita.' ) ) return;
        const row = btn.closest( '.vit-sync-row' );
        btn.disabled = true;
        rowSetStatus( row, 'removendo…', '' );
        const res = await syncCall( 'vit_ajax_sync_delete_one', { post_id: postId } );
        if ( ! res || ! res.success ) {
            rowSetStatus( row, 'Erro: ' + ( res?.data?.msg || 'falha' ), 'vit-status-error' );
            btn.disabled = false;
            return;
        }
        rowSetStatus( row, 'Removido (' + res.data.attachments_deleted + ' fotos apagadas)', 'vit-status-done' );
        btn.remove();
    }

    async function syncRefresh( btn ) {
        const code = btn.getAttribute( 'data-code' );
        const row  = btn.closest( '.vit-sync-row' );
        btn.disabled = true;
        rowSetStatus( row, 'buscando campos na API…', '' );
        const res = await syncCall( 'vit_ajax_sync_refresh_one', { code } );
        if ( ! res || ! res.success ) {
            rowSetStatus( row, 'Erro: ' + ( res?.data?.msg || 'falha' ), 'vit-status-error' );
            btn.disabled = false;
            return;
        }
        const d = res.data;
        const badge = row.querySelector( '.vit-badge' );
        if ( badge ) {
            badge.className = 'vit-badge vit-badge-' + d.overall;
            badge.textContent = d.overall === 'green' ? 'Completo' : d.overall === 'yellow' ? 'Parcial' : 'Falhou';
        }
        if ( d.rate ) window.VIT_RATE && window.VIT_RATE.update( d.rate );
        const tsRefLabel = d.updated_at ? ' — ' + d.updated_at.replace( /:\d{2}$/, '' ) : '';
        rowSetStatus( row, d.overall === 'green' ? 'Agora verde!' + tsRefLabel : 'Ainda parcial (score ' + d.score + '/100)' + tsRefLabel, d.overall === 'green' ? 'vit-status-done' : '' );
        if ( d.log ) rowShowLog( row, d.log );
        if ( window.VIT_LIST ) {
            window.VIT_LIST.renderOrUpdateItem( { ...d, updated: Math.floor( Date.now() / 1000 ) } );
        }
        btn.disabled = false;
    }

    // ── Loops "Executar todos" ───────────────────────────────────────────

    async function execAll( section, btnClass, handler ) {
        const btns = Array.from( section.querySelectorAll( '.' + btnClass + ':not(:disabled)' ) );
        for ( const btn of btns ) {
            await handler( btn );
            await new Promise( r => setTimeout( r, 150 ) );
        }
    }

    // ── Scan principal ───────────────────────────────────────────────────

    async function syncScan() {
        scanBtn.disabled = true;
        syncStatus( 'Varrendo CRM e comparando com o WordPress…', '' );
        [ secNovos, secDesativados, secForaCrm, secAmar ].forEach( s => { s.style.display = 'none'; s.innerHTML = ''; } );

        const res = await syncCall( 'vit_ajax_sync_scan' );
        scanBtn.disabled = false;

        if ( ! res || ! res.success ) {
            syncStatus( 'Erro: ' + ( res?.data?.msg || 'falha na varredura' ), 'vit-status-error' );
            return;
        }
        const d = res.data;
        const cnt = d.counts;
        const allClear = cnt.novos === 0 && cnt.desativados_crm === 0 && cnt.fora_crm === 0 && cnt.amarelos === 0;
        let msg = 'Varredura concluída: ' + cnt.novos + ' novos | ' +
            cnt.desativados_crm + ' desativados no CRM | ' + cnt.amarelos + ' parciais';
        if ( cnt.fora_crm ) msg += ' | ' + cnt.fora_crm + ' ausentes do CRM';
        msg += '.';
        syncStatus( msg, allClear ? 'vit-status-done' : '' );

        renderSyncSection( secNovos,       'Imóveis novos no CRM (não importados)',                       d.novos,              rowNovo,           'import_new', 'Importar todos os novos' );
        renderSyncSection( secDesativados, 'Desativados no CRM — remover do WordPress (decisão humana)',  d.desativados_crm,    rowDesativadoCrm,  'delete',     'Remover todos desativados' );
        renderSyncSection( secForaCrm,     'Ausentes do CRM — verificar manualmente (sem código no CRM)', d.fora_crm || [],     rowForaCrm,        'delete_fora','Remover todos ausentes' );
        renderSyncSection( secAmar,        'Imóveis parciais (amarelos) — pente fino',                   d.amarelos,           rowAmarelo,        'refresh',    'Completar todos os amarelos' );
    }

    // ── Delegação de cliques ─────────────────────────────────────────────

    syncRoot.addEventListener( 'click', async function ( e ) {
        const t = e.target;
        if ( ! t ) return;

        if ( t.id === 'vit-sync-scan' ) {
            e.preventDefault();
            syncScan();
        } else if ( t.classList.contains( 'vit-sync-import-new' ) ) {
            e.preventDefault();
            syncImportNew( t );
        } else if ( t.classList.contains( 'vit-sync-delete' ) ) {
            e.preventDefault();
            syncDelete( t );
        } else if ( t.classList.contains( 'vit-sync-refresh' ) ) {
            e.preventDefault();
            syncRefresh( t );
        } else if ( t.classList.contains( 'vit-sync-exec-btn' ) ) {
            e.preventDefault();
            const action = t.getAttribute( 'data-action' );
            t.disabled = true;
            if ( action === 'import_new' )  await execAll( secNovos,       'vit-sync-import-new', syncImportNew );
            if ( action === 'delete' )      await execAll( secDesativados, 'vit-sync-delete',     syncDelete );
            if ( action === 'delete_fora' ) await execAll( secForaCrm,     'vit-sync-delete',     syncDelete );
            if ( action === 'refresh' )     await execAll( secAmar,        'vit-sync-refresh',    syncRefresh );
            t.disabled = false;
        }
    } );
})();
