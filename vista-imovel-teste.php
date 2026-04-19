<?php
/**
 * Plugin Name:       Vista Imóvel Teste
 * Plugin URI:        https://example.com/
 * Description:       Um plugin simples para testar a importação de 1 imóvel da API Vista.
 * Version:           1.0.0
 * Author:            Seu Nome
 * Author URI:        https://example.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vista-imovel-teste
 */

// Prevenir acesso direto ao arquivo.
if ( ! defined( 'ABSPATH'  ) ) {
    exit;
}

// Constantes do Plugin
define( 'VIT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Incluir arquivos necessários
require_once VIT_PLUGIN_PATH . 'includes/post-type.php';
require_once VIT_PLUGIN_PATH . 'includes/admin-page.php';
require_once VIT_PLUGIN_PATH . 'includes/import-logic.php';

// Ações principais do plugin
add_action( 'init', 'vit_register_imoveis_post_type' );
add_action( 'admin_menu', 'vit_add_admin_menu' );
add_action( 'admin_post_vit_import_single_property', 'vit_handle_import_single_property' );

