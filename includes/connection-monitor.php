<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Executa uma verificação rápida de conectividade e salva o resultado.
 * Usado tanto pelo cron horário quanto pelo botão manual.
 */
function vit_run_connection_check( string $api_url = '' ): array {
    if ( empty( $api_url ) ) {
        $api_url = get_option( 'vit_api_url', '' );
    }

    $parsed = wp_parse_url( $api_url );
    $host   = $parsed['host'] ?? '';
    $scheme = $parsed['scheme'] ?? 'https';
    $port   = $parsed['port'] ?? ( $scheme === 'https' ? 443 : 80 );

    $result = [
        'checked_at' => current_time( 'mysql' ),
        'api_url'    => $api_url,
        'host'       => $host,
        'ip'         => '',
        'steps'      => [],
        'status'     => 'error',
        'summary'    => '',
    ];

    if ( empty( $host ) ) {
        $result['summary'] = 'URL da API não configurada. Salve a URL antes de verificar.';
        update_option( 'vit_connection_status', $result );
        return $result;
    }

    // Passo 1: Resolução DNS
    $ip             = gethostbyname( $host );
    $result['ip']   = $ip;
    $dns_ok         = ( $ip !== $host );
    $result['steps'][] = [
        'label' => 'DNS',
        'path'  => '"' . $host . '"',
        'ok'    => $dns_ok,
        'msg'   => $dns_ok
            ? 'Resolvido para ' . $ip
            : 'Falha — hostname não reconhecido pelo servidor WordPress',
    ];
    if ( ! $dns_ok ) {
        $result['summary'] = 'DNS não conseguiu resolver o hostname. Verifique a URL configurada.';
        update_option( 'vit_connection_status', $result );
        return $result;
    }

    // Passo 2: Conexão TCP
    $errno  = 0;
    $errstr = '';
    $sock   = @fsockopen( ( $scheme === 'https' ? 'ssl://' : '' ) . $host, $port, $errno, $errstr, 10 );
    $tcp_ok = (bool) $sock;
    if ( $sock ) {
        fclose( $sock );
    }
    $result['steps'][] = [
        'label' => 'TCP',
        'path'  => $ip . ':' . $port,
        'ok'    => $tcp_ok,
        'msg'   => $tcp_ok
            ? 'Porta ' . $port . ' aberta'
            : 'Porta ' . $port . ' bloqueada — ' . $errstr . ' (código ' . $errno . ')',
    ];
    if ( ! $tcp_ok ) {
        $result['summary'] = 'Porta ' . $port . ' inacessível. Provável bloqueio de firewall na hospedagem ou servidor externo fora do ar.';
        update_option( 'vit_connection_status', $result );
        return $result;
    }

    // Passo 3: Requisição HTTP
    @ini_set( 'default_socket_timeout', 20 );
    $resp    = wp_remote_get( rtrim( $api_url, '/' ) . '/', [
        'timeout'         => 15,
        'connect_timeout' => 10,
        'sslverify'       => false,
        'headers'         => [ 'Accept' => 'application/json' ],
    ] );
    $http_ok = ! is_wp_error( $resp );
    $http_code = $http_ok ? wp_remote_retrieve_response_code( $resp ) : 0;
    $result['steps'][] = [
        'label' => 'HTTP',
        'path'  => $scheme . '://' . $host . '/',
        'ok'    => $http_ok,
        'msg'   => $http_ok
            ? 'Servidor respondeu HTTP ' . $http_code
            : 'Erro: ' . $resp->get_error_message(),
    ];
    if ( ! $http_ok ) {
        $result['summary'] = 'TCP conectou mas a requisição HTTP falhou. Pode ser problema de SSL ou firewall na aplicação.';
        update_option( 'vit_connection_status', $result );
        return $result;
    }

    $result['status']  = 'ok';
    $result['summary'] = 'Todos os caminhos verificados. Conexão estável para importação.';
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

    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}
