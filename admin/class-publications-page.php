<?php
/**
 * Página de administración "Publicaciones OpenAlex"
 * (submenú bajo el CPT team).
 *
 * @package OpenAlexTeam
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAlex_Publications_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_post_openalex_save_visibility', [ $this, 'save_visibility' ] );
    }

    public function register_menu(): void {
        add_submenu_page(
            'edit.php?post_type=team',
            'Publicaciones OpenAlex',
            'Publicaciones OpenAlex',
            'manage_options',
            'openalex-publications',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;

        echo '<div class="wrap"><h1>' . esc_html__( 'Publicaciones OpenAlex', 'openalex-team' ) . '</h1>';

        $this->maybe_show_sync_notice();

        if ( $post_id ) {
            $this->render_member_detail( $post_id );
        } else {
            $this->render_members_list();
        }

        echo '</div>';
    }

    private function maybe_show_sync_notice(): void {
        $key    = 'openalex_sync_result_' . get_current_user_id();
        $result = get_transient( $key );
        if ( ! $result ) return;
        delete_transient( $key );

        $type = empty( $result['errors'] ) ? 'success' : 'warning';
        echo '<div class="notice notice-' . $type . ' is-dismissible"><p>';
        echo '<strong>' . esc_html( $result['member_name'] ) . '</strong> — ';
        echo 'Encontradas: <strong>' . intval( $result['total_found'] ) . '</strong>. ';
        echo 'Nuevas: <strong>' . intval( $result['added'] ) . '</strong>. ';
        echo 'Ya existían: <strong>' . intval( $result['skipped'] ) . '</strong>.';
        if ( ! empty( $result['errors'] ) ) {
            echo '<br><span style="color:#8a1a0a;">⚠ ' . esc_html( implode( '; ', $result['errors'] ) ) . '</span>';
        }
        echo '</p></div>';
    }

    private function render_members_list(): void {
        $members = get_posts( [
            'post_type'   => 'team',
            'numberposts' => -1,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'meta_query'  => [ [ 'key' => 'openalex_id', 'value' => '', 'compare' => '!=' ] ],
        ] );

        if ( empty( $members ) ) {
            echo '<div class="notice notice-info inline"><p>'
               . 'No hay miembros con OpenAlex ID. '
               . '<a href="' . esc_url( admin_url( 'edit.php?post_type=team' ) ) . '">Asignalos desde la lista de Team</a>.'
               . '</p></div>';
            return;
        }
        ?>
        <p>Miembros con OpenAlex ID: <strong><?php echo count( $members ); ?></strong></p>
        <table class="widefat striped">
            <thead><tr>
                <th>Nombre</th><th>Equipos</th><th>OpenAlex ID</th><th>Última sync</th><th>Acción</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $members as $m ):
                $openalex_id = get_post_meta( $m->ID, 'openalex_id', true );
                $last_sync   = get_post_meta( $m->ID, 'openalex_last_sync', true );
                $terms       = get_the_terms( $m->ID, 'team_designation' );
                $team_names  = ( ! empty( $terms ) && ! is_wp_error( $terms ) )
                               ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '—';
            ?>
            <tr>
                <td><strong><?php echo esc_html( $m->post_title ); ?></strong></td>
                <td><?php echo esc_html( $team_names ); ?></td>
                <td><code><?php echo esc_html( $openalex_id ); ?></code></td>
                <td><?php echo $last_sync
                    ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $last_sync ) ) )
                    : '<em style="color:#8c8f94;">Nunca</em>'; ?></td>
                <td>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action"  value="openalex_sync">
                        <input type="hidden" name="post_id" value="<?php echo $m->ID; ?>">
                        <?php wp_nonce_field( 'openalex_sync_' . $m->ID, 'openalex_sync_nonce' ); ?>
                        <button type="submit" class="button button-primary">
                            <?php echo $last_sync ? '↻ Re-sincronizar' : '⬇ Sincronizar'; ?>
                        </button>
                    </form>
                    <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=openalex-publications&post_id=' . $m->ID ) ); ?>">
                        Ver publicaciones
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_member_detail( int $post_id ): void {
        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'team' ) {
            echo '<div class="notice notice-error inline"><p>Miembro no encontrado.</p></div>';
            return;
        }

        $openalex_id = get_post_meta( $post_id, 'openalex_id', true );
        $last_sync   = get_post_meta( $post_id, 'openalex_last_sync', true );

        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=openalex-publications' ) ) . '">← Volver</a></p>';
        echo '<h2>' . esc_html( $post->post_title ) . '</h2>';

        if ( ! $openalex_id ) {
            echo '<div class="notice notice-warning inline"><p>Este miembro no tiene OpenAlex ID.</p></div>';
            return;
        }

        echo '<p><strong>OpenAlex ID:</strong> <code>' . esc_html( $openalex_id ) . '</code>';
        if ( $last_sync ) {
            echo ' &nbsp;|&nbsp; <strong>Última sync:</strong> '
               . esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', strtotime( $last_sync ) ) );
        }
        echo '</p>';
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
            <input type="hidden" name="action"   value="openalex_sync">
            <input type="hidden" name="post_id"  value="<?php echo $post_id; ?>">
            <input type="hidden" name="redirect" value="detail">
            <?php wp_nonce_field( 'openalex_sync_' . $post_id, 'openalex_sync_nonce' ); ?>
            <button type="submit" class="button button-primary">
                <?php echo $last_sync ? '↻ Re-sincronizar publicaciones' : '⬇ Sincronizar publicaciones'; ?>
            </button>
        </form>
        <?php

        if ( ! OpenAlex_Helpers::teachpress_active() ) return;

        $pubs = OpenAlex_Helpers::get_member_publications( $post_id, false );
        if ( empty( $pubs ) ) {
            echo '<p><em>No hay publicaciones importadas aún.</em></p>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
        echo '<input type="hidden" name="action" value="openalex_save_visibility">';
        echo '<input type="hidden" name="post_id" value="' . intval( $post_id ) . '">';
        wp_nonce_field( 'openalex_save_visibility_' . $post_id, 'openalex_visibility_nonce' );

        echo '<p>Publicaciones en teachPress: <strong>' . count( $pubs ) . '</strong></p>';
        echo '<table class="widefat striped"><thead><tr><th>Título</th><th>Tipo</th><th>Año</th><th>DOI</th><th>Ocultar del listado</th></tr></thead><tbody>';

        foreach ( $pubs as $pub ) {
            $hidden = OpenAlex_Helpers::is_publication_hidden( (int) $pub->pub_id );

            echo '<tr>';
            echo '<td>' . esc_html( $pub->title ) . '</td>';
            echo '<td><code>' . esc_html( $pub->type ) . '</code></td>';
            echo '<td>' . esc_html( $pub->year ) . '</td>';
            echo '<td>' . (
                $pub->doi
                    ? '<a href="https://doi.org/' . esc_attr( $pub->doi ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $pub->doi ) . '</a>'
                    : '—'
            ) . '</td>';
            echo '<td>';
            echo '<label>';
            echo '<input type="checkbox" name="hidden_pubs[]" value="' . intval( $pub->pub_id ) . '" ' . checked( $hidden, true, false ) . '>';
            echo ' Ocultar';
            echo '</label>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p style="margin-top:12px;"><button type="submit" class="button button-primary">Guardar visibilidad</button></p>';
        echo '</form>';
    }

    public function save_visibility(): void {
        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( ! $post_id ) {
            wp_die( 'ID inválido.', 400 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', 403 );
        }

        if (
            ! isset( $_POST['openalex_visibility_nonce'] ) ||
            ! wp_verify_nonce( $_POST['openalex_visibility_nonce'], 'openalex_save_visibility_' . $post_id )
        ) {
            wp_die( 'Nonce inválido.', 403 );
        }

        $pubs = OpenAlex_Helpers::get_member_publications( $post_id, false );
        $selected = isset( $_POST['hidden_pubs'] ) ? array_map( 'intval', (array) $_POST['hidden_pubs'] ) : [];

        foreach ( $pubs as $pub ) {
            $pub_id = (int) $pub->pub_id;
            OpenAlex_Helpers::set_publication_hidden( $pub_id, in_array( $pub_id, $selected, true ) );
        }

        wp_redirect( admin_url( 'admin.php?page=openalex-publications&post_id=' . $post_id . '&updated=1' ) );
        exit;
    }
}