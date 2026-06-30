<?php

/**
 * Inyección del listado de publicaciones en la página single
 * del CPT 'team' (generada por el plugin tlp-team).
 *
 * @package OpenAlexTeam
 */

if (! defined('ABSPATH')) exit;

class OpenAlex_Single_Team
{

    private $post_id;

    public function __construct()
    {
        add_filter('template_include',   [$this, 'intercept_template'], 99);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);

    }

    /**
     * Cuando se detecta single-team.php, registra el hook de inyección.
     */
    public function intercept_template(string $template): string
    {
        if (is_singular('team') && strpos(basename($template), 'single-team') !== false) {
            add_action('wp_footer', [$this, 'inject_publications'], 1);
        }
        return $template;
    }

    /**
     * Genera el bloque HTML e inyecta vía JS al final del contenedor del miembro.
     */
    public function inject_publications(): void
    {
        if (! is_singular('team')) return;
        if (! OpenAlex_Helpers::teachpress_active()) return;

        global $post;
        $this->post_id = (int) $post->ID;        

        $html_cache_key = 'openalex_member_pubs_html_' . $this->post_id;
        $html = get_transient($html_cache_key); // <---

        if ($html === false) {
            $pubs = OpenAlex_Helpers::get_member_publications($this->post_id);
            if (empty($pubs)) return;

            $html = $this->render_publications_html($pubs);
            set_transient($html_cache_key, $html, 12 * HOUR_IN_SECONDS);
        }

?>
        <?php
        // Output full HTML server-side so crawlers can index it.
        // Render here and then (for visual placement) move into the
        // first `.tlp-single-container` on the page using a minimal mover.
        echo '<div id="openalex_publications_raw">' . $html . '</div>';
        ?>
        <script id="openalex_publications">
            (function() {
                try {
                    var raw = document.getElementById('openalex_publications_raw');
                    if (!raw) return;
                    var target = document.querySelector('.tlp-single-container');
                    if (target) {
                        target.appendChild(raw);
                    } else {
                        // fallback: leave raw HTML in place (footer)
                    }
                } catch (e) {
                    // fail silently
                }
            })();
        </script>
    <?php
    }

    public function enqueue_styles(): void
    {
        if (! is_singular('team')) return;
        wp_register_style('openalex-frontend', false);
        wp_enqueue_style('openalex-frontend');
        wp_add_inline_style('openalex-frontend', OpenAlex_Helpers::get_publications_styles());
    }

    // ── HTML del bloque de publicaciones ─────────────────────────────────────

    private function render_publications_html(array $pubs): string
    {
        $by_year = [];
        foreach ($pubs as $pub) {
            $by_year[$pub->year ?: __('Sin año', "openalex-team")][] = $pub;
        }
        krsort($by_year);

        // 1 query total para todos los miembros del team
        $members_map = OpenAlex_Helpers::get_team_members_map();

        ob_start(); ?>
        <div class="openalex-publications">
            <h3 class="openalex-publications__title"><?php echo esc_html__('Publicaciones', 'openalex-team'); ?>
                <span class="openalex-publications__count">(<?php echo count($pubs); ?>)</span>
            </h3>
            <?php foreach ($by_year as $year => $year_pubs): ?>
                <div class="openalex-publications__year-group">
                    <h4 class="openalex-publications__year"><?php echo esc_html($year); ?></h4>
                    <ul class="openalex-publications__list">
                        <?php foreach ($year_pubs as $pub): ?>
                            <?php
                            // 1 query liviana por publicación: nombre → openalex_author_id
                            $name_to_id_map = OpenAlex_Helpers::get_pub_name_to_openalex_id((int) $pub->pub_id);
                            ?>
                            <li class="openalex-publications__item">
                                <span class="openalex-pub-type openalex-pub-type--<?php echo esc_attr($pub->type); ?>">
                                    <?php echo esc_html(OpenAlex_Helpers::get_type_label($pub->type)); ?>
                                </span>
                                <span class="openalex-pub-title">
                                    <?php if ($pub->doi): ?>
                                        <a href="https://doi.org/<?php echo esc_attr($pub->doi); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($pub->title); ?></a>
                                    <?php elseif ($pub->url): ?>
                                        <a href="<?php echo esc_url($pub->url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($pub->title); ?></a>
                                    <?php else: ?>
                                        <?php echo esc_html($pub->title); ?>
                                    <?php endif; ?>
                                </span>
                                <?php if ($pub->author): ?>
                                    <span class="openalex-pub-authors">
                                        <?php 
                                        echo OpenAlex_Helpers::format_author_list(
                                            $pub->author,
                                            true,
                                            $name_to_id_map,
                                            $members_map,
                                            $this->post_id   // ← excluye al miembro actual del enlazado
                                        ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($pub->journal): ?>
                                    <em class="openalex-pub-journal"><?php echo esc_html($pub->journal); ?></em>
                                <?php endif; ?>
                                <?php if ($pub->doi): ?>
                                    <a class="openalex-pub-doi" href="https://doi.org/<?php echo esc_attr($pub->doi); ?>" target="_blank" rel="noopener noreferrer">
                                        DOI: <?php echo esc_html($pub->doi); ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
<?php return ob_get_clean();
    }

}
