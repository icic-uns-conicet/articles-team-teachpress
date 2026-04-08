<?php
/**
 * Plugin Name: OpenAlex Team Publications
 * Description: Integra Team (tlp-team) con OpenAlex y prepara datos para teachPress
 * Version: 1.1
 */

if (!defined('ABSPATH')) exit;

class OpenAlexTeamPlugin {

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_save_author_id', [$this, 'save_author_id']);
    }

    public function menu() {
        add_menu_page(
            'OpenAlex Team',
            'OpenAlex Team',
            'manage_options',
            'openalex-team',
            [$this, 'page']
        );
    }

    private function get_team_members($team_filter = null) {
        $args = [
            'post_type' => 'team',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ];

        if ($team_filter) {
            $args['tax_query'] = [[
                'taxonomy' => 'team_designation',
                'field' => 'term_id',
                'terms' => $team_filter
            ]];
        }

        return get_posts($args);
    }

    private function get_team_terms() {
        return get_terms([
            'taxonomy' => 'team_designation',
            'hide_empty' => false
        ]);
    }

    public function page() {
        $selected_team = isset($_GET['team_filter']) ? intval($_GET['team_filter']) : null;

        $members = $this->get_team_members($selected_team);
        $teams = $this->get_team_terms();
        ?>
        <div class="wrap">
            <h1>Miembros del Team</h1>

            <form method="get">
                <input type="hidden" name="page" value="openalex-team">
                <select name="team_filter">
                    <option value="">Todos los equipos</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?php echo $t->term_id; ?>" <?php selected($selected_team, $t->term_id); ?>>
                            <?php echo esc_html($t->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button">Filtrar</button>
            </form>

            <br>

            <table class="widefat">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Equipos</th>
                        <th>OpenAlex ID</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody>
        <?php
        foreach ($members as $m) {
            $id = get_post_meta($m->ID, 'openalex_id', true);

            $terms = get_the_terms($m->ID, 'team_designation');
            $teams_names = [];
            if ($terms && !is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $teams_names[] = $t->name;
                }
            }

            ?>
            <tr>
                <td><?php echo esc_html($m->post_title); ?></td>
                <td><?php echo esc_html(implode(', ', $teams_names)); ?></td>
                <td>
                    <?php if ($id): ?>
                        <span><?php echo esc_html($id); ?></span>
                    <?php else: ?>
                        <a href="#" class="editinline" data-id="<?php echo $m->ID; ?>">No ID</a>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="hidden" id="inline-edit-<?php echo $m->ID; ?>">
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                            <input type="hidden" name="action" value="save_author_id">
                            <input type="hidden" name="post_id" value="<?php echo $m->ID; ?>">
                            <input type="text" name="openalex_id" placeholder="A123456...">
                            <button type="submit" class="button">Guardar</button>
                        </form>
                    </div>
                </td>
                <td>
                    <?php if ($id): ?>
                        <a class="button button-primary" href="<?php echo admin_url('admin.php?page=openalex-team&fetch=' . $m->ID); ?>">
                            Obtener publicaciones
                        </a>
                    <?php else: ?>
                        <span>Sin ID</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php
        }
        ?>
                </tbody>
            </table>
        </div>
        <?php

        if (isset($_GET['fetch'])) {
            $this->fetch_publications(intval($_GET['fetch']));
        }
    }

    public function save_author_id() {
        $post_id = intval($_POST['post_id']);
        $id = sanitize_text_field($_POST['openalex_id']);

        update_post_meta($post_id, 'openalex_id', $id);

        wp_redirect(admin_url('admin.php?page=openalex-team'));
        exit;
    }

    private function fetch_publications($post_id) {
        $author_id = get_post_meta($post_id, 'openalex_id', true);

        if (!$author_id) return;

        $author_id = basename($author_id);

        $url = "https://api.openalex.org/works?filter=author.id:$author_id";

        $response = wp_remote_get($url);
        if (is_wp_error($response)) return;

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        echo '<h2>Publicaciones encontradas</h2>';

        if (!empty($data['results'])) {
            echo '<ul>';
            foreach ($data['results'] as $work) {
                echo '<li>' . esc_html($work['title']) . '</li>';
            }
            echo '</ul>';
        }
    }
    
    public function enqueue_admin_scripts($hook) {
        // Check if we're on the correct admin page
        if ($hook !== 'toplevel_page_openalex-team') {
            return;
        }

        // Enqueue the inline-edit-post.js script
        wp_enqueue_script('inline-edit-post');
    }
}

new OpenAlexTeamPlugin();
add_action('admin_enqueue_scripts', [new OpenAlexTeamPlugin(), 'enqueue_admin_scripts']);
