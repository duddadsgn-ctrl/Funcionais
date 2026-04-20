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
        $mon      = get_option( 'vit_connection_status', null );
        $next_ts  = wp_next_scheduled( 'vit_hourly_connection_check' );
        $next_str = $next_ts
            ? human_time_diff( time(), $next_ts ) . ' (às ' . wp_date( 'H:i', $next_ts ) . ')'
            : 'não agendado';

        $card_color  = '#d63638'; // error red
        $card_bg     = '#fce8e8';
        $card_icon   = '✗';
        $card_label  = 'CONEXÃO INDISPONÍVEL';
        if ( $mon && ( $mon['status'] ?? '' ) === 'ok' ) {
            $card_color = '#1a7a1a'; $card_bg = '#edfaed'; $card_icon = '✓'; $card_label = 'CONEXÃO ESTÁVEL';
        }
        ?>
        <div style="background:#fff;border:1px solid #ddd;border-radius:4px;padding:20px 24px;margin-bottom:20px;">
            <h3 style="margin-top:0;margin-bottom:16px;">Monitor de Conexão
                <span style="font-size:12px;font-weight:normal;color:#777;margin-left:8px;">verificação automática a cada 1h</span>
            </h3>

            <?php if ( $mon ) : ?>
                <!-- Card de status -->
                <div style="background:<?php echo esc_attr( $card_bg ); ?>;border-left:4px solid <?php echo esc_attr( $card_color ); ?>;padding:12px 16px;border-radius:3px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                    <span style="font-size:22px;color:<?php echo esc_attr( $card_color ); ?>;"><?php echo esc_html( $card_icon ); ?></span>
                    <div>
                        <strong style="color:<?php echo esc_attr( $card_color ); ?>;font-size:14px;"><?php echo esc_html( $card_label ); ?></strong><br>
                        <span style="font-size:12px;color:#555;"><?php echo esc_html( $mon['summary'] ); ?></span>
                    </div>
                </div>

                <!-- Caminho verificado -->
                <table style="border-collapse:collapse;font-size:13px;font-family:monospace;margin-bottom:14px;">
                    <?php foreach ( $mon['steps'] as $step ) :
                        $s_color = $step['ok'] ? '#1a7a1a' : '#b22222';
                        $s_icon  = $step['ok'] ? '✓' : '✗';
                    ?>
                    <tr>
                        <td style="padding:4px 10px 4px 0;color:#888;white-space:nowrap;"><?php echo esc_html( $step['label'] ); ?></td>
                        <td style="padding:4px 10px;color:#444;"><?php echo esc_html( $step['path'] ); ?></td>
                        <td style="padding:4px 0;color:<?php echo esc_attr( $s_color ); ?>;font-weight:bold;white-space:nowrap;">
                            <?php echo esc_html( $s_icon . ' ' . $step['msg'] ); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <p style="font-size:12px;color:#666;margin:0 0 14px;">
                    Última verificação: <strong><?php echo esc_html( $mon['checked_at'] ); ?></strong>
                    &nbsp;|&nbsp; Próxima verificação automática: <strong><?php echo esc_html( $next_str ); ?></strong>
                </p>
            <?php else : ?>
                <p style="color:#888;font-size:13px;margin:0 0 14px;">Nenhuma verificação realizada ainda. Clique em "Verificar Agora" para checar a conexão.</p>
            <?php endif; ?>

            <!-- Botão Verificar Agora -->
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
                <input type="hidden" name="action" value="vit_manual_check">
                <input type="hidden" name="vit_api_url" value="<?php echo esc_attr( get_option( 'vit_api_url', 'https://cli41034-rest.vistahost.com.br' ) ); ?>">
                <?php wp_nonce_field( 'vit_manual_check_action', 'vit_manual_check_nonce' ); ?>
                <button type="submit" class="button button-primary">Verificar Agora</button>
            </form>
        </div>
        <hr>

        <?php
        $test_report = get_transient( 'vit_test_report' );
        if ( $test_report ) {
            $test_class = match( $test_report['status'] ) {
                'success' => 'notice-success',
                'warning' => 'notice-warning',
                default   => 'notice-error',
            };
            echo '<div class="notice ' . esc_attr( $test_class ) . ' is-dismissible">';
            echo '<h3>Resultado do Teste de Conexão</h3>';
            echo '<ul style="font-family: monospace; font-size: 13px; line-height: 1.8;">';
            foreach ( $test_report['log'] as $message ) {
                $color = '';
                if ( strpos( $message, '[OK]' ) === 0 )    $color = 'color:#1a7a1a;font-weight:bold;';
                if ( strpos( $message, '[FALHA]' ) === 0 ) $color = 'color:#b22222;font-weight:bold;';
                if ( strpos( $message, '[AVISO]' ) === 0 ) $color = 'color:#c07000;font-weight:bold;';
                if ( strpos( $message, '>>' ) === 0 )      $color = 'color:#0050b3;font-weight:bold;';
                echo '<li style="' . esc_attr( $color ) . '">' . esc_html( $message ) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
            delete_transient( 'vit_test_report' );
        }

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

        <hr>
        <h3>Diagnóstico de Conexão</h3>
        <p>Use este botão para testar se o servidor consegue se conectar à API Vista antes de importar.</p>
        <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
            <input type="hidden" name="action" value="vit_test_connection">
            <input type="hidden" name="vit_api_url" value="<?php echo esc_attr( get_option( 'vit_api_url', 'https://cli41034-rest.vistahost.com.br' ) ); ?>">
            <input type="hidden" name="vit_api_key" value="<?php echo esc_attr( get_option( 'vit_api_key', '' ) ); ?>">
            <?php wp_nonce_field( 'vit_test_nonce_action', 'vit_test_nonce_field' ); ?>
            <?php submit_button( 'Testar Conexão', 'secondary' ); ?>
        </form>
    </div>
    <?php
}
