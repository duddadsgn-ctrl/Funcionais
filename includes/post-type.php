<?php
// Prevenir acesso direto.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registra o Custom Post Type 'imoveis' se ele ainda não existir.
 */
function vit_register_imoveis_post_type() {
    if ( post_type_exists( 'imoveis' ) ) {
        return;
    }

    $labels = array(
        'name'               => _x( 'Imóveis', 'post type general name', 'vista-imovel-teste' ),
        'singular_name'      => _x( 'Imóvel', 'post type singular name', 'vista-imovel-teste' ),
        'menu_name'          => _x( 'Imóveis', 'admin menu', 'vista-imovel-teste' ),
        'name_admin_bar'     => _x( 'Imóvel', 'add new on admin bar', 'vista-imovel-teste' ),
        'add_new'            => _x( 'Adicionar Novo', 'imovel', 'vista-imovel-teste' ),
        'add_new_item'       => __( 'Adicionar Novo Imóvel', 'vista-imovel-teste' ),
        'new_item'           => __( 'Novo Imóvel', 'vista-imovel-teste' ),
        'edit_item'          => __( 'Editar Imóvel', 'vista-imovel-teste' ),
        'view_item'          => __( 'Ver Imóvel', 'vista-imovel-teste' ),
        'all_items'          => __( 'Todos os Imóveis', 'vista-imovel-teste' ),
        'search_items'       => __( 'Buscar Imóveis', 'vista-imovel-teste' ),
        'not_found'          => __( 'Nenhum imóvel encontrado.', 'vista-imovel-teste' ),
        'not_found_in_trash' => __( 'Nenhum imóvel encontrado na lixeira.', 'vista-imovel-teste' )
    );

    $args = array(
        'labels'             => $labels,
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'query_var'          => true,
        'rewrite'            => array( 'slug' => 'imoveis' ),
        'capability_type'    => 'post',
        'has_archive'        => true,
        'hierarchical'       => false,
        'menu_position'      => 5,
        'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        'menu_icon'          => 'dashicons-admin-home',
    );

    register_post_type( 'imoveis', $args );

    // ── Taxonomias de controle ────────────────────────────────────────────

    // visibilidade_imovel: visivel | oculto  (driven by ExibirNoSite)
    if ( ! taxonomy_exists( 'visibilidade_imovel' ) ) {
        register_taxonomy( 'visibilidade_imovel', 'imoveis', [
            'label'             => 'Visibilidade',
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => false,
            'rewrite'           => false,
        ] );
        foreach ( [ [ 'Visível', 'visivel' ], [ 'Oculto', 'oculto' ] ] as [ $name, $slug ] ) {
            if ( ! term_exists( $slug, 'visibilidade_imovel' ) ) {
                wp_insert_term( $name, 'visibilidade_imovel', [ 'slug' => $slug ] );
            }
        }
    }

    // destaque_imovel: lancamento | destaque_web | super_destaque | exclusivo  (driven by CRM flags)
    if ( ! taxonomy_exists( 'destaque_imovel' ) ) {
        register_taxonomy( 'destaque_imovel', 'imoveis', [
            'label'             => 'Destaque',
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => false,
            'rewrite'           => [ 'slug' => 'destaque-imovel' ],
        ] );
        foreach ( [
            [ 'Lançamento',   'lancamento'    ],
            [ 'Destaque Web', 'destaque_web'  ],
            [ 'Super Destaque', 'super_destaque' ],
            [ 'Exclusivo',    'exclusivo'     ],
        ] as [ $name, $slug ] ) {
            if ( ! term_exists( $slug, 'destaque_imovel' ) ) {
                wp_insert_term( $name, 'destaque_imovel', [ 'slug' => $slug ] );
            }
        }
    }

    // imediacoes_imovel: termos dinâmicos por imediação (Serra, Costa Verde, …)
    if ( ! taxonomy_exists( 'imediacoes_imovel' ) ) {
        register_taxonomy( 'imediacoes_imovel', 'imoveis', [
            'label'             => 'Imediações',
            'public'            => true,
            'show_ui'           => true,
            'show_admin_column' => false,
            'hierarchical'      => false,
            'rewrite'           => [ 'slug' => 'imediacao' ],
        ] );
    }
}
