<?php
/**
 * Handler del formulario de sincronización (admin-post).
 *
 * Encola sincronizaciones en background.
 *
 * @package OpenAlexTeam
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAlex_Admin_Sync {

    public function __construct() {
        add_action( 'admin_post_openalex_sync', [ $this, 'handle_sync' ] );
    }

    public function handle_sync(): void {
        $post_id = intval( $_POST['post_id'] ?? 0 );

        if ( ! $post_id || ! wp_verify_nonce( $_POST['openalex_sync_nonce'] ?? '', 'openalex_sync_' . $post_id ) ) {
            wp_die( 'Solicitud no válida.', 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.', 403 );
        }

        $queue = OpenAlex_Job_Queue::enqueue_member_sync( $post_id );

        set_transient( 'openalex_sync_result_' . get_current_user_id(), [
            'member_name' => get_the_title( $post_id ),
            'errors'      => $queue['queued'] ? [] : [ $queue['message'] ],
            'notice'      => $queue['queued']
                ? sprintf(
                    __('La sincronización del miembro %s fue encolada.', "openalex-team"),
                    get_the_title( $post_id )
                )
                : $queue['message'],
        ], 60 );

        $is_detail = ( ( $_POST['redirect'] ?? '' ) === 'detail' );
        $redirect  = $is_detail
            ? admin_url( 'admin.php?page=openalex-publications&post_id=' . $post_id )
            : admin_url( 'admin.php?page=openalex-publications' );

        wp_redirect( $redirect );
        exit;
    }
}