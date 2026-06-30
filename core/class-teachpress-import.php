<?php

/**
 * Importación de publicaciones de OpenAlex a teachPress.
 *
 * Gestiona la deduplicación, el mapeo de campos y las relaciones autor↔pub.
 *
 * @package OpenAlexTeam
 */

if (! defined('ABSPATH')) exit;

class OpenAlex_TeachPress_Import
{

    /**
     * Importa los works de un miembro del team a teachPress.
     *
     * @param  int    $post_id  ID del post del CPT 'team'
     * @return array{member_name: string, total_found: int, added: int, updated: int, skipped: int, errors: string[]}
     */
    public static function sync_member(int $post_id): array
    {
        $result = [
            'member_name' => get_the_title($post_id),
            'total_found' => 0,
            'added'       => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'errors'      => [],
        ];

        $openalex_id = get_post_meta($post_id, 'openalex_id', true);
        if (! $openalex_id) {
            $result['errors'][] = __('El miembro no tiene OpenAlex ID.', 'openalex-team');
            return $result;
        }

        $fetch = OpenAlex_API::fetch_works($openalex_id);
        $result['errors']      = $fetch['errors'];
        $result['total_found'] = count($fetch['works']);

        foreach ($fetch['works'] as $work) {
            $status = self::import_work($work, $post_id);
            if ($status === 'added')        $result['added']++;
            elseif ($status === 'updated')  $result['updated']++;
            elseif ($status === 'skipped')  $result['skipped']++;
            else                              $result['errors'][] = $status;
        }

        update_post_meta($post_id, 'openalex_last_sync', current_time('mysql'));

        return $result;
    }

    /**
     * Importa un work individual.
     * Retorna 'added', 'skipped' o un string de error.
     */
    private static function import_work(array $work, int $member_post_id): string
    {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'teachpress_pub_meta';
        $pub_table  = $wpdb->prefix . 'teachpress_pub';

        $openalex_work_id = basename($work['id'] ?? '');
        if (! $openalex_work_id) return __('Trabajo sin ID, omitido.', 'openalex-team');

        // ── Deduplicación 1: por openalex_work_id ────────────────────────────
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT pub_id FROM {$meta_table}
             WHERE meta_key = 'openalex_work_id' AND meta_value = %s LIMIT 1",
            $openalex_work_id
        ));
        if ($existing) {
            $relation_added = self::ensure_member_relation((int) $existing, $member_post_id);
            $refreshed      = self::refresh_publication_author_meta((int) $existing, $work['authorships'] ?? []);
            return ($relation_added || $refreshed) ? 'updated' : 'skipped';
        }

        // ── Deduplicación 2: por DOI ─────────────────────────────────────────
        $doi = ltrim(str_replace('https://doi.org/', '', $work['doi'] ?? ''), '/');
        if ($doi) {
            $existing_doi = $wpdb->get_var($wpdb->prepare(
                "SELECT pub_id FROM {$pub_table} WHERE doi = %s LIMIT 1",
                $doi
            ));
            if ($existing_doi) {
                TP_Publications::add_pub_meta($existing_doi, 'openalex_work_id', $openalex_work_id);
                $relation_added = self::ensure_member_relation((int) $existing_doi, $member_post_id);
                $refreshed      = self::refresh_publication_author_meta((int) $existing_doi, $work['authorships'] ?? []);
                return ($relation_added || $refreshed) ? 'updated' : 'skipped';
            }
        }

        // ── Construir datos para teachPress ──────────────────────────────────
        $year       = intval($work['publication_year'] ?? 0);
        $biblio     = $work['biblio'] ?? [];
        $source     = $work['primary_location']['source'] ?? [];

        $primary_location = $work['primary_location'] ?? [];

        $source = $primary_location['source'] ?? [];

        if (! is_array($source)) {
            $source = [];
        }

        $source['display_name'] = $source['display_name'] ?? ($primary_location['raw_source_name'] ?? '');

        $pages_str  = (! empty($biblio['first_page']) && ! empty($biblio['last_page']))
            ? $biblio['first_page'] . '--' . $biblio['last_page']
            : ($biblio['first_page'] ?? '');
        $author_str = OpenAlex_Helpers::build_author_string($work['authorships'] ?? []);
        $abstract   = ! empty($work['abstract_inverted_index'])
            ? OpenAlex_Helpers::reconstruct_abstract($work['abstract_inverted_index'])
            : '';

        $data = [
            'type'      => OpenAlex_Helpers::map_pub_type($work['type'] ?? ''),
            'bibtex'    => OpenAlex_Helpers::generate_bibtex_key($author_str, $year, $work['title'] ?? ''),
            'title'     => $work['title'] ?? __('[Sin título]', 'openalex-team'),
            'author'    => $author_str,
            'doi'       => $doi,
            'url'       => $work['primary_location']['landing_page_url'] ?? '',
            'date'      => $year > 0 ? "{$year}-01-01" : '0000-00-00',
            'journal'   => $source['display_name'] ?? '',
            'volume'    => $biblio['volume'] ?? '',
            'issue'     => $biblio['issue'] ?? '',
            'pages'     => $pages_str,
            'publisher' => $source['host_organization_name'] ?? '',
            'abstract'  => $abstract,
            'status'    => 'published',
        ];

        // ── Insertar en teachPress ────────────────────────────────────────────
        $pub_id = TP_Publications::add_publication($data, '');
        if (! $pub_id) return __("Error al guardar '{$data['title']}' en teachPress.", 'openalex-team');

        // ── Metadatos de trazabilidad ────────────────────────────────────────
        TP_Publications::add_pub_meta($pub_id, 'openalex_work_id',   $openalex_work_id);
        TP_Publications::add_pub_meta($pub_id, 'openalex_member_id', $member_post_id);

        // ── Relaciones individuales autor↔publicación ────────────────────────
        self::save_author_relations($pub_id, $work['authorships'] ?? []);

        return 'added';
    }

    /**
     * Guarda relaciones individuales en teachpress_rel_pub_auth,
     * reutilizando autores existentes por nombre.
     */
    private static function save_author_relations(int $pub_id, array $authorships): void
    {
        global $wpdb;

        $authors_table = $wpdb->prefix . 'teachpress_authors';
        $rel_table     = $wpdb->prefix . 'teachpress_rel_pub_auth';
        $meta_table    = $wpdb->prefix . 'teachpress_pub_meta';

        $wpdb->delete($rel_table, ['pub_id' => $pub_id], ['%d']);

        foreach ($authorships as $authorship) {
            $display         = $authorship['author']['display_name'] ?? '';
            $openalex_author = basename($authorship['author']['id'] ?? '');

            if (! $display) continue;

            $formatted = OpenAlex_Helpers::format_author_name($display);
            $sort_name = OpenAlex_Helpers::get_sort_name($display);

            $author_id = $wpdb->get_var($wpdb->prepare(
                "SELECT author_id FROM {$authors_table} WHERE name = %s LIMIT 1",
                $formatted
            ));

            if (! $author_id) {
                $author_id = TP_Authors::add_author($formatted, $sort_name);
            }

            if ($author_id) {
                TP_Authors::add_author_relation($pub_id, (int) $author_id, 1, 0);

                // Guardar openalex_author_id como meta de la publicación
                // clave: openalex_author_id_{author_id} → valor: A123456789
                if ($openalex_author) {
                    $meta_key = 'openalex_author_id_' . (int) $author_id;
                    $exists   = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_id FROM {$meta_table}
                     WHERE pub_id = %d AND meta_key = %s LIMIT 1",
                        $pub_id,
                        $meta_key
                    ));

                    if (! $exists) {
                        $wpdb->insert($meta_table, [
                            'pub_id'     => $pub_id,
                            'meta_key'   => $meta_key,
                            'meta_value' => $openalex_author,
                        ], ['%d', '%s', '%s']);
                    }
                }
            }
        }
    }

    /**
     * Asegura que exista el meta openalex_member_id para una publicación
     * ya existente (evita duplicar la relación miembro↔publicación).
     */
    private static function ensure_member_relation(int $pub_id, int $member_post_id): bool
    {
        global $wpdb;
        $meta_table = $wpdb->prefix . 'teachpress_pub_meta';
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$meta_table}
             WHERE pub_id = %d AND meta_key = 'openalex_member_id' AND meta_value = %s LIMIT 1",
            $pub_id,
            $member_post_id
        ));
        if (! $exists) {
            TP_Publications::add_pub_meta($pub_id, 'openalex_member_id', $member_post_id);
            return true;
        }

        return false;
    }

    /**
     * Refresca meta de autor OpenAlex para una publicación ya existente.
     * Esto permite agregar metadatos perdidos cuando un miembro se agrega después.
     */
    private static function refresh_publication_author_meta(int $pub_id, array $authorships): bool
    {
        global $wpdb;

        if (empty($authorships)) {
            return false;
        }

        $authors_table = $wpdb->prefix . 'teachpress_authors';
        $rel_table     = $wpdb->prefix . 'teachpress_rel_pub_auth';
        $meta_table    = $wpdb->prefix . 'teachpress_pub_meta';
        $updated       = false;

        foreach ($authorships as $authorship) {
            $display         = $authorship['author']['display_name'] ?? '';
            $openalex_author = basename($authorship['author']['id'] ?? '');

            if (! $display) {
                continue;
            }

            $formatted = OpenAlex_Helpers::format_author_name($display);
            $sort_name = OpenAlex_Helpers::get_sort_name($display);

            $author_id = $wpdb->get_var($wpdb->prepare(
                "SELECT author_id FROM {$authors_table} WHERE name = %s LIMIT 1",
                $formatted
            ));

            if (! $author_id) {
                $author_id = TP_Authors::add_author($formatted, $sort_name);
            }

            if (! $author_id) {
                continue;
            }

            $relation_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT con_id FROM {$rel_table} WHERE pub_id = %d AND author_id = %d LIMIT 1",
                $pub_id,
                $author_id
            ));

            if (! $relation_exists) {
                TP_Authors::add_author_relation($pub_id, (int) $author_id, 1, 0);
                $updated = true;
            }

            if ($openalex_author) {
                $meta_key = 'openalex_author_id_' . (int) $author_id;
                $meta_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$meta_table}
                     WHERE pub_id = %d AND meta_key = %s LIMIT 1",
                    $pub_id,
                    $meta_key
                ));

                if (! $meta_exists) {
                    $wpdb->insert($meta_table, [
                        'pub_id'     => $pub_id,
                        'meta_key'   => $meta_key,
                        'meta_value' => $openalex_author,
                    ], ['%d', '%s', '%s']);
                    $updated = true;
                }
            }
        }

        return $updated;
    }
}
