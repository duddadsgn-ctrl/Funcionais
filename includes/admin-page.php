<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function vit_add_admin_menu() {
    add_menu_page(
        'Vista Teste 1 Imóvel',
        'Vista Teste 1 Imóvel',
        'manage_options',
        'vista-imovel-teste',
        'vit_admin_page_html',
        'dashicons-rest-api',
        20
    );
}

function vit_admin_page_html() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p>
            O plugin vai buscar automaticamente um imóvel ativo e bem preenchido no seu CRM e importá-lo.<br>
            Nenhum código, categoria ou finalidade precisam ser informados.
        </p>

        <?php
        // ── Monitor de Conexão ─────────────────────────────────────────────
        $mon     = get_option( 'vit_connection_status', null );
        $next_ts = wp_next_scheduled( 'vit_hourly_connection_check' );
        $next_str = $next_ts
            ? human_time_diff( time(), $next_ts ) . ' (às ' . wp_date( 'H:i', $next_ts ) . ')'
            : 'não agendado';

        if ( $mon ) {
            $mon_class = match( $mon['status'] ?? 'error' ) {
                'ok'    => 'notice-success',
                default => 'notice-error',
            };
            echo '<div class="notice ' . esc_attr( $mon_class ) . '">';
            echo '<h3>Monitor de Conexão <span style="font-size:12px;font-weight:normal;color:#555;">— verificação automática a cada 1h</span></h3>';
            echo '<ul style="font-family:monospace;font-size:13px;line-height:1.8;">';
            foreach ( $mon['log'] as $line ) {
                $color = '';
                if ( str_starts_with( $line, '[OK]' ) )    $color = 'color:#1a7a1a;font-weight:bold;';
                if ( str_starts_with( $line, '[FALHA]' ) ) $color = 'color:#b22222;font-weight:bold;';
                if ( str_starts_with( $line, '>>' ) )      $color = 'color:#0050b3;font-weight:bold;';
                echo '<li style="' . esc_attr( $color ) . '">' . esc_html( $line ) . '</li>';
            }
            echo '</ul>';
            echo '<p style="font-size:12px;color:#555;margin:4px 0 10px;">Próxima verificação automática: <strong>' . esc_html( $next_str ) . '</strong></p>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-info"><p><strong>Monitor de Conexão</strong> — Nenhuma verificação realizada ainda. Clique em "Verificar Agora".</p></div>';
        }
        ?>

        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="margin-bottom:20px;">
            <input type="hidden" name="action" value="vit_manual_check">
            <input type="hidden" name="vit_api_url" value="<?php echo esc_attr( get_option( 'vit_api_url', 'https://cli41034-rest.vistahost.com.br' ) ); ?>">
            <?php wp_nonce_field( 'vit_manual_check_action', 'vit_manual_check_nonce' ); ?>
            <button type="submit" class="button button-primary">Verificar Agora</button>
            <span style="font-size:12px;color:#888;margin-left:8px;">Próxima automática: <?php echo esc_html( $next_str ); ?></span>
        </form>
        <hr>

        <?php
        $report = get_transient( 'vit_import_report' );
        if ( $report ) {
            $notice_class = ( $report['status'] === 'success' ) ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible">';
            echo '<h3>Relatório de Importação</h3>';
            echo '<ul style="font-family: monospace; font-size: 13px; line-height: 1.8;">';
            foreach ( $report['log'] as $message ) {
                echo '<li>' . esc_html( $message ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
            delete_transient( 'vit_import_report' );
        }
        ?>

        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="vit_import_single_property">
            <?php wp_nonce_field( 'vit_import_nonce_action', 'vit_import_nonce_field' ); ?>

            <h3>Configurações da API Vista</h3>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="vit_api_url">API URL (Host REST)</label></th>
                    <td>
                        <input type="text" id="vit_api_url" name="vit_api_url" class="regular-text"
                            value="<?php echo esc_attr( get_option( 'vit_api_url', 'https://cli41034-rest.vistahost.com.br' ) ); ?>"
                            required />
                        <p class="description">Ex: https://cli41034-rest.vistahost.com.br</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><label for="vit_api_key">API Key</label></th>
                    <td>
                        <input type="text" id="vit_api_key" name="vit_api_key" class="regular-text"
                            value="<?php echo esc_attr( get_option( 'vit_api_key', '' ) ); ?>"
                            required />
                        <p class="description">Chave de acesso fornecida pelo Vista CRM.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Importar 1 imóvel de teste' ); ?>
        </form>

    </div>
    <?php
}
