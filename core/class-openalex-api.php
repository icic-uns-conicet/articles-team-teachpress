<?php

/**
 * Comunicación con la API de OpenAlex.
 *
 * @package OpenAlexTeam
 */

if (! defined('ABSPATH')) exit;

class OpenAlex_API
{

    const MAX_PAGES     = 10;
    const PER_PAGE      = 200;
    const SELECT_FIELDS = 'id,title,type,publication_year,doi,authorships,biblio,primary_location,abstract_inverted_index';

    /**
     * Recupera todos los works de un autor.
     *
     * @param  string $openalex_author_id
     * @return array{works: array, errors: string[]}
     */
    public static function fetch_works(string $openalex_author_id): array
    {
        $author_id = basename(trim($openalex_author_id));
        $works     = [];
        $errors    = [];
        $cursor    = '*';

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $url = self::build_url([
                'filter'   => "author.id:{$author_id}",
                'per-page' => self::PER_PAGE,
                'cursor'   => $cursor,
                'select'   => self::SELECT_FIELDS,
            ]);

            $response = wp_remote_get($url, [
                'timeout' => 30,
                'headers' => self::get_headers(),
            ]);

            if (is_wp_error($response)) {
                $errors[] = 'Error de conexión: ' . $response->get_error_message();
                break;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                $errors[] = 'OpenAlex devolvió HTTP ' . $code . ': ' . wp_remote_retrieve_body($response);
                break;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($body['results'])) break;

            $works  = array_merge($works, $body['results']);
            $cursor = $body['meta']['next_cursor'] ?? null;

            if (! $cursor) break;
        }

        return compact('works', 'errors');
    }

    /**
     * Arma la URL con api_key y mailto si existen.
     */
    private static function build_url(array $args): string
    {
        $api_key = class_exists('OpenAlex_Settings') ? OpenAlex_Settings::get_api_key() : '';
        $mailto  = class_exists('OpenAlex_Settings') ? OpenAlex_Settings::get_mailto()  : '';

        if (! empty($api_key)) {
            $args['api_key'] = $api_key;
        }

        if (! empty($mailto)) {
            $args['mailto'] = $mailto;
        }

        return add_query_arg($args, 'https://api.openalex.org/works');
    }

    /**
     * User-Agent opcional con mailto.
     */
    private static function get_headers(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        $mailto = class_exists('OpenAlex_Settings') ? OpenAlex_Settings::get_mailto() : '';
        $api_key = class_exists('OpenAlex_Settings') ? OpenAlex_Settings::get_api_key() : '';

        if ($mailto) {
            $headers['User-Agent'] = 'OpenAlexTeamPlugin/4.0 (mailto:' . $mailto . ')';
        } else {
            $headers['User-Agent'] = 'OpenAlexTeamPlugin/4.0';
        }

        if ($api_key) {
            $headers['Authorization'] = 'Bearer ' . $api_key;
        }

        return $headers;
    }
}
