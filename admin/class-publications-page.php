<?php
/**
 * Página de administración "Publicaciones OpenAlex"
 * (submenú bajo el CPT team).
 *
 * @package OpenAlexTeam
 */

if (!defined("ABSPATH")) {
    exit();
}
require_once ABSPATH . "wp-admin/includes/class-wp-list-table.php";

class OpenAlex_Publications_Page
{
    public function __construct()
    {
        // add_action("admin_init", [$this, "check_permissions"]);
        add_action("admin_menu", [$this, "register_menu"]);
        add_action("admin_post_openalex_save_visibility", [
            $this,
            "save_visibility",
        ]);
        add_action("wp_ajax_openalex_toggle_publication_visibility", [
            $this,
            "ajax_toggle_publication_visibility",
        ]);
        add_action("admin_post_openalex_invalidate_transients", [$this, "handle_invalidate_transients"]);
        # el nombre del hook debe tener el prefijo wp_ajax_
        add_action('wp_ajax_openalex_check_sync_status', [$this, 'ajax_check_sync_status']);
    }
    public function check_permissions(): void
    {
        if (!current_user_can("manage_options")) {
            wp_die("Unauthorized");
        }
    }

    public function register_menu(): void
    {
        add_submenu_page(
            "edit.php?post_type=team",
            __("Publicaciones OpenAlex", "openalex-team"),
            __("Publicaciones OpenAlex", "openalex-team"),
            "manage_options",
            "openalex-publications",
            [$this, "render_page"]
        );
    }

    public function render_page(): void
    {
        $post_id = isset($_GET["post_id"])
            ? intval(sanitize_text_field($_GET["post_id"]))
            : 0;

        echo '<div class="wrap"><h1>' .
            esc_html__("Publicaciones OpenAlex", "openalex-team") .
            "</h1>";

        $this->render_invalidate_transients_button();
        $this->maybe_show_cache_cleared_notice();
        $this->maybe_show_sync_notice();

        if ($post_id) {
            $this->render_member_detail($post_id);
        } else {
            $this->render_members_list();
        }

        echo "</div>";
    }

    private function maybe_show_sync_notice(): void
    {
        $key = "openalex_sync_result_" . get_current_user_id();
        $result = get_transient($key);
        if (!$result) {
            return;
        }
        delete_transient($key);

        $type = empty($result["errors"]) ? "success" : "warning";
        echo '<div class="notice notice-' .
            esc_attr($type) .
            ' is-dismissible"><p>';

        $message = $result["notice"] ?? '';
        if ($message === '' && !empty($result["errors"])) {
            $message = implode(
                "; ",
                array_map("sanitize_text_field", $result["errors"])
            );
        }

        echo esc_html($message);
        echo "</p></div>";
    }

    private function maybe_show_cache_cleared_notice(): void
    {
        $key = 'openalex_cache_cleared_' . get_current_user_id();
        $cleared = get_transient($key);
        if (!$cleared) {
            return;
        }
        delete_transient($key);

        echo '<div class="notice notice-success is-dismissible"><p>';        
        echo esc_html($cleared);
        echo '</p></div>';
    }

    private function render_invalidate_transients_button(): void
    {
        $member_id = isset($_GET['post_id']) ? intval(sanitize_text_field($_GET['post_id'])) : 0;
        if ($member_id > 0) {
            // Vista de detalle de un miembro: botón específico
            $button_text = '🗑️ ' . esc_html__('Limpiar caché miembro', "openalex-team");
        } else {
            // Vista de lista: botón global
            $button_text = esc_html__('Limpiar caché de publicaciones', "openalex-team");
        }
            ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <input type="hidden" name="action" value="openalex_invalidate_transients">
            <input type="hidden" name="member_id" value="<?php echo esc_attr($member_id); ?>">
            <?php wp_nonce_field('openalex_invalidate_transients', 'openalex_invalidate_nonce'); ?>
            <?php submit_button($button_text, 'secondary', 'submit', false); ?>
        </form>
        <br><br>
        <?php
    }

    public function handle_invalidate_transients(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Sin permisos.', "openalex-team"), 403);
        }

        if (
            !isset($_POST['openalex_invalidate_nonce']) ||
            !wp_verify_nonce($_POST['openalex_invalidate_nonce'], 'openalex_invalidate_transients')
        ) {
            wp_die(__('Nonce inválido.', "openalex-team"), 403);
        }

        //
        $member_id = isset($_POST['member_id']) ? intval($_POST['member_id']) : 0;
        
        if ($member_id > 0) {
            OpenAlex_Helpers::clear_member_publications_cache($member_id);
            $member_post = get_post($member_id);
            $member_name = $member_post ? $member_post->post_title : 'Miembro';
            $message = sprintf(__('Caché del miembro "%s" limpiada con éxito.', "openalex-team"), $member_name);
        } else {        
            // Clear all member publication caches
            $members = get_posts([
                'post_type'   => 'team',
                'numberposts' => -1,
                'meta_query'  => [[
                    'key'     => 'openalex_id',
                    'value'   => '',
                    'compare' => '!=',
                ]],
            ]);

            foreach ($members as $member) {
                OpenAlex_Helpers::clear_member_publications_cache($member->ID);
            }
            $message = __('Caché de todas las publicaciones limpiada con éxito.', 'openalex-team');
        }

        set_transient('openalex_cache_cleared_' . get_current_user_id(), $message, 60);        

        $redirect_url = admin_url('admin.php?page=openalex-publications');
        if ($member_id > 0) {
            $redirect_url = add_query_arg('post_id', $member_id, $redirect_url);
        }
        
        wp_redirect($redirect_url);

        exit;
    }

    private function render_members_list(): void
    {
        $members = get_posts([
            "post_type" => "team",
            "numberposts" => -1,
            "orderby" => "title",
            "order" => "ASC",
            "meta_query" => [
                ["key" => "openalex_id", "value" => "", "compare" => "!="],
            ],
        ]);

        if (empty($members)) {
            echo '<div class="notice notice-info inline"><p>' .
                esc_html__("No hay miembros con ID OpenAlex.", "openalex-team") .
                ' <a href="' .
                esc_url(admin_url("edit.php?post_type=team")) .
                '"> '.esc_html__("Asignalos desde la lista de Team", "openalex-team").'</a>.' .
                "</p></div>";
            return;
        }
        ?>
        <p><?php echo esc_html__("Miembros con ID OpenAlex:", 'openalex-team'); ?> <strong><?php echo count(
            $members
        ); ?></strong></p>
        <table class="widefat striped openalex-members-table">
            <thead><tr>
                <th><?php echo esc_html__("Nombre", 'openalex-team'); ?></th>
                <th><?php echo esc_html__("Equipos", 'openalex-team'); ?></th>
                <th><?php echo esc_html__("ID de OpenAlex", 'openalex-team'); ?></th>
                <th><?php echo esc_html__("Última sincr.", 'openalex-team'); ?></th>
                <th><?php echo esc_html__("Estado", 'openalex-team'); ?></th>
                <th><?php echo esc_html__("Acción", 'openalex-team'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($members as $m):

                $openalex_id = get_post_meta($m->ID, "openalex_id", true);
                $last_sync = get_post_meta($m->ID, "openalex_last_sync", true);
                $terms = get_the_terms($m->ID, "team_designation");
                $team_names =
                    !empty($terms) && !is_wp_error($terms)
                        ? implode(", ", wp_list_pluck($terms, "name"))
                        : "—";

                $job = OpenAlex_Job_Queue::get_member_status($m->ID);
                ?>
            <tr data-member-id="<?php echo esc_attr($m->ID); ?>">
                <td><strong><?php echo esc_html($m->post_title); ?></strong></td>
                <td><?php echo esc_html($team_names); ?></td>
                <td><code><?php echo esc_html($openalex_id); ?></code></td>
                <td><?php echo esc_html($last_sync)
                    ? esc_html(
                        date_i18n(
                            get_option("date_format") . " H:i",
                            strtotime($last_sync)
                        )
                    )
                    : '<em style="color:#8c8f94;">Nunca</em>'; ?></td>
                <td class="openalex-status">
                    <?php
                    $color = "#646970";
                    if ($job["status"] === "queued") {                        $color = "#996800";                    }
                    if ($job["status"] === "running") {                       $color = "#135e96";                    }
                    if ($job["status"] === "completed") {                     $color = "#0a7a20";                    }
                    if ($job["status"] === "failed") {                        $color = "#b32d2e";                    }

                    echo '<strong class="openalex-status-text" style="color:' . esc_attr($color) . ';">' . esc_html(strtoupper($job["status"])) . "</strong><br>";
                    echo '<span class="openalex-status-message" style="color:#646970;">' . esc_html($job["message"]) . "</span>";
                    ?>
                </td>
                <td>
                    <form method="post" action="<?php echo esc_url(
                        admin_url("admin-post.php")
                    ); ?>" style="display:inline;">
                        <input type="hidden" name="action"  value="openalex_sync">
                        <input type="hidden" name="post_id" value="<?php echo esc_attr(
                            $m->ID
                        ); ?>">
                        <?php wp_nonce_field(
                            "openalex_sync_" . $m->ID,
                            "openalex_sync_nonce"
                        ); ?>

                        <button type="submit" class="button button-primary" <?php disabled(
                            $job["is_locked"],
                            true
                        ); ?>>
                            <?php if ($job["status"] === "queued") {
                                echo "En cola...";
                            } elseif ($job["status"] === "running") {
                                echo "Procesando...";
                            } else {
                                echo $last_sync
                                    ? "↻ " . esc_html__("Re-sincronizar", 'openalex-team')
                                    : "⬇ " . esc_html__("Sincronizar", 'openalex-team');
                            } ?>
                        </button>
                    </form>

                    <a class="button" href="<?php echo esc_url(
                        admin_url(
                            "admin.php?page=openalex-publications&post_id=" .
                                $m->ID
                        )
                    ); ?>">
                    <?php echo esc_html__("Ver publicaciones", 'openalex-team'); ?>
                    </a>
                </td>
            </tr>
            <?php
            endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_member_detail(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== "team") {
            echo '<div class="notice notice-error inline"><p>'.esc_html__('Miembro no encontrado', "openalex-team").'.</p></div>';
            return;
        }

        $openalex_id = get_post_meta($post_id, "openalex_id", true);
        $last_sync = get_post_meta($post_id, "openalex_last_sync", true);
        $job = OpenAlex_Job_Queue::get_member_status($post_id);

        echo '<p><a href="' .
            esc_url(admin_url("admin.php?page=openalex-publications")) .
            '">← '. esc_html__("Volver", "openalex-team") .'</a></p>';
        echo "<h2>" . esc_html($post->post_title) . "</h2>";

        if (!$openalex_id) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html__('Este miembro no tiene ID OpenAlex', "openalex-team").'.</p></div>';
            return;
        }

        echo "<p><strong>". esc_html__("ID OpenAlex", "openalex-team").":</strong> <code>" .
            esc_html($openalex_id) .
            "</code>";
        if ($last_sync) {
            echo " &nbsp;|&nbsp; <strong>".esc_html__("Última sincr", "openalex-team").":</strong> " .
                esc_html(
                    date_i18n(
                        get_option("date_format") . " H:i",
                        strtotime($last_sync)
                    )
                );
        }
        echo "</p>";

        if (isset($_GET["updated"]) && "1" === $_GET["updated"]) {
            echo '<div class="notice notice-success inline"><p>'.
                esc_html__('La visibilidad de las publicaciones fue actualizada.', 'openalex-team').'</p></div>';
        }

        $pubs = OpenAlex_Helpers::get_member_publications($post_id, false);
        if (empty($pubs)) {
            echo '<p><em>'.
                esc_html__('No hay publicaciones importadas aún.', 'openalex-team' ).'</em></p>';
        } else {
            $items = array_map(function ($pub) {
                return [
                    "pub_id" => (int) $pub->pub_id,
                    "title" => $pub->title,
                    "type" => $pub->type,
                    "year" => $pub->year,
                    "doi" => $pub->doi,
                    "hidden" => OpenAlex_Helpers::is_publication_hidden(
                        (int) $pub->pub_id
                    ),
                ];
            }, $pubs);

            $table = new OpenAlex_Publications_Table($items, $post_id);
            $table->prepare_items();

            $current_year = isset($_GET["filter_year"])
                ? sanitize_text_field(wp_unslash($_GET["filter_year"]))
                : "";
            $current_type = isset($_GET["filter_type"])
                ? sanitize_text_field(wp_unslash($_GET["filter_type"]))
                : "";
            $current_hidden = isset($_GET["filter_hidden"])
                ? sanitize_text_field(wp_unslash($_GET["filter_hidden"]))
                : "";

            $years = $table->get_filter_years();
            $types = $table->get_filter_types();

            // Formulario GET: solo filtros
            echo '<form method="get" action="' .
                esc_url(admin_url("admin.php")) .
                '" style="margin:16px 0;">';
            echo '<input type="hidden" name="page" value="openalex-publications">';
            echo '<input type="hidden" name="post_id" value="' .
                intval($post_id) .
                '">';
            $current_orderby = isset($_GET["orderby"])
                ? sanitize_key($_GET["orderby"])
                : "year";
            $current_order = isset($_GET["order"])
                ? sanitize_key($_GET["order"])
                : "desc";

            echo '<input type="hidden" name="orderby" value="' .
                esc_attr($current_orderby) .
                '">';
            echo '<input type="hidden" name="order" value="' .
                esc_attr($current_order) .
                '">';

            echo '<div class="alignleft actions" style="margin-bottom:12px;">';

            if (!empty($years)) {
                echo '<select name="filter_year" style="margin-right:5px;">';
                echo '<option value="">'.esc_html__('Filtrar por año', 'openalex-team').'</option>';
                foreach ($years as $year) {
                    echo '<option value="' .
                        esc_attr($year) .
                        '" ' .
                        selected($current_year, (string) $year, false) .
                        ">" .
                        esc_html($year) .
                        "</option>";
                }
                echo "</select>";
            }

            if (!empty($types)) {
                echo '<select name="filter_type" style="margin-right:5px;">';
                echo '<option value="">'.esc_html__('Filtrar por tipo', 'openalex-team').'</option>';
                foreach ($types as $type) {
                    echo '<option value="' .
                        esc_attr($type) .
                        '" ' .
                        selected($current_type, $type, false) .
                        ">" .
                        esc_html($type) .
                        "</option>";
                }
                echo "</select>";
            }

            echo '<select name="filter_hidden" style="margin-right:10px;">';
            echo '<option value="">'.esc_html__('Filtrar por estado', 'openalex-team').'</option>';
            echo '<option value="yes" ' .
                selected($current_hidden, "yes", false) .
                ">".esc_html__('Ocultas', 'openalex-team')."</option>";
            echo '<option value="no" ' .
                selected($current_hidden, "no", false) .
                ">".esc_html__('Visibles', 'openalex-team')."</option>";
            echo "</select>";

            submit_button(__("Filtrar", "openalex-team"), "secondary", "", false);

            if ($current_year || $current_type || $current_hidden) {
                $clear_url = admin_url(
                    "admin.php?page=openalex-publications&post_id=" . $post_id
                );
                echo ' <a href="' .
                    esc_url($clear_url) .
                    '" class="button">'.esc_html__('Limpiar filtros', 'openalex-team').'</a>';
            }

            echo "</div>";
            echo '<br class="clear">';
            echo "</form>";

            // Formulario POST: tabla + checkboxes + guardar
            echo '<form method="post" action="' .
                esc_url(admin_url("admin-post.php")) .
                '">';
            echo '<input type="hidden" name="action" value="openalex_save_visibility">';
            echo '<input type="hidden" name="post_id" value="' .
                intval($post_id) .
                '">';

            // preserva filtros actuales al volver luego del guardado
            echo '<input type="hidden" name="filter_year" value="' .
                esc_attr($current_year) .
                '">';
            echo '<input type="hidden" name="filter_type" value="' .
                esc_attr($current_type) .
                '">';
            echo '<input type="hidden" name="filter_hidden" value="' .
                esc_attr($current_hidden) .
                '">';

            wp_nonce_field(
                "openalex_save_visibility_" . $post_id,
                "openalex_visibility_nonce"
            );

            $table->display();

            echo '<p style="margin-top:12px;"><button type="submit" class="button button-primary">'.esc_html__('Guardar visibilidad', 'openalex-team').'</button></p>';
            echo "</form>";
        }

        // Formulario POST independiente: re-sync
        echo '<form method="post" action="' .
            esc_url(admin_url("admin-post.php")) .
            '" style="margin-top:16px;">';
        echo '<input type="hidden" name="action" value="openalex_sync">';
        echo '<input type="hidden" name="post_id" value="' .
            intval($post_id) .
            '">';
        wp_nonce_field("openalex_sync_" . $post_id, "openalex_sync_nonce");

        echo '<button type="submit" class="button button-secondary" ' .
            disabled($job["is_locked"], true, false) .
            ">";
        if ($job["status"] === "queued") {
            echo esc_html__("En cola", "openalex-team")."...";
        } elseif ($job["status"] === "running") {
            echo esc_html__("Procesando", "openalex-team")."...";
        } else {
            echo $last_sync
                ? "↻ " . esc_html__("Re-sincronizar publicaciones", "openalex-team")
                : "⬇ " . esc_html__("Sincronizar publicaciones", "openalex-team");
        }
        echo "</button>";

        echo '<span style="margin-left:10px;color:#646970;">' .
            esc_html($job["message"]) .
            "</span>";
        echo "</form>";

        $this->render_toggle_visibility_script();
    }

    public function save_visibility(): void
    {
        $post_id = intval($_POST["post_id"] ?? 0);

        if (!$post_id) {
            wp_die(__("ID de publicación inválido.", "openalex-team"), 400);
        }

        if (!current_user_can("manage_options")) {
            wp_die(__("Sin permisos.", "openalex-team"), 403);
        }

        if (
            !isset($_POST["openalex_visibility_nonce"]) ||
            !wp_verify_nonce(
                $_POST["openalex_visibility_nonce"],
                "openalex_save_visibility_" . $post_id
            )
        ) {
            wp_die("Nonce inválido.", 403);
        }

        $pubs = OpenAlex_Helpers::get_member_publications($post_id, false);
        $selected = isset($_POST["hidden_pubs"])
            ? array_map("intval", (array) $_POST["hidden_pubs"])
            : [];

        foreach ($pubs as $pub) {
            $pub_id = (int) $pub->pub_id;
            OpenAlex_Helpers::set_publication_hidden(
                $pub_id,
                in_array($pub_id, $selected, true)
            );
        }

        OpenAlex_Helpers::clear_member_publications_cache($post_id);

        $redirect = [
            "page" => "openalex-publications",
            "post_id" => $post_id,
            "updated" => 1,
        ];

        if (isset($_POST["filter_year"]) && $_POST["filter_year"] !== "") {
            $redirect["filter_year"] = sanitize_text_field(
                wp_unslash($_POST["filter_year"])
            );
        }

        if (isset($_POST["filter_type"]) && $_POST["filter_type"] !== "") {
            $redirect["filter_type"] = sanitize_text_field(
                wp_unslash($_POST["filter_type"])
            );
        }

        if (isset($_POST["filter_hidden"]) && $_POST["filter_hidden"] !== "") {
            $redirect["filter_hidden"] = sanitize_text_field(
                wp_unslash($_POST["filter_hidden"])
            );
        }

        if (isset($_POST["orderby"]) && $_POST["orderby"] !== "") {
            $redirect["orderby"] = sanitize_key(wp_unslash($_POST["orderby"]));
        }

        if (isset($_POST["order"]) && $_POST["order"] !== "") {
            $redirect["order"] = sanitize_key(wp_unslash($_POST["order"]));
        }

        wp_redirect(add_query_arg($redirect, admin_url("admin.php")));
        exit();
    }

    public function ajax_toggle_publication_visibility(): void
    {
        if (!current_user_can("manage_options")) {
            wp_send_json_error(["message" => "Sin permisos."], 403);
        }

        check_ajax_referer("openalex_toggle_visibility", "nonce");

        $pub_id = isset($_POST["pub_id"]) ? intval($_POST["pub_id"]) : 0;
        $hidden = isset($_POST["hidden"]) ? intval($_POST["hidden"]) : 0;

        if (!$pub_id) {
            wp_send_json_error(
                ["message" => __("ID de publicación inválido.", "openalex-team")],
                400
            );
        }

        OpenAlex_Helpers::set_publication_hidden($pub_id, (bool) $hidden);

        wp_send_json_success([
            "pub_id" => $pub_id,
            "hidden" => (bool) $hidden,
            "message" => $hidden
                ? __("Publicación ocultada.", "openalex-team")
                : __("Publicación visible.", "openalex-team"),
        ]);
    }

    private function render_toggle_visibility_script(): void
    {
        $nonce = wp_create_nonce("openalex_toggle_visibility"); ?>
    <script>
    document.addEventListener('change', function(e) {
        const checkbox = e.target.closest('.openalex-visibility-toggle');
        if (!checkbox) return;

        const pubId = checkbox.dataset.pubId;
        const hidden = checkbox.checked ? 1 : 0;
        const status = checkbox.closest('td').querySelector('.openalex-toggle-status');
        const originalChecked = !checkbox.checked;

        checkbox.disabled = true;
        if (status) {
            status.textContent = 'Guardando...';
        }

        const body = new URLSearchParams({
            action: 'openalex_toggle_publication_visibility',
            nonce: '<?php echo esc_js($nonce); ?>',
            pub_id: pubId,
            hidden: hidden
        });

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        })
        .then(response => response.json())
        .then(data => {
            if (!data || !data.success) {
                throw new Error(data && data.data && data.data.message ? data.data.message : 'Error al guardar.');
            }

            if (status) {
                status.textContent = data.data.message || 'Guardado.';
                status.style.color = '#0a7a20';
                setTimeout(() => {
                    status.textContent = '';
                }, 1500);
            }
        })
        .catch(error => {
            checkbox.checked = originalChecked;
            if (status) {
                status.textContent = error.message || 'No se pudo guardar.';
                status.style.color = '#b32d2e';
            }
        })
        .finally(() => {
            checkbox.disabled = false;
        });
    });
    </script>
    <?php    
    }

    /**
    * Registra el hook de AJAX para verificar el estado de sincronización.
    */
    public function ajax_check_sync_status() {
        // 1. Verificar seguridad (nonce)
        check_ajax_referer('openalex_admin_nonce', 'nonce');

        // 2. Obtener los IDs de las publicaciones/miembros que se muestran en la tabla
        $post_ids = isset($_POST['post_ids']) ? array_map('intval', $_POST['post_ids']) : [];
        
        if (empty($post_ids)) {
            wp_send_json_error('No post IDs provided');
        }

        // 3. Verificar el estado de cada uno (usando post_meta es mucho más rápido que consultar Action Scheduler)
        $status_map = [];
        foreach ($post_ids as $post_id) {
            $status = get_post_meta($post_id, 'openalex_sync_status', true);
            $last_sync = get_post_meta($post_id, 'openalex_last_sync', true);
            
            $status_map[$post_id] = [
                'status' => $status ?: 'idle',
                'last_sync' => $last_sync ?: ''
            ];
        }

        // 4. Devolver la respuesta al frontend
        wp_send_json_success($status_map);
    }
}

// Agregar la clase personalizada para manejar la tabla de publicaciones
class OpenAlex_Publications_Table extends WP_List_Table
{
    public $items;
    public $all_items;
    private $post_id;

    public function __construct($items, $post_id = 0)
    {
        parent::__construct([
            "singular" => "publication",
            "plural" => "publications",
            "ajax" => false,
        ]);

        $this->all_items = $items;
        $this->items = $items;
        $this->post_id = $post_id;
    }

    public function get_columns()
    {
        return [
            "title" => __("Título", "openalex-team"),
            "type" => __("Tipo", "openalex-team"),
            "year" => __("Año", "openalex-team"),
            "doi" => "DOI",
            "hidden" => __("Ocultar del listado", "openalex-team"),
        ];
    }

    protected function get_sortable_columns()
    {
        return [
            "title" => ["title", false],
            "type" => ["type", false],
            "year" => ["year", true],
            "doi" => ["doi", false],
            "hidden" => ["hidden", false],
        ];
    }

    protected function column_default($item, $column_name)
    {
        switch ($column_name) {
            case "title":
                return esc_html($item["title"]);

            case "type":
                return esc_html($item["type"]);

            case "year":
                return esc_html($item["year"]);

            case "doi":
                return $item["doi"]
                    ? '<a href="https://doi.org/' .
                            esc_attr($item["doi"]) .
                            '" target="_blank" rel="noopener noreferrer">' .
                            esc_html($item["doi"]) .
                            "</a>"
                    : "—";

            case "hidden":
                return sprintf(
                    '<label>
                        <input type="checkbox"
                            class="openalex-visibility-toggle"
                            data-pub-id="%d"
                            %s>
                        <span style="margin-left:6px;">'.esc_html__('Oculta', "openalex-team").'</span>
                    </label>
                    <span class="openalex-toggle-status" style="margin-left:8px;color:#646970;"></span>',
                    intval($item["pub_id"]),
                    checked($item["hidden"], true, false)
                );
            default:
                return "—";
        }
    }

    public function prepare_items()
    {
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $primary = "title";

        $this->_column_headers = [$columns, $hidden, $sortable, $primary];
        $this->items = $this->all_items;

        $filtered_year = isset($_GET["filter_year"])
            ? sanitize_text_field(wp_unslash($_GET["filter_year"]))
            : "";
        $filtered_type = isset($_GET["filter_type"])
            ? sanitize_text_field(wp_unslash($_GET["filter_type"]))
            : "";
        $filtered_hidden = isset($_GET["filter_hidden"])
            ? sanitize_text_field(wp_unslash($_GET["filter_hidden"]))
            : "";

        if ($filtered_year !== "") {
            $this->items = array_filter($this->items, function ($item) use (
                $filtered_year
            ) {
                return (string) $item["year"] === $filtered_year;
            });
        }

        if ($filtered_type !== "") {
            $this->items = array_filter($this->items, function ($item) use (
                $filtered_type
            ) {
                return (string) $item["type"] === $filtered_type;
            });
        }

        if ($filtered_hidden !== "") {
            $this->items = array_filter($this->items, function ($item) use (
                $filtered_hidden
            ) {
                if ("yes" === $filtered_hidden) {
                    return !empty($item["hidden"]);
                }

                if ("no" === $filtered_hidden) {
                    return empty($item["hidden"]);
                }

                return true;
            });
        }

        $this->items = array_values($this->items);

        $orderby = isset($_GET["orderby"])
            ? sanitize_key($_GET["orderby"])
            : "year";
        $order = isset($_GET["order"]) ? sanitize_key($_GET["order"]) : "desc";

        $allowed_orderby = ["title", "type", "year", "doi", "hidden"];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = "year";
        }

        $order = "asc" === strtolower($order) ? "asc" : "desc";

        usort($this->items, function ($a, $b) use ($orderby, $order) {
            $result = 0;

            switch ($orderby) {
                case "year":
                    $value_a = isset($a["year"]) ? (int) $a["year"] : 0;
                    $value_b = isset($b["year"]) ? (int) $b["year"] : 0;
                    $result = $value_a <=> $value_b;
                    break;

                case "hidden":
                    $value_a = !empty($a["hidden"]) ? 1 : 0;
                    $value_b = !empty($b["hidden"]) ? 1 : 0;
                    $result = $value_a <=> $value_b;
                    break;

                case "title":
                case "type":
                case "doi":
                default:
                    $value_a = isset($a[$orderby])
                        ? mb_strtolower((string) $a[$orderby])
                        : "";
                    $value_b = isset($b[$orderby])
                        ? mb_strtolower((string) $b[$orderby])
                        : "";
                    $result = strcmp($value_a, $value_b);
                    break;
            }

            return "asc" === $order ? $result : -$result;
        });
    }

    public function display_rows()
    {
        if (empty($this->items)) {
            echo '<tr><td colspan="' .
                count($this->get_columns()) .
                '" style="text-align:center;padding:20px;"><em>'.
                esc_html__('No hay publicaciones que coincidan con los filtros.', "openalex").'</em></td></tr>';
            return;
        }

        foreach ($this->items as $item) {
            echo "<tr>";
            foreach (
                $this->get_columns()
                as $column_name => $column_display_name
            ) {
                echo "<td>" .
                    $this->column_default($item, $column_name) .
                    "</td>";
            }
            echo "</tr>";
        }
    }

    public function extra_tablenav($which)
    {
        // Los filtros se renderizan fuera de la tabla.
    }

    public function get_filter_years(): array
    {
        $years = array_filter(
            array_unique(array_column($this->all_items, "year"))
        );
        rsort($years);
        return $years;
    }

    public function get_filter_types(): array
    {
        $types = array_filter(
            array_unique(array_column($this->all_items, "type"))
        );
        sort($types);
        return $types;
    }    
}
