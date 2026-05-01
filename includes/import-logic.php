<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handler do botão "Importar 1 imóvel de teste".
 */
function vit_handle_import_single_property() {
    if ( ! isset( $_POST['vit_import_nonce_field'] ) || ! wp_verify_nonce( $_POST['vit_import_nonce_field'], 'vit_import_nonce_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Você não tem permissão para executar esta ação.' );
    }

    $api_url = esc_url_raw( $_POST['vit_api_url'] ?? '' );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] ?? '' );

    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );

    $report = vit_import_property( $api_url, $api_key );

    set_transient( 'vit_import_report', $report, 120 );
    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}

/**
 * Função principal: descobre automaticamente 1 imóvel bom, busca detalhes e importa.
 */
function vit_import_property( $api_url, $api_key ) {
    $log = [];
    $log[] = '================ INÍCIO DA IMPORTAÇÃO ================';
    $log[] = 'Data/hora: ' . current_time( 'mysql' );

    if ( empty( $api_url ) || empty( $api_key ) ) {
        $log[] = 'ERRO: API URL e API Key são obrigatórios.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    // ============ FASE 1: buscar lista de candidatos ============
    $log[] = '';
    $log[] = '--- FASE 1: buscando lista de candidatos em /imoveis/listar ---';

    // IMPORTANTE: /imoveis/listar NÃO aceita o campo "Foto" — ele só existe em /detalhes.
    $list_params = [
        'fields'    => [ 'Codigo', 'TituloSite', 'Status', 'Categoria', 'Finalidade', 'Dormitorios', 'Cidade', 'Bairro' ],
        'paginacao' => [ 'pagina' => 1, 'quantidade' => 20 ],
    ];

    $list_response = vit_call_api_get( $api_url, '/imoveis/listar', $api_key, $list_params, $log );

    if ( is_wp_error( $list_response ) ) {
        $log[] = 'ERRO FINAL (listar): ' . $list_response->get_error_message();
        return [ 'status' => 'error', 'log' => $log ];
    }

    $candidates = vit_extract_candidates( $list_response, $log );
    if ( empty( $candidates ) ) {
        $log[] = 'ERRO: A API não retornou nenhum imóvel utilizável em /imoveis/listar.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    // ============ FASE 2: pegar o primeiro imóvel disponível ============
    $log[] = '';
    $log[] = '--- FASE 2: selecionando imóvel ---';
    $log[] = 'Total de imóveis recebidos: ' . count( $candidates );

    $chosen       = $candidates[0];
    $property_code = $chosen['Codigo'] ?? '';
    $categoria     = $chosen['Categoria'] ?? '';
    $finalidade    = $chosen['Finalidade'] ?? '';

    $log[] = sprintf(
        'Imóvel selecionado: código=%s | Categoria=%s | Status=%s | Cidade=%s',
        $property_code,
        $categoria ?: '-',
        $chosen['Status']      ?? '-',
        $chosen['Cidade']      ?? '-'
    );

    // FASES 3–5 delegadas para vit_import_single_by_code.
    $single = vit_import_single_by_code( $api_url, $api_key, $property_code, $categoria, $finalidade, $chosen );
    $log = array_merge( $log, $single['log'] );

    return [ 'status' => $single['status'], 'log' => $log ];
}

/**
 * Importa um único imóvel a partir do código explícito.
 *
 * Usado tanto pelo botão "Importar 1 imóvel de teste" (após a FASE 1-2 de
 * listar/escolher) quanto pelo loop da importação em lote (que já tem
 * o código em mãos).
 *
 * @param string $api_url
 * @param string $api_key
 * @param string $code        Código do imóvel no Vista CRM.
 * @param string $categoria   Opcional — ajuda /imoveis/detalhes.
 * @param string $finalidade  Opcional.
 * @param array  $base_data   Opcional — dados já obtidos em /listar para merge.
 * @return array { status, post_id, title, log, field_counters, image_counters }
 */
function vit_import_single_by_code( $api_url, $api_key, $code, $categoria = '', $finalidade = '', $base_data = [] ) {
    $log = [];
    $log[] = '================ IMPORTAÇÃO (código ' . $code . ') ================';
    $log[] = 'Data/hora: ' . current_time( 'mysql' );

    if ( empty( $api_url ) || empty( $api_key ) || empty( $code ) ) {
        $log[] = 'ERRO: api_url, api_key e código são obrigatórios.';
        return [ 'status' => 'error', 'post_id' => 0, 'title' => '', 'log' => $log ];
    }

    // ============ FASE 3: detalhes ============
    $log[] = '';
    $log[] = '--- FASE 3: buscando detalhes em /imoveis/detalhes ---';

    $details_response = vit_call_detalhes( $api_url, $api_key, $code, $categoria, $finalidade, $log );
    if ( is_wp_error( $details_response ) ) {
        $log[] = 'ERRO (detalhes): ' . $details_response->get_error_message();
        $log[] = 'Usando apenas dados base para importação parcial...';
        $details_response = [];
    }

    // ============ MERGE base (listagem) + detalhes ============
    $log[] = '';
    $log[] = '--- MERGE: base + detalhes ---';
    if ( ! is_array( $base_data ) ) {
        $base_data = [];
    }
    if ( empty( $base_data['Codigo'] ) ) {
        $base_data['Codigo'] = $code;
    }
    if ( empty( $base_data['Categoria'] ) && $categoria !== '' ) {
        $base_data['Categoria'] = $categoria;
    }
    if ( empty( $base_data['Finalidade'] ) && $finalidade !== '' ) {
        $base_data['Finalidade'] = $finalidade;
    }

    $property_data = $base_data;
    $field_origins = [];
    foreach ( $base_data as $k => $v ) {
        $field_origins[ $k ] = ( $v !== '' && $v !== null ) ? 'listar' : 'vazio';
    }
    foreach ( (array) $details_response as $k => $v ) {
        $detail_has_value = ( $v !== '' && $v !== null && ! ( is_array( $v ) && empty( $v ) ) );
        if ( $detail_has_value ) {
            $property_data[ $k ] = $v;
            $field_origins[ $k ] = 'detalhes';
        } elseif ( ! isset( $property_data[ $k ] ) ) {
            $property_data[ $k ] = $v;
            $field_origins[ $k ] = 'vazio';
        }
    }

    if ( empty( $property_data['Codigo'] ) ) {
        $log[] = 'ERRO: nenhum código após merge.';
        return [ 'status' => 'error', 'post_id' => 0, 'title' => '', 'log' => $log ];
    }

    $log[] = 'Campos após merge: ' . count( $property_data ) . ' | Código: ' . $property_data['Codigo'];

    // ============ FASE 4: post + metas ============
    $log[] = '';
    $log[] = '--- FASE 4: criando/atualizando post ---';

    $post_id = vit_get_or_create_property_post( $property_data['Codigo'], $log );
    if ( ! $post_id ) {
        $log[] = 'ERRO: falha ao criar/localizar o post.';
        return [ 'status' => 'error', 'post_id' => 0, 'title' => '', 'log' => $log ];
    }

    $field_counters = [ 'saved' => 0, 'empty' => 0 ];
    vit_update_property_fields( $post_id, $property_data, $log, $field_counters, $field_origins );
    vit_sync_property_taxonomies( $post_id, $property_data, $log );

    // ============ FASE 5: imagens ============
    $log[] = '';
    $log[] = '--- FASE 5: processando imagens ---';
    $image_counters = [ 'found' => 0, 'imported' => 0, 'failed' => 0, 'thumbnail_set' => false, 'thumbnail_id' => 0 ];
    vit_process_property_images( $post_id, $property_data, $log, $image_counters );

    // Marca data/hora da última atualização (campo visível no painel ACF)
    update_post_meta( $post_id, 'data_hora_atualizacao', current_time( 'mysql' ) );

    // ============ RESUMO ============
    $log[] = '';
    $log[] = '========== RESUMO ==========';
    $log[] = 'ID do Post WordPress : ' . $post_id;
    $log[] = 'Código do Imóvel     : ' . $property_data['Codigo'];
    $log[] = 'Título               : ' . get_the_title( $post_id );
    $log[] = 'Campos salvos / vazios: ' . $field_counters['saved'] . ' / ' . $field_counters['empty'];
    $log[] = 'Imagens encontradas  : ' . $image_counters['found'];
    $log[] = 'Imagens importadas   : ' . $image_counters['imported'];
    $log[] = 'Imagens falhadas     : ' . $image_counters['failed'];
    $log[] = 'Thumbnail definida   : ' . ( $image_counters['thumbnail_set'] ? ( 'SIM (ID: ' . $image_counters['thumbnail_id'] . ')' ) : 'NÃO' );
    $log[] = 'Link para editar     : ' . get_edit_post_link( $post_id, 'raw' );
    $log[] = '============================';

    return [
        'status'         => 'success',
        'post_id'        => $post_id,
        'title'          => get_the_title( $post_id ),
        'log'            => $log,
        'field_counters' => $field_counters,
        'image_counters' => $image_counters,
    ];
}

/**
 * Normaliza a resposta de /imoveis/listar em array de candidatos.
 * A API pode retornar: array numérico, objeto com chaves numéricas como strings, ou payload com "total".
 */
function vit_extract_candidates( $response, &$log ) {
    if ( ! is_array( $response ) ) {
        $log[] = 'Resposta da listar não é array. Ignorando.';
        return [];
    }

    $items = [];
    foreach ( $response as $key => $value ) {
        if ( in_array( $key, [ 'paginacao', 'total', 'pagina', 'quantidade' ], true ) ) continue;
        if ( is_array( $value ) && isset( $value['Codigo'] ) ) {
            $items[] = $value;
        }
    }
    return $items;
}

/**
 * Busca detalhes do imóvel no formato G confirmado.
 * Faz 2 chamadas: uma para campos de texto, outra só para fotos.
 * Retorna merge das duas respostas.
 */
function vit_call_detalhes( $base_url, $api_key, $codigo, $categoria, $finalidade, &$log ) {
    $base = rtrim( $base_url, '/' ) . '/imoveis/detalhes';

    $cat_arr = [];
    if ( ! empty( $categoria ) )  $cat_arr['Categoria']  = $categoria;
    if ( ! empty( $finalidade ) ) $cat_arr['Finalidade'] = $finalidade;

    // Campos de texto confirmados disponíveis neste Vista CRM.
    // Vista retorna HTTP 400 se QUALQUER campo da lista não existir — por isso
    // esta lista inclui apenas campos validados. O retry automático abaixo remove
    // dinamicamente qualquer campo que o CRM sinalize como indisponível.
    $text_fields = [
        // Identificação
        'Codigo', 'CodigoCorretor',
        // Título e descrição
        'TituloSite', 'DescricaoWeb',
        // Localização
        'Bairro', 'BairroComercial', 'Cidade', 'UF',
        'CEP', 'Endereco', 'Numero', 'Complemento', 'Latitude', 'Longitude',
        // Classificação
        'Status', 'Finalidade', 'Categoria', 'Moeda',
        'Exclusivo', 'Lancamento', 'ExibirNoSite', 'DestaqueWeb',
        // Cômodos e áreas
        'Dormitorios', 'Suites', 'BanheiroSocialQtd',
        'Vagas', 'Closet', 'Hidromassagem', 'Living',
        'AreaTotal', 'AreaPrivativa', 'AreaTerreno', 'Frente',
        // Valores
        'ValorVenda', 'ValorLocacao', 'ValorIptu', 'ValorCondominio',
        // Fotos de destaque (URLs)
        'FotoDestaque', 'FotoDestaquePequena',
        // Arrays
        'Caracteristicas', 'InfraEstrutura', 'Imediacoes',
    ];

    // ---- Chamada 1: campos de texto ----
    $url1 = add_query_arg( array_merge(
        [ 'key' => $api_key, 'imovel' => $codigo ],
        $cat_arr,
        [ 'pesquisa' => wp_json_encode( [ 'fields' => $text_fields ] ) ]
    ), $base );

    $log[] = 'Chamada 1/2 (campos texto): ' . wp_json_encode( $text_fields );
    $data_text = vit_raw_get( $url1, $log );

    // Retry automático: se o Vista retornou 400 por campos inválidos, extrai os
    // nomes deles da mensagem de erro e retenta sem eles. Adapta a qualquer CRM.
    if ( is_wp_error( $data_text ) ) {
        $err_msg = $data_text->get_error_message();
        preg_match_all( '/Campo (\w+) não está disponível/', $err_msg, $m );
        if ( ! empty( $m[1] ) ) {
            $invalid      = $m[1];
            $clean_fields = array_values( array_diff( $text_fields, $invalid ) );
            $log[] = 'Campos inválidos neste CRM (' . count( $invalid ) . '): ' . implode( ', ', $invalid );
            $log[] = 'Retentando com ' . count( $clean_fields ) . ' campos válidos...';
            $url_clean = add_query_arg( array_merge(
                [ 'key' => $api_key, 'imovel' => $codigo ],
                $cat_arr,
                [ 'pesquisa' => wp_json_encode( [ 'fields' => $clean_fields ] ) ]
            ), $base );
            $data_text = vit_raw_get( $url_clean, $log );
        }
    }

    // Último recurso: sem filtro de campos (API retorna o disponível por padrão)
    if ( is_wp_error( $data_text ) ) {
        $log[] = 'Ainda com erro — tentando sem filtro de campos: ' . $data_text->get_error_message();
        $url_bare = add_query_arg( array_merge(
            [ 'key' => $api_key, 'imovel' => $codigo ],
            $cat_arr
        ), $base );
        $data_text = vit_raw_get( $url_bare, $log );
    }

    if ( is_wp_error( $data_text ) ) {
        return $data_text;
    }

    // ---- Chamada 2: fotos (nested spec) ----
    // Vista CRM usa nomes "Foto" e "FotoPequena" DENTRO do sub-objeto Foto.
    // Formato real da API: {"Foto":[{"Foto":"url_grande","FotoPequena":"url_pq","Destaque":"Sim","Ordem":"1"},...]}
    $photo_attempts = [
        // Nomes reais da Vista (primária)
        [ 'Foto' => [ 'Foto', 'FotoPequena', 'Destaque', 'Ordem' ] ],
        // Sem lista de subcampos (API devolve tudo que houver)
        [ 'Foto' => [] ],
        // Nomes alternativos antigos
        [ 'Foto' => [ 'URLFoto', 'URLFotoPequena', 'Destaque', 'Ordem' ] ],
        [ 'Fotos' => [ 'Foto', 'FotoPequena', 'Destaque', 'Ordem' ] ],
        [ 'Imagens' => [ 'Foto', 'FotoPequena', 'Destaque', 'Ordem' ] ],
    ];

    $data_foto = null;
    $used_key  = null;
    foreach ( $photo_attempts as $photo_spec ) {
        $field_key  = array_key_first( $photo_spec );
        $subfields  = $photo_spec[ $field_key ];
        $url_foto   = add_query_arg( array_merge(
            [ 'key' => $api_key, 'imovel' => $codigo ],
            $cat_arr,
            [ 'pesquisa' => wp_json_encode( [ 'fields' => [ $photo_spec ] ] ) ]
        ), $base );

        $log[] = sprintf(
            "Chamada 2/2 (fotos): campo='%s' subfields=%s",
            $field_key,
            empty( $subfields ) ? '[] (todos)' : wp_json_encode( $subfields )
        );
        $resp = vit_raw_get( $url_foto, $log );

        if ( ! is_wp_error( $resp ) && is_array( $resp ) ) {
            // Procura o bloco de fotos na resposta
            foreach ( [ 'Foto', 'Fotos', 'Imagens' ] as $fk ) {
                if ( ! empty( $resp[ $fk ] ) && is_array( $resp[ $fk ] ) ) {
                    $data_foto = $resp;
                    $used_key  = $fk;
                    break 2;
                }
            }
            $log[] = "  resposta OK mas sem bloco de fotos (chaves: " . implode( ', ', array_keys( $resp ) ) . ")";
            continue;
        }
        if ( is_wp_error( $resp ) ) {
            $log[] = "  '{$field_key}' não disponível: " . $resp->get_error_message();
        }
    }

    // Merge: começar com data_text, completar com data_foto se houver
    $merged = $data_text;
    if ( $data_foto && $used_key ) {
        $photos = $data_foto[ $used_key ];
        $merged[ $used_key ] = $photos;
        $merged['Foto']      = $photos; // normaliza: sempre disponível em "Foto"
        $log[] = "Fotos recebidas no campo '{$used_key}': " . count( $photos );

        // Log das chaves do primeiro item para debug
        if ( ! empty( $photos[0] ) && is_array( $photos[0] ) ) {
            $log[] = '  Chaves do 1º item de foto: ' . implode( ', ', array_keys( $photos[0] ) );
            $first_preview = wp_json_encode( $photos[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            if ( mb_strlen( $first_preview ) > 300 ) $first_preview = mb_substr( $first_preview, 0, 300 ) . '...';
            $log[] = '  Exemplo: ' . $first_preview;
        }
    } else {
        $log[] = 'Bloco de fotos não retornado pela API neste endpoint (todas as tentativas falharam).';
    }

    return $merged;
}

/**
 * Limita chamadas à API Vista a 290/min (margem antes dos 300 da Loft).
 * Usa transient com bucket por minuto UTC. Se atingido, dorme até o próximo minuto.
 */
function vit_api_rate_limit() {
    $key   = 'vit_rate_' . gmdate( 'YmdHi' );
    $count = (int) get_transient( $key );

    if ( $count >= 290 ) {
        $secs_left = 60 - (int) gmdate( 's' );
        sleep( max( 1, $secs_left + 1 ) );
        $key   = 'vit_rate_' . gmdate( 'YmdHi' ); // novo bucket após sleep
        $count = (int) get_transient( $key );       // lê o novo bucket (pode ter outras requisições)
    }

    set_transient( $key, $count + 1, 120 );
}

/**
 * GET helper simples — retorna array decodificado ou WP_Error.
 */
function vit_raw_get( $url, &$log ) {
    vit_api_rate_limit();
    @ini_set( 'default_socket_timeout', 35 );
    $raw = wp_remote_get( $url, [
        'timeout'         => 30,
        'connect_timeout' => 15,
        'sslverify'       => false,
        'headers'         => [ 'Accept' => 'application/json' ],
    ] );

    if ( is_wp_error( $raw ) ) {
        $log[] = '  ERRO CONEXÃO: ' . $raw->get_error_message();
        return $raw;
    }

    $http  = wp_remote_retrieve_response_code( $raw );
    $rbody = wp_remote_retrieve_body( $raw );
    $dec   = json_decode( $rbody, true );

    if ( $http !== 200 ) {
        $msg = '';
        if ( is_array( $dec ) && isset( $dec['message'] ) ) {
            $msg = is_array( $dec['message'] ) ? implode( ' | ', $dec['message'] ) : (string) $dec['message'];
        }
        $log[] = '  HTTP ' . $http . ( $msg ? " — {$msg}" : '' );
        return new WP_Error( 'api_http_error', "HTTP {$http}" . ( $msg ? " — {$msg}" : '' ) );
    }

    if ( json_last_error() !== JSON_ERROR_NONE ) {
        $log[] = '  JSON inválido: ' . json_last_error_msg();
        return new WP_Error( 'api_json_error', 'JSON inválido' );
    }

    $log[] = '  HTTP 200 OK — campos recebidos: ' . implode( ', ', array_keys( $dec ?? [] ) );
    return $dec;
}

/**
 * GET para /imoveis/listar — parâmetros via query string "pesquisa" em JSON.
 */
function vit_call_api_get( $base_url, $endpoint, $api_key, $params, &$log ) {
    vit_api_rate_limit();
    $url = rtrim( $base_url, '/' ) . $endpoint;
    $url = add_query_arg( [
        'key'      => $api_key,
        'pesquisa' => wp_json_encode( $params ),
        'showtotal' => 1,
    ], $url );

    $log[] = 'Endpoint   : GET ' . $endpoint;
    $log[] = 'Pesquisa   : ' . wp_json_encode( $params, JSON_UNESCAPED_UNICODE );

    @ini_set( 'default_socket_timeout', 35 );
    $response = wp_remote_get( $url, [
        'timeout'         => 30,
        'connect_timeout' => 15,
        'sslverify'       => false,
        'headers'         => [ 'Accept' => 'application/json' ],
    ] );
    return vit_handle_api_response( $response, $log );
}

function vit_handle_api_response( $response, &$log ) {
    if ( is_wp_error( $response ) ) {
        $log[] = 'ERRO DE CONEXÃO: ' . $response->get_error_message();
        return $response;
    }
    $body      = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response );

    $log[] = 'HTTP Status: ' . $http_code;
    $log[] = 'Resposta   : ' . substr( $body, 0, 500 ) . ( strlen( $body ) > 500 ? ' (...truncado)' : '' );

    $decoded = json_decode( $body, true );

    if ( $http_code !== 200 ) {
        // Tenta extrair a mensagem de erro estruturada da API Vista.
        $api_msg = '';
        if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
            $api_msg = is_array( $decoded['message'] ) ? implode( ' | ', $decoded['message'] ) : (string) $decoded['message'];
        }
        $log[] = 'ERRO da API: ' . ( $api_msg ?: '(sem mensagem)' );
        return new WP_Error( 'api_http_error', "HTTP {$http_code}" . ( $api_msg ? " — {$api_msg}" : '' ) );
    }
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        return new WP_Error( 'api_json_error', 'Falha ao decodificar JSON: ' . json_last_error_msg() );
    }
    return $decoded;
}

/**
 * Procura post existente por _vista_codigo ou cria novo.
 */
function vit_get_or_create_property_post( $vista_code, &$log ) {
    $query = new WP_Query( [
        'post_type'      => 'imoveis',
        'post_status'    => 'any',
        'meta_key'       => '_vista_codigo',
        'meta_value'     => $vista_code,
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );

    if ( $query->have_posts() ) {
        $post_id = $query->posts[0];
        $log[] = "Post existente localizado (ID {$post_id}). Atualizando.";
        return $post_id;
    }

    $post_id = wp_insert_post( [
        'post_type'   => 'imoveis',
        'post_status' => 'publish',
        'post_title'  => 'Imóvel ' . $vista_code,
    ] );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        $log[] = 'Falha ao criar post: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'erro desconhecido' );
        return 0;
    }
    $log[] = "Post novo criado (ID {$post_id}).";
    return $post_id;
}

/**
 * Salva todos os campos do imóvel como meta e loga um a um.
 */
function vit_update_property_fields( $post_id, $data, &$log, &$counters, $field_origins = [] ) {
    // Título e conteúdo
    $title = ! empty( $data['TituloSite'] )
        ? $data['TituloSite']
        : trim( ( $data['Cidade'] ?? '' ) . ' - ' . ( $data['Bairro'] ?? '' ) );
    if ( empty( trim( $title, ' -' ) ) ) $title = 'Imóvel ' . ( $data['Codigo'] ?? '' );

    wp_update_post( [
        'ID'           => $post_id,
        'post_title'   => sanitize_text_field( $title ),
        'post_content' => wp_kses_post( $data['DescricaoWeb'] ?? '' ),
    ] );
    $log[] = "[TÍTULO] definido como: \"{$title}\"";

    // Mapa completo: meta_key WP => nome do campo na API Vista.
    // Cobre absolutamente todos os campos escalares conhecidos do Vista CRM.
    // Mapa WP meta_key → nome do campo na API Vista.
    // Contém apenas campos confirmados disponíveis nesta instalação do CRM
    // (alinhado com $text_fields em vit_call_detalhes).
    $map = [
        // Identificação
        '_vista_codigo'        => 'Codigo',
        'codigo'               => 'Codigo',
        'codigo_corretor'      => 'CodigoCorretor',
        // Textos
        'titulo_site'          => 'TituloSite',
        'descricao_web'        => 'DescricaoWeb',
        // Localização
        'bairro'               => 'Bairro',
        'bairro_comercial'     => 'BairroComercial',
        'cidade'               => 'Cidade',
        'uf'                   => 'UF',
        'cep'                  => 'CEP',
        'endereco'             => 'Endereco',
        'numero'               => 'Numero',
        'complemento'          => 'Complemento',
        'latitude'             => 'Latitude',
        'longitude'            => 'Longitude',
        // Classificação
        'status'               => 'Status',
        'finalidade'           => 'Finalidade',
        'categoria'            => 'Categoria',
        'moeda'                => 'Moeda',
        'exclusivo'            => 'Exclusivo',
        'lancamento'           => 'Lancamento',
        'exibir_no_site'       => 'ExibirNoSite',
        'destaque_web'         => 'DestaqueWeb',
        // Cômodos e áreas
        'dormitorios'          => 'Dormitorios',
        'suites'               => 'Suites',
        'banheiros'            => 'BanheiroSocialQtd',
        'vagas'                => 'Vagas',
        'closet'               => 'Closet',
        'hidromassagem'        => 'Hidromassagem',
        'living'               => 'Living',
        'area_total'           => 'AreaTotal',
        'area_privativa'       => 'AreaPrivativa',
        'area_terreno'         => 'AreaTerreno',
        'frente'               => 'Frente',
        // Fotos de destaque
        'foto_destaque'        => 'FotoDestaque',
        'foto_destaque_peq'    => 'FotoDestaquePequena',
    ];

    foreach ( $map as $meta_key => $api_key ) {
        $value  = $data[ $api_key ] ?? null;
        $origin = $field_origins[ $api_key ] ?? 'desconhecido';

        if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
            $log[] = sprintf( "[CAMPO] API:'%s' -> WP:'%s' | origem=%s | Valor: \"\" | - VAZIO (ignorado)", $api_key, $meta_key, $origin );
            $counters['empty']++;
            continue;
        }
        $clean   = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : maybe_serialize( $value );
        $preview = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
        if ( mb_strlen( $preview ) > 60 ) $preview = mb_substr( $preview, 0, 60 ) . '...';
        update_post_meta( $post_id, $meta_key, $clean );
        $log[] = sprintf( "[CAMPO] API:'%s' -> WP:'%s' | origem=%s | Valor: \"%s\" | OK SALVO", $api_key, $meta_key, $origin, $preview );
        $counters['saved']++;
    }

    // Campos monetários — bruto + formatado logados em par por campo
    $price_fields   = Vista_Price_Formatter::build_price_fields( $data );
    $price_api_map  = [
        'ValorVenda'      => 'valor_venda',
        'ValorLocacao'    => 'valor_locacao',
        'ValorIptu'       => 'valor_iptu',
        'ValorCondominio' => 'valor_condominio',
    ];
    foreach ( $price_api_map as $api_key => $wp_key ) {
        $raw = $price_fields[ $wp_key ]                ?? '';
        $fmt = $price_fields[ $wp_key . '_formatado' ] ?? '';

        if ( $raw === '' ) {
            $log[] = sprintf(
                "[PREÇO] API:'%s' → WP:'%s' = \"\" | WP:'%s_formatado' = \"\" | - VAZIO (ignorado)",
                $api_key, $wp_key, $wp_key
            );
            $counters['empty'] += 2;
            continue;
        }

        update_post_meta( $post_id, $wp_key, $raw );
        update_post_meta( $post_id, $wp_key . '_formatado', $fmt );
        $log[] = sprintf(
            "[PREÇO] API:'%s' → WP:'%s' = \"%s\" | WP:'%s_formatado' = \"%s\" | OK SALVO",
            $api_key, $wp_key, $raw, $wp_key, $fmt
        );
        $counters['saved'] += 2;
    }

    // Mapa "latitude,longitude"
    if ( ! empty( $data['Latitude'] ) && ! empty( $data['Longitude'] ) ) {
        $mapa_val = $data['Latitude'] . ',' . $data['Longitude'];
        update_post_meta( $post_id, 'mapa', $mapa_val );
        $log[] = "[CAMPO] WP:'mapa' | Valor: \"{$mapa_val}\" | OK SALVO";
        $counters['saved']++;
    } else {
        $log[] = "[CAMPO] WP:'mapa' | Valor: \"\" | - VAZIO (ignorado)";
        $counters['empty']++;
    }

    // Características, Infraestrutura, Imediações
    // Imediacoes pode ser string de texto livre OU array de Sim/Não.
    $feature_map = [
        'caracteristicas' => 'Caracteristicas',
        'infraestrutura'  => 'InfraEstrutura',
        'imediacoes'      => 'Imediacoes',
    ];
    foreach ( $feature_map as $meta_key => $api_key ) {
        $group = $data[ $api_key ] ?? null;

        // String de texto livre (ex: "Próximo ao metrô, shopping, escolas")
        if ( is_string( $group ) && trim( $group ) !== '' ) {
            update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $group ) );
            $log[] = "[FEATURE] '{$api_key}' → texto livre salvo: \"" . mb_substr( $group, 0, 60 ) . "...\"";
            $counters['saved']++;
            continue;
        }

        if ( empty( $group ) || ! is_array( $group ) ) {
            $log[] = "[FEATURE] '{$api_key}': vazio/ignorado.";
            $counters['empty']++;
            continue;
        }

        // Array associativo {Nome: "Sim"/"Não"}
        $positive = array_keys( $group, 'Sim' );
        $total    = count( $group );
        $pos      = count( $positive );

        $log[] = sprintf( "[FEATURE] '%s' → %d itens | %d marcados Sim", $api_key, $total, $pos );
        if ( $pos > 0 ) {
            $log[] = "[FEATURE] '{$api_key}' positivos: " . implode( ', ', $positive );
            update_post_meta( $post_id, $meta_key, implode( ', ', $positive ) );
            update_post_meta( $post_id, "_{$meta_key}_raw", $group );
            $counters['saved']++;
        } else {
            // CRM retornou o campo mas sem nenhum Sim → limpa meta para espelhar o CRM.
            delete_post_meta( $post_id, $meta_key );
            delete_post_meta( $post_id, "_{$meta_key}_raw" );
            $log[] = "[FEATURE] '{$api_key}' → nenhum Sim: meta removida.";
            $counters['saved']++;
        }
    }

    // Catch-all: salva qualquer campo escalar que veio da API mas não está
    // no $map acima, evitando perder dados de campos futuros do CRM.
    $already_mapped = array_flip( array_values( $map ) );
    $skip_keys = [ 'Foto', 'Fotos', 'Imagens', 'Caracteristicas', 'InfraEstrutura', 'Imediacoes',
                   'TituloSite', 'Titulo', 'DescricaoWeb', 'Descricao' ];
    foreach ( $data as $api_key => $value ) {
        if ( isset( $already_mapped[ $api_key ] ) ) continue;
        if ( in_array( $api_key, $skip_keys, true ) ) continue;
        if ( ! is_scalar( $value ) || $value === '' || $value === null ) continue;
        $meta_key = '_vista_' . strtolower( preg_replace( '/[^a-zA-Z0-9]/', '_', $api_key ) );
        update_post_meta( $post_id, $meta_key, sanitize_text_field( (string) $value ) );
        $log[] = "[EXTRA] API:'{$api_key}' → WP:'{$meta_key}' = \"{$value}\" | OK SALVO";
        $counters['saved']++;
    }
}

/**
 * Sincroniza as taxonomias de controle com os campos do CRM:
 *   visibilidade_imovel  → ExibirNoSite (visivel / oculto)
 *   destaque_imovel      → Lancamento, DestaqueWeb, SuperDestaque, Exclusivo
 *   imediacoes_imovel    → Imediacoes (termos dinâmicos)
 */
function vit_sync_property_taxonomies( $post_id, $data, &$log ) {
    // ── visibilidade_imovel ──────────────────────────────────────────────
    $exibir = strtolower( trim( (string) ( $data['ExibirNoSite'] ?? '' ) ) );
    if ( $exibir === 'sim' ) {
        wp_set_object_terms( $post_id, 'visivel', 'visibilidade_imovel' );
        $log[] = '[TAX] visibilidade_imovel → visivel';
    } elseif ( $exibir !== '' ) {
        wp_set_object_terms( $post_id, 'oculto', 'visibilidade_imovel' );
        $log[] = '[TAX] visibilidade_imovel → oculto';
    }

    // ── destaque_imovel ──────────────────────────────────────────────────
    $flag_map = [
        'lancamento'     => 'Lancamento',
        'destaque_web'   => 'DestaqueWeb',
        'super_destaque' => 'SuperDestaque',
        'exclusivo'      => 'Exclusivo',
    ];
    $destaque_terms = [];
    foreach ( $flag_map as $slug => $api_key ) {
        $val = strtolower( trim( (string) ( $data[ $api_key ] ?? '' ) ) );
        if ( $val === 'sim' ) {
            $destaque_terms[] = $slug;
        }
    }
    wp_set_object_terms( $post_id, $destaque_terms, 'destaque_imovel' );
    $log[] = '[TAX] destaque_imovel → ' . ( $destaque_terms ? implode( ', ', $destaque_terms ) : '(nenhum)' );

    // ── imediacoes_imovel ────────────────────────────────────────────────
    $imed = $data['Imediacoes'] ?? null;
    if ( is_array( $imed ) ) {
        $imed_terms = array_keys( array_filter( $imed, fn( $v ) => strtolower( trim( (string) $v ) ) === 'sim' ) );
    } elseif ( is_string( $imed ) && trim( $imed ) !== '' ) {
        $imed_terms = array_values( array_filter( array_map( 'trim', explode( ',', $imed ) ) ) );
    } else {
        $imed_terms = [];
    }
    wp_set_object_terms( $post_id, $imed_terms, 'imediacoes_imovel' );
    $log[] = '[TAX] imediacoes_imovel → ' . ( $imed_terms ? implode( ', ', $imed_terms ) : '(nenhuma)' );
}

/**
 * Baixa todas as imagens do bloco Foto, define thumbnail e salva galeria em 4 metas.
 */
function vit_process_property_images( $post_id, $data, &$log, &$counters ) {
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $photos = $data['Foto'] ?? [];
    if ( empty( $photos ) || ! is_array( $photos ) ) {
        $log[] = 'Nenhuma imagem no bloco Foto.';
        return;
    }

    // Normaliza: se for objeto associativo com chaves numéricas-string, transforma em lista
    $photos = array_values( $photos );
    $counters['found'] = count( $photos );
    $log[] = 'Imagens encontradas na API: ' . $counters['found'];

    if ( isset( $photos[0]['Ordem'] ) ) {
        usort( $photos, fn( $a, $b ) => (int) ( $a['Ordem'] ?? 0 ) <=> (int) ( $b['Ordem'] ?? 0 ) );
    }

    $gallery_ids      = [];
    $featured_id      = 0;
    $total            = count( $photos );

    // Mapa URL → attachment ID para imagens já na galeria.
    // Evita qualquer HTTP ao CDN para fotos já importadas (refresh seguro).
    $already_imported = [];
    $cur_gallery = get_post_meta( $post_id, 'galeria', true );
    if ( is_array( $cur_gallery ) ) {
        foreach ( $cur_gallery as $att_id ) {
            $origin = get_post_meta( (int) $att_id, '_vista_image_origin_url', true );
            if ( $origin ) {
                $already_imported[ $origin ] = (int) $att_id;
            }
        }
    }

    foreach ( $photos as $i => $photo ) {
        $idx  = $i + 1;
        $url  = null;
        $used_field = null;
        // Ordem de preferência: tamanho grande primeiro, depois pequeno
        foreach ( [ 'Foto', 'FotoGrande', 'URL', 'URLFoto', 'Link', 'FotoPequena', 'URLFotoPequena' ] as $field ) {
            if ( ! empty( $photo[ $field ] ) && filter_var( $photo[ $field ], FILTER_VALIDATE_URL ) ) {
                $url = $photo[ $field ];
                $used_field = $field;
                break;
            }
        }
        $destaque = ( ! empty( $photo['Destaque'] ) && strtolower( $photo['Destaque'] ) === 'sim' );

        if ( empty( $url ) ) {
            $keys_found = is_array( $photo ) ? implode( ', ', array_keys( $photo ) ) : '(não-array)';
            $log[] = sprintf( "[IMAGEM %d/%d] URL ausente. Chaves disponíveis: %s. PULADA.", $idx, $total, $keys_found );
            $counters['failed']++;
            continue;
        }

        $log[] = sprintf( "[IMAGEM %d/%d] URL: %s | Campo usado: '%s' | Destaque: %s", $idx, $total, $url, $used_field, $destaque ? 'Sim' : 'Não' );

        // Skip sem HTTP: imagem já importada — reutiliza o attachment existente
        if ( isset( $already_imported[ $url ] ) ) {
            $attachment_id = $already_imported[ $url ];
            $log[] = sprintf( "[IMAGEM %d/%d] Já importada (ID:%d). Reusando.", $idx, $total, $attachment_id );
            $gallery_ids[] = $attachment_id;
            if ( $destaque && ! $featured_id ) {
                $featured_id = $attachment_id;
            }
            continue;
        }

        $attachment_id = vit_sideload_image( $url, $post_id, get_the_title( $post_id ) );
        if ( is_wp_error( $attachment_id ) ) {
            $log[] = sprintf( "[IMAGEM %d/%d] Download: FALHOU | Motivo: %s", $idx, $total, $attachment_id->get_error_message() );
            $counters['failed']++;
            continue;
        }

        $gallery_ids[] = $attachment_id;
        update_post_meta( $attachment_id, '_vista_image_origin_url', esc_url_raw( $url ) );
        $log[] = sprintf( "[IMAGEM %d/%d] Download: SUCESSO | Attachment ID: %d", $idx, $total, $attachment_id );
        $counters['imported']++;

        if ( $destaque && ! $featured_id ) {
            $featured_id = $attachment_id;
            $log[] = sprintf( "[IMAGEM %d/%d] -> Marcada como THUMBNAIL (Destaque=Sim)", $idx, $total );
        }
    }

    // Se não houve destaque explícito, usa a primeira importada
    if ( ! $featured_id && ! empty( $gallery_ids ) ) {
        $featured_id = $gallery_ids[0];
        $log[] = sprintf( "[THUMBNAIL] Nenhuma imagem marcada como Destaque=Sim. Usando a primeira (ID %d).", $featured_id );
    }

    if ( $featured_id ) {
        set_post_thumbnail( $post_id, $featured_id );
        $counters['thumbnail_set'] = true;
        $counters['thumbnail_id']  = $featured_id;
    }

    if ( ! empty( $gallery_ids ) ) {
        $csv = implode( ',', $gallery_ids );
        // galeria -> array de IDs (para plugins que leem array)
        update_post_meta( $post_id, 'galeria', $gallery_ids );
        // galeria_ids e galeria_imagens -> CSV
        update_post_meta( $post_id, 'galeria_ids', $csv );
        update_post_meta( $post_id, 'galeria_imagens', $csv );
        // _vista_gallery_ids -> retrocompatibilidade
        update_post_meta( $post_id, '_vista_gallery_ids', $csv );

        $log[] = "[GALERIA] Salvo em 4 metas: galeria (array), galeria_ids (CSV), galeria_imagens (CSV), _vista_gallery_ids (CSV).";
        $log[] = '[GALERIA] IDs: ' . $csv;
    }
}

/**
 * Baixa 1 imagem e cria o attachment no WP, evitando duplicatas pela URL de origem.
 *
 * Usa wp_remote_get() direto em vez de download_url() porque este último
 * aplica wp_http_validate_url() (via wp_safe_remote_get), que pode recusar
 * URLs de CDN externos e retorna "Invalid URL Provided".
 */
function vit_sideload_image( $file_url, $post_id, $desc ) {
    // Evita duplicata
    $existing = new WP_Query( [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'meta_query'     => [ [ 'key' => '_vista_image_origin_url', 'value' => esc_url_raw( $file_url ) ] ],
        'posts_per_page' => 1,
        'fields'         => 'ids',
    ] );
    if ( $existing->have_posts() ) {
        return (int) $existing->posts[0];
    }

    // Baixa via wp_remote_get (sem validação anti-SSRF que bloqueia CDNs)
    $response = wp_remote_get( $file_url, [
        'timeout'    => 60,
        'sslverify'  => false,
        'user-agent' => 'Mozilla/5.0 WordPress/vit-importer',
    ] );
    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        return new WP_Error( 'http_' . $http_code, "HTTP {$http_code} ao baixar imagem" );
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        return new WP_Error( 'empty_body', 'Resposta vazia ao baixar imagem' );
    }

    // Escreve em arquivo temporário
    $tmp = wp_tempnam( $file_url );
    if ( ! $tmp ) {
        return new WP_Error( 'tmp_failed', 'Falha ao criar arquivo temporário' );
    }
    if ( file_put_contents( $tmp, $body ) === false ) {
        @unlink( $tmp );
        return new WP_Error( 'write_failed', 'Falha ao escrever arquivo temporário' );
    }

    preg_match( '/[^\?]+\.(jpg|jpe|jpeg|gif|png|webp)/i', $file_url, $matches );
    $filename = ! empty( $matches[0] ) ? basename( $matches[0] ) : basename( parse_url( $file_url, PHP_URL_PATH ) ?: 'imovel.jpg' );

    $file_array = [ 'tmp_name' => $tmp, 'name' => $filename ?: 'imovel.jpg' ];

    $id = media_handle_sideload( $file_array, $post_id, $desc );
    if ( is_wp_error( $id ) ) {
        @unlink( $file_array['tmp_name'] );
        return $id;
    }
    return (int) $id;
}

/**
 * Testa a conectividade com o servidor da API passo a passo.
 * Retorna array com log detalhado de cada etapa.
 */
function vit_test_connection( $api_url, $api_key ) {
    $log    = [];
    $log[]  = '================ TESTE DE CONEXÃO ================';
    $log[]  = 'Data/hora : ' . current_time( 'mysql' );
    $log[]  = 'API URL   : ' . $api_url;
    $log[]  = '';

    $parsed = wp_parse_url( $api_url );
    $host   = $parsed['host'] ?? '';
    $scheme = $parsed['scheme'] ?? 'https';
    $port   = $parsed['port'] ?? ( $scheme === 'https' ? 443 : 80 );

    if ( empty( $host ) ) {
        $log[] = '[ERRO] URL inválida — não foi possível extrair o host.';
        return [ 'status' => 'error', 'log' => $log ];
    }

    // --- Etapa 1: Resolução DNS ---
    $log[] = '--- Etapa 1: Resolução DNS ---';
    $ip = gethostbyname( $host );
    if ( $ip === $host ) {
        $log[] = '[FALHA] DNS: não foi possível resolver "' . $host . '" em um endereço IP.';
        $log[] = '        Causa provável: problema de DNS no servidor WordPress ou hostname incorreto.';
        return [ 'status' => 'error', 'log' => $log ];
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

        // --- Etapa 2b: Tentativas alternativas ---
        $log[] = '';
        $log[] = '--- Etapa 2b: Tentativas alternativas de conexão ---';

        $alternatives = [
            [ 'label' => 'HTTP porta 80',         'prefix' => '',       'sock_host' => $host, 'port' => 80,   'scheme' => 'http',  'url_host' => $host ],
            [ 'label' => 'IP direto porta 443',   'prefix' => 'ssl://', 'sock_host' => $ip,   'port' => 443,  'scheme' => 'https', 'url_host' => $ip   ],
            [ 'label' => 'IP direto porta 80',    'prefix' => '',       'sock_host' => $ip,   'port' => 80,   'scheme' => 'http',  'url_host' => $ip   ],
            [ 'label' => 'Porta 8443 (SSL alt)',  'prefix' => 'ssl://', 'sock_host' => $host, 'port' => 8443, 'scheme' => 'https', 'url_host' => $host ],
            [ 'label' => 'Porta 8080 (HTTP alt)', 'prefix' => '',       'sock_host' => $host, 'port' => 8080, 'scheme' => 'http',  'url_host' => $host ],
        ];

        $working_urls = [];
        foreach ( $alternatives as $alt ) {
            $ae = 0; $as = '';
            $s  = @fsockopen( $alt['prefix'] . $alt['sock_host'], $alt['port'], $ae, $as, 8 );
            if ( $s ) {
                fclose( $s );
                $url = $alt['scheme'] . '://' . $alt['url_host'];
                if ( ! in_array( $alt['port'], [ 80, 443 ], true ) ) {
                    $url .= ':' . $alt['port'];
                }
                $log[]          = '[OK] ' . $alt['label'] . ': conectou! → Use esta URL: ' . $url;
                $working_urls[] = $url;
            } else {
                $log[] = '[FALHA] ' . $alt['label'] . ': ' . $as . ' (código ' . $ae . ')';
            }
        }

        $log[] = '';
        if ( ! empty( $working_urls ) ) {
            $log[] = '>> Alternativa(s) encontrada(s). Atualize o campo "API URL" com uma das URLs acima e tente importar novamente.';
            return [ 'status' => 'warning', 'log' => $log ];
        }

        $log[] = '>> Nenhuma alternativa funcionou. Contate sua hospedagem para liberar saída na porta 443.';
        return [ 'status' => 'error', 'log' => $log ];
    }
    fclose( $sock );
    $log[] = '[OK] TCP: conexão estabelecida com ' . $host . ':' . $port;

    // --- Etapa 3: Requisição HTTPS ---
    $log[] = '';
    $log[] = '--- Etapa 3: Requisição HTTPS (GET /) ---';
    @ini_set( 'default_socket_timeout', 35 );
    $resp = wp_remote_get( rtrim( $api_url, '/' ) . '/', [
        'timeout'         => 15,
        'connect_timeout' => 10,
        'sslverify'       => false,
        'headers'         => [ 'Accept' => 'application/json' ],
    ] );
    if ( is_wp_error( $resp ) ) {
        $log[] = '[FALHA] HTTPS: ' . $resp->get_error_message();
        $log[] = '        Causa provável: problema de certificado SSL ou servidor recusando a requisição.';
        return [ 'status' => 'error', 'log' => $log ];
    }
    $http = wp_remote_retrieve_response_code( $resp );
    $log[] = '[OK] HTTPS: servidor respondeu com HTTP ' . $http;

    // --- Etapa 4: Teste de autenticação (listar com key) ---
    if ( ! empty( $api_key ) ) {
        $log[] = '';
        $log[] = '--- Etapa 4: Teste de autenticação com API Key ---';
        $test_url = add_query_arg( [
            'key'      => $api_key,
            'pesquisa' => wp_json_encode( [ 'fields' => [ 'Codigo' ], 'paginacao' => [ 'pagina' => 1, 'quantidade' => 1 ] ] ),
        ], rtrim( $api_url, '/' ) . '/imoveis/listar' );

        @ini_set( 'default_socket_timeout', 35 );
        $auth_resp = wp_remote_get( $test_url, [
            'timeout'         => 20,
            'connect_timeout' => 10,
            'sslverify'       => false,
            'headers'         => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $auth_resp ) ) {
            $log[] = '[FALHA] Auth: ' . $auth_resp->get_error_message();
            return [ 'status' => 'error', 'log' => $log ];
        }
        $auth_http = wp_remote_retrieve_response_code( $auth_resp );
        $auth_body = wp_remote_retrieve_body( $auth_resp );
        $auth_dec  = json_decode( $auth_body, true );

        if ( $auth_http === 200 ) {
            $count = is_array( $auth_dec ) ? count( array_filter( array_keys( $auth_dec ), 'is_numeric' ) ) : '?';
            $log[] = '[OK] Auth: HTTP 200 — API Key válida. Registros retornados: ' . $count;
        } elseif ( $auth_http === 401 || $auth_http === 403 ) {
            $log[] = '[FALHA] Auth: HTTP ' . $auth_http . ' — API Key inválida ou sem permissão.';
            return [ 'status' => 'error', 'log' => $log ];
        } else {
            $msg = ( is_array( $auth_dec ) && isset( $auth_dec['message'] ) ) ? $auth_dec['message'] : substr( $auth_body, 0, 200 );
            $log[] = '[AVISO] Auth: HTTP ' . $auth_http . ' — ' . $msg;
        }
    }

    $log[] = '';
    $log[] = '================== RESULTADO ==================';
    $log[] = 'Conexão com "' . $host . '" está OK!';
    $log[] = 'Se a importação ainda falhar, verifique a API Key e os parâmetros de busca.';

    return [ 'status' => 'success', 'log' => $log ];
}

/**
 * Handler do botão "Testar Conexão".
 */
function vit_handle_test_connection() {
    if ( ! isset( $_POST['vit_test_nonce_field'] ) || ! wp_verify_nonce( $_POST['vit_test_nonce_field'], 'vit_test_nonce_action' ) ) {
        wp_die( 'Falha na verificação de segurança (nonce).' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Você não tem permissão para executar esta ação.' );
    }

    $api_url = esc_url_raw( $_POST['vit_api_url'] ?? '' );
    $api_key = sanitize_text_field( $_POST['vit_api_key'] ?? '' );

    update_option( 'vit_api_url', $api_url );
    update_option( 'vit_api_key', $api_key );

    $result = vit_test_connection( $api_url, $api_key );
    set_transient( 'vit_test_report', $result, 120 );
    wp_redirect( admin_url( 'admin.php?page=vista-imovel-teste' ) );
    exit;
}
