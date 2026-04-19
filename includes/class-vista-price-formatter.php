<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Formata campos monetários recebidos da API Vista CRM.
 *
 * Responsabilidade única: receber valores crus, normalizar e devolver
 * a versão bruta (limpa) e a versão formatada em BRL.
 *
 * Este arquivo NÃO chama API, NÃO cria/atualiza posts e NÃO salva metas.
 */
class Vista_Price_Formatter {

    /**
     * Campos da API Vista que representam valores monetários,
     * mapeados para suas respectivas meta_keys no WordPress.
     */
    private const PRICE_FIELDS = [
        'valor_venda'      => 'ValorVenda',
        'valor_locacao'    => 'ValorLocacao',
        'valor_iptu'       => 'ValorIptu',
        'valor_condominio' => 'ValorCondominio',
    ];

    /**
     * Limpa e normaliza um valor monetário bruto vindo da API.
     *
     * Suporta:
     *   "3.500.000,00"  → "3500000"   (BR com separador de milhar e decimal)
     *   "3.500.000"     → "3500000"   (BR só milhar, sem decimal)
     *   "3500000.00"    → "3500000"   (ponto como decimal)
     *   "12000.50"      → "12000.50"  (centavos preservados)
     *   "500,00"        → "500"       (vírgula como decimal BR)
     *   ""  / "0"       → ""          (vazio ou zero = sem valor)
     */
    public static function normalize_money_value( $value ): string {
        if ( $value === null || $value === '' || $value === false ) {
            return '';
        }

        // Remove símbolos de moeda, espaços e caracteres não numéricos (exceto . e ,)
        $str = preg_replace( '/[^\d.,]/', '', trim( (string) $value ) );

        if ( $str === '' ) {
            return '';
        }

        $has_dot   = str_contains( $str, '.' );
        $has_comma = str_contains( $str, ',' );

        if ( $has_dot && $has_comma ) {
            // Ambos presentes: o último separador indica o decimal
            if ( strrpos( $str, ',' ) > strrpos( $str, '.' ) ) {
                // BR: "3.500.000,00" — ponto=milhar, vírgula=decimal
                $str = str_replace( '.', '', $str );
                $str = str_replace( ',', '.', $str );
            } else {
                // US: "3,500,000.00" — vírgula=milhar, ponto=decimal
                $str = str_replace( ',', '', $str );
            }
        } elseif ( $has_comma ) {
            // Só vírgula: trata como decimal BR ("500,00" → "500.00")
            $str = str_replace( ',', '.', $str );
        } elseif ( $has_dot ) {
            // Só ponto: verifica se é separador de milhar ou decimal
            $parts = explode( '.', $str );
            // Mais de um ponto, ou parte final com 3 dígitos → separador de milhar
            if ( count( $parts ) > 2 || strlen( end( $parts ) ) === 3 ) {
                $str = str_replace( '.', '', $str );
            }
            // Caso contrário, ponto é decimal — mantém como está
        }

        $num = (float) $str;

        if ( $num <= 0 ) {
            return '';
        }

        // Devolve inteiro se não houver centavos significativos
        $int = (int) round( $num );
        if ( abs( $num - $int ) < 0.005 ) {
            return (string) $int;
        }

        return number_format( $num, 2, '.', '' );
    }

    /**
     * Formata um valor normalizado como moeda brasileira.
     *
     * normalize_money_value() deve ser chamado antes deste método.
     *
     * Exemplos:
     *   "3500000"  → "R$ 3.500.000,00"
     *   "12000.50" → "R$ 12.000,50"
     *   ""         → ""
     */
    public static function format_brl( string $value ): string {
        if ( $value === '' ) {
            return '';
        }

        $num = (float) $value;

        if ( $num <= 0 ) {
            return '';
        }

        return 'R$ ' . number_format( $num, 2, ',', '.' );
    }

    /**
     * Recebe o array bruto da API Vista e devolve os campos de preço
     * prontos para salvar como meta no WordPress.
     *
     * @param array $property Dados brutos do imóvel (vindos da API).
     * @return array [
     *   'valor_venda'           => '3500000',
     *   'valor_venda_formatado' => 'R$ 3.500.000,00',
     *   ...
     * ]
     */
    public static function build_price_fields( array $property ): array {
        $result = [];

        foreach ( self::PRICE_FIELDS as $wp_key => $api_key ) {
            $raw              = $property[ $api_key ] ?? '';
            $normalized       = self::normalize_money_value( $raw );
            $result[ $wp_key ]                    = $normalized;
            $result[ $wp_key . '_formatado' ] = self::format_brl( $normalized );
        }

        return $result;
    }
}
