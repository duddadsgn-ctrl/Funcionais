<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conjunto WP meta_key ↔ nome CRM API.
 * Espelha exatamente os 41 campos contados em vit_count_crm_filled().
 */
function vit_wp_field_map() {
    return [
        'codigo'           => 'Codigo',
        'codigo_corretor'  => 'CodigoCorretor',
        'titulo_site'      => 'TituloSite',
        'descricao_web'    => 'DescricaoWeb',
        'bairro'           => 'Bairro',
        'bairro_comercial' => 'BairroComercial',
        'cidade'           => 'Cidade',
        'uf'               => 'UF',
        'cep'              => 'CEP',
        'endereco'         => 'Endereco',
        'numero'           => 'Numero',
        'complemento'      => 'Complemento',
        'latitude'         => 'Latitude',
        'longitude'        => 'Longitude',
        'status'           => 'Status',
        'finalidade'       => 'Finalidade',
        'categoria'        => 'Categoria',
        'moeda'            => 'Moeda',
        'exclusivo'        => 'Exclusivo',
        'lancamento'       => 'Lancamento',
        'exibir_no_site'   => 'ExibirNoSite',
        'destaque_web'     => 'DestaqueWeb',
        'dormitorios'      => 'Dormitorios',
        'suites'           => 'Suites',
        'banheiros'        => 'BanheiroSocialQtd',
        'vagas'            => 'Vagas',
        'closet'           => 'Closet',
        'hidromassagem'    => 'Hidromassagem',
        'living'           => 'Living',
        'area_total'       => 'AreaTotal',
        'area_privativa'   => 'AreaPrivativa',
        'area_terreno'     => 'AreaTerreno',
        'frente'           => 'Frente',
        'valor_venda'      => 'ValorVenda',
        'valor_locacao'    => 'ValorLocacao',
        'valor_iptu'       => 'ValorIptu',
        'valor_condominio' => 'ValorCondominio',
        'foto_destaque'    => 'FotoDestaque',
        'foto_destaque_peq'=> 'FotoDestaquePequena',
        'caracteristicas'  => 'Caracteristicas',
        'infraestrutura'   => 'InfraEstrutura',
        'imediacoes'       => 'Imediacoes',
    ];
}

/**
 * Conta quantos dos 41 campos mapeados estão preenchidos no WP.
 */
function vit_count_wp_filled( $post_id ) {
    $count = 0;
    foreach ( array_keys( vit_wp_field_map() ) as $meta_key ) {
        $v = get_post_meta( $post_id, $meta_key, true );
        if ( $v !== '' && $v !== null && $v !== false ) {
            $count++;
        }
    }
    return $count;
}

/**
 * Valida um imóvel importado e retorna o status agregado + 3 checks.
 *
 * Overall:
 *   - red    = post inexistente ou campos essenciais ausentes (código, título, cidade)
 *   - green  = WP tem pelo menos tantos campos preenchidos quanto o CRM retornou
 *   - yellow = WP tem menos campos que o CRM (importação incompleta)
 */
function vit_validate_property( $post_id ) {
    if ( ! $post_id || ! get_post( $post_id ) ) {
        return [
            'overall' => 'red',
            'score'   => 0,
            'checks'  => [
                'info'     => [ 'status' => 'fail', 'present' => [], 'missing' => [ 'post' ] ],
                'images'   => [ 'status' => 'fail', 'count' => 0, 'thumbnail' => false ],
                'complete' => [ 'status' => 'fail', 'score' => 0, 'crm_count' => 0, 'wp_count' => 0 ],
            ],
        ];
    }

    $info   = vit_validate_info( $post_id );
    $images = vit_validate_images( $post_id );

    $crm_count = (int) get_post_meta( $post_id, '_vista_crm_filled_count', true );
    $wp_count  = vit_count_wp_filled( $post_id );

    // Score proporcional à paridade; 100 quando WP ≥ CRM.
    $score = $crm_count > 0 ? min( 100, (int) round( $wp_count / $crm_count * 100 ) ) : 0;

    $parity_ok = $crm_count > 0 && $wp_count >= $crm_count;

    $complete_status = $parity_ok ? 'ok' : ( $info['status'] === 'fail' ? 'fail' : 'partial' );

    $overall = 'yellow';
    if ( $info['status'] === 'fail' ) {
        $overall = 'red';
    } elseif ( $parity_ok ) {
        $overall = 'green';
    }

    return [
        'overall' => $overall,
        'score'   => $score,
        'checks'  => [
            'info'     => $info,
            'images'   => $images,
            'complete' => [
                'status'    => $complete_status,
                'score'     => $score,
                'crm_count' => $crm_count,
                'wp_count'  => $wp_count,
            ],
        ],
    ];
}

/**
 * Valida os campos textuais essenciais do imóvel.
 * Retorna fail se código, título ou cidade estiverem ausentes (critério de red).
 */
function vit_validate_info( $post_id ) {
    $required = [
        'codigo'     => get_post_meta( $post_id, '_vista_codigo', true ),
        'titulo'     => get_the_title( $post_id ),
        'cidade'     => get_post_meta( $post_id, 'cidade', true ),
        'categoria'  => get_post_meta( $post_id, 'categoria', true ),
        'finalidade' => get_post_meta( $post_id, 'finalidade', true ),
        'status'     => get_post_meta( $post_id, 'status', true ),
    ];

    $valor_venda   = get_post_meta( $post_id, 'valor_venda', true );
    $valor_locacao = get_post_meta( $post_id, 'valor_locacao', true );
    $required['valor'] = ( $valor_venda !== '' || $valor_locacao !== '' ) ? '1' : '';

    $present = [];
    $missing = [];
    foreach ( $required as $k => $v ) {
        if ( $v !== '' && $v !== null ) {
            $present[] = $k;
        } else {
            $missing[] = $k;
        }
    }

    $essential    = [ 'codigo', 'titulo', 'cidade' ];
    $has_essential = count( array_intersect( $essential, $present ) ) === count( $essential );

    if ( ! $has_essential ) {
        $status = 'fail';
    } elseif ( empty( $missing ) ) {
        $status = 'ok';
    } else {
        $status = 'partial';
    }

    return [
        'status'  => $status,
        'present' => $present,
        'missing' => $missing,
    ];
}

/**
 * Valida galeria + thumbnail.
 */
function vit_validate_images( $post_id ) {
    $galeria = get_post_meta( $post_id, 'galeria', true );
    if ( ! is_array( $galeria ) ) {
        $galeria = [];
    }

    $valid_ids = [];
    foreach ( $galeria as $att_id ) {
        if ( (int) $att_id > 0 && wp_attachment_is_image( (int) $att_id ) ) {
            $valid_ids[] = (int) $att_id;
        }
    }

    $thumb_id  = (int) get_post_thumbnail_id( $post_id );
    $has_thumb = $thumb_id > 0 && wp_attachment_is_image( $thumb_id );
    $count     = count( $valid_ids );

    if ( $count > 0 && $has_thumb ) {
        $status = 'ok';
    } elseif ( $count > 0 || $has_thumb ) {
        $status = 'partial';
    } else {
        $status = 'fail';
    }

    return [
        'status'    => $status,
        'count'     => $count,
        'thumbnail' => $has_thumb,
        'thumb_id'  => $has_thumb ? $thumb_id : 0,
    ];
}
