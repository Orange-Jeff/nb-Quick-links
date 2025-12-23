<?php
/**
 * Plugin Name: NB Quick Links
 * Description: Customizable quick links dropdown in the admin bar - works on both frontend and backend. Configure your own shortcuts to any admin page.
 * Version: 2.2.0
 * Author: Orange Jeff
 * Text Domain: nb-quick-links
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Version 2.2.0 - 2025-12-22 - Replaced drag reorder with up/down arrow buttons (more reliable)
 * Version 2.1.0 - 2025-12-22 - Fixed checkbox show/hide, fixed drag reorder, added background color picker
 * Version 2.0.0 - 2025-12-22 - Complete rewrite: works frontend+backend, customizable links, admin settings page
 * Version 1.2.0 - 2025-12-22 - Added Quick Actions: Delete (Trash), Disable (Draft), Password Protect
 * Version 1.1.0 - 2025-12-22 - Added DIVI Library, Add New submenus
 * Version 1.0.0 - 2025-12-22 - Initial release as NB Frontend Toolbar
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// ============================================================================
// NETBOUND SHARED MENU SYSTEM v2.1 (embedded - works standalone or with other NB plugins)
// ============================================================================
if (!defined('NB_SHARED_MENU_VERSION')) {
    define('NB_SHARED_MENU_VERSION', '2.1.0');

    global $nb_registered_plugins;
    if (!isset($nb_registered_plugins)) {
        $nb_registered_plugins = array();
    }

    function nb_get_all_plugins() {
        return array(
            'nb-critical-block' => array('name' => 'NB Critical Block', 'description' => 'Fail-safe recovery toolkit', 'icon' => 'dashicons-shield'),
            'nb-quick-links' => array('name' => 'NB Quick Links', 'description' => 'Customizable admin bar shortcuts', 'icon' => 'dashicons-admin-links'),
            'nb-site-toolbox' => array('name' => 'NB Site Toolbox', 'description' => 'Developer & admin utilities', 'icon' => 'dashicons-admin-tools'),
        );
    }

    function nb_register_plugin($slug, $name, $desc = '', $version = '1.0', $icon = 'dashicons-admin-generic', $menu_slug = '') {
        global $nb_registered_plugins;
        $nb_registered_plugins[$slug] = array('name' => $name, 'description' => $desc, 'version' => $version, 'icon' => $icon, 'menu_slug' => $menu_slug ?: $slug);
    }

    function nb_create_parent_menu() {
        global $admin_page_hooks;
        if (isset($admin_page_hooks['nb_netbound_tools'])) return;
        add_menu_page('NetBound Tools', 'NetBound Tools', 'manage_options', 'nb_netbound_tools', 'nb_render_index_page', 'dashicons-shield', 80);
        add_submenu_page('nb_netbound_tools', 'NetBound Tools', 'All Tools', 'manage_options', 'nb_netbound_tools', 'nb_render_index_page');
    }
    add_action('admin_menu', 'nb_create_parent_menu', 5);

    function nb_render_index_page() {
        global $nb_registered_plugins;
        $all_plugins = nb_get_all_plugins();
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-shield" style="font-size:30px;margin-right:10px;"></span> NetBound Tools</h1>
            <p>Your WordPress toolkit by Orange Jeff</p>
            <?php if (!empty($nb_registered_plugins)): ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px;">
                <?php foreach ($nb_registered_plugins as $slug => $p): ?>
                <div class="card" style="margin:0;padding:20px;border-left:4px solid #00a32a;">
                    <h2 style="margin-top:0;"><span class="dashicons <?php echo esc_attr($p['icon']); ?>"></span> <?php echo esc_html($p['name']); ?></h2>
                    <p><?php echo esc_html($p['description']); ?></p>
                    <?php if(!empty($p['menu_slug'])): ?><a href="<?php echo esc_url(admin_url('admin.php?page='.$p['menu_slug'])); ?>" class="button button-primary">Open →</a><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    function nb_get_parent_slug() { return 'nb_netbound_tools'; }
}

// Register this plugin
nb_register_plugin('nb-quick-links', 'NB Quick Links', 'Customizable admin bar shortcuts', '2.1.0', 'dashicons-admin-links', 'nb-quick-links');
// ============================================================================

class NB_Quick_Links {

    private $detected_shortcodes = [];
    private $default_links = [];

    public function __construct() {
        // Initialize default links
        $this->default_links = $this->get_default_links();

        // Add admin bar menus on BOTH frontend and backend
        add_action('admin_bar_menu', [$this, 'add_toolbar_menus'], 5); // Priority 5 = early = near front

        // Styles for both frontend and backend
        add_action('wp_head', [$this, 'toolbar_styles']);
        add_action('admin_head', [$this, 'toolbar_styles']);

        // Frontend-only: shortcode detection
        if (!is_admin()) {
            add_filter('do_shortcode_tag', [$this, 'capture_shortcode'], 10, 4);
            add_filter('the_content', [$this, 'scan_content_for_shortcodes'], 1);
        }

        // Admin page under NetBound Tools
        add_action('admin_menu', [$this, 'add_admin_menu'], 20);

        // Save settings
        add_action('admin_init', [$this, 'handle_settings']);

        // AJAX handlers for page actions (frontend)
        add_action('wp_ajax_nb_password_page', [$this, 'ajax_password_page']);
        add_action('wp_ajax_nb_disable_page', [$this, 'ajax_disable_page']);
        add_action('wp_ajax_nb_enable_page', [$this, 'ajax_enable_page']);
        add_action('wp_ajax_nb_delete_page', [$this, 'ajax_delete_page']);
    }

    /**
     * Get default quick links
     */
    private function get_default_links() {
        return [
            ['icon' => '📝', 'label' => 'All Posts', 'url' => 'edit.php', 'enabled' => true, 'color' => ''],
            ['icon' => '📄', 'label' => 'All Pages', 'url' => 'edit.php?post_type=page', 'enabled' => true, 'color' => ''],
            ['icon' => '🖼️', 'label' => 'Media Library', 'url' => 'upload.php', 'enabled' => true, 'color' => ''],
            ['icon' => '💬', 'label' => 'Comments', 'url' => 'edit-comments.php', 'enabled' => true, 'color' => ''],
            ['icon' => '🔌', 'label' => 'Plugins', 'url' => 'plugins.php', 'enabled' => true, 'color' => ''],
            ['icon' => '👥', 'label' => 'Users', 'url' => 'users.php', 'enabled' => true, 'color' => ''],
            ['icon' => '---', 'label' => '─── Create ───', 'url' => '#', 'enabled' => true, 'color' => ''],
            ['icon' => '➕', 'label' => 'New Post', 'url' => 'post-new.php', 'enabled' => true, 'color' => ''],
            ['icon' => '➕', 'label' => 'New Page', 'url' => 'post-new.php?post_type=page', 'enabled' => true, 'color' => ''],
            ['icon' => '➕', 'label' => 'Upload Media', 'url' => 'media-new.php', 'enabled' => true, 'color' => ''],
            ['icon' => '➕', 'label' => 'Add Plugin', 'url' => 'plugin-install.php', 'enabled' => true, 'color' => ''],
            ['icon' => '➕', 'label' => 'Add User', 'url' => 'user-new.php', 'enabled' => true, 'color' => ''],
            ['icon' => '---', 'label' => '─── Appearance ───', 'url' => '#', 'enabled' => true, 'color' => ''],
            ['icon' => '📦', 'label' => 'Widgets', 'url' => 'widgets.php', 'enabled' => true, 'color' => ''],
            ['icon' => '📋', 'label' => 'Menus', 'url' => 'nav-menus.php', 'enabled' => true, 'color' => ''],
            ['icon' => '🎨', 'label' => 'Themes', 'url' => 'themes.php', 'enabled' => true, 'color' => ''],
            ['icon' => '⚙️', 'label' => 'Customizer', 'url' => 'customize.php', 'enabled' => true, 'color' => ''],
            ['icon' => '---', 'label' => '─── Settings ───', 'url' => '#', 'enabled' => true, 'color' => ''],
            ['icon' => '⚙️', 'label' => 'General Settings', 'url' => 'options-general.php', 'enabled' => true, 'color' => ''],
            ['icon' => '🔗', 'label' => 'Permalinks', 'url' => 'options-permalink.php', 'enabled' => true, 'color' => ''],
            ['icon' => '🏥', 'label' => 'Site Health', 'url' => 'site-health.php', 'enabled' => true, 'color' => ''],
            ['icon' => '🔄', 'label' => 'Updates', 'url' => 'update-core.php', 'enabled' => true, 'color' => ''],
        ];
    }

    /**
     * Get DIVI-specific links if DIVI is active
     */
    private function get_divi_links() {
        if (!defined('ET_BUILDER_VERSION') && !function_exists('et_setup_theme')) {
            return [];
        }

        return [
            ['icon' => '---', 'label' => '─── DIVI ───', 'url' => '#', 'enabled' => true, 'color' => ''],
            ['icon' => '💎', 'label' => 'Theme Options', 'url' => 'admin.php?page=et_divi_options', 'enabled' => true, 'color' => ''],
            ['icon' => '🏗️', 'label' => 'Theme Builder', 'url' => 'admin.php?page=et_theme_builder', 'enabled' => true, 'color' => ''],
            ['icon' => '📚', 'label' => 'DIVI Library', 'url' => 'edit.php?post_type=et_pb_layout', 'enabled' => true, 'color' => ''],
        ];
    }

    /**
     * Get saved links or defaults
     */
    private function get_links() {
        $saved = get_option('nb_quick_links', []);
        if (empty($saved)) {
            $links = $this->default_links;
            // Add DIVI links if available
            $divi_links = $this->get_divi_links();
            if (!empty($divi_links)) {
                $links = array_merge($links, $divi_links);
            }
            return $links;
        }
        return $saved;
    }

    /**
     * Add admin menu under NetBound Tools
     */
    public function add_admin_menu() {
        add_submenu_page(
            nb_get_parent_slug(),
            'NB Quick Links',
            '⚡ Quick Links',
            'manage_options',
            'nb-quick-links',
            [$this, 'render_admin_page']
        );
    }

    /**
     * Handle settings save
     */
    public function handle_settings() {
        if (!isset($_POST['nb_quick_links_save'])) return;
        if (!current_user_can('manage_options')) return;
        if (!wp_verify_nonce($_POST['_wpnonce'], 'nb_quick_links_nonce')) return;

        $links = [];

        // Get the order from the hidden field (properly ordered indices)
        $order = isset($_POST['link_order']) ? array_map('intval', explode(',', $_POST['link_order'])) : [];

        if (isset($_POST['links']) && is_array($_POST['links'])) {
            // Process in the order specified
            foreach ($order as $idx) {
                if (!isset($_POST['links'][$idx])) continue;
                $link = $_POST['links'][$idx];

                // Check for the enabled hidden field (checkbox hack: hidden=0, checkbox=1 if checked)
                $enabled = isset($_POST['links_enabled'][$idx]) && $_POST['links_enabled'][$idx] === '1';

                $links[] = [
                    'icon' => sanitize_text_field($link['icon'] ?? '🔗'),
                    'label' => sanitize_text_field($link['label'] ?? ''),
                    'url' => sanitize_text_field($link['url'] ?? ''),
                    'color' => sanitize_hex_color($link['color'] ?? ''),
                    'enabled' => $enabled,
                ];
            }
        }

        update_option('nb_quick_links', $links);
        add_settings_error('nb_quick_links', 'saved', 'Quick Links saved!', 'success');
    }

    /**
     * Add the main Quick Links dropdown to admin bar
     */
    public function add_toolbar_menus($wp_admin_bar) {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return;
        }

        // Main Quick Links dropdown - priority 5 puts it near the front
        $wp_admin_bar->add_node([
            'id'    => 'nb-quick-links',
            'title' => '<span class="ab-icon dashicons dashicons-admin-links" style="margin-top:2px;"></span> Quick',
            'href'  => '#',
            'meta'  => ['class' => 'nb-quick-links-menu']
        ]);

        // Add all configured links
        $links = $this->get_links();
        foreach ($links as $index => $link) {
            if (empty($link['enabled'])) continue;
            if (empty($link['label'])) continue;

            $href = $link['url'];
            // Convert relative admin URLs to full URLs
            if ($href !== '#' && strpos($href, 'http') !== 0 && strpos($href, '/') !== 0) {
                $href = admin_url($href);
            }

            // Build style for background color
            $color_class = '';
            if (!empty($link['color'])) {
                $color_class = 'nb-colored-' . $index;
            }

            // Handle separator lines
            if ($link['icon'] === '---' || strpos($link['label'], '───') !== false) {
                $wp_admin_bar->add_node([
                    'id'     => 'nb-ql-' . $index,
                    'parent' => 'nb-quick-links',
                    'title'  => '<span style="color:#888;font-size:11px;">' . esc_html($link['label']) . '</span>',
                    'href'   => '#',
                    'meta'   => ['class' => 'nb-separator']
                ]);
            } else {
                $wp_admin_bar->add_node([
                    'id'     => 'nb-ql-' . $index,
                    'parent' => 'nb-quick-links',
                    'title'  => $link['icon'] . ' ' . esc_html($link['label']),
                    'href'   => $href,
                    'meta'   => $color_class ? ['class' => $color_class] : []
                ]);
            }
        }

        // Add link to settings at the bottom
        $wp_admin_bar->add_node([
            'id'     => 'nb-ql-separator-bottom',
            'parent' => 'nb-quick-links',
            'title'  => '<span style="color:#888;font-size:11px;">─── Settings ───</span>',
            'href'   => '#'
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'nb-ql-settings',
            'parent' => 'nb-quick-links',
            'title'  => '⚙️ Edit Quick Links',
            'href'   => admin_url('admin.php?page=nb-quick-links')
        ]);

        // ========================================
        // FRONTEND ONLY: Shortcodes & Page Actions
        // ========================================
        if (!is_admin() && is_singular()) {
            $this->add_frontend_menus($wp_admin_bar);
        }
    }

    /**
     * Add frontend-only menus (shortcodes, page actions)
     */
    private function add_frontend_menus($wp_admin_bar) {
        $post_id = get_the_ID();
        $post_type = get_post_type();
        $post_type_obj = get_post_type_object($post_type);

        // Shortcodes menu
        $shortcode_count = count($this->detected_shortcodes);
        $shortcode_title = $shortcode_count > 0
            ? '<span class="ab-icon dashicons dashicons-shortcode"></span> [' . $shortcode_count . ']'
            : '<span class="ab-icon dashicons dashicons-shortcode"></span>';

        $wp_admin_bar->add_node([
            'id'    => 'nb-shortcodes',
            'title' => $shortcode_title,
            'href'  => '#',
            'meta'  => ['class' => 'nb-shortcodes-menu']
        ]);

        if ($shortcode_count > 0) {
            sort($this->detected_shortcodes);
            foreach ($this->detected_shortcodes as $index => $shortcode) {
                $wp_admin_bar->add_node([
                    'id'     => 'nb-shortcode-' . $index,
                    'parent' => 'nb-shortcodes',
                    'title'  => '<code style="background:#1d2327;padding:2px 6px;border-radius:3px;color:#72aee6;">[' . esc_html($shortcode) . ']</code>',
                    'href'   => '#',
                    'meta'   => ['onclick' => 'navigator.clipboard.writeText("[' . esc_attr($shortcode) . ']"); alert("Copied: [' . esc_attr($shortcode) . ']"); return false;']
                ]);
            }
        } else {
            $wp_admin_bar->add_node([
                'id'     => 'nb-no-shortcodes',
                'parent' => 'nb-shortcodes',
                'title'  => '<em style="color:#a7aaad;">No shortcodes</em>',
                'href'   => '#'
            ]);
        }

        // Page Actions menu
        $wp_admin_bar->add_node([
            'id'    => 'nb-page-actions',
            'title' => '<span class="ab-icon dashicons dashicons-edit"></span> Page',
            'href'  => get_edit_post_link($post_id),
            'meta'  => ['class' => 'nb-page-actions-menu']
        ]);

        // Edit in WP
        $wp_admin_bar->add_node([
            'id'     => 'nb-edit-wp',
            'parent' => 'nb-page-actions',
            'title'  => '📝 Edit in WordPress',
            'href'   => get_edit_post_link($post_id)
        ]);

        // DIVI Visual Builder
        if (defined('ET_BUILDER_VERSION')) {
            $wp_admin_bar->add_node([
                'id'     => 'nb-divi-edit',
                'parent' => 'nb-page-actions',
                'title'  => '💎 Edit with DIVI',
                'href'   => add_query_arg('et_fb', '1', get_permalink($post_id))
            ]);
        }

        // Elementor
        if (defined('ELEMENTOR_VERSION')) {
            $wp_admin_bar->add_node([
                'id'     => 'nb-elementor-edit',
                'parent' => 'nb-page-actions',
                'title'  => '🔷 Edit with Elementor',
                'href'   => admin_url('post.php?post=' . $post_id . '&action=elementor')
            ]);
        }

        // Separator
        $wp_admin_bar->add_node([
            'id'     => 'nb-page-sep',
            'parent' => 'nb-page-actions',
            'title'  => '<span style="color:#888;">── Quick Actions ──</span>',
            'href'   => '#'
        ]);

        // Password protect
        $wp_admin_bar->add_node([
            'id'     => 'nb-password-page',
            'parent' => 'nb-page-actions',
            'title'  => '🔐 Password Protect',
            'href'   => admin_url('admin-ajax.php?action=nb_password_page&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('nb_page_action')),
            'meta'   => ['onclick' => 'var pw = prompt("Enter password (blank to remove):"); if(pw !== null) { window.location.href = this.href + "&password=" + encodeURIComponent(pw); } return false;']
        ]);

        // Draft/Publish toggle
        $current_status = get_post_status($post_id);
        if ($current_status === 'publish') {
            $wp_admin_bar->add_node([
                'id'     => 'nb-disable-page',
                'parent' => 'nb-page-actions',
                'title'  => '⏸️ Disable (Draft)',
                'href'   => admin_url('admin-ajax.php?action=nb_disable_page&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('nb_page_action')),
                'meta'   => ['onclick' => 'if(confirm("Set to Draft?")) { window.location.href = this.href; } return false;']
            ]);
        } else {
            $wp_admin_bar->add_node([
                'id'     => 'nb-enable-page',
                'parent' => 'nb-page-actions',
                'title'  => '▶️ Publish',
                'href'   => admin_url('admin-ajax.php?action=nb_enable_page&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('nb_page_action')),
                'meta'   => ['onclick' => 'if(confirm("Publish this page?")) { window.location.href = this.href; } return false;']
            ]);
        }

        // Delete
        $wp_admin_bar->add_node([
            'id'     => 'nb-delete-page',
            'parent' => 'nb-page-actions',
            'title'  => '🗑️ Delete (Trash)',
            'href'   => admin_url('admin-ajax.php?action=nb_delete_page&post_id=' . $post_id . '&_wpnonce=' . wp_create_nonce('nb_page_action')),
            'meta'   => ['onclick' => 'if(confirm("Move to Trash?")) { window.location.href = this.href; } return false;']
        ]);
    }

    /**
     * Shortcode detection
     */
    public function capture_shortcode($output, $tag, $attr, $m) {
        if (!in_array($tag, $this->detected_shortcodes)) {
            $this->detected_shortcodes[] = $tag;
        }
        return $output;
    }

    public function scan_content_for_shortcodes($content) {
        if (preg_match_all('/\[([a-zA-Z0-9_-]+)/', $content, $matches)) {
            foreach ($matches[1] as $tag) {
                if (shortcode_exists($tag) && !in_array($tag, $this->detected_shortcodes)) {
                    $this->detected_shortcodes[] = $tag;
                }
            }
        }
        return $content;
    }

    /**
     * AJAX handlers for page actions
     */
    public function ajax_password_page() {
        if (!current_user_can('edit_posts') || !wp_verify_nonce($_GET['_wpnonce'], 'nb_page_action')) {
            wp_die('Unauthorized');
        }
        $post_id = intval($_GET['post_id']);
        $password = isset($_GET['password']) ? sanitize_text_field($_GET['password']) : '';
        wp_update_post(['ID' => $post_id, 'post_password' => $password]);
        wp_redirect(get_permalink($post_id));
        exit;
    }

    public function ajax_disable_page() {
        if (!current_user_can('edit_posts') || !wp_verify_nonce($_GET['_wpnonce'], 'nb_page_action')) {
            wp_die('Unauthorized');
        }
        $post_id = intval($_GET['post_id']);
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        wp_redirect(admin_url('edit.php?post_type=' . get_post_type($post_id)));
        exit;
    }

    public function ajax_enable_page() {
        if (!current_user_can('edit_posts') || !wp_verify_nonce($_GET['_wpnonce'], 'nb_page_action')) {
            wp_die('Unauthorized');
        }
        $post_id = intval($_GET['post_id']);
        wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
        wp_redirect(get_permalink($post_id));
        exit;
    }

    public function ajax_delete_page() {
        if (!current_user_can('delete_posts') || !wp_verify_nonce($_GET['_wpnonce'], 'nb_page_action')) {
            wp_die('Unauthorized');
        }
        $post_id = intval($_GET['post_id']);
        $post_type = get_post_type($post_id);
        wp_trash_post($post_id);
        wp_redirect(admin_url('edit.php?post_type=' . $post_type));
        exit;
    }

    /**
     * Admin page for configuring quick links
     */
    public function render_admin_page() {
        $links = $this->get_links();
        $nonce = wp_create_nonce('nb_quick_links_nonce');

        // Enqueue color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        ?>
        <div class="wrap">
            <h1>⚡ NB Quick Links</h1>
            <p>Configure your admin bar quick links. These appear on <strong>both frontend and backend</strong>.</p>
            <p><em>Checkbox = Show/Hide &nbsp;|&nbsp; ▲▼ = Reorder &nbsp;|&nbsp; Color = background highlight</em></p>

            <?php settings_errors('nb_quick_links'); ?>

            <form method="post" id="nb-quick-links-form">
                <input type="hidden" name="_wpnonce" value="<?php echo $nonce; ?>">
                <input type="hidden" name="nb_quick_links_save" value="1">
                <input type="hidden" name="link_order" id="link_order" value="<?php echo implode(',', array_keys($links)); ?>">

                <div class="nb-links-container" id="links-container">
                    <?php foreach ($links as $index => $link):
                        $is_enabled = !empty($link['enabled']);
                        $color = $link['color'] ?? '';
                    ?>
                        <div class="nb-link-row <?php echo $is_enabled ? '' : 'nb-disabled'; ?>" data-index="<?php echo $index; ?>">
                            <span class="nb-move-buttons">
                                <button type="button" class="nb-move-up" title="Move up">▲</button>
                                <button type="button" class="nb-move-down" title="Move down">▼</button>
                            </span>
                            <label class="nb-checkbox-wrap" title="Show/Hide this link">
                                <input type="checkbox" class="nb-enabled-checkbox" data-index="<?php echo $index; ?>" <?php checked($is_enabled); ?>>
                                <input type="hidden" name="links_enabled[<?php echo $index; ?>]" value="<?php echo $is_enabled ? '1' : '0'; ?>">
                            </label>
                            <input type="text" name="links[<?php echo $index; ?>][icon]" value="<?php echo esc_attr($link['icon']); ?>" class="nb-icon-input" placeholder="🔗" title="Emoji or ---">
                            <input type="text" name="links[<?php echo $index; ?>][label]" value="<?php echo esc_attr($link['label']); ?>" class="nb-label-input" placeholder="Label">
                            <input type="text" name="links[<?php echo $index; ?>][url]" value="<?php echo esc_attr($link['url']); ?>" class="nb-url-input" placeholder="admin page or full URL">
                            <input type="text" name="links[<?php echo $index; ?>][color]" value="<?php echo esc_attr($color); ?>" class="nb-color-input" placeholder="" title="Background color">
                            <button type="button" class="button nb-remove-link" title="Remove">✕</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <p style="margin-top:15px;">
                    <button type="button" class="button" id="add-link">➕ Add Link</button>
                    <button type="button" class="button" id="add-separator">── Add Separator</button>
                    <button type="button" class="button" id="reset-defaults">🔄 Reset to Defaults</button>
                </p>

                <p class="submit">
                    <input type="submit" class="button button-primary" value="Save Quick Links">
                </p>
            </form>

            <div class="card" style="margin-top:20px;max-width:600px;">
                <h2>💡 Tips</h2>
                <ul>
                    <li><strong>Checkbox:</strong> Uncheck to hide a link from the menu (it stays in your list)</li>
                    <li><strong>Icon:</strong> Use any emoji, or <code>---</code> for a separator line</li>
                    <li><strong>Color:</strong> Click to pick a background highlight color for important links</li>
                    <li><strong>URL:</strong> Use relative admin paths like <code>edit.php</code> or full URLs</li>
                    <li><strong>Separator:</strong> Set icon to <code>---</code> and label to something like <code>─── Section ───</code></li>
                </ul>

                <h3>Common Admin URLs:</h3>
                <table class="widefat" style="margin-top:10px;">
                    <tr><td><code>edit.php</code></td><td>All Posts</td></tr>
                    <tr><td><code>edit.php?post_type=page</code></td><td>All Pages</td></tr>
                    <tr><td><code>upload.php</code></td><td>Media Library</td></tr>
                    <tr><td><code>plugins.php</code></td><td>Plugins</td></tr>
                    <tr><td><code>themes.php</code></td><td>Themes</td></tr>
                    <tr><td><code>widgets.php</code></td><td>Widgets</td></tr>
                    <tr><td><code>nav-menus.php</code></td><td>Menus</td></tr>
                    <tr><td><code>users.php</code></td><td>Users</td></tr>
                    <tr><td><code>options-general.php</code></td><td>General Settings</td></tr>
                    <tr><td><code>tools.php</code></td><td>Tools</td></tr>
                    <tr><td><code>admin.php?page=et_divi_options</code></td><td>DIVI Theme Options</td></tr>
                    <tr><td><code>admin.php?page=et_theme_builder</code></td><td>DIVI Theme Builder</td></tr>
                    <tr><td><code>edit.php?post_type=et_pb_layout</code></td><td>DIVI Library</td></tr>
                </table>
            </div>
        </div>

        <style>
            .nb-links-container {
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
                padding: 10px;
                max-width: 1000px;
            }
            .nb-link-row {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 8px;
                background: #f9f9f9;
                border-radius: 4px;
                margin-bottom: 5px;
                transition: opacity 0.2s, background 0.2s;
            }
            .nb-link-row:hover {
                background: #f0f0f1;
            }
            .nb-link-row.nb-disabled {
                opacity: 0.5;
                background: #fafafa;
            }
            .nb-link-row.nb-disabled input:not([type="checkbox"]) {
                color: #999;
            }
            .nb-move-buttons {
                display: flex;
                flex-direction: column;
                gap: 1px;
            }
            .nb-move-up, .nb-move-down {
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 2px;
                cursor: pointer;
                font-size: 10px;
                line-height: 1;
                padding: 2px 6px;
                color: #50575e;
            }
            .nb-move-up:hover, .nb-move-down:hover {
                background: #2271b1;
                color: #fff;
                border-color: #2271b1;
            }
            .nb-move-up:disabled, .nb-move-down:disabled {
                opacity: 0.3;
                cursor: not-allowed;
            }
            .nb-checkbox-wrap {
                display: flex;
                align-items: center;
            }
            .nb-checkbox-wrap input[type="checkbox"] {
                width: 18px;
                height: 18px;
                margin: 0;
            }
            .nb-icon-input {
                width: 50px !important;
                text-align: center;
            }
            .nb-label-input {
                width: 180px !important;
            }
            .nb-url-input {
                flex: 1;
                min-width: 180px;
            }
            .nb-color-input {
                width: 70px !important;
                padding: 2px 5px !important;
            }
            .nb-remove-link {
                color: #dc3232 !important;
            }
            .nb-link-row.moving {
                background: #d5e5f5;
                box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            }
            /* Color picker adjustments */
            .nb-link-row .wp-picker-container {
                display: inline-block;
            }
            .nb-link-row .wp-color-result {
                height: 26px !important;
                min-height: 26px !important;
            }
        </style>

        <script>
        jQuery(document).ready(function($) {
            var linkIndex = <?php echo count($links); ?>;

            // Initialize color pickers
            function initColorPickers() {
                $('.nb-color-input').not('.wp-color-picker').wpColorPicker({
                    change: function(event, ui) {
                        $(this).val(ui.color.toString());
                    },
                    clear: function() {
                        $(this).val('');
                    }
                });
            }
            initColorPickers();

            // Checkbox change - update hidden field and row styling
            $(document).on('change', '.nb-enabled-checkbox', function() {
                var $row = $(this).closest('.nb-link-row');
                var $hidden = $(this).siblings('input[type="hidden"]');

                if ($(this).is(':checked')) {
                    $hidden.val('1');
                    $row.removeClass('nb-disabled');
                } else {
                    $hidden.val('0');
                    $row.addClass('nb-disabled');
                }
            });

            // Add new link
            $('#add-link').on('click', function() {
                var html = '<div class="nb-link-row" data-index="' + linkIndex + '">' +
                    '<span class="nb-move-buttons">' +
                    '<button type="button" class="nb-move-up" title="Move up">▲</button>' +
                    '<button type="button" class="nb-move-down" title="Move down">▼</button>' +
                    '</span>' +
                    '<label class="nb-checkbox-wrap" title="Show/Hide this link">' +
                    '<input type="checkbox" class="nb-enabled-checkbox" data-index="' + linkIndex + '" checked>' +
                    '<input type="hidden" name="links_enabled[' + linkIndex + ']" value="1">' +
                    '</label>' +
                    '<input type="text" name="links[' + linkIndex + '][icon]" class="nb-icon-input" placeholder="🔗" value="🔗">' +
                    '<input type="text" name="links[' + linkIndex + '][label]" class="nb-label-input" placeholder="Label">' +
                    '<input type="text" name="links[' + linkIndex + '][url]" class="nb-url-input" placeholder="admin page or full URL">' +
                    '<input type="text" name="links[' + linkIndex + '][color]" class="nb-color-input" value="">' +
                    '<button type="button" class="button nb-remove-link" title="Remove">✕</button>' +
                    '</div>';
                $('#links-container').append(html);
                initColorPickers();
                updateOrder();
                updateMoveButtons();
                linkIndex++;
            });

            // Add separator
            $('#add-separator').on('click', function() {
                var html = '<div class="nb-link-row" data-index="' + linkIndex + '">' +
                    '<span class="nb-move-buttons">' +
                    '<button type="button" class="nb-move-up" title="Move up">▲</button>' +
                    '<button type="button" class="nb-move-down" title="Move down">▼</button>' +
                    '</span>' +
                    '<label class="nb-checkbox-wrap" title="Show/Hide this link">' +
                    '<input type="checkbox" class="nb-enabled-checkbox" data-index="' + linkIndex + '" checked>' +
                    '<input type="hidden" name="links_enabled[' + linkIndex + ']" value="1">' +
                    '</label>' +
                    '<input type="text" name="links[' + linkIndex + '][icon]" class="nb-icon-input" value="---">' +
                    '<input type="text" name="links[' + linkIndex + '][label]" class="nb-label-input" value="─── Section ───">' +
                    '<input type="text" name="links[' + linkIndex + '][url]" class="nb-url-input" value="#">' +
                    '<input type="text" name="links[' + linkIndex + '][color]" class="nb-color-input" value="">' +
                    '<button type="button" class="button nb-remove-link" title="Remove">✕</button>' +
                    '</div>';
                $('#links-container').append(html);
                updateOrder();
                updateMoveButtons();
                linkIndex++;
            });

            // Remove link
            $(document).on('click', '.nb-remove-link', function() {
                $(this).closest('.nb-link-row').remove();
                updateOrder();
            });

            // Reset to defaults
            $('#reset-defaults').on('click', function() {
                if (confirm('Reset all links to defaults? This will remove your customizations.')) {
                    $.post(ajaxurl, {
                        action: 'nb_reset_quick_links',
                        _wpnonce: '<?php echo wp_create_nonce('nb_reset_links'); ?>'
                    }, function() {
                        location.reload();
                    });
                }
            });

            // Update the order hidden field
            function updateOrder() {
                var order = [];
                $('#links-container .nb-link-row').each(function() {
                    order.push($(this).data('index'));
                });
                $('#link_order').val(order.join(','));
            }

            // ========================================
            // UP/DOWN ARROW REORDERING
            // ========================================

            // Update button states (disable up on first, down on last)
            function updateMoveButtons() {
                var rows = $('#links-container .nb-link-row');
                rows.find('.nb-move-up, .nb-move-down').prop('disabled', false);
                rows.first().find('.nb-move-up').prop('disabled', true);
                rows.last().find('.nb-move-down').prop('disabled', true);
            }

            // Move up
            $(document).on('click', '.nb-move-up', function(e) {
                e.preventDefault();
                var row = $(this).closest('.nb-link-row');
                var prev = row.prev('.nb-link-row');
                if (prev.length) {
                    row.addClass('moving');
                    row.insertBefore(prev);
                    setTimeout(function() { row.removeClass('moving'); }, 200);
                    updateOrder();
                    updateMoveButtons();
                }
            });

            // Move down
            $(document).on('click', '.nb-move-down', function(e) {
                e.preventDefault();
                var row = $(this).closest('.nb-link-row');
                var next = row.next('.nb-link-row');
                if (next.length) {
                    row.addClass('moving');
                    row.insertAfter(next);
                    setTimeout(function() { row.removeClass('moving'); }, 200);
                    updateOrder();
                    updateMoveButtons();
                }
            });

            // Initialize on load
            updateOrder();
            updateMoveButtons();
        });
        </script>
        <?php
    }

    /**
     * Toolbar styles
     */
    public function toolbar_styles() {
        $links = $this->get_links();
        ?>
        <style>
            #wpadminbar .nb-quick-links-menu > a {
                background: linear-gradient(135deg, #1e3a5f 0%, #2c3338 100%) !important;
            }
            #wpadminbar .nb-quick-links-menu:hover > a {
                background: linear-gradient(135deg, #2271b1 0%, #3c434a 100%) !important;
            }
            #wpadminbar .nb-shortcodes-menu > a {
                background: linear-gradient(135deg, #4a1e5f 0%, #2c3338 100%) !important;
            }
            #wpadminbar .nb-page-actions-menu > a {
                background: #2271b1 !important;
            }
            #wpadminbar .menupop .ab-sub-wrapper {
                min-width: 200px;
            }
            #wpadminbar .ab-submenu code {
                font-family: monospace;
            }
            #wpadminbar .nb-separator > a {
                cursor: default !important;
            }
            /* Dynamic colored items */
            <?php foreach ($links as $index => $link):
                if (!empty($link['color']) && !empty($link['enabled'])): ?>
            #wpadminbar .nb-colored-<?php echo $index; ?> > a,
            #wpadminbar .nb-colored-<?php echo $index; ?>:hover > a {
                background: <?php echo esc_attr($link['color']); ?> !important;
            }
            <?php endif; endforeach; ?>
        </style>
        <?php
    }
}

// AJAX handler for reset
add_action('wp_ajax_nb_reset_quick_links', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'nb_reset_links')) {
        wp_die('Unauthorized');
    }
    delete_option('nb_quick_links');
    wp_send_json_success();
});

// Initialize the plugin
new NB_Quick_Links();
