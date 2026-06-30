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
        add_action('update_option_' . self::OPTION_NAME, [$this, 'on_settings_updated']);
        add_action('openalex_sync_all_members', [$this, 'sync_all_members']);
        add_action('wp_loaded', [$this, 'ensure_schedule']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=team',
            esc_html__('Configuración OpenAlex', 'openalex-team'),
            esc_html__('Configuración OpenAlex', 'openalex-team'),
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
            __('API de OpenAlex', 'openalex-team'),
            function () {
                echo '<p>'.esc_html__('Configurá la API key para autenticar los pedidos a OpenAlex.', 'openalex-team').'</p>';
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
            __('Email para User-Agent', 'openalex-team'),
            [$this, 'field_api_email'],
            self::PAGE_SLUG,
            'openalex_team_api_section'
        );

        // ── Sección General ────────────────────────────────────────────────────
        add_settings_section(
            'openalex_general',
            __('General', 'openalex-team'),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'sync_interval',
            __('Sincronización automática', 'openalex-team'),
            [$this, 'field_sync_interval'],
            self::PAGE_SLUG,
            'openalex_general'
        );

        add_settings_field(
            'max_results',
            __('Máximo de publicaciones por miembro', 'openalex-team'),
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
        $opts = $this->get_options();        ?>
        <input
            type="email"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_email]"
            value="<?php echo esc_attr( $opts['api_email'] ); ?>"
            class="regular-text"
        >
        <p class="description">
            <?php
            echo esc_html__(
                'Incluido en el User-Agent de las peticiones a OpenAlex para mejor rate-limiting.',
                'openalex-team'
            );
            ?>
        </p><?php
    }

    public function field_api_key(): void
    {
        $opts = $this->get_options();
        ?><input
            type="password"
            name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_key]"
            value="<?php echo esc_attr( $opts['api_key'] ); ?>"
            class="regular-text"
            autocomplete="new-password"
        >
        <p class="description">
            <?php
            echo esc_html__(
                'API key de OpenAlex. Dejá en blanco si usás el acceso público.',
                'openalex-team'
            );
            ?>
        </p><?php

    }

    public function field_sync_interval(): void
    {
        $opts      = $this->get_options();
        $current   = $opts['sync_interval'];
        $intervals = [
            'manual'     => __('Solo manual', 'openalex-team'),
            'hourly'     => __('Cada hora', 'openalex-team'),
            'twicedaily' => __('Dos veces al día', 'openalex-team'),
            'daily'      => __('Diario', 'openalex-team'),
            'weekly'     => __('Semanal', 'openalex-team'),
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
        echo '<input type="number" name="' . self::OPTION_NAME . '[max_results]" value="' . esc_attr($opts['max_results']) . '" min="10" max="1000" step="10" class="small-text"> ' . esc_html__('publicaciones', 'openalex-team');
        ?>
        <p class="description"><?php echo esc_html__("Límite por miembro en cada sincronización.", "openalex-team");?></p>
        <?php
    }

    public function render_page(): void
    {
?>
        <div class="wrap">
            <h1><?php echo esc_html__("Configuración OpenAlex Team", "openalex-team");?></h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_GROUP); ?>
                <?php do_settings_sections(self::PAGE_SLUG); ?>
                <?php submit_button(__('Guardar configuración', 'openalex-team')); ?>
            </form>
            <hr>
        </div>
    <?php
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
        $members = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_id ON p.ID = pm_id.post_id AND pm_id.meta_key = 'openalex_id'
             LEFT JOIN {$wpdb->postmeta} pm_date ON p.ID = pm_date.post_id AND pm_date.meta_key = 'openalex_sync_finished_at'
             WHERE p.post_type = 'team'
             AND p.post_status = 'publish'
             AND pm_id.meta_value != ''
             ORDER BY CAST(pm_date.meta_value AS DATETIME) ASC, p.ID ASC
             LIMIT 5"
        );
  
        OpenAlex_Helpers::log('Syncing all members. Oldest 5 synched members. | IDs: ' . implode(', ', $members));

        foreach ($members as $member_id) {
            OpenAlex_Job_Queue::enqueue_member_sync(intval($member_id));

        }
    }

    
}
