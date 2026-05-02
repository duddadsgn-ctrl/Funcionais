<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VIT_SYNC_PLAN_OPTION        = 'vit_sync_plan';
const VIT_DAILY_SCAN_RESULT_OPT   = 'vit_daily_scan_result';

// ────────────────────────────────────────────────────────────────────────
// Helpers internos
// ────────────────────────────────────────────────────────────────────────

/**
 * Retorna todos os imóveis no WP que têm meta _vista_codigo.
 * @return array [ 'CODIGO' => ['post_id'=>int, 'title'=>string, …], … ]
 */
function vit_get_all_wp_properties() {
    $query = new WP_Query( [
        'post_type'      => 'imoveis',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_query'     => [ [ 'key' => '_vista_codigo', 'compare' => 'EXISTS' ] ],
        'fields'         => 'ids',
    ] );

    $result = [];
    foreach ( $query->posts as $post_id ) {
        $code = get_post_meta( $post_id, '_vista_codigo', true );
        if ( ! $code ) {
            continue;
        }
        $result[ (string) $code ] = [
            'code'       => (string) $code,
            'post_id'    => (int) $post_id,
            'title'      => get_the_title( $post_id ),
            'wp_status'  => (string) get_post_meta( $post_id, 'status',                true ),
            'categoria'  => (string) get_post_meta( $post_id, 'categoria',             true ),
            'cidade'     => (string) get_post_meta( $post_id, 'cidade',                true ),
            'wp_updated' => (string) get_post_meta( $post_id, 'data_hora_atualizacao', true ),
        ];
    }
    return $result;
}

/**
 * Apaga um post de imóvel junto com TODOS os seus attachments (galeria +
 * filhos + thumbnail). Retorna contagem de attachments deletados.
 */
function vit_delete_property_fully( $post_id ) {
    $deleted_att = 0;
    $seen        = [];

    $children = get_children( [
        'post_parent' => $post_id,
        'post_type'   => 'attachment',
        'numberposts' => -1,
    ] );
    foreach ( $children as $att ) {
        if ( isset( $seen[ $att->ID ] ) ) continue;
        $seen[ $att->ID ] = true;
        wp_delete_attachment( $att->ID, true );
        $deleted_att++;
    }

    $galeria = get_post_meta( $post_id, 'galeria', true );
    if ( is_array( $galeria ) ) {
        foreach ( $galeria as $att_id ) {
            $att_id = (int) $att_id;
            if ( ! $att_id || isset( $seen[ $att_id ] ) ) continue;
            $seen[ $att_id ] = true;
            wp_delete_attachment( $att_id, true );
            $deleted_att++;
        }
    }

    $thumb = (int) get_post_thumbnail_id( $post_id );
    if ( $thumb && ! isset( $seen[ $thumb ] ) ) {
        $seen[ $thumb ] = true;
        wp_delete_attachment( $thumb, true );
        $deleted_att++;
    }

    wp_delete_post( $post_id, true );
    return $deleted_att;
}

// ────────────────────────────────────────────────────────────────────────
// Núcleo do scan — reutilizado pelo AJAX e pelo cron diário
// ────────────────────────────────────────────────────────────────────────

/**
 * Executa a varredura CRM ↔ WP e persiste o plano.
 * Retorna array com os resultados ou WP_Error em caso de falha.
 *
 * Categorias:
 *   novos           = ativos no CRM, não no WP
 *   desativados_crm = no WP e no CRM com status inativo
 *   fora_crm        = no WP mas não encontrado em nenhuma lista do CRM
 *   amarelos        = em ambos, mas validação WP != green
 *   atualizados     = em ambos e green, mas CRM tem DataAtualizacao mais recente
 */
function vit_run_sync_scan() {
    $api_url = get_option( 'vit_api_url', '' );
    $api_key = get_option( 'vit_api_key', '' );
    if ( empty( $api_url ) || empty( $api_key ) ) {
        return new WP_Error( 'no_config', 'Configure a API URL e API Key antes.' );
    }

    $fetched = vit_fetch_all_codes( $api_url, $api_key );
    if ( is_wp_error( $fetched ) ) {
        return new WP_Error( 'fetch_fail', 'Falha ao listar CRM: ' . $fetched->get_error_message() );
    }

    $crm_active       = array_flip( $fetched['codes'] );
    $crm_inactive_map = $fetched['inactive_by_code'] ?? [];
    $crm_meta         = $fetched['meta_by_code']     ?? [];

    if ( empty( $crm_active ) ) {
        return new WP_Error( 'empty_crm',
            'O CRM retornou 0 imóveis ativos. Verifique a conectividade e a API Key — a varredura foi cancelada para evitar remoções indevidas.'
        );
    }

    $wp_props = vit_get_all_wp_properties();

    $novos           = [];
    $desativados_crm = [];
    $fora_crm        = [];
    $amarelos        = [];
    $atualizados     = [];  // green no WP, mas CRM tem timestamp mais recente

    foreach ( $crm_active as $code => $_ ) {
        if ( ! isset( $wp_props[ $code ] ) ) {
            $novos[] = [
                'code' => $code,
                'meta' => $crm_meta[ $code ] ?? [],
            ];
            continue;
        }

        $prop  = $wp_props[ $code ];
        $valid = vit_validate_property( $prop['post_id'] );

        if ( $valid['overall'] !== 'green' ) {
            $amarelos[] = array_merge( $prop, [
                'overall' => $valid['overall'],
                'checks'  => $valid['checks'],
                'score'   => $valid['score'],
            ] );
        } else {
            // Verde: verifica se o CRM foi atualizado após o último import
            $crm_updated = $crm_meta[ $code ]['DataAtualizacao'] ?? '';
            $wp_updated  = $prop['wp_updated'];
            if ( $crm_updated !== '' && $wp_updated !== '' && $crm_updated > $wp_updated ) {
                $atualizados[] = array_merge( $prop, [
                    'crm_updated' => $crm_updated,
                ] );
            }
        }
    }

    foreach ( $wp_props as $code => $prop ) {
        if ( isset( $crm_active[ $code ] ) ) {
            continue;
        }
        if ( isset( $crm_inactive_map[ $code ] ) ) {
            $desativados_crm[] = array_merge( $prop, [
                'crm_status' => $crm_inactive_map[ $code ],
            ] );
        } else {
            $fora_crm[] = $prop;
        }
    }

    $plan = [
        'novos'           => $novos,
        'desativados_crm' => $desativados_crm,
        'fora_crm'        => $fora_crm,
        'amarelos'        => $amarelos,
        'atualizados'     => $atualizados,
        'crm_active_map'  => array_keys( $crm_active ),
        'scanned_at'      => time(),
        'api_url'         => $api_url,
        'api_key'         => $api_key,
    ];
    update_option( VIT_SYNC_PLAN_OPTION, $plan, false );

    return $plan;
}

// ────────────────────────────────────────────────────────────────────────
// Cron diário
// ────────────────────────────────────────────────────────────────────────

function vit_cron_daily_scan(): void {
    @set_time_limit( 300 );

    $result = vit_run_sync_scan();

    if ( is_wp_error( $result ) ) {
        update_option( VIT_DAILY_SCAN_RESULT_OPT, [
            'status'     => 'error',
            'msg'        => $result->get_error_message(),
            'scanned_at' => time(),
        ], false );
        return;
    }

    $has_changes = ! empty( $result['novos'] )
                || ! empty( $result['desativados_crm'] )
                || ! empty( $result['atualizados'] )
                || ! empty( $result['fora_crm'] );

    update_option( VIT_DAILY_SCAN_RESULT_OPT, [
        'status'     => $has_changes ? 'changes' : 'clean',
        'scanned_at' => $result['scanned_at'],
        'counts'     => [
            'novos'       => count( $result['novos'] ),
            'desativados' => count( $result['desativados_crm'] ),
            'atualizados' => count( $result['atualizados'] ),
            'ausentes'    => count( $result['fora_crm'] ),
            'amarelos'    => count( $result['amarelos'] ),
        ],
    ], false );
}

// ────────────────────────────────────────────────────────────────────────
// Handlers AJAX — todos usam o mesmo nonce do bulk (vit_bulk_nonce)
// ────────────────────────────────────────────────────────────────────────

function vit_ajax_sync_scan() {
    @set_time_limit( 300 );
    check_ajax_referer( 'vit_bulk_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'sem permissão' ], 403 );
    }

    $result = vit_run_sync_scan();
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'msg' => $result->get_error_message() ] );
    }

    wp_send_json_success( [
        'counts' => [
            'novos'           => count( $result['novos'] ),
            'desativados_crm' => count( $result['desativados_crm'] ),
            'fora_crm'        => count( $result['fora_crm'] ),
            'amarelos'        => count( $result['amarelos'] ),
            'atualizados'     => count( $result['atualizados'] ),
        ],
        'novos'           => $result['novos'],
        'desativados_crm' => $result['desativados_crm'],
        'fora_crm'        => $result['fora_crm'],
        'amarelos'        => $result['amarelos'],
        'atualizados'     => $result['atualizados'],
    ] );
}

/**
 * Importa um único imóvel novo (código deve estar no plano de sync).
 */
function vit_ajax_sync_import_new() {
    @set_time_limit( 300 );
    check_ajax_referer( 'vit_bulk_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'sem permissão' ], 403 );
    }

    $code = sanitize_text_field( $_POST['code'] ?? '' );
    if ( $code === '' ) {
        wp_send_json_error( [ 'msg' => 'code obrigatório.' ] );
    }

    $plan = get_option( VIT_SYNC_PLAN_OPTION, [] );
    $meta = [];
    foreach ( (array) ( $plan['novos'] ?? [] ) as $item ) {
        if ( (string) ( $item['code'] ?? '' ) === $code ) {
            $meta = $item['meta'] ?? [];
            break;
        }
    }

    $api_url = $plan['api_url'] ?? get_option( 'vit_api_url', '' );
    $api_key = $plan['api_key'] ?? get_option( 'vit_api_key', '' );

    $result = vit_import_single_by_code( $api_url, $api_key, $code, $meta['Categoria'] ?? '', $meta['Finalidade'] ?? '' );
    $valid  = vit_validate_property( $result['post_id'] ?? 0 );

    $pid_new = $result['post_id'] ?? 0;
    wp_send_json_success( [
        'code'       => $code,
        'post_id'    => $pid_new,
        'title'      => $result['title'] ?? '',
        'overall'    => $valid['overall'],
        'score'      => $valid['score'],
        'checks'     => $valid['checks'],
        'edit_url'   => $pid_new ? get_edit_post_link( $pid_new, 'raw' ) : '',
        'updated_at' => $pid_new ? (string) get_post_meta( $pid_new, 'data_hora_atualizacao', true ) : '',
        'log'        => $result['log'] ?? [],
        'rate'       => vit_api_rate_status(),
    ] );
}

/**
 * Reimporta um imóvel existente (pente fino: busca tudo na API e preenche).
 */
function vit_ajax_sync_refresh_one() {
    @set_time_limit( 300 );
    check_ajax_referer( 'vit_bulk_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'sem permissão' ], 403 );
    }

    $code = sanitize_text_field( $_POST['code'] ?? '' );
    if ( $code === '' ) {
        wp_send_json_error( [ 'msg' => 'code obrigatório.' ] );
    }

    $plan    = get_option( VIT_SYNC_PLAN_OPTION, [] );
    $api_url = $plan['api_url'] ?? get_option( 'vit_api_url', '' );
    $api_key = $plan['api_key'] ?? get_option( 'vit_api_key', '' );

    $q = new WP_Query( [
        'post_type'      => 'imoveis',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => [ [ 'key' => '_vista_codigo', 'value' => $code ] ],
    ] );
    $found_id = $q->have_posts() ? (int) $q->posts[0] : 0;
    $cat = $found_id ? (string) get_post_meta( $found_id, 'categoria',  true ) : '';
    $fin = $found_id ? (string) get_post_meta( $found_id, 'finalidade', true ) : '';

    $result = vit_import_single_by_code( $api_url, $api_key, $code, $cat, $fin );
    $valid  = vit_validate_property( $result['post_id'] ?? 0 );

    // Atualiza o plano de sync (amarelos e atualizados) para refletir o novo status
    $fresh_plan = get_option( VIT_SYNC_PLAN_OPTION, [] );
    $became_green = $valid['overall'] === 'green';

    foreach ( [ 'amarelos', 'atualizados' ] as $section ) {
        if ( empty( $fresh_plan[ $section ] ) ) continue;
        if ( $became_green ) {
            $fresh_plan[ $section ] = array_values( array_filter(
                $fresh_plan[ $section ],
                fn( $i ) => (string) ( $i['code'] ?? '' ) !== $code
            ) );
        } else {
            foreach ( $fresh_plan[ $section ] as &$item ) {
                if ( (string) ( $item['code'] ?? '' ) === $code ) {
                    $item['overall'] = $valid['overall'];
                    $item['checks']  = $valid['checks'];
                    $item['score']   = $valid['score'];
                    break;
                }
            }
            unset( $item );
        }
    }
    update_option( VIT_SYNC_PLAN_OPTION, $fresh_plan, false );

    $pid_ref = $result['post_id'] ?? 0;
    wp_send_json_success( [
        'code'       => $code,
        'post_id'    => $pid_ref,
        'title'      => $result['title'] ?? '',
        'overall'    => $valid['overall'],
        'score'      => $valid['score'],
        'checks'     => $valid['checks'],
        'edit_url'   => $pid_ref ? get_edit_post_link( $pid_ref, 'raw' ) : '',
        'updated_at' => $pid_ref ? (string) get_post_meta( $pid_ref, 'data_hora_atualizacao', true ) : '',
        'log'        => $result['log'] ?? [],
        'rate'       => vit_api_rate_status(),
    ] );
}

/**
 * Remove um imóvel desativado/oculto do WP (post + todos attachments).
 */
function vit_ajax_sync_delete_one() {
    check_ajax_referer( 'vit_bulk_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'msg' => 'sem permissão' ], 403 );
    }

    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        wp_send_json_error( [ 'msg' => 'post_id obrigatório.' ] );
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_type !== 'imoveis' ) {
        wp_send_json_error( [ 'msg' => 'Post não encontrado ou tipo incorreto.' ] );
    }

    // Segurança: só apaga se o código NÃO está entre os ativos do CRM
    $plan   = get_option( VIT_SYNC_PLAN_OPTION, [] );
    $code   = get_post_meta( $post_id, '_vista_codigo', true );
    $active = array_flip( (array) ( $plan['crm_active_map'] ?? [] ) );
    if ( isset( $active[ $code ] ) ) {
        wp_send_json_error( [ 'msg' => 'Imóvel ainda ativo no CRM. Execute uma nova varredura.' ] );
    }

    $deleted_att = vit_delete_property_fully( $post_id );

    wp_send_json_success( [
        'code'                => $code,
        'post_id'             => $post_id,
        'attachments_deleted' => $deleted_att,
    ] );
}
