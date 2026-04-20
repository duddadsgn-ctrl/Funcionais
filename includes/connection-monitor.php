<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Executa verificação de conectividade e salva o resultado como opção persistente.
 * Gera o mesmo formato de log do diagnóstico manual (linhas [OK]/[FALHA]).
 */
function vit_run_connection_check( string $api_url = '' ): array {
    if ( empty( $api_url ) ) {
        $api_url = get_option( 'vit_api_url', '' );
    }

    $log    = [];
    $log[]  = '================ VERIFICAÇÃO DE CONEXÃO ================';
    $log[]  = 'Data/hora : ' . current_time( 'mysql' );
    $log[]  = 'API URL   : ' . $api_url;
    $log[]  = '';

    $parsed = wp_parse_url( $api_url );
    $host   = $parsed['host'] ?? '';
    $scheme = $parsed['scheme'] ?? 'https';
    $port   = $parsed['port'] ?? ( $scheme === 'https' ? 443 : 80 );

    if ( empty( $host ) ) {
        $log[]  = '[FALHA] URL da API não configurada ou inválida.';
        $result = [ 'status' => 'error', 'log' => $log ];
        update_option( 'vit_connection_status', $result );
        return $result;
    }

    // --- Etapa 1: Resolução DNS ---
    $log[] = '--- Etapa 1: Resolução DNS ---';
    $ip    = gethostbyname( $host );
    if ( $ip === $host ) {
        $log[] = '[FALHA] DNS: não foi possível resolver "' . $host . '" em um endereço IP.';
        $log[] = '        Causa provável: hostname incorreto ou problema de DNS no servidor WordPress.';
        $result = [ 'status' => 'error', 'log' => $log ];
        update_option( 'vit_connection_status', $result );
        return $result;
    }
    $log[] = '[OK] DNS: "' . $host . '" resolvido para ' . $ip;

    // --- Etapa 2: Conexão TCP ---
    $log[]  = '';
    $log[]  = '--- Etapa 2: Conexão TCP na porta ' . $port . ' ---';
    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen( ( $scheme === 'https' ? 'ssl://' : '' ) . $host, $port, $errno, $errstr, 10 );
    if ( ! $sock ) {
        $log[] = '[FALHA] TCP: não conseguiu conectar em ' . $host . ':' . $port;
        $log[] = '        Erro: ' . $errstr . ' (código ' . $errno . ')';
        $log[] = '        Causa provável: servidor externo fora do ar, ou firewall bloqueando saída na porta ' . $port . '.';
        $result = [ 'status' => 'error', 'log' => $log ];
        update_option( 'vit_connection_status', $result );
        return $result;
    }
    fclose( $sock );
    $log[] = '[OK] TCP: porta ' . $port . ' aberta — ' . $host . ' (' . $ip . ')';

    // --- Etapa 3: Requisição HTTP ---
    $log[] = '';
    $log[] = '--- Etapa 3: Requisição HTTP ---';
    @ini_set( 'default_socket_timeout', 20 );
    $resp = wp_remote_get( rtrim( $api_url, '/' ) . '/', [
        'timeout'         => 15,
        'connect_timeout' => 10,
        'sslverify'       => false,
        'headers'         => [ 'Accept' => 'application/json' ],
    ] );
    if ( is_wp_error( $resp ) ) {
        $log[] = '[FALHA] HTTP: ' . $resp->get_error_message();
        $log[] = '        Causa provável: problema de certificado SSL ou servidor recusando a requisição.';
        $result = [ 'status' => 'error', 'log' => $log ];
        update_option( 'vit_connection_status', $result );
        return $result;
    }
    $http  = wp_remote_retrieve_response_code( $resp );
    $log[] = '[OK] HTTP: servidor respondeu com HTTP ' . $http;

    $log[] = '';
    $log[] = '>> Conexão estável. A importação pode ser executada.';

    $result = [ 'status' => 'ok', 'log' => $log ];
    update_option( 'vit_connection_status', $result );
    return $result;
}

/**
 * Callback do cron horário.
 */
function vit_cron_connection_check(): void {
    vit_run_connection_check( get_option( 'vit_api_url', '' ) );
}

/**
 * Handler do botão "Verificar Agora".
 */
function vit_handle_manual_check(): void {
    if ( ! isset( $_POST['vit_manual_check_nonce'] ) || ! wp_verify_nonce( $_POST['vit_manual_check_nonce'], 'vit_manual_check_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Sem permissão.' );
    }

    $api_url = esc_url_raw( $_POST['vit_api_url'] ?? '' );
    if ( ! empty( $api_url ) ) {
        update_option( 'vit_api_url', $api_url );
    }

    vit_run_connection_check( $api_url ?: get_option( 'vit_api_url', '' ) );

    // Renova o timer: próxima verificação automática em 1h a partir de agora
    $hook = 'vit_hourly_connection_check';
    $next = wp_next_scheduled( $hook );
    if ( $next ) {
        wp_unschedule_event( $next, $hook );
    }
    wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', $hook );

    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}
