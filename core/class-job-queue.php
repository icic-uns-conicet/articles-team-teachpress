<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class OpenAlex_Job_Queue {
    const GROUP = 'openalex-team';
    const HOOK  = 'openalex_sync_member_background';

    public function __construct() {
        add_action( self::HOOK, [ $this, 'process_sync' ], 10, 1 );
    }

    public static function enqueue_member_sync( int $post_id ): array {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) {
            return [ 'queued' => false, 'action_id' => false, 'message' => __('Action Scheduler no está disponible.', "openalex-team") ];
        }

        if ( self::has_pending_job( $post_id ) ) {
            return [ 'queued' => false, 'action_id' => false, 'message' => __('Este miembro ya tiene una sincronización en curso o en cola.', "openalex-team") ];
        }

        update_post_meta( $post_id, 'openalex_sync_status', 'queued' );
        update_post_meta( $post_id, 'openalex_sync_message', __('En cola', "openalex-team") );
        update_post_meta( $post_id, 'openalex_sync_started_at', '' );
        update_post_meta( $post_id, 'openalex_sync_finished_at', '' );

        $action_id = as_enqueue_async_action( self::HOOK, [ 'post_id' => $post_id ], self::GROUP );

        if ( ! $action_id ) {
            update_post_meta( $post_id, 'openalex_sync_status', 'failed' );
            update_post_meta( $post_id, 'openalex_sync_message', __('No se pudo encolar la tarea.', "openalex-team") );
            return [ 'queued' => false, 'action_id' => false, 'message' => __('No se pudo encolar la tarea.', "openalex-team") ];
        }

        update_post_meta( $post_id, 'openalex_sync_action_id', (int) $action_id );
        return [ 'queued' => true, 'action_id' => (int) $action_id, 'message' => __('Sincronización encolada.', "openalex-team") ];
    }

    public function process_sync( $args ): void {
        $post_id = is_array( $args ) ? intval( $args['post_id'] ?? 0 ) : intval( $args );
        if ( ! $post_id ) return;

        update_post_meta( $post_id, 'openalex_sync_status', 'running' );
        update_post_meta( $post_id, 'openalex_sync_message', __('Procesando publicaciones...', "openalex-team") );
        update_post_meta( $post_id, 'openalex_sync_started_at', current_time( 'mysql' ) );

        try {
            if ( ! OpenAlex_Helpers::teachpress_active() ) {
                throw new Exception( __('teachPress no está activo.', "openalex-team") );
            }

            $result = OpenAlex_TeachPress_Import::sync_member( $post_id );
            OpenAlex_Helpers::clear_member_publications_cache( $post_id );

            update_post_meta( $post_id, 'openalex_sync_status', 'completed' );
            update_post_meta( $post_id, 'openalex_sync_message', sprintf(
                esc_html__( 'Completado. Nuevas: %d, actualizadas: %d, omitidas: %d', 'openalex-team' ),
                intval( $result['added'] ?? 0 ),
                intval( $result['updated'] ?? 0 ),
                intval( $result['skipped'] ?? 0 )
            ) );
            update_post_meta( $post_id, 'openalex_sync_finished_at', current_time( 'mysql' ) );
            update_post_meta( $post_id, 'openalex_sync_last_result', wp_json_encode( $result ) );
        } catch ( Throwable $e ) {
            update_post_meta( $post_id, 'openalex_sync_status', 'failed' );
            update_post_meta( $post_id, 'openalex_sync_message', $e->getMessage() );
            update_post_meta( $post_id, 'openalex_sync_finished_at', current_time( 'mysql' ) );
        }
    }

    public static function has_pending_job( int $post_id ): bool {
        if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
            return false;
        }

        $pending = as_get_scheduled_actions( [
            'hook' => self::HOOK,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'group' => self::GROUP,
            'args' => [ 'post_id' => $post_id ],
            'per_page' => 1,
        ], 'ids' );

        $running = as_get_scheduled_actions( [
            'hook' => self::HOOK,
            'status' => ActionScheduler_Store::STATUS_RUNNING,
            'group' => self::GROUP,
            'args' => [ 'post_id' => $post_id ],
            'per_page' => 1,
        ], 'ids' );

        return ! empty( $pending ) || ! empty( $running );
    }

    public static function get_member_status( int $post_id ): array {
        $status  = get_post_meta( $post_id, 'openalex_sync_status', true ) ?: 'idle';
        $message = get_post_meta( $post_id, 'openalex_sync_message', true ) ?: __( 'Sin actividad', 'openalex-team' );
        $start   = get_post_meta( $post_id, 'openalex_sync_started_at', true );
        $end     = get_post_meta( $post_id, 'openalex_sync_finished_at', true );

        if ( self::has_pending_job( $post_id ) && $status !== 'running' ) {
            $status = 'queued';
            $message = __('En cola', "openalex-team");
        }

        return [
            'status' => $status,
            'message' => $message,
            'started' => $start,
            'finished' => $end,
            'is_locked' => in_array( $status, [ 'queued', 'running' ], true ),
        ];
    }
}
