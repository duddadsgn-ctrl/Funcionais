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
