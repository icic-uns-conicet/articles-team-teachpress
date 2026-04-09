<?php
/**
 * Funciones auxiliares compartidas por todos los módulos.
 *
 * @package OpenAlexTeam
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAlex_Helpers {

    public static function teachpress_active(): bool {
        return class_exists( 'TP_Publications' ) && class_exists( 'TP_Authors' );
    }

    public static function map_pub_type( string $type ): string {
        $map = [
            'article'             => 'article',
            'journal-article'     => 'article',
            'book-chapter'        => 'inbook',
            'book'                => 'book',
            'edited-book'         => 'book',
            'proceedings-article' => 'inproceedings',
            'conference-paper'    => 'inproceedings',
            'review'              => 'article',
            'dissertation'        => 'phdthesis',
            'thesis'              => 'phdthesis',
            'preprint'            => 'unpublished',
            'report'              => 'techreport',
            'dataset'             => 'misc',
            'other'               => 'misc',
        ];
        return $map[ $type ] ?? 'misc';
    }

    public static function get_type_label( string $type ): string {
        $labels = [
            'article'       => 'Artículo',
            'inbook'        => 'Capítulo',
            'book'          => 'Libro',
            'inproceedings' => 'Conferencia',
            'phdthesis'     => 'Tesis',
            'techreport'    => 'Informe',
            'unpublished'   => 'Preprint',
            'misc'          => 'Misc',
        ];
        return $labels[ $type ] ?? ucfirst( $type );
    }

    public static function format_author_name( string $display_name ): string {
        $parts = preg_split( '/\s+/', trim( $display_name ) );
        if ( count( $parts ) < 2 ) return $display_name;
        $last = array_pop( $parts );
        return $last . ', ' . implode( ' ', $parts );
    }

    public static function get_sort_name( string $display_name ): string {
        $parts = preg_split( '/\s+/', trim( $display_name ) );
        return array_pop( $parts ) ?? $display_name;
    }

    public static function build_author_string( array $authorships ): string {
        $names = [];
        foreach ( $authorships as $a ) {
            $name = $a['author']['display_name'] ?? '';
            if ( $name ) $names[] = self::format_author_name( $name );
        }
        return implode( ' and ', $names );
    }

    public static function reconstruct_abstract( array $inverted_index ): string {
        $positions = [];
        foreach ( $inverted_index as $word => $pos_list ) {
            foreach ( $pos_list as $pos ) $positions[ $pos ] = $word;
        }
        ksort( $positions );
        return implode( ' ', $positions );
    }

    public static function generate_bibtex_key( string $author_string, int $year, string $title ): string {
        $first_author = preg_replace( '/[^a-zA-Z]/', '', strtok( $author_string, ',' ) ?: 'unknown' );
        $stopwords    = [ 'a', 'an', 'the', 'of', 'in', 'on', 'at', 'to', 'and', 'or' ];
        $first_word   = '';
        foreach ( preg_split( '/\s+/', $title ) as $w ) {
            $clean = preg_replace( '/[^a-zA-Z]/', '', strtolower( $w ) );
            if ( $clean && ! in_array( $clean, $stopwords, true ) ) {
                $first_word = ucfirst( $clean );
                break;
            }
        }
        return strtolower( $first_author ) . ( $year ?: 'nd' ) . $first_word;
    }

    public static function format_author_list( string $author_string ): string {
        $names = explode( ' and ', $author_string );
        $short = [];
        foreach ( array_slice( $names, 0, 5 ) as $name ) {
            $parts    = explode( ',', $name, 2 );
            $last     = trim( $parts[0] );
            $first    = isset( $parts[1] ) ? trim( $parts[1] ) : '';
            $initials = '';
            if ( $first ) {
                foreach ( preg_split( '/\s+/', $first ) as $part ) {
                    if ( $part ) $initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) ) . '.';
                }
            }
            $short[] = $last . ( $initials ? ' ' . $initials : '' );
        }
        $result = implode( ', ', $short );
        if ( count( $names ) > 5 ) $result .= ' et al.';
        return $result;
    }

    public static function is_publication_hidden( int $pub_id ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'teachpress_pub_meta';

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value
             FROM {$table}
             WHERE pub_id = %d
               AND meta_key = 'openalex_hidden'
             ORDER BY meta_id DESC
             LIMIT 1",
            $pub_id
        ) );

        return (string) $value === '1';
    }

    public static function set_publication_hidden( int $pub_id, bool $hidden ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'teachpress_pub_meta';

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id
             FROM {$table}
             WHERE pub_id = %d
               AND meta_key = 'openalex_hidden'
             LIMIT 1",
            $pub_id
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                [ 'meta_value' => $hidden ? '1' : '0' ],
                [ 'meta_id' => $existing ],
                [ '%s' ],
                [ '%d' ]
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'pub_id'     => $pub_id,
                    'meta_key'   => 'openalex_hidden',
                    'meta_value' => $hidden ? '1' : '0',
                ],
                [ '%d', '%s', '%s' ]
            );
        }
    }

    /**
     * Obtiene publicaciones asociadas a un miembro.
     *
     * @param int  $post_id
     * @param bool $only_visible Si true, excluye las marcadas como ocultas.
     */
    public static function get_member_publications( int $post_id, bool $only_visible = true ): array {
        global $wpdb;

        $sql = "
            SELECT p.pub_id, p.title, p.type, DATE_FORMAT(p.date,'%%Y') AS year,
                   p.doi, p.url, p.author, p.journal
            FROM {$wpdb->prefix}teachpress_pub p
            INNER JOIN {$wpdb->prefix}teachpress_pub_meta m
                ON m.pub_id = p.pub_id
            LEFT JOIN {$wpdb->prefix}teachpress_pub_meta h
                ON h.pub_id = p.pub_id
               AND h.meta_key = 'openalex_hidden'
            WHERE m.meta_key = 'openalex_member_id'
              AND m.meta_value = %s
        ";

        if ( $only_visible ) {
            $sql .= " AND (h.meta_value IS NULL OR h.meta_value != '1')";
        }

        $sql .= " ORDER BY p.date DESC";

        return $wpdb->get_results( $wpdb->prepare( $sql, $post_id ) );
    }
}