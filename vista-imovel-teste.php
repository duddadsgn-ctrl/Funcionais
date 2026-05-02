<?php
/**
 * Plugin Name:       ImobFlow
 * Plugin URI:        https://anuve.com.br/
 * Description:       Plugin para WordPress que integra seu CRM imobiliário e importa automaticamente imóveis para o seu site. Mantenha sua vitrine sempre atualizada, com fotos, descrições, valores e status sincronizados em tempo real, sem trabalho manual.
 * Version:           1.0.0
 * Author:            anuve
 * Author URI:        https://anuve.com.br/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       imobflow
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
require_once VIT_PLUGIN_PATH . 'includes/class-vista-price-formatter.php';
require_once VIT_PLUGIN_PATH . 'includes/connection-monitor.php';
require_once VIT_PLUGIN_PATH . 'includes/admin-page.php';
require_once VIT_PLUGIN_PATH . 'includes/import-logic.php';
require_once VIT_PLUGIN_PATH . 'includes/validation.php';
require_once VIT_PLUGIN_PATH . 'includes/bulk-import.php';
require_once VIT_PLUGIN_PATH . 'includes/sync.php';

// Ações principais do plugin
add_action( 'init', 'vit_register_imoveis_post_type' );
add_action( 'admin_menu', 'vit_add_admin_menu' );
add_action( 'admin_post_vit_import_single_property', 'vit_handle_import_single_property' );
add_action( 'admin_post_vit_test_connection', 'vit_handle_test_connection' );
add_action( 'admin_post_vit_manual_check', 'vit_handle_manual_check' );

// AJAX da importação em lote (fila 1-a-1 + validador)
add_action( 'wp_ajax_vit_ajax_start_queue',  'vit_ajax_start_queue' );
add_action( 'wp_ajax_vit_ajax_process_next', 'vit_ajax_process_next' );
add_action( 'wp_ajax_vit_ajax_retry',        'vit_ajax_retry' );
add_action( 'wp_ajax_vit_ajax_get_state',    'vit_ajax_get_state' );
add_action( 'admin_enqueue_scripts',         'vit_enqueue_admin_assets' );

// AJAX da Atualização Geral (sync CRM ↔ WP)
add_action( 'wp_ajax_vit_ajax_sync_scan',        'vit_ajax_sync_scan' );
add_action( 'wp_ajax_vit_ajax_sync_import_new',  'vit_ajax_sync_import_new' );
add_action( 'wp_ajax_vit_ajax_sync_refresh_one', 'vit_ajax_sync_refresh_one' );
add_action( 'wp_ajax_vit_ajax_sync_delete_one',  'vit_ajax_sync_delete_one' );

// Cron: verificação horária de conectividade
add_action( 'vit_hourly_connection_check', 'vit_cron_connection_check' );
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'vit_hourly_connection_check' ) ) {
        wp_schedule_event( time(), 'hourly', 'vit_hourly_connection_check' );
    }
} );

// Cron: varredura diária CRM ↔ WP
add_action( 'vit_daily_sync_scan', 'vit_cron_daily_scan' );
add_action( 'init', function () {
    if ( ! wp_next_scheduled( 'vit_daily_sync_scan' ) ) {
        wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'vit_daily_sync_scan' );
    }
} );

