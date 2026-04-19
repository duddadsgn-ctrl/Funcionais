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
