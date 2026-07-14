<?php
/**
 * Plugin Name: Manifested Fit Affiliates
 * Description: Categorized affiliate offer registry + in-post ad placement. When the Content Engine creates a draft, the AI decides where (and whether) to place offer cards between paragraphs; posts render the cards dynamically, so an empty registry shows nothing and edits apply everywhere instantly. All links are rel="sponsored nofollow" with a visible disclosure.
 * Version: 0.1.0
 * Author: Spinning Monkey Studios
 */

if (!defined('ABSPATH')) { exit; }

class MFA_Plugin {

    const OPT_OFFERS   = 'mfa_offers';
    const OPT_SETTINGS = 'mfa_settings';
    const META_PLACEMENTS = 'mfa_placements';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_mfa_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_mfa_add_offer', array(__CLASS__, 'handle_add_offer'));
        add_action('admin_post_mfa_update_offer', array(__CLASS__, 'handle_update_offer'));
        add_action('admin_post_mfa_delete_offer', array(__CLASS__, 'handle_delete_offer'));
        add_action('admin_post_mfa_plan_post', array(__CLASS__, 'handle_plan_post'));
        add_filter('the_content', array(__CLASS__, 'inject_ads'), 20);
        add_action('wp_head', array(__CLASS__, 'print_styles'));
        // Content Engine fires this after each new AI draft.
        add_action('mfce_draft_created', array(__CLASS__, 'on_draft_created'), 10, 4);
    }

    public static function defaults() {
        return array(
            'enabled'         => 1,
            'max_per_post'    => 2,
            'min_gap_blocks'  => 4,  // rule-based fallback: blocks between ads
            'disclosure'      => 'Affiliate link — if you buy through it, Manifested Fit may earn a commission at no extra cost to you.',
            'ai_placement'    => 1,  // use the Content Engine's AI provider to choose spots
        );
    }

    public static function settings() {
        $s = get_option(self::OPT_SETTINGS, array());
        return wp_parse_args(is_array($s) ? $s : array(), self::defaults());
    }

    /** Offers: id, name, url, category (''=any), blurb, button, active. */
    public static function offers($active_only = false) {
        $offers = get_option(self::OPT_OFFERS, array());
        if (!is_array($offers)) { $offers = array(); }
        if ($active_only) {
            $offers = array_values(array_filter($offers, function ($o) { return !empty($o['active']); }));
        }
        return $offers;
    }

    public static function blog_categories() {
        if (class_exists('MFCE_Engine')) { return MFCE_Engine::categories(); }
        return array_map(function ($t) { return $t->name; }, get_categories(array('hide_empty' => false)));
    }

    // ---------------------------------------------------------- content split

    /**
     * Split rendered post HTML into top-level blocks so ads can slot between
     * them. Must stay deterministic: planning and rendering both use this.
     */
    public static function split_blocks($html) {
        $parts = preg_split('/(<\/(?:p|h2|h3|ul|ol|blockquote|figure|div)>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $blocks = array();
        for ($i = 0; $i < count($parts); $i += 2) {
            $blocks[] = $parts[$i] . (isset($parts[$i + 1]) ? $parts[$i + 1] : '');
        }
        return $blocks;
    }

    /** Plain-text preview of a block, for the AI prompt. */
    private static function block_text($block, $len = 160) {
        $t = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($block)));
        return function_exists('mb_substr') ? mb_substr($t, 0, $len) : substr($t, 0, $len);
    }

    // ------------------------------------------------------------- planning

    public static function on_draft_created($post_id, $result, $persona, $pick) {
        if ($pick['mode'] === 'solemn') { return; } // no ads on solemn pieces
        self::plan_placements($post_id);
    }

    /**
     * Decide where offers go in a post. AI chooses when available (via the
     * Content Engine's configured provider); otherwise an even-spacing rule.
     * Stores placements in post meta. No active offers -> meta cleared.
     */
    public static function plan_placements($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'post') { return 'post not found'; }
        $offers = self::offers(true);
        if (empty($offers)) {
            delete_post_meta($post_id, self::META_PLACEMENTS);
            return 'no active offers - nothing to place';
        }
        $s = self::settings();
        $blocks = self::split_blocks($post->post_content);
        if (count($blocks) < 3) { return 'post too short for ads'; }

        $placements = null;
        if (!empty($s['ai_placement']) && class_exists('MFCE_Engine')) {
            $placements = self::ai_plan($post, $blocks, $offers, $s);
        }
        if (!is_array($placements)) {
            $placements = self::rule_plan($post, $blocks, $offers, $s);
        }
        update_post_meta($post_id, self::META_PLACEMENTS, wp_json_encode($placements));
        return count($placements) . ' placement(s) saved';
    }

    private static function ai_plan($post, $blocks, $offers, $s) {
        $engine_s = MFCE_Engine::settings();
        if (!MFCE_Engine::provider_ready($engine_s)) { return null; }

        $cats = implode(', ', array_map(function ($t) { return $t->name; }, get_the_category($post->ID)));
        $block_list = '';
        foreach ($blocks as $i => $b) {
            $block_list .= "[$i] " . self::block_text($b) . "\n";
        }
        $offer_list = '';
        foreach ($offers as $o) {
            $offer_list .= "- id \"{$o['id']}\": {$o['name']} (category: " . ($o['category'] ?: 'any') . ") — {$o['blurb']}\n";
        }
        $max = (int) $s['max_per_post'];

        $system = "You place tasteful affiliate offer cards inside wellness blog posts. Rules:\n"
            . "- At most $max placements; fewer (or zero) if nothing fits naturally. Relevance beats revenue.\n"
            . "- Never place an ad before block 1, in the middle of a step sequence, right next to another ad, or in the final block (that's the sign-off).\n"
            . "- Prefer a spot right after the post has just discussed the problem the offer solves.\n"
            . "- Only choose offers whose topic genuinely relates to the post.";
        $user = "Post title: {$post->post_title}\nPost categories: $cats\n\nNumbered content blocks:\n$block_list\nAvailable offers:\n$offer_list\nReturn the placements.";

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'placements' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'offer_id'    => array('type' => 'string'),
                            'after_block' => array('type' => 'integer'),
                            'why'         => array('type' => 'string'),
                        ),
                        'required' => array('offer_id', 'after_block', 'why'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('placements'),
            'additionalProperties' => false,
        );

        $parsed = MFCE_Engine::ai_json($engine_s, $system, $user, $schema);
        if (is_wp_error($parsed) || !isset($parsed['placements']) || !is_array($parsed['placements'])) { return null; }

        $valid_ids = wp_list_pluck($offers, 'id');
        $out = array();
        foreach ($parsed['placements'] as $p) {
            if (!in_array($p['offer_id'], $valid_ids, true)) { continue; }
            $after = max(0, min((int) $p['after_block'], count($blocks) - 2));
            $out[] = array('offer_id' => $p['offer_id'], 'after_block' => $after, 'why' => sanitize_text_field($p['why'] ?? ''));
            if (count($out) >= (int) $s['max_per_post']) { break; }
        }
        return $out;
    }

    /** Fallback: category-matched offers, evenly spaced through the post. */
    private static function rule_plan($post, $blocks, $offers, $s) {
        $post_cats = array_map(function ($t) { return $t->name; }, get_the_category($post->ID));
        $matched = array_values(array_filter($offers, function ($o) use ($post_cats) {
            return empty($o['category']) || in_array($o['category'], $post_cats, true);
        }));
        if (empty($matched)) { return array(); }
        $n = count($blocks);
        $max = min((int) $s['max_per_post'], count($matched), max(1, floor($n / max(1, (int) $s['min_gap_blocks']))));
        $out = array();
        for ($k = 0; $k < $max; $k++) {
            $after = (int) floor($n * (($k + 1) / ($max + 1)));
            $after = max(1, min($after, $n - 2));
            $out[] = array('offer_id' => $matched[$k % count($matched)]['id'], 'after_block' => $after, 'why' => 'even spacing (rule-based)');
        }
        return $out;
    }

    // ------------------------------------------------------------- rendering

    public static function inject_ads($content) {
        if (is_admin() || !is_singular('post') || !in_the_loop() || !is_main_query()) { return $content; }
        $s = self::settings();
        if (empty($s['enabled'])) { return $content; }
        $offers = self::offers(true);
        if (empty($offers)) { return $content; }
        $offer_map = array();
        foreach ($offers as $o) { $offer_map[$o['id']] = $o; }

        $post_id = get_the_ID();
        $raw = get_post_meta($post_id, self::META_PLACEMENTS, true);
        $placements = $raw ? json_decode($raw, true) : null;
        if (!is_array($placements)) {
            // Older posts with no plan: quietly rule-plan once and save.
            self::plan_placements($post_id);
            $raw = get_post_meta($post_id, self::META_PLACEMENTS, true);
            $placements = $raw ? json_decode($raw, true) : array();
        }
        if (empty($placements)) { return $content; }

        $blocks = self::split_blocks($content);
        $by_index = array();
        foreach ($placements as $p) {
            if (!isset($offer_map[$p['offer_id']])) { continue; }
            $idx = max(0, min((int) $p['after_block'], count($blocks) - 1));
            $by_index[$idx][] = $offer_map[$p['offer_id']];
        }
        if (empty($by_index)) { return $content; }

        $out = '';
        foreach ($blocks as $i => $b) {
            $out .= $b;
            if (isset($by_index[$i])) {
                foreach ($by_index[$i] as $offer) { $out .= self::ad_card($offer, $s); }
            }
        }
        return $out;
    }

    private static function ad_card($offer, $s) {
        $button = !empty($offer['button']) ? $offer['button'] : 'Learn more';
        return '<aside class="mfa-ad">'
            . '<span class="mfa-ad-tag">Partner pick</span>'
            . '<div class="mfa-ad-body">'
            . '<strong class="mfa-ad-name">' . esc_html($offer['name']) . '</strong>'
            . (!empty($offer['blurb']) ? '<p class="mfa-ad-blurb">' . esc_html($offer['blurb']) . '</p>' : '')
            . '<a class="mfa-ad-btn" href="' . esc_url($offer['url']) . '" target="_blank" rel="sponsored nofollow noopener">' . esc_html($button) . ' &rarr;</a>'
            . '</div>'
            . '<span class="mfa-ad-disclosure">' . esc_html($s['disclosure']) . '</span>'
            . '</aside>';
    }

    public static function print_styles() {
        if (!is_singular('post')) { return; }
        echo '<style id="mfa-ad-css">
.mfa-ad{margin:2rem 0;padding:1.15rem 1.3rem;background:#eef5ec;border:1px solid #dbe5dd;border-left:5px solid #4d9a57;border-radius:14px;font-family:inherit}
.mfa-ad-tag{display:inline-block;font-size:.7rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#8060bb;margin-bottom:.35rem}
.mfa-ad-name{display:block;font-size:1.05rem;color:#183833}
.mfa-ad-blurb{margin:.35rem 0 .7rem;color:#5d706b;font-size:.93rem}
.mfa-ad-btn{display:inline-block;background:#08736f;color:#fff!important;font-weight:800;font-size:.88rem;text-decoration:none;border-radius:999px;padding:.5rem 1.1rem}
.mfa-ad-btn:hover{background:#065d59}
.mfa-ad-disclosure{display:block;margin-top:.7rem;font-size:.72rem;color:#5d706b}
</style>';
    }

    // ----------------------------------------------------------------- admin

    public static function admin_menu() {
        add_menu_page('Affiliate Ads', 'Affiliate Ads', 'manage_options', 'mfa', array(__CLASS__, 'render_admin'), 'dashicons-money-alt', 58);
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfa_save_settings');
        $s = self::settings();
        $s['enabled']        = isset($_POST['enabled']) ? 1 : 0;
        $s['ai_placement']   = isset($_POST['ai_placement']) ? 1 : 0;
        $s['max_per_post']   = max(0, min(5, (int) ($_POST['max_per_post'] ?? $s['max_per_post'])));
        $s['min_gap_blocks'] = max(2, (int) ($_POST['min_gap_blocks'] ?? $s['min_gap_blocks']));
        $disclosure          = sanitize_text_field(wp_unslash($_POST['disclosure'] ?? ''));
        if ($disclosure !== '') { $s['disclosure'] = $disclosure; }
        update_option(self::OPT_SETTINGS, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=mfa&notice=saved'));
        exit;
    }

    public static function handle_add_offer() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfa_add_offer');
        $offers = self::offers();
        $offers[] = array(
            'id'       => uniqid('o'),
            'name'     => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'url'      => esc_url_raw(wp_unslash($_POST['url'] ?? '')),
            'category' => sanitize_text_field(wp_unslash($_POST['category'] ?? '')),
            'blurb'    => sanitize_text_field(wp_unslash($_POST['blurb'] ?? '')),
            'button'   => sanitize_text_field(wp_unslash($_POST['button'] ?? '')),
            'active'   => isset($_POST['active']) ? 1 : 0,
        );
        update_option(self::OPT_OFFERS, $offers, false);
        wp_safe_redirect(admin_url('admin.php?page=mfa&notice=added'));
        exit;
    }

    public static function handle_update_offer() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfa_update_offer');
        $id = sanitize_text_field(wp_unslash($_POST['offer_id'] ?? ''));
        $offers = self::offers();
        foreach ($offers as &$o) {
            if ($o['id'] !== $id) { continue; }
            $o['name']     = sanitize_text_field(wp_unslash($_POST['name'] ?? $o['name']));
            $o['url']      = esc_url_raw(wp_unslash($_POST['url'] ?? $o['url']));
            $o['category'] = sanitize_text_field(wp_unslash($_POST['category'] ?? $o['category']));
            $o['blurb']    = sanitize_text_field(wp_unslash($_POST['blurb'] ?? $o['blurb']));
            $o['button']   = sanitize_text_field(wp_unslash($_POST['button'] ?? $o['button']));
            $o['active']   = isset($_POST['active']) ? 1 : 0;
        }
        unset($o);
        update_option(self::OPT_OFFERS, $offers, false);
        wp_safe_redirect(admin_url('admin.php?page=mfa&notice=updated'));
        exit;
    }

    public static function handle_delete_offer() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfa_delete_offer');
        $id = sanitize_text_field(wp_unslash($_POST['offer_id'] ?? ''));
        $offers = array_values(array_filter(self::offers(), function ($o) use ($id) { return $o['id'] !== $id; }));
        update_option(self::OPT_OFFERS, $offers, false);
        wp_safe_redirect(admin_url('admin.php?page=mfa&notice=deleted'));
        exit;
    }

    /** Manual (re)plan for one post, or all posts when post_id is "all". */
    public static function handle_plan_post() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfa_plan_post');
        $target = sanitize_text_field(wp_unslash($_POST['post_id'] ?? ''));
        if ($target === 'all') {
            $ids = get_posts(array('post_type' => 'post', 'post_status' => array('publish', 'draft'), 'numberposts' => 100, 'fields' => 'ids'));
            foreach ($ids as $pid) { self::plan_placements($pid); }
            $msg = 'planned-all';
        } else {
            self::plan_placements((int) $target);
            $msg = 'planned';
        }
        wp_safe_redirect(admin_url('admin.php?page=mfa&notice=' . $msg));
        exit;
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $offers = self::offers();
        $cats = self::blog_categories();
        $notice = sanitize_text_field($_GET['notice'] ?? '');
        $engine = class_exists('MFCE_Engine');
        ?>
        <div class="wrap">
            <h1>Affiliate Ads</h1>
            <?php if ($notice) : ?><div class="notice notice-success"><p>Done: <?php echo esc_html($notice); ?>.</p></div><?php endif; ?>
            <p>Offers below are placed inside posts as "Partner pick" cards. <strong>No active offers = no ads anywhere.</strong>
               <?php echo $engine ? 'AI placement uses the Content Engine\'s provider.' : '<strong style="color:#b00">Content Engine plugin not active — rule-based placement only.</strong>'; ?></p>

            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mfa_save_settings'); ?>
                <input type="hidden" name="action" value="mfa_save_settings">
                <table class="form-table">
                    <tr><th>Show ads</th><td><label><input type="checkbox" name="enabled" <?php checked($s['enabled']); ?>> Enabled</label></td></tr>
                    <tr><th>AI placement</th><td><label><input type="checkbox" name="ai_placement" <?php checked($s['ai_placement']); ?>> Let the AI pick spots on each new draft (falls back to even spacing)</label></td></tr>
                    <tr><th>Max ads per post</th><td><input type="number" name="max_per_post" min="0" max="5" value="<?php echo esc_attr($s['max_per_post']); ?>"></td></tr>
                    <tr><th>Min blocks between ads</th><td><input type="number" name="min_gap_blocks" min="2" value="<?php echo esc_attr($s['min_gap_blocks']); ?>"> <span class="description">(rule-based fallback only)</span></td></tr>
                    <tr><th>Disclosure line</th><td><input type="text" name="disclosure" class="large-text" value="<?php echo esc_attr($s['disclosure']); ?>"></td></tr>
                </table>
                <?php submit_button('Save settings'); ?>
            </form>

            <h2>Offers (<?php echo count($offers); ?>)</h2>
            <table class="widefat striped">
                <thead><tr><th>Name</th><th>Category</th><th>Blurb</th><th>Button</th><th>URL</th><th>Active</th><th></th></tr></thead>
                <tbody>
                <?php if (!$offers) : ?>
                    <tr><td colspan="7"><em>No offers yet — posts show no ads.</em></td></tr>
                <?php endif; ?>
                <?php foreach ($offers as $o) : ?>
                    <tr>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('mfa_update_offer'); ?>
                        <input type="hidden" name="action" value="mfa_update_offer">
                        <input type="hidden" name="offer_id" value="<?php echo esc_attr($o['id']); ?>">
                        <td><input type="text" name="name" value="<?php echo esc_attr($o['name']); ?>"></td>
                        <td><select name="category"><option value="">Any</option>
                            <?php foreach ($cats as $c) : ?><option value="<?php echo esc_attr($c); ?>" <?php selected($o['category'], $c); ?>><?php echo esc_html($c); ?></option><?php endforeach; ?>
                        </select></td>
                        <td><input type="text" name="blurb" value="<?php echo esc_attr($o['blurb']); ?>"></td>
                        <td><input type="text" name="button" value="<?php echo esc_attr($o['button']); ?>" placeholder="Learn more" style="width:9em"></td>
                        <td><input type="url" name="url" value="<?php echo esc_attr($o['url']); ?>" style="width:14em"></td>
                        <td><input type="checkbox" name="active" <?php checked(!empty($o['active'])); ?>></td>
                        <td><button class="button">Save</button></td>
                    </form>
                    <td>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this offer?');">
                        <?php wp_nonce_field('mfa_delete_offer'); ?>
                        <input type="hidden" name="action" value="mfa_delete_offer">
                        <input type="hidden" name="offer_id" value="<?php echo esc_attr($o['id']); ?>">
                        <button class="button-link-delete">Delete</button>
                    </form>
                    </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h3>Add offer</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mfa_add_offer'); ?>
                <input type="hidden" name="action" value="mfa_add_offer">
                <table class="form-table">
                    <tr><th>Name</th><td><input type="text" name="name" class="regular-text" required placeholder="7-Day Calm Journal"></td></tr>
                    <tr><th>Affiliate URL</th><td><input type="url" name="url" class="regular-text" required placeholder="https://partner.example.com/?ref=manifestedfit"></td></tr>
                    <tr><th>Category</th><td><select name="category"><option value="">Any</option>
                        <?php foreach ($cats as $c) : ?><option value="<?php echo esc_attr($c); ?>"><?php echo esc_html($c); ?></option><?php endforeach; ?>
                    </select> <span class="description">Ads only appear in matching posts ("Any" = all posts).</span></td></tr>
                    <tr><th>Blurb</th><td><input type="text" name="blurb" class="large-text" placeholder="One honest sentence on why readers might like it."></td></tr>
                    <tr><th>Button text</th><td><input type="text" name="button" placeholder="Learn more"></td></tr>
                    <tr><th>Active</th><td><input type="checkbox" name="active" checked></td></tr>
                </table>
                <?php submit_button('Add offer'); ?>
            </form>

            <h2>Re-plan placements</h2>
            <p>New drafts are planned automatically. Use this for existing posts (or after changing offers).</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mfa_plan_post'); ?>
                <input type="hidden" name="action" value="mfa_plan_post">
                <input type="text" name="post_id" placeholder='post ID, or "all"' required>
                <button class="button button-primary">Plan placements</button>
            </form>
        </div>
        <?php
    }
}

MFA_Plugin::init();
