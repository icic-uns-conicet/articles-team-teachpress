<?php
/**
 * Columnas personalizadas, Quick Edit y filtro de taxonomía
 * en la pantalla edit.php del CPT 'team'.
 *
 * @package OpenAlexTeam
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAlex_Admin_Columns {

    public function __construct() {
        // Columnas
        add_filter( 'manage_team_posts_columns',           [ $this, 'add_columns' ] );
        add_action( 'manage_team_posts_custom_column',     [ $this, 'render_column' ], 10, 2 );
        add_filter( 'manage_edit-team_sortable_columns',   [ $this, 'sortable_columns' ] );
        add_action( 'pre_get_posts',                       [ $this, 'sort_by_openalex_id' ] );

        // Filtro por taxonomía
        add_action( 'restrict_manage_posts', [ $this, 'taxonomy_filter_ui' ] );
        add_filter( 'parse_query',           [ $this, 'taxonomy_filter_query' ] );

        // Quick Edit
        add_action( 'quick_edit_custom_box', [ $this, 'quick_edit_field' ], 10, 2 );
        add_action( 'save_post_team',        [ $this, 'save_openalex_id' ] );

        // Row actions
        add_filter( 'post_row_actions', [ $this, 'row_actions' ], 10, 2 );

        // Scripts
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    // ── Columnas ──────────────────────────────────────────────────────────────

    public function add_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['openalex_id']        = 'OpenAlex ID';
                $new['openalex_last_sync'] = 'Última sync';
            }
        }
        return $new;
    }

    public function render_column( string $column, int $post_id ): void {
        if ( $column === 'openalex_id' ) {
            $id = get_post_meta( $post_id, 'openalex_id', true );
            echo $id
                ? '<code>' . esc_html( $id ) . '</code><span class="hidden openalex-id-raw">' . esc_attr( $id ) . '</span>'
                : '<span aria-hidden="true">—</span><span class="hidden openalex-id-raw"></span>';
        }

        if ( $column === 'openalex_last_sync' ) {
            $date = get_post_meta( $post_id, 'openalex_last_sync', true );
            echo $date
                ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $date ) ) )
                : '—';
        }
    }

    public function sortable_columns( array $columns ): array {
        $columns['openalex_id'] = 'openalex_id';
        return $columns;
    }

    public function sort_by_openalex_id( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( $query->get( 'post_type' ) !== 'team' ) return;
        if ( $query->get( 'orderby' ) === 'openalex_id' ) {
            $query->set( 'meta_key', 'openalex_id' );
            $query->set( 'orderby', 'meta_value' );
        }
    }

    // ── Filtro de taxonomía ───────────────────────────────────────────────────

    public function taxonomy_filter_ui( string $post_type ): void {
        if ( $post_type !== 'team' ) return;
        $selected = isset( $_GET['team_designation'] ) ? sanitize_text_field( $_GET['team_designation'] ) : '';
        $terms    = get_terms( [ 'taxonomy' => 'team_designation', 'hide_empty' => false ] );
        if ( empty( $terms ) || is_wp_error( $terms ) ) return;
        echo '<select name="team_designation"><option value="">' . esc_html__( 'Todos los equipos', 'openalex-team' ) . '</option>';
        foreach ( $terms as $t ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $t->slug ),
                selected( $selected, $t->slug, false ),
                esc_html( $t->name )
            );
        }
        echo '</select>';
    }

    public function taxonomy_filter_query( \WP_Query $query ): void {
        global $pagenow;
        if ( $pagenow !== 'edit.php' || empty( $_GET['team_designation'] ) ) return;
        if ( ( $query->query_vars['post_type'] ?? '' ) !== 'team' ) return;
        $query->set( 'tax_query', [ [
            'taxonomy' => 'team_designation',
            'field'    => 'slug',
            'terms'    => sanitize_text_field( $_GET['team_designation'] ),
        ] ] );
    }

    // ── Quick Edit ────────────────────────────────────────────────────────────

    public function quick_edit_field( string $column, string $post_type ): void {
        if ( $post_type !== 'team' || $column !== 'openalex_id' ) return;
        wp_nonce_field( 'openalex_quick_edit_nonce', 'openalex_quick_edit_nonce_field' );
        ?>
        <fieldset class="inline-edit-col-left">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php esc_html_e( 'OpenAlex ID', 'openalex-team' ); ?></span>
                    <span class="input-text-wrap">
                        <input type="text" name="openalex_id" class="ptitle" value="" placeholder="Ej: A123456789">
                    </span>
                </label>
            </div>
        </fieldset>
        <?php
    }

    public function save_openalex_id( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision( $post_id ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( ! isset( $_POST['openalex_id'] ) ) return;
        if (
            ! isset( $_POST['openalex_quick_edit_nonce_field'] ) ||
            ! wp_verify_nonce( $_POST['openalex_quick_edit_nonce_field'], 'openalex_quick_edit_nonce' )
        ) return;

        $raw_value = sanitize_text_field($_POST['openalex_id']);
        
        // NUEVO: Normalizar el formato
        // Aceptar: "A123|A456", "https://openalex.org/A123|A456", etc.
        $ids = array_map('trim', explode('|', $raw_value));
        $clean_ids = [];
        
        foreach ($ids as $id) {
            if (! $id) continue;
            $clean = strtoupper(basename($id));
            if ($clean) {
                $clean_ids[] = $clean;
            }
        }
        
        // Guardar en formato limpio: "A123|A456"
        $final_value = implode('|', array_unique($clean_ids));
        update_post_meta($post_id, 'openalex_id', $final_value);        
    }

    // ── Row actions ───────────────────────────────────────────────────────────

    public function row_actions( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== 'team' ) return $actions;
        if ( ! get_post_meta( $post->ID, 'openalex_id', true ) ) return $actions;
        $url = admin_url( 'admin.php?page=openalex-publications&post_id=' . $post->ID );
        $actions['openalex_view'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ver publicaciones', 'openalex-team' ) . '</a>';
        return $actions;
    }

    // ── Scripts admin ─────────────────────────────────────────────────────────

    public function enqueue_scripts( string $hook ): void {
        // check if we're on the team list page or the quick edit is open for team post type
        if (!($hook === 'team_page_openalex-publications')) return;
    
        wp_register_script( 'openalex-quick-edit-js', false, [ 'inline-edit-post' ], OPENALEX_TEAM_VERSION, true );
        wp_enqueue_script( 'openalex-quick-edit-js' );

        wp_localize_script('openalex-quick-edit-js', 'openalex_admin_vars', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('openalex_admin_nonce')
        ]);
        
        $quick_edit_js = '(function ($) {
            if (typeof inlineEditPost === "undefined") return;
            var $wpInlineEdit = inlineEditPost.edit;
            inlineEditPost.edit = function (id) {
                $wpInlineEdit.apply(this, arguments);
                var postId = (typeof id === "object") ? this.getId(id) : id;
                var currentId = $("#post-" + postId).find(".column-openalex_id .openalex-id-raw").text().trim();
                $("input[name=\"openalex_id\"]", "#edit-" + postId).val(currentId);
            };
        }(jQuery));';

        $polling_js = '(function ($) {
            if (!window.location.search.includes("page=openalex-publications")) return;
            const $table = $(".openalex-members-table");
            if ($table.length === 0) return;

            const activeStatuses = ["QUEUED", "RUNNING"];
            let previousState = {};
            let hasActive = false;

            $table.find("tbody tr").each(function() {
                const memberId = $(this).data("member-id");
                const status = $(this).find(".openalex-status-text").text().trim().toUpperCase();
                if (memberId && status) {
                    previousState[memberId] = status;
                    if (activeStatuses.includes(status)) hasActive = true;
                }
            });

            // Only start polling if at least one member is actively syncing.
            if (!hasActive || Object.keys(previousState).length === 0) return;

            function checkSyncStatus() {
                $.post(openalex_admin_vars.ajax_url, {
                    action: "openalex_check_sync_status",
                    nonce: openalex_admin_vars.nonce,
                    security: openalex_admin_vars.nonce,
                    post_ids: Object.keys(previousState)
                }, function(response) {
                    if (response.success) {
                        let hasChanges = false;
                        let stillActive = false;
                        $.each(response.data, function(postId, data) {
                            const newStatus = (data.status || "").toUpperCase();
                            if (previousState[postId] !== newStatus) hasChanges = true;
                            if (activeStatuses.includes(newStatus)) stillActive = true;
                        });

                        if (hasChanges) {
                            // Reload once to show the updated state.
                            // The page will not re-start polling unless new active jobs exist.
                            location.reload();
                        } else if (stillActive) {
                            // Jobs still running, keep polling.
                            setTimeout(checkSyncStatus, 5000);
                        }
                        // If no changes and no active jobs, stop polling silently.
                    } else {
                        setTimeout(checkSyncStatus, 10000);
                    }
                }).fail(function() {
                    setTimeout(checkSyncStatus, 10000);
                });
            }
            setTimeout(checkSyncStatus, 5000);
        }(jQuery));';

        wp_add_inline_script( 'openalex-quick-edit-js', $quick_edit_js . "\n" . $polling_js );
    }
}
