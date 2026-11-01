<?php
/**
 * Plugin Name: Basic OpenGraph by Techlaju
 * Description: Adds Open Graph tags with a selected logo, site name + page title, and excerpt or page text for description.
 * Version: 1.1.0
 * Author: Techlaju
 * Author URI: https://www.techlaju.com
 * Plugin URI: https://www.techlaju.com
 * Text Domain: basic-opengraph
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BOG_VERSION', '1.1.0');
define('BOG_TECHLAJU_URL', 'https://www.techlaju.com');
define('BOG_LICENSE_SERVER_URL', 'https://lesen.techlaju.com');
define('BOG_PLUGIN_FILE', __FILE__);
define('BOG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOG_OPTION_LOGO', 'bog_logo_id');
define('BOG_OPTION_TITLE_SEP', 'bog_title_separator');
define('BOG_OPTION_DESCRIPTION', 'bog_default_description');
define('BOG_META_DESCRIPTION', '_bog_og_description');

require_once BOG_PLUGIN_DIR . 'includes/class-bog-license.php';

register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook(BOG_License::CRON_HOOK);
});

final class Basic_OpenGraph
{
    public static function register(): void
    {
        BOG_License::register();

        add_action('admin_menu', [self::class, 'register_settings_page']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_admin_assets']);
        add_action('add_meta_boxes', [self::class, 'register_meta_box']);
        add_action('save_post', [self::class, 'save_meta_box']);
        add_filter('plugin_row_meta', [self::class, 'plugin_row_meta'], 10, 2);
        add_action('wp_head', [self::class, 'render_meta'], 1);
    }

    public static function plugin_row_meta(array $links, string $file): array
    {
        if ($file !== plugin_basename(BOG_PLUGIN_FILE)) {
            return $links;
        }

        $links[] = '<a href="' . esc_url(BOG_TECHLAJU_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Techlaju', 'basic-opengraph') . '</a>';
        $links[] = '<a href="' . esc_url(admin_url('options-general.php?page=basic-opengraph')) . '">' . esc_html__('Settings', 'basic-opengraph') . '</a>';

        return $links;
    }

    public static function register_settings_page(): void
    {
        add_options_page(
            __('OpenGraph', 'basic-opengraph'),
            __('OpenGraph', 'basic-opengraph'),
            'manage_options',
            'basic-opengraph',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('bog_settings', BOG_OPTION_LOGO, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0,
        ]);

        register_setting('bog_settings', BOG_OPTION_TITLE_SEP, [
            'type' => 'string',
            'sanitize_callback' => static function ($value) {
                $value = sanitize_text_field((string) $value);
                return $value !== '' ? $value : '|';
            },
            'default' => '|',
        ]);

        register_setting('bog_settings', BOG_OPTION_DESCRIPTION, [
            'type' => 'string',
            'sanitize_callback' => static function ($value) {
                return sanitize_textarea_field((string) $value);
            },
            'default' => '',
        ]);
    }

    public static function register_meta_box(): void
    {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            add_meta_box(
                'bog_og_description',
                __('Open Graph description', 'basic-opengraph'),
                [self::class, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box(WP_Post $post): void
    {
        $value = (string) get_post_meta($post->ID, BOG_META_DESCRIPTION, true);
        wp_nonce_field('bog_save_og_description', 'bog_og_description_nonce');
        ?>
        <p>
            <label for="bog_og_description" class="screen-reader-text"><?php esc_html_e('Open Graph description', 'basic-opengraph'); ?></label>
            <textarea
                class="widefat"
                rows="4"
                id="bog_og_description"
                name="bog_og_description"
                maxlength="200"
            ><?php echo esc_textarea($value); ?></textarea>
        </p>
        <p class="description"><?php esc_html_e('Custom text for social previews. Leave blank to use excerpt or page content.', 'basic-opengraph'); ?></p>
        <?php
    }

    public static function save_meta_box(int $post_id): void
    {
        if (!isset($_POST['bog_og_description_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bog_og_description_nonce'])), 'bog_save_og_description')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (!isset($_POST['bog_og_description'])) {
            return;
        }

        $value = sanitize_textarea_field(wp_unslash($_POST['bog_og_description']));

        if ($value === '') {
            delete_post_meta($post_id, BOG_META_DESCRIPTION);
            return;
        }

        update_post_meta($post_id, BOG_META_DESCRIPTION, $value);
    }

    public static function enqueue_admin_assets(string $hook): void
    {
        if ($hook !== 'settings_page_basic-opengraph') {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style(
            'bog-admin',
            BOG_PLUGIN_URL . 'assets/admin.css',
            [],
            BOG_VERSION
        );
        wp_enqueue_script(
            'bog-admin',
            BOG_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            BOG_VERSION,
            true
        );
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['bog_license_updated'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('License settings updated.', 'basic-opengraph') . '</p></div>';
        }

        $logo_id = (int) get_option(BOG_OPTION_LOGO, 0);
        $separator = (string) get_option(BOG_OPTION_TITLE_SEP, '|');
        $default_description = (string) get_option(BOG_OPTION_DESCRIPTION, '');
        $logo_url = $logo_id > 0 ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        $license_key = get_option(BOG_License::OPTION_KEY, '');
        $license_status = BOG_License::get_status();
        $is_valid = !empty($license_status['valid']);
        ?>
        <div class="wrap bog-settings">
            <h1><?php esc_html_e('Basic OpenGraph by Techlaju', 'basic-opengraph'); ?></h1>

            <div class="bog-card">
                <h2><?php esc_html_e('License', 'basic-opengraph'); ?></h2>
                <p class="bog-status <?php echo $is_valid ? 'is-valid' : 'is-invalid'; ?>">
                    <?php echo esc_html($license_status['message'] ?? __('Not licensed.', 'basic-opengraph')); ?>
                </p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('This website', 'basic-opengraph'); ?></th>
                        <td><code><?php echo esc_html(BOG_License::get_site_domain()); ?></code></td>
                    </tr>
                    <?php if (!empty($license_status['licensed_domain'])) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Licensed for', 'basic-opengraph'); ?></th>
                            <td><code><?php echo esc_html($license_status['licensed_domain']); ?></code></td>
                        </tr>
                    <?php endif; ?>
                    <?php if (!empty($license_status['expires'])) : ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Expires', 'basic-opengraph'); ?></th>
                            <td><?php echo esc_html(gmdate('j M Y', (int) $license_status['expires'])); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>

                <?php if ($license_key !== '') : ?>
                    <p><code><?php echo esc_html(BOG_License::mask_code($license_key)); ?></code></p>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('bog_license_action'); ?>
                    <input type="hidden" name="action" value="bog_activate_license">
                    <p>
                        <label for="bog_license_key"><strong><?php esc_html_e('License code', 'basic-opengraph'); ?></strong></label><br>
                        <input
                            type="<?php echo $license_key !== '' ? 'password' : 'text'; ?>"
                            class="regular-text code"
                            id="bog_license_key"
                            name="bog_license_key"
                            value=""
                            placeholder="<?php echo $license_key !== '' ? esc_attr__('Enter new code to replace', 'basic-opengraph') : 'TLJ-XXXX-XXXX-XXXX-XXXX'; ?>"
                            autocomplete="off"
                            spellcheck="false"
                        >
                    </p>
                    <?php submit_button(__('Verify License', 'basic-opengraph'), 'primary', 'submit', false); ?>
                    <p class="description">
                        <?php
                        echo wp_kses(
                            sprintf(
                                /* translators: 1: Techlaju link, 2: plugin slug */
                                __('Enter the TLJ license code issued by %1$s for this domain. Plugin slug on the license server: %2$s', 'basic-opengraph'),
                                '<a href="' . esc_url(BOG_TECHLAJU_URL) . '" target="_blank" rel="noopener noreferrer">Techlaju</a>',
                                '<code>' . esc_html(BOG_License::PLUGIN_SLUG) . '</code>'
                            ),
                            [
                                'a' => ['href' => true, 'target' => true, 'rel' => true],
                                'code' => [],
                            ]
                        );
                        ?>
                    </p>
                </form>

                <?php if ($license_key !== '') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                        <?php wp_nonce_field('bog_license_action'); ?>
                        <input type="hidden" name="action" value="bog_deactivate_license">
                        <?php submit_button(__('Remove License', 'basic-opengraph'), 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <div class="bog-card">
                <h2><?php esc_html_e('Open Graph settings', 'basic-opengraph'); ?></h2>
                <?php if (!$is_valid) : ?>
                    <p class="description"><?php esc_html_e('Activate a valid license to output Open Graph tags on the frontend.', 'basic-opengraph'); ?></p>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php settings_fields('bog_settings'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php esc_html_e('Open Graph logo / image', 'basic-opengraph'); ?></th>
                            <td>
                                <div id="bog-logo-preview" style="margin-bottom:12px;">
                                    <?php if ($logo_url) : ?>
                                        <img src="<?php echo esc_url($logo_url); ?>" alt="" style="max-width:240px;height:auto;border:1px solid #c3c4c7;border-radius:6px;">
                                    <?php else : ?>
                                        <em><?php esc_html_e('No image selected.', 'basic-opengraph'); ?></em>
                                    <?php endif; ?>
                                </div>
                                <input type="hidden" name="<?php echo esc_attr(BOG_OPTION_LOGO); ?>" id="bog_logo_id" value="<?php echo esc_attr((string) $logo_id); ?>">
                                <button type="button" class="button" id="bog-logo-select"><?php esc_html_e('Select image', 'basic-opengraph'); ?></button>
                                <button type="button" class="button" id="bog-logo-remove" <?php disabled(!$logo_id); ?>><?php esc_html_e('Remove', 'basic-opengraph'); ?></button>
                                <p class="description"><?php esc_html_e('Recommended: 1200 × 630 px. Used as og:image for pages without a featured image.', 'basic-opengraph'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bog_title_separator"><?php esc_html_e('Title separator', 'basic-opengraph'); ?></label></th>
                            <td>
                                <input type="text" class="small-text" id="bog_title_separator" name="<?php echo esc_attr(BOG_OPTION_TITLE_SEP); ?>" value="<?php echo esc_attr($separator); ?>" maxlength="5">
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s: example title */
                                        esc_html__('Format: %s', 'basic-opengraph'),
                                        '<code>' . esc_html(get_bloginfo('name') . ' ' . $separator . ' ' . __('Page Title', 'basic-opengraph')) . '</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="bog_default_description"><?php esc_html_e('Default sharing description', 'basic-opengraph'); ?></label></th>
                            <td>
                                <textarea
                                    class="large-text"
                                    rows="3"
                                    id="bog_default_description"
                                    name="<?php echo esc_attr(BOG_OPTION_DESCRIPTION); ?>"
                                    maxlength="200"
                                ><?php echo esc_textarea($default_description); ?></textarea>
                                <p class="description"><?php esc_html_e('Used for the homepage and as a fallback when a page has no custom Open Graph description, excerpt, or usable content. Max 200 characters.', 'basic-opengraph'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save settings', 'basic-opengraph')); ?>
                </form>
            </div>

            <div class="bog-card">
                <h2><?php esc_html_e('Plugin Info', 'basic-opengraph'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Developer', 'basic-opengraph'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(BOG_TECHLAJU_URL); ?>" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e('Techlaju', 'basic-opengraph'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Website', 'basic-opengraph'); ?></th>
                        <td>
                            <a href="<?php echo esc_url(BOG_TECHLAJU_URL); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html(parse_url(BOG_TECHLAJU_URL, PHP_URL_HOST) ?: BOG_TECHLAJU_URL); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Version', 'basic-opengraph'); ?></th>
                        <td><?php echo esc_html(BOG_VERSION); ?></td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    public static function render_meta(): void
    {
        if (is_admin() || wp_is_json_request() || is_feed()) {
            return;
        }

        if (!BOG_License::is_valid()) {
            return;
        }

        $title = self::get_title();
        $description = self::get_description();
        $image = self::get_image_url();
        $url = self::get_url();
        $site_name = wp_strip_all_tags(get_bloginfo('name'));
        $type = is_singular() ? 'article' : 'website';

        echo "\n<!-- Basic OpenGraph -->\n";
        self::print_meta('og:site_name', $site_name);
        self::print_meta('og:title', $title);
        self::print_meta('og:description', $description);
        self::print_meta('og:url', $url);
        self::print_meta('og:type', $type);
        self::print_meta('og:locale', str_replace('-', '_', get_bloginfo('language')));

        if ($image !== '') {
            self::print_meta('og:image', $image);
            self::print_meta('og:image:secure_url', set_url_scheme($image, 'https'));
        }

        self::print_name_meta('twitter:card', $image !== '' ? 'summary_large_image' : 'summary');
        self::print_name_meta('twitter:title', $title);
        self::print_name_meta('twitter:description', $description);
        if ($image !== '') {
            self::print_name_meta('twitter:image', $image);
        }
        echo "<!-- /Basic OpenGraph -->\n";
    }

    private static function print_meta(string $property, string $content): void
    {
        $content = self::normalize_text($content);

        if ($content === '') {
            return;
        }

        printf(
            '<meta property="%1$s" content="%2$s" />' . "\n",
            esc_attr($property),
            esc_attr($content)
        );
    }

    private static function print_name_meta(string $name, string $content): void
    {
        $content = self::normalize_text($content);

        if ($content === '') {
            return;
        }

        printf(
            '<meta name="%1$s" content="%2$s" />' . "\n",
            esc_attr($name),
            esc_attr($content)
        );
    }

    private static function get_title(): string
    {
        $site_name = wp_strip_all_tags(get_bloginfo('name'));
        $separator = trim((string) get_option(BOG_OPTION_TITLE_SEP, '|'));
        if ($separator === '') {
            $separator = '|';
        }

        if (is_front_page() && is_home()) {
            $tagline = wp_strip_all_tags(get_bloginfo('description'));
            return $tagline !== '' ? $site_name . ' ' . $separator . ' ' . $tagline : $site_name;
        }

        if (is_front_page()) {
            return $site_name;
        }

        $page_title = '';

        if (is_singular()) {
            $page_title = get_the_title();
        } elseif (is_home()) {
            $posts_page_id = (int) get_option('page_for_posts');
            $page_title = $posts_page_id ? get_the_title($posts_page_id) : __('Blog', 'basic-opengraph');
        } elseif (is_category() || is_tag() || is_tax()) {
            $page_title = single_term_title('', false);
        } elseif (is_search()) {
            $page_title = sprintf(
                /* translators: %s: search query */
                __('Search results for “%s”', 'basic-opengraph'),
                get_search_query()
            );
        } elseif (is_author()) {
            $page_title = get_the_author();
        } elseif (is_archive()) {
            $page_title = wp_strip_all_tags(get_the_archive_title());
        } else {
            $page_title = wp_get_document_title();
        }

        $page_title = self::normalize_text((string) $page_title);

        if ($page_title === '' || strcasecmp($page_title, $site_name) === 0) {
            return $site_name;
        }

        return $site_name . ' ' . $separator . ' ' . $page_title;
    }

    private static function get_description(): string
    {
        if (is_singular()) {
            $post = get_queried_object();

            if ($post instanceof WP_Post) {
                $custom = trim((string) get_post_meta($post->ID, BOG_META_DESCRIPTION, true));
                if ($custom !== '') {
                    return self::trim_description($custom);
                }

                if (is_front_page()) {
                    $default = trim((string) get_option(BOG_OPTION_DESCRIPTION, ''));
                    if ($default !== '') {
                        return self::trim_description($default);
                    }
                }

                $excerpt = trim((string) $post->post_excerpt);

                if ($excerpt !== '') {
                    return self::trim_description($excerpt);
                }

                $from_content = self::extract_text_from_content((string) $post->post_content, (int) $post->ID);
                if ($from_content !== '') {
                    return self::trim_description($from_content);
                }
            }
        }

        if (is_front_page() || is_home()) {
            $default = trim((string) get_option(BOG_OPTION_DESCRIPTION, ''));
            if ($default !== '') {
                return self::trim_description($default);
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term && $term->description !== '') {
                return self::trim_description($term->description);
            }
        }

        $default = trim((string) get_option(BOG_OPTION_DESCRIPTION, ''));
        if ($default !== '') {
            return self::trim_description($default);
        }

        $tagline = wp_strip_all_tags(get_bloginfo('description'));
        if ($tagline !== '') {
            return self::trim_description($tagline);
        }

        return self::trim_description(get_bloginfo('name'));
    }

    private static function extract_text_from_content(string $content, int $seed = 0): string
    {
        $content = strip_shortcodes($content);
        $content = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;

        $blocks = preg_split('/\n\s*\n/', $content) ?: [];
        $paragraphs = [];

        foreach ($blocks as $block) {
            $text = self::normalize_text(wp_strip_all_tags($block));
            if (strlen($text) >= 40) {
                $paragraphs[] = $text;
            }
        }

        if (empty($paragraphs)) {
            return self::normalize_text(wp_strip_all_tags($content));
        }

        $index = count($paragraphs) > 1 ? abs($seed) % count($paragraphs) : 0;

        return $paragraphs[$index];
    }

    private static function get_image_url(): string
    {
        if (is_singular() && has_post_thumbnail()) {
            $url = get_the_post_thumbnail_url(null, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $logo_id = (int) get_option(BOG_OPTION_LOGO, 0);
        if ($logo_id > 0) {
            $url = wp_get_attachment_image_url($logo_id, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id > 0) {
            $url = wp_get_attachment_image_url($custom_logo_id, 'full');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        $site_icon = get_site_icon_url(512);
        return is_string($site_icon) ? $site_icon : '';
    }

    private static function get_url(): string
    {
        if (is_singular()) {
            return (string) get_permalink();
        }

        if (is_home() && !is_front_page()) {
            $posts_page_id = (int) get_option('page_for_posts');
            if ($posts_page_id) {
                return (string) get_permalink($posts_page_id);
            }
        }

        if (is_category() || is_tag() || is_tax()) {
            $link = get_term_link(get_queried_object());
            return is_wp_error($link) ? home_url('/') : (string) $link;
        }

        global $wp;
        $request = isset($wp->request) ? (string) $wp->request : '';
        return home_url('/' . ltrim($request, '/'));
    }

    private static function trim_description(string $text): string
    {
        $text = self::normalize_text($text);

        if (function_exists('mb_substr')) {
            if (mb_strlen($text) > 200) {
                $text = rtrim(mb_substr($text, 0, 197)) . '...';
            }
        } elseif (strlen($text) > 200) {
            $text = rtrim(substr($text, 0, 197)) . '...';
        }

        return $text;
    }

    private static function normalize_text(string $text): string
    {
        $text = wp_strip_all_tags(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }
}

Basic_OpenGraph::register();
