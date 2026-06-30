<?php
/**
 * Registro de bloques de Gutenberg para OpenAlex
 */

if (!defined('ABSPATH')) {
    exit;
}

class OpenAlex_Blocks {

    public function __construct() {
        add_action('init', [$this, 'register_blocks']);        
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_block_styles']);
    }

    /**
     * Registrar el bloque de Gutenberg
     */
    public function register_blocks(): void {
        register_block_type(__DIR__ . '/publications-selector', [
            'render_callback' => [$this, 'render_publications_selector']
        ]);
    }

    /**
     * Enqueue styles para el bloque
     */
    public function enqueue_block_styles(): void {
        wp_register_style('openalex-block-styles', false);
        wp_enqueue_style('openalex-block-styles');
        wp_add_inline_style('openalex-block-styles', OpenAlex_Helpers::get_publications_styles());
    }

    /**
     * Registrar endpoints REST API
     */
    public function register_rest_routes(): void {
        // Obtener lista de miembros
        register_rest_route('openalex/v1', '/members', [
            'methods' => 'GET',
            'callback' => [$this, 'get_members'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            }
        ]);

        // Buscar publicaciones de un miembro
        register_rest_route('openalex/v1', '/publications/(?P<member_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'search_publications'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'member_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'search' => [
                    'required' => false,
                    'sanitize_callback' => 'sanitize_text_field'
                ]
            ]
        ]);

        // NUEVO: Obtener publicaciones por IDs
        register_rest_route('openalex/v1', '/publications-by-ids', [
            'methods' => 'GET',
            'callback' => [$this, 'get_publications_by_ids'],
            'permission_callback' => '__return_true' // Público para el frontend
        ]);

        // NUEVO: Limpiar cache de publicaciones por IDs
        register_rest_route('openalex/v1', '/publications-cache/clear', [
            'methods' => 'POST',
            'callback' => [$this, 'clear_publications_cache'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'ids' => [ 'required' => true ]
            ]
        ]);
    }

    /**
     * Endpoint: Obtener miembros del equipo con OpenAlex ID
     */
    public function get_members(): array {
        $members = get_posts([
            'post_type' => 'team',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [[
                'key' => 'openalex_id',
                'value' => '',
                'compare' => '!='
            ]]
        ]);

        return array_map(function($member) {
            return [
                'id' => $member->ID,
                'title' => $member->post_title
            ];
        }, $members);
    }

    /**
     * Endpoint: Buscar publicaciones de un miembro
     */
    public function search_publications(WP_REST_Request $request): array {
        $member_id = (int) $request->get_param('member_id');
        $search = $request->get_param('search');

        if (!$member_id) {
            return new WP_Error('invalid_member', __('ID de miembro inválido', "openalex-team"), ['status' => 400]);
        }

        // Obtener todas las publicaciones del miembro
        $publications = OpenAlex_Helpers::get_member_publications($member_id, false);

        if (empty($publications)) {
            return [];
        }

        // Filtrar por búsqueda si existe
        if ($search && strlen($search) >= 2) {
            $search_lower = mb_strtolower($search);
            $publications = array_filter($publications, function($pub) use ($search_lower) {
                return mb_strpos(mb_strtolower($pub->title), $search_lower) !== false;
            });
        }

        // Limitar a 20 resultados para no sobrecargar
        $publications = array_slice($publications, 0, 20);

        return array_map(function($pub) {
            return [
                'pub_id' => (int) $pub->pub_id,
                'title' => $pub->title,
                'year' => $pub->year,
                'type' => $pub->type,
                'doi' => $pub->doi,
                'author' => $pub->author ?? ''
            ];
        }, $publications);
    }

    /**
     * NUEVO: Endpoint: Obtener publicaciones por IDs
     */
    public function get_publications_by_ids(WP_REST_Request $request): array {
        $ids_param = $request->get_param('ids');

        if (empty($ids_param)) {
            return [];
        }

        // Parsear IDs (pueden venir como string "123,456,789" o array)
        if (is_string($ids_param)) {
            $ids = array_map('intval', explode(',', $ids_param));
        } else {
            $ids = array_map('intval', (array) $ids_param);
        }

        $ids = array_filter($ids); // Eliminar ceros

        if (empty($ids)) {
            return [];
        }

        global $wpdb;
        $pub_table = $wpdb->prefix . 'teachpress_pub';

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $publications = $wpdb->get_results($wpdb->prepare(
            "SELECT pub_id, title, type, DATE_FORMAT(date,'%%Y') AS year, doi, author
             FROM {$pub_table}
             WHERE pub_id IN ({$placeholders})",
            ...$ids
        ));

        return array_map(function($pub) {
            return [
                'pub_id' => (int) $pub->pub_id,
                'title' => $pub->title,
                'year' => $pub->year,
                'type' => $pub->type,
                'doi' => $pub->doi,
                'author' => $pub->author ?? ''
            ];
        }, $publications);
    }

    /**
     * Clear cached rendered HTML for a set of publication IDs.
     * Expects 'ids' as string "1,2,3" or array.
     */
    public function clear_publications_cache(WP_REST_Request $request): WP_REST_Response {
        $ids_param = $request->get_param('ids');
        if (empty($ids_param)) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Sin IDs', "openalex-team")], 400);
        }

        if (is_string($ids_param)) {
            $ids = array_map('intval', explode(',', $ids_param));
        } else {
            $ids = array_map('intval', (array) $ids_param);
        }
        $ids = array_filter($ids);
        if (empty($ids)) {
            return new WP_REST_Response(['ok' => false, 'message' => __('Sin IDs válidos', "openalex-team")], 400);
        }

        sort($ids, SORT_NUMERIC);
        $cache_key = 'openalex_block_pubs_html_' . md5(implode(',', $ids));
        delete_transient($cache_key);

        return new WP_REST_Response(['ok' => true], 200);
    }

    /**
     * Renderizado del bloque en el frontend
     */
    public function render_publications_selector(array $attributes): string {
        $selected_ids = $attributes['selectedPublicationIds'] ?? [];

        if (empty($selected_ids)) {
            return '<p>' . esc_html__('No hay publicaciones seleccionadas.', 'openalex-team') . '</p>';
        }
        // Normalize and build a stable cache key (order-insensitive)
        $selected_ids = array_map('intval', (array) $selected_ids);
        $selected_ids = array_filter($selected_ids);
        if (empty($selected_ids)) {
            return '<p>' . esc_html__('Las publicaciones seleccionadas no están disponibles.', 'openalex-team') . '</p>';
        }
        sort($selected_ids, SORT_NUMERIC);
        $cache_key = 'openalex_block_pubs_html_' . md5(implode(',', $selected_ids));

        // Try transient (12 hours)
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $pub_table = $wpdb->prefix . 'teachpress_pub';

        // Obtener publicaciones por IDs
        $placeholders = implode(',', array_fill(0, count($selected_ids), '%d'));

        $publications = $wpdb->get_results($wpdb->prepare(
            "SELECT p.pub_id, p.title, p.type, DATE_FORMAT(p.date,'%%Y') AS year,
                    p.doi, p.url, p.author, p.journal
             FROM {$pub_table} p
             WHERE p.pub_id IN ({$placeholders})",
            ...$selected_ids
        ));

        if (empty($publications)) {
            return '<p>' . esc_html__('Las publicaciones seleccionadas no están disponibles.', 'openalex-team') . '</p>';
        }

        // Agrupar por año
        $grouped = [];
        foreach ($publications as $pub) {
            $year = $pub->year ?: esc_html__('Sin año', 'openalex-team');
            $grouped[$year][] = $pub;
        }
        krsort($grouped); // Ordenar por año descendente

        ob_start();
        ?>
        <div class="openalex-selected-publications">
        <h3 class="openalex-publications__title">
                <?php echo esc_html__('Publicaciones seleccionadas', 'openalex-team'); ?>
        </h3>
            <?php foreach ($grouped as $year => $pubs): ?>
                <div class="openalex-publications__year-group">
                    <h4 class="openalex-publications__year"><?php echo esc_html($year); ?></h4>
                    <ul class="openalex-publications__list">
                        <?php foreach ($pubs as $pub): ?>
                            <li class="openalex-publications__item">
                                <?php // Tipo de publicación con badge ?>
                                <span class="openalex-pub-type openalex-pub-type--<?php echo sanitize_html_class($pub->type); ?>">
                                    <?php echo esc_html(OpenAlex_Helpers::get_type_label($pub->type)); ?>
                                </span>
                                <span class="openalex-pub-title">
                                    <?php if ($pub->doi): ?>
                                        <a href="<?php echo esc_url('https://doi.org/' . $pub->doi); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo esc_html($pub->title); ?>
                                        </a>
                                    <?php else: ?>
                                        <?php echo esc_html($pub->title); ?>
                                    <?php endif; ?>
                                </span>

                                <?php if (!empty($pub->author)): ?>
                                    <span class="openalex-pub-authors">
                                        <?php
                                        // Formatear autores sin enlazar (porque no sabemos a qué miembro pertenecen)
                                        $authors = explode(' and ', $pub->author);
                                        $formatted = array_map(function($name) {
                                            return esc_html(trim($name));
                                        }, $authors);
                                        echo implode(', ', $formatted);
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($pub->journal): ?>
                                    <em class="openalex-pub-journal"><?php echo esc_html($pub->journal); ?></em>
                                <?php endif; ?>
                                <?php if ($pub->doi): ?>
                                    <a class="openalex-pub-doi" href="<?php echo esc_url('https://doi.org/' . $pub->doi); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html('DOI:'); echo esc_html($pub->doi); ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $html = ob_get_clean();
        // Cachear HTML renderizado por 12 horas
        set_transient($cache_key, $html, 12 * HOUR_IN_SECONDS);
        return $html;
    }
}     

// Inicializar
new OpenAlex_Blocks();
