<?php
/**
 * Plugin Name: OpenAlex Team Publications
 * Description: Integra Team (tlp-team) con OpenAlex — usa la tabla estándar de edit.php
 * Version: 2.0
 */

if (!defined('ABSPATH')) exit;

class OpenAlexTeamPlugin {

    public function __construct() {

        // ── Columnas en edit.php para el CPT 'team' ──────────────────────────
        add_filter('manage_team_posts_columns',       [$this, 'add_columns']);
        add_action('manage_team_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('manage_edit-team_sortable_columns',[$this, 'sortable_columns']);
        add_action('pre_get_posts',                   [$this, 'sort_by_openalex_id']);

        // ── Filtro por taxonomía team_designation ─────────────────────────────
        add_action('restrict_manage_posts', [$this, 'taxonomy_filter_ui']);
        add_filter('parse_query',           [$this, 'taxonomy_filter_query']);

        // ── Quick Edit ────────────────────────────────────────────────────────
        add_action('quick_edit_custom_box', [$this, 'quick_edit_field'], 10, 2);
        add_action('save_post_team',        [$this, 'save_openalex_id']);

        // ── Row action "Obtener publicaciones" ────────────────────────────────
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);

        // ── Scripts (solo en edit.php?post_type=team) ─────────────────────────
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);

        // ── Subpágina para mostrar resultados de publicaciones ────────────────
        add_action('admin_menu',                      [$this, 'menu']);
        add_action('admin_action_openalex_fetch',     [$this, 'handle_fetch_action']);
    }

    // =========================================================================
    // COLUMNAS
    // =========================================================================

    /**
     * Agrega la columna "OpenAlex ID" después de "title".
     */
    public function add_columns(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['openalex_id'] = 'OpenAlex ID';
            }
        }
        return $new;
    }

    /**
     * Muestra el valor de la columna.
     * El valor se guarda en un <span> oculto para que el JS del quick-edit
     * pueda leerlo (mismo patrón que WP usa para categorías y taxonomías).
     */
    public function render_column(string $column, int $post_id): void {
        if ($column !== 'openalex_id') return;

        $id = get_post_meta($post_id, 'openalex_id', true);

        if ($id) {
            echo '<code>' . esc_html($id) . '</code>';
        } else {
            echo '<span aria-hidden="true">—</span>';
        }
        // Span oculto que el JS del quick-edit lee para pre-rellenar el input
        echo '<span class="hidden openalex-id-raw">' . esc_attr((string) $id) . '</span>';
    }

    /**
     * Hace la columna ordenable.
     */
    public function sortable_columns(array $columns): array {
        $columns['openalex_id'] = 'openalex_id';
        return $columns;
    }

    /**
     * Ordena por meta cuando se pide orden por openalex_id.
     */
    public function sort_by_openalex_id(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'team') return;

        if ($query->get('orderby') === 'openalex_id') {
            $query->set('meta_key', 'openalex_id');
            $query->set('orderby', 'meta_value');
        }
    }

    // =========================================================================
    // FILTRO POR TAXONOMÍA
    // =========================================================================

    /**
     * Agrega el dropdown de team_designation en la barra de filtros.
     * WordPress lo muestra automáticamente para taxonomías jerárquicas,
     * pero team_designation puede ser plana — este hook cubre ambos casos.
     */
    public function taxonomy_filter_ui(string $post_type): void {
        if ($post_type !== 'team') return;

        $selected = isset($_GET['team_designation']) ? sanitize_text_field($_GET['team_designation']) : '';
        $terms    = get_terms(['taxonomy' => 'team_designation', 'hide_empty' => false]);

        if (empty($terms) || is_wp_error($terms)) return;
        ?>
        <select name="team_designation">
            <option value="">Todos los equipos</option>
            <?php foreach ($terms as $t): ?>
                <option value="<?php echo esc_attr($t->slug); ?>"
                    <?php selected($selected, $t->slug); ?>>
                    <?php echo esc_html($t->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Aplica el filtro de taxonomía a la query principal de edit.php.
     */
    public function taxonomy_filter_query(\WP_Query $query): void {
        global $pagenow;

        if ($pagenow !== 'edit.php') return;
        if (!isset($_GET['team_designation']) || $_GET['team_designation'] === '') return;
        if (($query->query_vars['post_type'] ?? '') !== 'team') return;

        $query->set('tax_query', [[
            'taxonomy' => 'team_designation',
            'field'    => 'slug',
            'terms'    => sanitize_text_field($_GET['team_designation']),
        ]]);
    }

    // =========================================================================
    // QUICK EDIT
    // =========================================================================

    /**
     * Agrega el campo OpenAlex ID al panel de quick-edit.
     * Sigue la estructura HTML exacta que usa WordPress en sus campos nativos.
     */
    public function quick_edit_field(string $column, string $post_type): void {
        if ($post_type !== 'team' || $column !== 'openalex_id') return;

        wp_nonce_field('openalex_quick_edit_nonce', 'openalex_quick_edit_nonce_field');
        ?>
        <fieldset class="inline-edit-col-left">
            <div class="inline-edit-col">
                <label>
                    <span class="title">OpenAlex ID</span>
                    <span class="input-text-wrap">
                        <input type="text"
                               name="openalex_id"
                               class="openalex-id-input ptitle"
                               value=""
                               placeholder="Ej: A123456789">
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    /**
     * Guarda el openalex_id al dispararse save_post para el CPT 'team'.
     * Cubre tanto el quick-edit como el formulario de edición individual.
     */
    public function save_openalex_id(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['openalex_id'])) return;

        // Verificar nonce (generado en quick_edit_field)
        if (
            !isset($_POST['openalex_quick_edit_nonce_field']) ||
            !wp_verify_nonce($_POST['openalex_quick_edit_nonce_field'], 'openalex_quick_edit_nonce')
        ) return;

        update_post_meta($post_id, 'openalex_id', sanitize_text_field($_POST['openalex_id']));
    }

    // =========================================================================
    // ROW ACTIONS
    // =========================================================================

    /**
     * Agrega la acción "Obtener publicaciones" en las row-actions de la tabla,
     * solo cuando el miembro tiene un OpenAlex ID asignado.
     */
    public function row_actions(array $actions, \WP_Post $post): array {
        if ($post->post_type !== 'team') return $actions;

        $id = get_post_meta($post->ID, 'openalex_id', true);
        if (!$id) return $actions;

        $url = wp_nonce_url(
            admin_url('admin.php?action=openalex_fetch&post_id=' . $post->ID),
            'openalex_fetch_' . $post->ID
        );

        $actions['openalex_fetch'] = sprintf(
            '<a href="%s">Obtener publicaciones</a>',
            esc_url($url)
        );

        return $actions;
    }

    // =========================================================================
    // SCRIPTS — solo en edit.php?post_type=team
    // =========================================================================

    public function enqueue_scripts(string $hook): void {
        global $post_type;

        if ($hook !== 'edit.php' || $post_type !== 'team') return;

        // Depende de 'inline-edit-post' para que inlineEditPost ya esté disponible
        wp_register_script('openalex-quick-edit-js', false, ['inline-edit-post'], '2.0', true);
        wp_enqueue_script('openalex-quick-edit-js');
        wp_add_inline_script('openalex-quick-edit-js', $this->get_quick_edit_script());
    }

    /**
     * Monkey-patch de inlineEditPost.edit.
     * Patrón canónico usado por WooCommerce, The Events Calendar, ACF, etc.
     * Lee el valor actual desde el <span class="openalex-id-raw"> de la fila
     * y lo inyecta en el input del panel quick-edit antes de que se muestre.
     */
    private function get_quick_edit_script(): string {
        return <<<'JS'
(function ($) {
    var $wpInlineEdit = inlineEditPost.edit;

    inlineEditPost.edit = function (id) {
        // 1. Ejecutar el comportamiento original de WordPress
        $wpInlineEdit.apply(this, arguments);

        // 2. Obtener el post ID (puede llegar como número o como elemento DOM)
        var postId = (typeof id === 'object') ? this.getId(id) : id;

        // 3. Leer el valor actual desde el span oculto en la fila de la tabla
        var currentId = $('#post-' + postId)
                            .find('.column-openalex_id .openalex-id-raw')
                            .text()
                            .trim();

        // 4. Pre-rellenar el input en el panel quick-edit
        $('input[name="openalex_id"]', '#edit-' + postId).val(currentId);
    };
}(jQuery));
JS;
    }

    // =========================================================================
    // SUBMENÚ Y PÁGINA DE PUBLICACIONES
    // =========================================================================

    /**
     * Agrega "Publicaciones OpenAlex" como submenú del CPT 'team',
     * reemplazando la página independiente de la versión anterior.
     */
    public function menu(): void {
        add_submenu_page(
            'edit.php?post_type=team',
            'Publicaciones OpenAlex',
            'Publicaciones OpenAlex',
            'manage_options',
            'openalex-publications',
            [$this, 'publications_page']
        );
    }

    public function publications_page(): void {
        $post_id = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        echo '<div class="wrap"><h1>Publicaciones OpenAlex</h1>';

        if ($post_id) {
            $post = get_post($post_id);
            if ($post && $post->post_type === 'team') {
                echo '<h2>' . esc_html($post->post_title) . '</h2>';
                echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=team')) . '">'
                   . '← Volver a la lista</a></p>';
                $this->fetch_publications($post_id);
            } else {
                echo '<div class="notice notice-error"><p>Miembro no encontrado.</p></div>';
            }
        } else {
            echo '<p>Usá el enlace <strong>Obtener publicaciones</strong> desde la '
               . '<a href="' . esc_url(admin_url('edit.php?post_type=team')) . '">lista de Team</a>.</p>';
        }

        echo '</div>';
    }

    /**
     * Maneja la acción openalex_fetch (disparada desde la row action).
     * Verifica nonce y redirige a la subpágina de publicaciones.
     */
    public function handle_fetch_action(): void {
        $post_id = intval($_GET['post_id'] ?? 0);

        if (!$post_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'openalex_fetch_' . $post_id)) {
            wp_die('Solicitud no válida.', 403);
        }

        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Sin permisos.', 403);
        }

        wp_redirect(admin_url('admin.php?page=openalex-publications&post_id=' . $post_id));
        exit;
    }

    // =========================================================================
    // FETCH PUBLICACIONES (OpenAlex API)
    // =========================================================================

    private function fetch_publications(int $post_id): void {
        $author_id = get_post_meta($post_id, 'openalex_id', true);

        if (!$author_id) {
            echo '<div class="notice notice-warning inline"><p>Este miembro no tiene OpenAlex ID.</p></div>';
            return;
        }

        $author_id = basename($author_id);
        $url       = "https://api.openalex.org/works?filter=author.id:{$author_id}";
        $response  = wp_remote_get($url, ['timeout' => 15]);

        if (is_wp_error($response)) {
            echo '<div class="notice notice-error inline"><p>Error al conectar con OpenAlex: '
               . esc_html($response->get_error_message()) . '</p></div>';
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['results'])) {
            echo '<p>Se encontraron <strong>' . count($data['results']) . '</strong> publicaciones:</p><ul>';
            foreach ($data['results'] as $work) {
                echo '<li>' . esc_html($work['title']) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<div class="notice notice-info inline"><p>No se encontraron publicaciones para este autor.</p></div>';
        }
    }
}

new OpenAlexTeamPlugin();
