<?php

/**
 * Settings del plugin OpenAlex Team.
 *
 * @package OpenAlexTeam
 */

if (! defined('ABSPATH')) exit;

class OpenAlex_Settings
{

    const OPTION_GROUP = 'openalex_team_settings';
    const OPTION_NAME  = 'openalex_team_options';
    const PAGE_SLUG    = 'openalex-team-settings';
   
    public function __construct()
    {
        add_action('admin_menu',  [$this, 'register_menu']);
        add_action('admin_init',  [$this, 'register_settings']);
        add_action('admin_post_openalex_migrate_author_ids', [$this, 'handle_migrate_author_ids']);
        add_action('update_option_' . self::OPTION_NAME, [$this, 'on_settings_updated']);
        add_action('openalex_sync_all_members', [$this, 'sync_all_members']);
        add_action('wp_loaded', [$this, 'ensure_schedule']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=team',
            'Configuración OpenAlex',
            'Configuración OpenAlex',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'sanitize_callback' => [$this, 'sanitize_options'],
        ]);

        // ── Sección API ────────────────────────────────────────────────────────
        add_settings_section(
            'openalex_team_api_section',
            'API de OpenAlex',
            function () {
                echo '<p>Configurá la API key para autenticar las requests a OpenAlex.</p>';
            },
            self::PAGE_SLUG
        );

        add_settings_field(
            'api_key',
            'API Key',
            [$this, 'field_api_key'],
            self::PAGE_SLUG,
            'openalex_team_api_section'
        );

        add_settings_field(
            'api_email',
            'Email para User-Agent',
            [$this, 'field_api_email'],
            self::PAGE_SLUG,
            'openalex_team_api_section'
        );

        // ── Sección General ────────────────────────────────────────────────────
        add_settings_section(
            'openalex_general',
            'General',
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'sync_interval',
            'Sincronización automática',
            [$this, 'field_sync_interval'],
            self::PAGE_SLUG,
            'openalex_general'
        );

        add_settings_field(
            'max_results',
            'Máximo de publicaciones por miembro',
            [$this, 'field_max_results'],
            self::PAGE_SLUG,
            'openalex_general'
        );
    }

    public function sanitize_options(array $input): array
    {
        $output = [];
        $output['api_email']    = sanitize_email($input['api_email'] ?? '');
		if (!empty($input['api_key'])) {
    		$output['api_key'] = sanitize_text_field($input['api_key']);
		} else {
    		// mantener la existente
    		$current = get_option(self::OPTION_NAME, []);
    		$output['api_key'] = $current['api_key'] ?? '';
		}
        $output['sync_interval'] = in_array($input['sync_interval'] ?? '', ['manual', 'hourly', 'twicedaily', 'daily', 'weekly'], true)
            ? $input['sync_interval']
            : 'daily';
        $output['max_results']  = min(max(intval($input['max_results'] ?? 200), 10), 1000);
        return $output;
    }

    public function field_api_email(): void
    {
        $opts = $this->get_options();
        echo '<input type="email" name="' . self::OPTION_NAME . '[api_email]" value="' . esc_attr($opts['api_email']) . '" class="regular-text">';
        echo '<p class="description">Incluido en el User-Agent de las peticiones a OpenAlex para mejor rate-limiting.</p>';
    }

    public function field_api_key(): void
    {
        $opts = $this->get_options();
        echo '<input type="password" name="' . self::OPTION_NAME . '[api_key]" value="' . esc_attr($opts['api_key']) . '" class="regular-text" autocomplete="new-password">';
        echo '<p class="description">API key de OpenAlex. Dejá en blanco si usás el acceso público.</p>';
    }

    public function field_sync_interval(): void
    {
        $opts      = $this->get_options();
        $current   = $opts['sync_interval'];
        $intervals = [
            'manual'     => 'Solo manual',
            'hourly'     => 'Cada hora',
            'twicedaily' => 'Dos veces al día',
            'daily'      => 'Diario',
            'weekly'     => 'Semanal',
        ];
        echo '<select name="' . self::OPTION_NAME . '[sync_interval]">';
        foreach ($intervals as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function field_max_results(): void
    {
        $opts = $this->get_options();
        echo '<input type="number" name="' . self::OPTION_NAME . '[max_results]" value="' . esc_attr($opts['max_results']) . '" min="10" max="1000" step="10" class="small-text"> publicaciones';
        echo '<p class="description">Límite por miembro en cada sincronización.</p>';
    }

    public function render_page(): void
    {
?>
        <div class="wrap">
            <h1>Configuración OpenAlex Team</h1>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <?php do_settings_sections(self::PAGE_SLUG); ?>
                <?php submit_button('Guardar configuración'); ?>
            </form>

            <hr>

            <?php $this->render_tools_section(); ?>
        </div>
    <?php
    }

    private function render_tools_section(): void
    {
        $result = get_transient('openalex_migrate_result_' . get_current_user_id());
        if ($result) {
            delete_transient('openalex_migrate_result_' . get_current_user_id());
            $type = $result['errors'] === 0 ? 'success' : 'warning';
            echo '<div class="notice notice-' . $type . ' inline"><p>';
            echo '<strong>Migración completada.</strong> ';
            echo 'Publicaciones procesadas: <strong>' . intval($result['pubs']) . '</strong>. ';
            echo 'Relaciones de autor actualizadas: <strong>' . intval($result['updated']) . '</strong>. ';
            if ($result['errors'] > 0) {
                echo 'Errores: <strong>' . intval($result['errors']) . '</strong>.';
            }
            echo '</p></div>';
        }
    ?>
        <h2>Herramientas</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label>Migrar IDs de autores</label>
                </th>
                <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="openalex_migrate_author_ids">
                        <?php wp_nonce_field('openalex_migrate_author_ids', 'openalex_migrate_nonce'); ?>
                        <?php submit_button(
                            'Ejecutar migración',
                            'secondary',
                            'submit',
                            false
                        ); ?>
                    </form>
                    <p class="description">
                        Recorre las publicaciones ya importadas en teachPress y guarda el
                        <code>openalex_author_id</code> de cada autoría individual.<br>
                        Necesario para que los autores que son miembros del equipo aparezcan
                        con enlace a su perfil cuando hay dos miembros con el mismo apellido.<br>
                        Solo afecta publicaciones que aún no tienen este dato guardado.
                        Es seguro ejecutarlo más de una vez.
                    </p>
                </td>
            </tr>
        </table>
<?php
    }

    public function handle_migrate_author_ids(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die('Sin permisos.', 403);
        }

        if (
            ! isset($_POST['openalex_migrate_nonce']) ||
            ! wp_verify_nonce($_POST['openalex_migrate_nonce'], 'openalex_migrate_author_ids')
        ) {
            wp_die('Nonce inválido.', 403);
        }

        $result = $this->run_author_id_migration();

        set_transient(
            'openalex_migrate_result_' . get_current_user_id(),
            $result,
            60
        );

        wp_redirect(admin_url('edit.php?post_type=team&page=' . self::PAGE_SLUG));
        exit;
    }

    private function run_author_id_migration(): array
    {
        global $wpdb;

        $rel_table  = $wpdb->prefix . 'teachpress_rel_pub_auth';
        $meta_table = $wpdb->prefix . 'teachpress_pub_meta';
        $auth_table = $wpdb->prefix . 'teachpress_authors';

        // Obtener todas las relaciones pub↔autor que tienen openalex_id en authors
        // teachPress guarda en la tabla de autores el nombre formateado;
        // buscamos autores cuyo nombre coincida con algún miembro del team
        // que tenga openalex_id, usando el mapa precargado.

        $members_posts = get_posts([
            'post_type'   => 'team',
            'numberposts' => -1,
            'post_status' => 'publish',
            'meta_query'  => [[
                'key'     => 'openalex_id',
                'value'   => '',
                'compare' => '!=',
            ]],
        ]);

        if (empty($members_posts)) {
            return ['pubs' => 0, 'updated' => 0, 'errors' => 0];
        }

        // Construir mapa nombre_normalizado => openalex_id del miembro
        $member_names = [];
        foreach ($members_posts as $post) {
            $raw_id   = trim(get_post_meta($post->ID, 'openalex_id', true));
            if (! $raw_id) continue;
            $clean_id = strtoupper(basename($raw_id));

            // Buscar el nombre del miembro en teachPress authors
            $name_in_tp = $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$auth_table} WHERE name LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($post->post_title) . '%'
            ));

            // También buscar por apellido del post_title
            $parts     = preg_split('/\s+/', trim($post->post_title));
            $last_name = array_pop($parts);

            $normalized_title = strtolower(trim($post->post_title));
            $normalized_last  = strtolower($last_name);

            $member_names[] = [
                'clean_id'    => $clean_id,
                'name_in_tp'  => $name_in_tp ? strtolower($name_in_tp) : null,
                'title_norm'  => $normalized_title,
                'last_norm'   => $normalized_last,
            ];
        }

        // Obtener todas las publicaciones que tienen openalex_source_id en meta
        // (es decir, las importadas por este plugin)
        $pub_ids = $wpdb->get_col(
            "SELECT DISTINCT pub_id FROM {$meta_table} WHERE meta_key = 'openalex_work_id'"
        );

        $updated = 0;
        $errors  = 0;

        foreach ($pub_ids as $pub_id) {
            $pub_id = intval($pub_id);

            // Obtener autores de esta publicación
            $authors = $wpdb->get_results($wpdb->prepare(
                "SELECT r.author_id, a.name
                 FROM {$rel_table} r
                 INNER JOIN {$auth_table} a ON a.author_id = r.author_id
                 WHERE r.pub_id = %d",
                $pub_id
            ));

            foreach ($authors as $author) {
                $author_name_norm = strtolower(trim($author->name));
                $meta_key         = 'openalex_author_id_' . intval($author->author_id);

                // Verificar si ya existe el meta
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$meta_table}
                     WHERE pub_id = %d AND meta_key = %s LIMIT 1",
                    $pub_id,
                    $meta_key
                ));

                if ($exists) continue;

                // Buscar si este autor es un miembro
                $matched_id = null;
                foreach ($member_names as $m) {
                    if (
                        ($m['name_in_tp'] && $author_name_norm === $m['name_in_tp']) ||
                        str_contains($author_name_norm, $m['last_norm'])
                    ) {
                        $matched_id = $m['clean_id'];
                        break;
                    }
                }

                if (! $matched_id) continue;

                $inserted = $wpdb->insert($meta_table, [
                    'pub_id'     => $pub_id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $matched_id,
                ], ['%d', '%s', '%s']);

                if ($inserted) {
                    $updated++;
                } else {
                    $errors++;
                }
            }
        }

        return [
            'pubs'    => count($pub_ids),
            'updated' => $updated,
            'errors'  => $errors,
        ];
    }

    public static function get_options(): array
    {
        $defaults = [
            'api_key'       => '',
            'api_email'     => '',
            'sync_interval' => 'daily',
            'max_results'   => 200,
        ];
        $saved = get_option(self::OPTION_NAME, []);
        return wp_parse_args($saved, $defaults);
    }
    
    public static function get_api_key(): string
    {
        $opts = self::get_options();
        return $opts['api_key'] ?? '';
    }

    public static function get_mailto(): string
    {
        $opts = self::get_options();
        return $opts['api_email'] ?? '';
    }

    /**
     * Ensure the recurring sync schedule is in place based on settings.
     */
    public function ensure_schedule(): void
    {
        if (!function_exists('as_get_scheduled_actions')) {
            return; // Action Scheduler not available
        }

        $opts = self::get_options();
        $interval = $opts['sync_interval'] ?? 'daily';

        // Check if already scheduled
        $scheduled = as_get_scheduled_actions([
            'hook' => 'openalex_sync_all_members',
            'group' => 'openalex-team',
        ], 'ids');

        if (!empty($scheduled)) {
            return; // Already scheduled
        }

        if ($interval !== 'manual') {
            $this->schedule_recurring_sync();
        }
    }

    /**
     * Schedule recurring synchronization based on the configured interval.
     */
    private function schedule_recurring_sync(): void
    {
        if (!function_exists('as_schedule_recurring_action')) {
            return; // Action Scheduler not available
        }

        $opts = self::get_options();
        $interval = $opts['sync_interval'] ?? 'daily';

        if ($interval === 'manual') {
            return;
        }

        $intervals_map = [
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
        ];

        $seconds = $intervals_map[$interval] ?? DAY_IN_SECONDS;

        as_schedule_recurring_action(
            time(),
            $seconds,
            'openalex_sync_all_members',
            [],
            'openalex-team'
        );
    }

    /**
     * Unschedule all recurring synchronizations.
     */
    private function unschedule_recurring_sync(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return; // Action Scheduler not available
        }

        as_unschedule_all_actions('openalex_sync_all_members', [], 'openalex-team');
    }

    /**
     * Handle settings update: reschedule sync if interval changed.
     */
    public function on_settings_updated(): void
    {
        $this->unschedule_recurring_sync();
        $this->ensure_schedule();
    }

    /**
     * Sync all team members with OpenAlex.
     * Enqueues a sync job for the 5 oldest synched team members.
     */
    public function sync_all_members(): void
    {
        if (!class_exists('OpenAlex_Job_Queue')) {
            return;
        }

        global $wpdb;

        // Get the 5 oldest synched team members (by openalex_sync_finished_at)
        $members = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_id ON p.ID = pm_id.post_id AND pm_id.meta_key = 'openalex_id'
             LEFT JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = 'openalex_sync_finished_at'
             WHERE p.post_type = 'team'
             AND p.post_status = 'publish'
             AND pm_id.meta_value != ''
             ORDER BY CAST(pm_date.meta_value AS DATETIME) ASC, p.ID ASC
             LIMIT 5"
        ));

        $member_names = array_map(function($m) { return $m['name'] . ' (última: ' . $m['date_str'] . ')'; }, $members);
        OpenAlex_Helpers::log("Syncing all members. Oldest 5 synched members. | Names: " . implode(', ', $member_names));

        foreach ($members as $member_id) {
            OpenAlex_Job_Queue::enqueue_member_sync(intval($member_id));

        }
    }

    
}
