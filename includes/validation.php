<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Valida um imóvel importado e retorna o status agregado + 3 checks.
 *
 * Overall:
 *   - red    = post inexistente ou info=fail
 *   - green  = info.ok && images.ok && complete.ok
 *   - yellow = qualquer outro caso
 */
function vit_validate_property( $post_id ) {
    if ( ! $post_id || ! get_post( $post_id ) ) {
        return [
            'overall' => 'red',
            'score'   => 0,
            'checks'  => [
                'info'     => [ 'status' => 'fail', 'present' => [], 'missing' => [ 'post' ] ],
                'images'   => [ 'status' => 'fail', 'count' => 0, 'thumbnail' => false ],
                'complete' => [ 'status' => 'fail', 'score' => 0 ],
            ],
        ];
    }

    $info   = vit_validate_info( $post_id );
    $images = vit_validate_images( $post_id );
    $complete = vit_validate_completeness( $post_id, $info, $images );

    $overall = 'yellow';
    if ( $info['status'] === 'fail' ) {
        $overall = 'red';
    } elseif ( $info['status'] === 'ok' && $images['status'] === 'ok' && $complete['status'] === 'ok' ) {
        $overall = 'green';
    }

    return [
        'overall' => $overall,
        'score'   => $complete['score'],
        'checks'  => [
            'info'     => $info,
            'images'   => $images,
            'complete' => $complete,
        ],
    ];
}

/**
 * Valida os campos textuais obrigatórios do imóvel.
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

    $essential = [ 'codigo', 'titulo', 'cidade' ];
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

    $count = count( $valid_ids );

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

/**
 * Agrega info + imagens + características em um score 0-100.
 */
function vit_validate_completeness( $post_id, $info, $images ) {
    $has_caract = trim( (string) get_post_meta( $post_id, 'caracteristicas', true ) ) !== '';
    $has_infra  = trim( (string) get_post_meta( $post_id, 'infraestrutura', true ) ) !== '';
    $has_imed   = trim( (string) get_post_meta( $post_id, 'imediacoes', true ) ) !== '';
    $has_features = ( $has_caract || $has_infra || $has_imed );

    $score = 0;
    if ( $info['status'] === 'ok' ) {
        $score += 40;
    } elseif ( $info['status'] === 'partial' ) {
        $score += 20;
    }
    if ( $images['status'] === 'ok' ) {
        $score += 40;
    } elseif ( $images['status'] === 'partial' ) {
        $score += 20;
    }
    if ( $has_features ) {
        $score += 20;
    }

    // Verde quando info + imagens ok. Features (caract/infra/imed) somam score mas não bloqueiam.
    if ( $info['status'] === 'ok' && $images['status'] === 'ok' ) {
        $status = 'ok';
    } elseif ( $info['status'] === 'fail' ) {
        $status = 'fail';
    } else {
        $status = 'partial';
    }

    return [
        'status' => $status,
        'score'  => $score,
    ];
}
