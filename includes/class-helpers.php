<?php

/**
 * Funciones auxiliares compartidas por todos los módulos.
 *
 * @package OpenAlexTeam
 */

if (! defined('ABSPATH')) exit;

class OpenAlex_Helpers
{

    public static function teachpress_active(): bool
    {
        return class_exists('TP_Publications') && class_exists('TP_Authors');
    }

    public static function map_pub_type(string $type): string
    {
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
        return $map[$type] ?? 'misc';
    }

    public static function get_type_label(string $type): string
    {
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
        return $labels[$type] ?? ucfirst($type);
    }

    public static function format_author_name(string $display_name): string
    {
        $parts = preg_split('/\s+/', trim($display_name));
        if (count($parts) < 2) return $display_name;
        $last = array_pop($parts);
        return $last . ', ' . implode(' ', $parts);
    }

    public static function get_sort_name(string $display_name): string
    {
        $parts = preg_split('/\s+/', trim($display_name));
        return array_pop($parts) ?? $display_name;
    }

    public static function build_author_string(array $authorships): string
    {
        $names = [];
        foreach ($authorships as $a) {
            $name = $a['author']['display_name'] ?? '';
            if ($name) $names[] = self::format_author_name($name);
        }
        return implode(' and ', $names);
    }

    public static function reconstruct_abstract(array $inverted_index): string
    {
        $positions = [];
        foreach ($inverted_index as $word => $pos_list) {
            foreach ($pos_list as $pos) $positions[$pos] = $word;
        }
        ksort($positions);
        return implode(' ', $positions);
    }

    public static function generate_bibtex_key(string $author_string, int $year, string $title): string
    {
        $first_author = preg_replace('/[^a-zA-Z]/', '', strtok($author_string, ',') ?: 'unknown');
        $stopwords    = ['a', 'an', 'the', 'of', 'in', 'on', 'at', 'to', 'and', 'or'];
        $first_word   = '';
        foreach (preg_split('/\s+/', $title) as $w) {
            $clean = preg_replace('/[^a-zA-Z]/', '', strtolower($w));
            if ($clean && ! in_array($clean, $stopwords, true)) {
                $first_word = ucfirst($clean);
                break;
            }
        }
        return strtolower($first_author) . ($year ?: 'nd') . $first_word;
    }

    /**
     * @param string                    $author_string
     * @param bool                      $link_team_members
     * @param array<string,string>|null $name_to_id_map    nombre_normalizado => openalex_author_id
     * @param array<string,string>|null $members_map       openalex_id => permalink
     * @param int                       $current_post_id   Post ID del miembro cuya página se está viendo.
     *                                                     Su nombre no se enlaza. 0 = sin exclusión.
     */
    public static function format_author_list(
        string $author_string,
        bool $link_team_members = true,
        ?array $name_to_id_map = null,
        ?array $members_map = null,
        int $current_post_id = 0
    ): string {
        $names = explode(' and ', $author_string);
        $short = [];

        if ($link_team_members && $members_map === null) {
            $members_map = self::get_team_members_map();
        }

        // NUEVO: Obtener TODOS los IDs del miembro actual
        $current_openalex_ids = [];
        if ($current_post_id > 0) {
            $raw = trim(get_post_meta($current_post_id, 'openalex_id', true));
            if ($raw) {
                $ids = array_map('trim', explode('|', $raw));
                foreach ($ids as $id) {
                    $current_openalex_ids[] = strtoupper(basename($id));
                }
            }
        }

        OpenAlex_Helpers::log("format_author_list() called for author_string: '{$author_string}' |".
            " current_openalex_ids: " . implode(', ', $current_openalex_ids) );    

        foreach (array_slice($names, 0, 5) as $name) {
            OpenAlex_Helpers::log("name: " . $name);
            $parts    = explode(',', $name, 2);
            $last     = trim($parts[0]);
            $first    = isset($parts[1]) ? trim($parts[1]) : '';
            $initials = '';

            if ($first) {
                foreach (preg_split('/\s+/', $first) as $part) {
                    if ($part) $initials .= mb_strtoupper(mb_substr($part, 0, 1)) . '.';
                }
            }

            $display = $last . ($initials ? ' ' . $initials : '');
            $linked  = false;

            if ($link_team_members && $name_to_id_map !== null && $members_map !== null) {
                $normalized      = strtolower(trim($name));
                $openalex_author = $name_to_id_map[$normalized] ?? null;

                if ($openalex_author) {
                    // El autor del paper puede tener múltiples IDs (ej: "A123|A456")
                    $author_ids = array_map('trim', explode('|', strtoupper($openalex_author)));
                    
                    // Verificar si alguno de sus IDs coincide con los del miembro actual
                    $is_current_member = false;
                    foreach ($author_ids as $aid) {
                        if (in_array($aid, $current_openalex_ids, true)) {
                            $is_current_member = true;
                            break;
                        }
                    }                   
                        
                    if (! $is_current_member) {
                        OpenAlex_Helpers::log("openalex_author: " . $openalex_author);
                        OpenAlex_Helpers::log("author_ids: " . print_r($author_ids, true));
                        // Buscar el primer ID que exista en el mapa de miembros
                        foreach ($author_ids as $aid) {
                            OpenAlex_Helpers::log("aid: " . $aid);
                            // OpenAlex_Helpers::log("members_map: " . print_r($members_map, true));
                            if (isset($members_map[$aid])) {
                                $url = $members_map[$aid];
                                $display = '<a href="' . esc_url($url) . '">' . esc_html($display) . '</a>';
                                $linked = true;
                                OpenAlex_Helpers::log("linked");
                                break;
                            } else {OpenAlex_Helpers::log("not linked");}
                        }
                    }
                    else {
                        OpenAlex_Helpers::log("is current");
                    }
                }

            }

            if (! $linked) {
                $display = esc_html($display);
            }

            $short[] = $display;
        }

        $result = implode(', ', $short);
        if (count($names) > 5) $result .= ' et al.';

        return $result;
    }

    public static function is_publication_hidden(int $pub_id): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'teachpress_pub_meta';

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value
             FROM {$table}
             WHERE pub_id = %d
               AND meta_key = 'openalex_hidden'
             ORDER BY meta_id DESC
             LIMIT 1",
            $pub_id
        ));

        return (string) $value === '1';
    }

    public static function set_publication_hidden(int $pub_id, bool $hidden): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'teachpress_pub_meta';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id
             FROM {$table}
             WHERE pub_id = %d
               AND meta_key = 'openalex_hidden'
             LIMIT 1",
            $pub_id
        ));

        if ($existing) {
            $wpdb->update(
                $table,
                ['meta_value' => $hidden ? '1' : '0'],
                ['meta_id' => $existing],
                ['%s'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'pub_id'     => $pub_id,
                    'meta_key'   => 'openalex_hidden',
                    'meta_value' => $hidden ? '1' : '0',
                ],
                ['%d', '%s', '%s']
            );
        }
    }

    /**
     * Obtiene publicaciones asociadas a un miembro.
     *
     * @param int  $post_id
     * @param bool $only_visible Si true, excluye las marcadas como ocultas.
     */
    public static function get_member_publications(int $post_id, bool $only_visible = true): array
    {
        global $wpdb;

        $suffix        = $only_visible ? 'visible' : 'all';
        $transient_key = 'openalex_member_pubs_' . $post_id . '_' . $suffix;

        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

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

        if ($only_visible) {
            $sql .= " AND (h.meta_value IS NULL OR h.meta_value != '1')";
        }

        $sql .= " ORDER BY p.date DESC";

        $results = $wpdb->get_results($wpdb->prepare($sql, $post_id));

        set_transient($transient_key, $results, 12 * HOUR_IN_SECONDS);

        return $results;
    }

    public static function clear_member_publications_cache(int $post_id): void
    {
        delete_transient('openalex_member_pubs_' . $post_id . '_visible');
        delete_transient('openalex_member_pubs_' . $post_id . '_all');
        delete_transient('openalex_member_pubs_html_' . $post_id);
    }

    /**
     * Devuelve un mapa openalex_id_limpio => permalink de todos los miembros del team.
     * Cacheado en memoria durante la request.
     *
     * @return array<string, string>
     */
    public static function get_team_members_map(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map   = [];
        $posts = get_posts([
            'post_type'   => 'team',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [[
                'key'     => 'openalex_id',
                'value'   => '',
                'compare' => '!=',
            ]],
        ]);

        foreach ($posts as $post) {
            $raw_id = trim(get_post_meta($post->ID, 'openalex_id', true));
            if (! $raw_id) continue;

            // NUEVO: Dividir por pipe y procesar cada ID
            $ids = array_map('trim', explode('|', $raw_id));
            $permalink = get_permalink($post->ID);
        
            foreach ($ids as $single_id) {
                if (! $single_id) continue;
                $clean_id = strtoupper(basename($single_id));
                if ($clean_id) {
                    $map[$clean_id] = $permalink;  // Todos los IDs apuntan al mismo miembro
                }
            }
        }
		OpenAlex_Helpers::log("get_team_members_map() loaded " .  print_r( $map, true ) . " members.");

        return $map;
    }

    /**
     * Para una publicación, devuelve mapa teachpress_author_id => openalex_author_id.
     *
     * @return array<int, string>
     */
    public static function get_pub_author_openalex_ids(int $pub_id): array
    {
        global $wpdb;

        $meta_table    = $wpdb->prefix . 'teachpress_pub_meta';
        $authors_table = $wpdb->prefix . 'teachpress_authors';
        $rel_table     = $wpdb->prefix . 'teachpress_rel_pub_auth';

        // Obtener author_ids de esta publicación
        $author_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT author_id FROM {$rel_table} WHERE pub_id = %d",
            $pub_id
        ));

        if (empty($author_ids)) return [];

        $map = [];
        foreach ($author_ids as $author_id) {
            $meta_key       = 'openalex_author_id_' . intval($author_id);
            $openalex_value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$meta_table}
             WHERE pub_id = %d AND meta_key = %s LIMIT 1",
                $pub_id,
                $meta_key
            ));

            if ($openalex_value) {
                $map[intval($author_id)] = strtoupper($openalex_value);
            }
        }
        
        OpenAlex_Helpers::log("get_pub_author_openalex_ids() for pub_id {$pub_id} returned map: " . print_r($map, true));

        return $map;
    }

    /**
     * Para una publicación, devuelve mapa nombre_formateado => openalex_author_id.
     * Se usa en format_author_list() para enlazar por ID en vez de por apellido.
     *
     * @return array<string, string>
     */
    public static function get_pub_name_to_openalex_id(int $pub_id): array
    {
        global $wpdb;

        $meta_table    = $wpdb->prefix . 'teachpress_pub_meta';
        $authors_table = $wpdb->prefix . 'teachpress_authors';
        $rel_table     = $wpdb->prefix . 'teachpress_rel_pub_auth';

        // 1. Obtener todos los autores asociados a esta publicación
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.author_id, a.name
         FROM {$rel_table} r
         INNER JOIN {$authors_table} a ON a.author_id = r.author_id
         WHERE r.pub_id = %d",
            $pub_id
        ));

        if (empty($rows)) {
            OpenAlex_Helpers::log("get_pub_name_to_openalex_id() for pub_id {$pub_id} found no authors.");
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            // 2. Buscar el meta guardado durante la importación
            $meta_key       = 'openalex_author_id_' . intval($row->author_id);
            $openalex_value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$meta_table}
                    WHERE pub_id = %d AND meta_key = %s LIMIT 1",
                $pub_id,
                $meta_key
            ));

            if ($openalex_value) {
                $raw_ids = array_map('trim', explode('|', $openalex_value));
                $clean_id = '';

                foreach ($raw_ids as $id) {
                    // Extraer solo la parte del ID (ej: de 'https://.../A123' a 'A123')
                    $potential_id = strtoupper(basename($id));                    
                    // 3. Si logramos extraer un ID limpio, lo agregamos al mapa
                    if (!empty($potential_id)) {
                        $clean_id = $potential_id;
                        break;
                    }
                }

                if ($clean_id) {
                    $normalized_name = strtolower(trim($row->name));
                    $map[$normalized_name] = $clean_id;
                }
            }
        }
        
        OpenAlex_Helpers::log("get_pub_name_to_openalex_id() for pub_id {$pub_id} returned map: " . print_r($map, true));

        return $map;
    }
    
    public static function log( string $message ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // Opcional: Agregar timestamp y prefijo automáticamente
            $formatted_message = sprintf(
                "[OpenAlex] %s",
                $message
            );
            error_log( $formatted_message );
        }
    }
}
