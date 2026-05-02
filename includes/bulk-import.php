<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VIT_QUEUE_OPTION = 'vit_queue';
const VIT_QUEUE_LOG_TAIL = 30;

/**
 * Retorna estado atual da fila (estrutura documentada no plano).
 */
function vit_queue_get() {
    $state = get_option( VIT_QUEUE_OPTION, null );
    if ( ! is_array( $state ) ) {
        return null;
    }
    return $state;
}

function vit_queue_save( $state ) {
    update_option( VIT_QUEUE_OPTION, $state, false );
}

function vit_queue_reset( $api_url, $api_key ) {
    $state = [
        'running'      => true,
        'total'        => 0,
        'index'        => 0,
        'queue'        => [],
        'meta_by_code' => [],
        'counts'       => [ 'green' => 0, 'yellow' => 0, 'red' => 0 ],
        'imports'      => [],
        'api_url'      => $api_url,
        'api_key'      => $api_key,
        'started_at'   => time(),
        'finished_at'  => 0,
        'last_error'   => '',
    ];
    vit_queue_save( $state );
    return $state;
}

/**
 * Guarda comum: nonce + capability.
 */
function vit_bulk_guard() {
    if ( ! check_ajax_referer( 'vit_bulk_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'msg' => 'nonce inválido' ], 403 );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'sem permissão' ], 403 );
    }
}

/**
 * Paginado: chama /imoveis/listar até cobrir todos os imóveis.
 * Retorna array [ 'codes' => [...], 'meta_by_code' => [...], 'total' => N, 'log' => [...] ]
 * ou WP_Error.
 */
function vit_fetch_all_codes( $api_url, $api_key ) {
    $codes            = [];
    $meta_by_code     = [];
    $inactive_by_code = [];  // código → status CRM para os não-ativos
    $log              = [];
    $per_page         = 50;
    $page             = 1;
    $total            = 0;

    while ( true ) {
        // Inclui Status para filtrar apenas imóveis ativos
        $params = [
            'fields'    => [ 'Codigo', 'Categoria', 'Finalidade', 'Status', 'DataAtualizacao' ],
            'paginacao' => [ 'pagina' => $page, 'quantidade' => $per_page ],
        ];
        $resp = vit_call_api_get( $api_url, '/imoveis/listar', $api_key, $params, $log );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        if ( ! is_array( $resp ) ) {
            break;
        }

        if ( $page === 1 && isset( $resp['paginacao']['total'] ) ) {
            $total = (int) $resp['paginacao']['total'];
        }

        $items_on_page = 0;
        foreach ( $resp as $key => $value ) {
            if ( in_array( $key, [ 'paginacao', 'total', 'pagina', 'quantidade' ], true ) ) {
                continue;
            }
            if ( ! is_array( $value ) ) {
                continue;
            }
            $codigo     = isset( $value['Codigo'] ) ? (string) $value['Codigo'] : '';
            $status_raw = trim( (string) ( $value['Status'] ?? '' ) );
            // mb_strtolower handles UTF-8 chars like "Locação" / "Locado" correctly
            $status     = function_exists( 'mb_strtolower' ) ? mb_strtolower( $status_raw, 'UTF-8' ) : strtolower( $status_raw );
            if ( $codigo === '' ) {
                continue;
            }
            $items_on_page++;
            // Exclusion list — statuses known to mean inactive/removed in Vista CRM.
            // Using exclusion (not whitelist) so unknown future statuses default to active.
            $inativos = [ 'suspenso', 'oculto', 'inativo', 'vendido', 'locado', 'alugado', 'cancelado' ];
            if ( in_array( $status, $inativos, true ) ) {
                if ( ! isset( $inactive_by_code[ $codigo ] ) ) {
                    $inactive_by_code[ $codigo ] = $status_raw ?: 'Inativo';
                }
                continue;
            }
            if ( isset( $meta_by_code[ $codigo ] ) ) {
                continue;
            }
            $meta_by_code[ $codigo ] = [
                'Categoria'       => isset( $value['Categoria'] )       ? (string) $value['Categoria']       : '',
                'Finalidade'      => isset( $value['Finalidade'] )      ? (string) $value['Finalidade']      : '',
                'DataAtualizacao' => isset( $value['DataAtualizacao'] ) ? (string) $value['DataAtualizacao'] : '',
            ];
            $codes[] = $codigo;
        }

        // Break only when the API returned zero property items (end of pagination).
        // Do NOT break on zero active items — a page may contain only inactive ones.
        if ( $items_on_page === 0 ) {
            break;
        }
        if ( $total > 0 && count( $codes ) >= $total ) {
            break;
        }
        $page++;
        if ( $page > 200 ) {
            break;
        }
    }

    if ( $total === 0 ) {
        $total = count( $codes );
    }

    return [
        'codes'            => $codes,
        'meta_by_code'     => $meta_by_code,
        'inactive_by_code' => $inactive_by_code,
        'total'            => $total,
        'log'              => $log,
    ];
}

/**
 * Executa validação + persiste em state['imports'][$code].
 */
function vit_queue_persist_result( &$state, $code, $import_result ) {
    $post_id = isset( $import_result['post_id'] ) ? (int) $import_result['post_id'] : 0;
    $valid   = vit_validate_property( $post_id );

    $log      = isset( $import_result['log'] ) && is_array( $import_result['log'] ) ? $import_result['log'] : [];
    $log_tail = array_slice( $log, -VIT_QUEUE_LOG_TAIL );

    $edit_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : '';
    $title    = ! empty( $import_result['title'] )
        ? $import_result['title']
        : ( $post_id ? get_the_title( $post_id ) : '' );

    if ( $title === '' ) {
        $title = 'Imóvel ' . $code;
    }

    $old = $state['imports'][ $code ] ?? null;
    if ( $old && isset( $old['overall'] ) && isset( $state['counts'][ $old['overall'] ] ) ) {
        $state['counts'][ $old['overall'] ] = max( 0, $state['counts'][ $old['overall'] ] - 1 );
    }

    $item = [
        'code'     => $code,
        'post_id'  => $post_id,
        'title'    => $title,
        'overall'  => $valid['overall'],
        'score'    => $valid['score'],
        'checks'   => $valid['checks'],
        'edit_url' => $edit_url,
        'log_tail' => $log_tail,
        'updated'  => time(),
    ];
    $state['imports'][ $code ] = $item;

    if ( ! isset( $state['counts'][ $valid['overall'] ] ) ) {
        $state['counts'][ $valid['overall'] ] = 0;
    }
    $state['counts'][ $valid['overall'] ]++;

    return $item;
}

function vit_queue_progress( $state ) {
    return [
        'index'  => (int) ( $state['index'] ?? 0 ),
        'total'  => (int) ( $state['total'] ?? 0 ),
        'counts' => $state['counts'] ?? [ 'green' => 0, 'yellow' => 0, 'red' => 0 ],
    ];
}

// ────────────────────────────────────────────────────────────────────────
// Handlers AJAX
// ────────────────────────────────────────────────────────────────────────

function vit_ajax_start_queue() {
    vit_bulk_guard();

    $force = ! empty( $_POST['force'] );

    $api_url = esc_url_raw( $_POST['vit_api_url'] ?? get_option( 'vit_api_url', '' ) );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] ?? get_option( 'vit_api_key', '' ) );
    if ( $api_url !== '' ) update_option( 'vit_api_url', $api_url );
    if ( $api_key !== '' ) update_option( 'vit_api_key', $api_key );

    if ( empty( $api_url ) || empty( $api_key ) ) {
        wp_send_json_error( [ 'msg' => 'API URL e API Key são obrigatórios.' ] );
    }

    $existing = vit_queue_get();
    if ( $existing && ! empty( $existing['running'] ) && empty( $existing['finished_at'] ) && ! $force ) {
        wp_send_json_error( [
            'msg'      => 'Já existe uma fila em execução. Use force=1 para reiniciar.',
            'progress' => vit_queue_progress( $existing ),
        ] );
    }

    $fetched = vit_fetch_all_codes( $api_url, $api_key );
    if ( is_wp_error( $fetched ) ) {
        wp_send_json_error( [ 'msg' => 'Falha ao listar imóveis: ' . $fetched->get_error_message() ] );
    }

    $state = vit_queue_reset( $api_url, $api_key );
    $state['queue']        = $fetched['codes'];
    $state['meta_by_code'] = $fetched['meta_by_code'];
    $state['total']        = $fetched['total'];
    vit_queue_save( $state );

    wp_send_json_success( [
        'total'               => $state['total'],
        'first_codes_preview' => array_slice( $state['queue'], 0, 5 ),
        'rate'                => vit_api_rate_status(),
    ] );
}

function vit_ajax_process_next() {
    @set_time_limit( 300 );
    vit_bulk_guard();

    $state = vit_queue_get();
    if ( ! $state ) {
        wp_send_json_error( [ 'msg' => 'Fila não inicializada.' ] );
    }

    $index = (int) $state['index'];
    $queue = $state['queue'] ?? [];
    if ( $index >= count( $queue ) ) {
        $state['running']     = false;
        $state['finished_at'] = time();
        vit_queue_save( $state );
        wp_send_json_success( [
            'done'     => true,
            'progress' => vit_queue_progress( $state ),
        ] );
    }

    $code = (string) $queue[ $index ];
    $meta = $state['meta_by_code'][ $code ] ?? [ 'Categoria' => '', 'Finalidade' => '' ];

    $result = vit_import_single_by_code(
        $state['api_url'],
        $state['api_key'],
        $code,
        $meta['Categoria'] ?? '',
        $meta['Finalidade'] ?? ''
    );

    $item = vit_queue_persist_result( $state, $code, $result );

    $state['index'] = $index + 1;
    $done = $state['index'] >= count( $queue );
    if ( $done ) {
        $state['running']     = false;
        $state['finished_at'] = time();
    }
    vit_queue_save( $state );

    wp_send_json_success( [
        'code'     => $code,
        'item'     => $item,
        'progress' => vit_queue_progress( $state ),
        'done'     => $done,
        'rate'     => vit_api_rate_status(),
    ] );
}

function vit_ajax_retry() {
    vit_bulk_guard();

    $code = sanitize_text_field( $_POST['code'] ?? '' );
    if ( $code === '' ) {
        wp_send_json_error( [ 'msg' => 'code obrigatório.' ] );
    }

    $state = vit_queue_get();
    if ( ! $state ) {
        wp_send_json_error( [ 'msg' => 'Fila não inicializada.' ] );
    }
    if ( ! in_array( $code, (array) $state['queue'], true ) ) {
        wp_send_json_error( [ 'msg' => 'Código fora da fila.' ] );
    }

    $meta = $state['meta_by_code'][ $code ] ?? [ 'Categoria' => '', 'Finalidade' => '' ];

    $result = vit_import_single_by_code(
        $state['api_url'],
        $state['api_key'],
        $code,
        $meta['Categoria'] ?? '',
        $meta['Finalidade'] ?? ''
    );

    $item = vit_queue_persist_result( $state, $code, $result );
    vit_queue_save( $state );

    wp_send_json_success( [
        'code'     => $code,
        'item'     => $item,
        'progress' => vit_queue_progress( $state ),
        'rate'     => vit_api_rate_status(),
    ] );
}

function vit_ajax_get_state() {
    vit_bulk_guard();

    $state = vit_queue_get();
    if ( ! $state ) {
        wp_send_json_success( [ 'initialized' => false ] );
    }

    wp_send_json_success( [
        'initialized' => true,
        'running'     => ! empty( $state['running'] ),
        'finished_at' => (int) ( $state['finished_at'] ?? 0 ),
        'started_at'  => (int) ( $state['started_at'] ?? 0 ),
        'progress'    => vit_queue_progress( $state ),
        'imports'     => array_values( $state['imports'] ?? [] ),
    ] );
}

// ────────────────────────────────────────────────────────────────────────
// Enqueue de CSS/JS só na página do plugin.
// ────────────────────────────────────────────────────────────────────────
function vit_enqueue_admin_assets( $hook ) {
    if ( $hook !== 'toplevel_page_vista-imovel-teste' ) {
        return;
    }
    wp_enqueue_style(
        'vit-admin',
        VIT_PLUGIN_URL . 'assets/admin.css',
        [],
        '1.0.0'
    );
    wp_enqueue_script(
        'vit-admin',
        VIT_PLUGIN_URL . 'assets/admin.js',
        [],
        '1.0.0',
        true
    );
    wp_localize_script( 'vit-admin', 'VIT_BULK', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'vit_bulk_nonce' ),
    ] );
}
