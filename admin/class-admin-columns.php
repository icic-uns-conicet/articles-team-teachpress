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

        // CSV import/export
        add_action( 'admin_post_openalex_import_team_members_csv', [ $this, 'handle_import_csv' ] );
        add_action( 'admin_post_openalex_export_team_members_csv', [ $this, 'handle_export_csv' ] );
        add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );

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
                $new['openalex_id']        = __('ID OpenAlex', "openalex-team");
                $new['openalex_last_sync'] = __('Última sincr.', "openalex-team");
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

        if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) : ?>
            <select name="team_designation">
                <option value=""><?php echo esc_html__( 'Todos los equipos', 'openalex-team' ); ?></option>
                <?php foreach ( $terms as $t ) : ?>
                    <option value="<?php echo esc_attr( $t->slug ); ?>"<?php selected( $selected, $t->slug, true ); ?>><?php echo esc_html( $t->name ); ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>

        <div class="alignleft actions" style="margin-right:12px; display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap;">
            <?php wp_nonce_field( 'openalex_import_team_members_csv', 'openalex_import_team_members_csv_nonce' ); ?>
            <label class="screen-reader-text" for="openalex-members-csv"><?php esc_html_e( 'CSV de miembros', 'openalex-team' ); ?></label>
            <input type="file" name="openalex_members_csv" id="openalex-members-csv" accept=".csv,text/csv" />
            <button type="submit" class="button" name="action" value="openalex_import_team_members_csv" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" formenctype="multipart/form-data">
                <?php esc_html_e( 'Importar custom fields', 'openalex-team' ); ?>
            </button>

            <?php wp_nonce_field( 'openalex_export_team_members_csv', 'openalex_export_team_members_csv_nonce' ); ?>
            <button type="submit" class="button button-secondary" name="action" value="openalex_export_team_members_csv" formaction="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" formmethod="post" style="margin-left:8px;">
                <?php esc_html_e( 'Descargar CSV', 'openalex-team' ); ?>
            </button>
        </div><?php
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

    public function handle_import_csv(): void {
        OpenAlex_Helpers::log( 'Iniciando importación de CSV de miembros del equipo...' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', 403 );
        }

        if (
            ! isset( $_POST['openalex_import_team_members_csv_nonce'] ) ||
            ! wp_verify_nonce( $_POST['openalex_import_team_members_csv_nonce'], 'openalex_import_team_members_csv' )
        ) {
            wp_die( 'Solicitud no válida.', 403 );
        }

        $upload_error = isset( $_FILES['openalex_members_csv']['error'] ) ? (int) $_FILES['openalex_members_csv']['error'] : UPLOAD_ERR_NO_FILE;

        if ( $upload_error !== UPLOAD_ERR_OK ) {
            wp_redirect( add_query_arg(
                [
                    'post_type'                       => 'team',
                    'openalex_members_csv_status'     => 'error',
                    'openalex_members_csv_upload_err' => $upload_error,
                ],
                admin_url( 'edit.php' )
            ) );
            exit;
        }

        if ( empty( $_FILES['openalex_members_csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['openalex_members_csv']['tmp_name'] ) ) {
            wp_redirect( add_query_arg(
                [
                    'post_type'                       => 'team',
                    'openalex_members_csv_status'     => 'error',
                    'openalex_members_csv_upload_err' => UPLOAD_ERR_NO_FILE,
                ],
                admin_url( 'edit.php' )
            ) );
            exit;
        }

        $rows = $this->parse_import_csv( $_FILES['openalex_members_csv']['tmp_name'] );
        if ( empty( $rows ) ) {
            wp_redirect( add_query_arg( [ 'post_type' => 'team', 'openalex_members_csv_status' => 'error' ], admin_url( 'edit.php' ) ) );
            exit;
        }

        $updated = 0;
        $skipped = 0;
        $allowed_fields = [ 'openalex_id', 'googlescholar_id', 'conicet_ficha', 'orc_id' ];

        foreach ( $rows as $row ) {
            $post_id = isset( $row['id_post'] ) ? absint( $row['id_post'] ) : 0;
            OpenAlex_Helpers::log( 'Procesando fila de importación... id_post: ' . $post_id );
            if ( $post_id < 1 ) {
                $skipped++;
                continue;
            }

            $team_post = get_post( $post_id );
            if ( ! $team_post || $team_post->post_type !== 'team' ) {
                $skipped++;
                continue;
            }

            foreach ( $allowed_fields as $field_name ) {
                if ( ! array_key_exists( $field_name, $row ) ) {
                    continue;
                }

                $value = trim( (string) $row[ $field_name ] );
                if ( $value === '' ) {
                    continue;
                }

                $this->save_member_custom_field( $post_id, $field_name, $value );
                $updated++;
            }
        }

        wp_redirect(
            add_query_arg(
                [
                    'post_type' => 'team',
                    'openalex_members_csv_status' => 'imported',
                    'openalex_members_csv_updated' => $updated,
                    'openalex_members_csv_skipped' => $skipped,
                ],
                admin_url( 'edit.php' )
            )
        );
        exit;
    }

    public function handle_export_csv(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', 403 );
        }

        if (
            ! isset( $_POST['openalex_export_team_members_csv_nonce'] ) ||
            ! wp_verify_nonce( $_POST['openalex_export_team_members_csv_nonce'], 'openalex_export_team_members_csv' )
        ) {
            wp_die( 'Solicitud no válida.', 403 );
        }

        $team_posts = get_posts( [
            'post_type'      => 'team',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ] );

        $columns = [ 'id_post', 'title', 'openalex_id', 'googlescholar_id', 'conicet_ficha', 'orc_id' ];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="team-members.csv"' );

        $handle = fopen( 'php://output', 'w' );
        if ( ! $handle ) {
            wp_die( 'No se pudo generar el CSV.', 500 );
        }

        fputcsv( $handle, $columns );

        foreach ( $team_posts as $team_post ) {
            $row = [
                (string) $team_post->ID,
                (string) get_the_title( $team_post->ID ),
                (string) get_post_meta( $team_post->ID, 'openalex_id', true ),
                (string) get_post_meta( $team_post->ID, 'googlescholar_id', true ),
                (string) get_post_meta( $team_post->ID, 'conicet_ficha', true ),
                (string) get_post_meta( $team_post->ID, 'orc_id', true ),
            ];
            fputcsv( $handle, $row, ',', '"', '\\' );
        }

        fclose( $handle );
        exit;
    }

    public function render_admin_notice(): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || $screen->base !== 'edit' || $screen->post_type !== 'team' ) {
            return;
        }

        $status = isset( $_GET['openalex_members_csv_status'] ) ? sanitize_text_field( wp_unslash( $_GET['openalex_members_csv_status'] ) ) : '';
        if ( $status === 'imported' ) {
            $updated = absint( $_GET['openalex_members_csv_updated'] ?? 0 );
            $skipped = absint( $_GET['openalex_members_csv_skipped'] ?? 0 );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                esc_html__( 'Se actualizaron %1$d campos desde el CSV. Se omitieron %2$d filas sin un id_post válido.', 'openalex-team' ),
                $updated,
                $skipped
            ) . '</p></div>';
            return;
        }

        if ( $status === 'error' ) {
            $upload_err  = isset( $_GET['openalex_members_csv_upload_err'] ) ? (int) $_GET['openalex_members_csv_upload_err'] : null;
            $upload_msgs = [
                UPLOAD_ERR_INI_SIZE   => __( 'El archivo supera el límite permitido por el servidor (upload_max_filesize).', 'openalex-team' ),
                UPLOAD_ERR_FORM_SIZE  => __( 'El archivo supera el límite indicado en el formulario.', 'openalex-team' ),
                UPLOAD_ERR_PARTIAL    => __( 'El archivo se subió de forma incompleta. Intentá de nuevo.', 'openalex-team' ),
                UPLOAD_ERR_NO_FILE    => __( 'No se seleccionó ningún archivo.', 'openalex-team' ),
                UPLOAD_ERR_NO_TMP_DIR => __( 'Falta el directorio temporal en el servidor.', 'openalex-team' ),
                UPLOAD_ERR_CANT_WRITE => __( 'No se pudo escribir el archivo en el disco del servidor.', 'openalex-team' ),
                UPLOAD_ERR_EXTENSION  => __( 'Una extensión de PHP bloqueó la subida del archivo.', 'openalex-team' ),
            ];

            $detail = ( $upload_err !== null && isset( $upload_msgs[ $upload_err ] ) )
                ? $upload_msgs[ $upload_err ]
                : __( 'No fue posible procesar el CSV. Revisa el formato o el archivo.', 'openalex-team' );

            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $detail ) . '</p></div>';
        }
    }

    private function parse_import_csv( string $file_path ): array {
        if ( ! file_exists( $file_path ) ) {
            return [];
        }

        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return [];
        }

        $delimiter = $this->detect_csv_delimiter( $file_path );
        $headers = fgetcsv( $handle, 0, $delimiter );
        if ( empty( $headers ) ) {
            fclose( $handle );
            return [];
        }

        $normalized_headers = [];
        foreach ( $headers as $index => $header ) {
            $normalized_headers[ $index ] = strtolower( trim( (string) preg_replace( '/^\xEF\xBB\xBF/', '', $header ) ) );
        }

        $rows = [];
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            if ( empty( array_filter( $row, static function ( $value ): bool {
                return $value !== null && $value !== '';
            } ) ) ) {
                continue;
            }

            $mapped_row = [];
            foreach ( $normalized_headers as $index => $header_name ) {
                if ( ! isset( $row[ $index ] ) ) {
                    continue;
                }

                if ( in_array( $header_name, [ 'id_post', 'openalex_id', 'googlescholar_id', 'conicet_ficha', 'orc_id' ], true ) ) {
                    $mapped_row[ $header_name ] = trim( (string) $row[ $index ] );
                }
            }

            $rows[] = $mapped_row;
        }

        fclose( $handle );
        return $rows;
    }

    private function detect_csv_delimiter( string $file_path ): string {
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return ',';
        }

        $line = fgets( $handle );
        fclose( $handle );

        if ( $line === false ) {
            return ',';
        }

        $comma_count = substr_count( $line, ',' );
        $semicolon_count = substr_count( $line, ';' );

        return $semicolon_count > $comma_count ? ';' : ',';
    }

    private function save_member_custom_field( int $post_id, string $meta_key, string $value ): void {
        if ( function_exists( 'update_field' ) ) {
            $updated = update_field( $meta_key, $value, $post_id );
            if ( $updated !== false ) {
                return;
            }
        }

        update_post_meta( $post_id, $meta_key, $value );
    }

    // ── Quick Edit ────────────────────────────────────────────────────────────

    public function quick_edit_field( string $column, string $post_type ): void {
        if ( $post_type !== 'team' || $column !== 'openalex_id' ) return;
        wp_nonce_field( 'openalex_quick_edit_nonce', 'openalex_quick_edit_nonce_field' );
        ?>
        <fieldset class="inline-edit-col-left">
            <div class="inline-edit-col">
                <label>
                    <span class="title"><?php esc_html_e( 'ID OpenAlex', 'openalex-team' ); ?></span>
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
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_team_list = $screen && $screen->base === 'edit' && $screen->post_type === 'team';
        $is_publications_page = $hook === 'team_page_openalex-publications';

        if ( ! $is_team_list && ! $is_publications_page ) {
            return;
        }

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
