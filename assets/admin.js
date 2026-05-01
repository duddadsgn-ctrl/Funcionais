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

    function renderItem( item ) {
        const overall  = item.overall || 'red';
        const checks   = item.checks  || {};
        const info     = checks.info     || {};
        const images   = checks.images   || {};
        const complete = checks.complete || {};

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
        const completeDetail = () => ( complete.score || 0 ) + '/100';

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
    const secNovos    = document.getElementById( 'vit-sync-novos' );
    const secRem      = document.getElementById( 'vit-sync-removidos' );
    const secForaCrm  = document.getElementById( 'vit-sync-fora-crm' );
    const secAmar     = document.getElementById( 'vit-sync-amarelos' );

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

    function rowRemovido( item ) {
        return (
            '<div class="vit-sync-row" data-post-id="' + esc( item.post_id ) + '">' +
                statusBadgeHtml( item.wp_status, 'vit-sync-badge-red' ) +
                '<span class="vit-sync-label">' + esc( item.title || item.code ) + '</span>' +
                '<span class="vit-code">#' + esc( item.code ) + '</span>' +
                metaHtml( item ) +
                '<span class="vit-sync-row-status"></span>' +
                '<button type="button" class="button vit-sync-delete" data-post-id="' + esc( item.post_id ) + '" data-code="' + esc( item.code ) + '">Remover</button>' +
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
        rowSetStatus( row, d.overall === 'green' ? 'Importado — verde!' : 'Importado (ainda parcial)', d.overall === 'green' ? 'vit-status-done' : '' );
        if ( d.log ) rowShowLog( row, d.log );
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
        rowSetStatus( row, d.overall === 'green' ? 'Agora verde!' : 'Ainda parcial (score ' + d.score + '/100)', d.overall === 'green' ? 'vit-status-done' : '' );
        if ( d.log ) rowShowLog( row, d.log );
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
        [ secNovos, secRem, secForaCrm, secAmar ].forEach( s => { s.style.display = 'none'; s.innerHTML = ''; } );

        const res = await syncCall( 'vit_ajax_sync_scan' );
        scanBtn.disabled = false;

        if ( ! res || ! res.success ) {
            syncStatus( 'Erro: ' + ( res?.data?.msg || 'falha na varredura' ), 'vit-status-error' );
            return;
        }
        const d = res.data;
        const cnt = d.counts;
        const allClear = cnt.novos === 0 && cnt.removidos === 0 && cnt.fora_crm === 0 && cnt.amarelos === 0;
        let msg = 'Varredura concluída: ' + cnt.novos + ' novos | ' +
            cnt.removidos + ' suspensos | ' + cnt.amarelos + ' parciais';
        if ( cnt.fora_crm ) msg += ' | ' + cnt.fora_crm + ' ausentes do CRM';
        msg += '.';
        syncStatus( msg, allClear ? 'vit-status-done' : '' );

        renderSyncSection( secNovos,   'Imóveis novos no CRM (não importados)',                     d.novos,    rowNovo,    'import_new', 'Importar todos os novos' );
        renderSyncSection( secRem,     'Suspensos/Ocultos — remover do WordPress',                  d.removidos, rowRemovido, 'delete',   'Remover todos suspensos' );
        renderSyncSection( secForaCrm, 'Ausentes do CRM mas ativos no WP — verificar manualmente',  d.fora_crm || [], rowForaCrm, '', '' );
        renderSyncSection( secAmar,    'Imóveis parciais (amarelos) — pente fino',                  d.amarelos, rowAmarelo,  'refresh',  'Completar todos os amarelos' );
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
            if ( action === 'import_new' ) await execAll( secNovos, 'vit-sync-import-new', syncImportNew );
            if ( action === 'delete' )     await execAll( secRem,   'vit-sync-delete',     syncDelete );
            if ( action === 'refresh' )    await execAll( secAmar,  'vit-sync-refresh',    syncRefresh );
            t.disabled = false;
        }
    } );
})();
