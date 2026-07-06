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
            'article'       => __('Artículo', 'openalex-team'),
            'inbook'        => __('Capítulo', 'openalex-team'),
            'book'          => __('Libro', 'openalex-team'),
            'inproceedings' => __('Conferencia', 'openalex-team'),
            'phdthesis'     => __('Tesis', 'openalex-team'),
            'techreport'    => __('Informe', 'openalex-team'),
            'unpublished'   => __('Preprint', 'openalex-team'),
            'misc'          => __('Misc', 'openalex-team'),
        ];
        return $labels[$type] ?? ucfirst($type);
    }

    /**
     * CSS compartido para listados de publicaciones
     * Usado por single-team page y bloques de Gutenberg
     */
    public static function get_publications_styles(): string
    {
        return '
.openalex-publications { margin-top:2.5em; padding-top:1.5em; border-top:2px solid #e0e0e0; font-size:.95em; line-height:1.55; }
.openalex-publications__title { font-size:1.4em; font-weight:700; margin-bottom:.75em; }
.openalex-publications__count { font-weight:400; font-size:.85em; color:#666; margin-left:.25em; }
.openalex-publications__year-group { margin-bottom:1.5em; }
.openalex-publications__year { font-size:1.05em; font-weight:600; color:#444; border-bottom:1px solid #e8e8e8; padding-bottom:.25em; margin-bottom:.6em; }
.openalex-publications__list { list-style:none; margin:0; padding:0; }
.openalex-publications__item { display:flex; flex-wrap:wrap; align-items:baseline; gap:.35em; padding:.55em 0; border-bottom:1px solid #f2f2f2; }
.openalex-publications__item:last-child { border-bottom:none; }
.openalex-pub-type { display:inline-block; font-size:.7em; font-weight:600; text-transform:uppercase; letter-spacing:.05em; padding:2px 7px; border-radius:3px; white-space:nowrap; background:#e8f0fe; color:#1a56c4; }
.openalex-pub-type--article { background:#e8f5e9; color:#2e7d32; }
.openalex-pub-type--inproceedings { background:#fff3e0; color:#e65100; }
.openalex-pub-type--book, .openalex-pub-type--inbook { background:#fce4ec; color:#880e4f; }
.openalex-pub-type--phdthesis { background:#ede7f6; color:#4527a0; }
.openalex-pub-type--misc, .openalex-pub-type--unpublished { background:#f5f5f5; color:#555; }
.openalex-pub-title { font-weight:600; flex:1 1 100%; }
.openalex-pub-title a { color:inherit; text-decoration:underline; text-underline-offset:2px; }
.openalex-pub-title a:hover { opacity:.75; }
.openalex-pub-authors { color:#555; font-size:.9em; flex:1 1 100%; }
.openalex-pub-journal { color:#666; font-size:.9em; }
.openalex-pub-doi { font-size:.8em; color:#888; text-decoration:none; }
.openalex-pub-doi:hover { text-decoration:underline; }
.openalex-pub-authors a { color: inherit; text-decoration: underline; text-underline-offset: 2px; opacity: 0.85; }
.openalex-pub-authors a:hover { opacity: 1; }
.openalex-selected-publications {padding-top:1.5em; border-top:2px solid #e0e0e0; font-size:.95em; line-height:1.55; }
        ';
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

        foreach (array_slice($names, 0, 5) as $name) {

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
                        // Buscar el primer ID que exista en el mapa de miembros
                        foreach ($author_ids as $aid) {
                            if (isset($members_map[$aid])) {
                                $url = $members_map[$aid];
                                $display = '<a href="' . esc_url($url) . '">' . esc_html($display) . '</a>';
                                $linked = true;
                                break;
                            }
                        }
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
     * Resuelve el ID del miembro en el idioma por defecto si Polylang está activo.
     *
     * @param int  $post_id
     * @param bool $only_visible Si true, excluye las marcadas como ocultas.
     */
    public static function resolve_member_post_id(int $post_id): int
    {
        if (! function_exists('pll_get_post')) {
            return $post_id;
        }

        $default_lang = function_exists('pll_default_language')
            ? pll_default_language()
            : 'es';

        $translated_id = pll_get_post($post_id, $default_lang);
        return $translated_id ? intval($translated_id) : $post_id;
    }

    public static function get_member_publications(int $post_id, bool $only_visible = true): array
    {
        global $wpdb;

        $post_id = self::resolve_member_post_id($post_id);
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
        $post_id = self::resolve_member_post_id($post_id);
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
