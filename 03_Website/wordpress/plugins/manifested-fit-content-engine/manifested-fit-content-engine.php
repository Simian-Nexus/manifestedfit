<?php
/**
 * Plugin Name: Manifested Fit Content Engine
 * Description: Supervised AI drafting engine. A daily cron (or the Run Now button) picks the day's persona and next queued topic, asks the AI to write the post (with a [VIDEO EMBED] placeholder and a YouTube video brief), saves it as a DRAFT under the persona's byline, and sends a Telegram notification for review. A companion video workflow can fetch briefs and attach approved YouTube videos via REST. Nothing is ever auto-published.
 * Version: 0.7.0
 * Author: Spinning Monkey Studios
 */

if (!defined('ABSPATH')) { exit; }

class MFCE_Engine {

    const OPT_SETTINGS = 'mfce_settings';
    const OPT_TOPICS   = 'mfce_topics';
    const OPT_LOG      = 'mfce_log';

    /**
     * Persona bylines mapped to their WP login names. Voice text is fed to the model.
     */
    public static function personas() {
        return array(
            'dana' => array(
                'login' => 'DanaCole',
                'name'  => 'Dana Cole',
                'voice' => 'Grounded and practical. Direct, warm, no fluff. Cuts to the real reason something works. Short punchy sentences, concrete steps, zero mysticism in the delivery even when the topic is manifestation. Signature energy: "Here is the real reason, and here is what to do about it."',
            ),
            'nadia' => array(
                'login' => 'NadiaBrooks',
                'name'  => 'Nadia Brooks',
                'voice' => 'Warm and story-led. Empathetic, a little vulnerable. Opens with a small personal-feeling story or scene, then draws the lesson out of it. Speaks reader-to-reader: "you are not alone in this." Gentle encouragement over instruction.',
            ),
            'frankie' => array(
                'login' => 'FrankieMoon',
                'name'  => 'Frankie Moon',
                'voice' => 'Playful and curious. Witty, light, good vibes. Uses fun metaphors and a wink of humour to make wellness feel easy and unintimidating. Never snarky or mean; the joke is always on the situation, not the reader.',
            ),
            'rowan' => array(
                'login' => 'RowanEllis',
                'name'  => 'Rowan Ellis',
                'voice' => 'Calm and minimal. Serene, spare, meditative. Fewer words, more space. Short paragraphs, soft imperatives, room to breathe. Lets the reader slow down just by reading. No exclamation marks.',
            ),
        );
    }

    /** Category names must match 05_Content/blog/categories.md exactly. */
    public static function categories() {
        return array('Manifestation', 'Mindset', 'Rituals & Routines', 'Gentle Movement', 'Sleep & Rest', 'Recipes');
    }

    /**
     * Known model ids per provider for the settings dropdowns (curated 2026-07).
     * The dropdown also offers a free-text override, so new models never require
     * a plugin update - this list just prevents typos for the common cases.
     */
    public static function known_models() {
        return array(
            'anthropic' => array('claude-opus-4-8', 'claude-fable-5', 'claude-sonnet-5', 'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5'),
            'gemini'    => array('gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite', 'gemini-2.0-flash'),
            'openai'    => array('gpt-5.1', 'gpt-5', 'gpt-5-mini', 'gpt-4.1', 'gpt-4o'),
            'grok'      => array('grok-4', 'grok-4-fast', 'grok-3', 'grok-3-mini'),
        );
    }

    /** Canadian statutory holidays -> Frankie. Format: m-d => label. */
    public static function holidays_2026() {
        return array(
            '01-01' => 'New Year\'s Day',
            '02-16' => 'Family Day',
            '04-03' => 'Good Friday',
            '04-06' => 'Easter Monday',
            '05-18' => 'Victoria Day',
            '07-01' => 'Canada Day',
            '08-03' => 'Civic Holiday',
            '09-07' => 'Labour Day',
            '10-12' => 'Thanksgiving',
            '12-25' => 'Christmas Day',
            '12-26' => 'Boxing Day',
        );
    }

    /** Solemn days: calm, respectful Rowan piece. No jokes, no CTA. */
    public static function solemn_days() {
        return array(
            '09-30' => 'National Day for Truth and Reconciliation',
            '11-11' => 'Remembrance Day',
        );
    }

    // ---------------------------------------------------------------- setup

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_mfce_save_settings', array(__CLASS__, 'handle_save_settings'));
        add_action('admin_post_mfce_add_topics', array(__CLASS__, 'handle_add_topics'));
        add_action('admin_post_mfce_update_topic', array(__CLASS__, 'handle_update_topic'));
        add_action('admin_post_mfce_delete_topic', array(__CLASS__, 'handle_delete_topic'));
        add_action('admin_post_mfce_plan_topics', array(__CLASS__, 'handle_plan_topics'));
        add_action('admin_post_mfce_apply_plan', array(__CLASS__, 'handle_apply_plan'));
        add_action('admin_post_mfce_discard_plan', array(__CLASS__, 'handle_discard_plan'));
        add_action('admin_post_mfce_run_now', array(__CLASS__, 'handle_run_now'));
        add_action('admin_post_mfce_test_telegram', array(__CLASS__, 'handle_test_telegram'));
        add_action('admin_post_mfce_register_webhook', array(__CLASS__, 'handle_register_webhook'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
        add_filter('the_content', array(__CLASS__, 'hide_video_placeholder'), 8);
        // News rewrites: the theme fires this after each fresh RSS fetch.
        add_action('mf_wellness_news_fetched', array(__CLASS__, 'queue_news_items'));
        add_action('mfce_process_news', array(__CLASS__, 'process_news_queue'));
    }

    public static function defaults() {
        return array(
            'enabled'            => 0,
            'auto_topic'         => 1, // queue empty -> the AI invents today's topic
            'news_rewrite'       => 1, // rewrite fresh wellness-news headlines into drafts
            'news_max_per_day'   => 2, // cap on news-rewrite drafts per day (0 = paused)
            'provider'           => 'anthropic', // anthropic | gemini | openai | grok | custom
            'anthropic_api_key'  => '',
            'model'              => 'claude-opus-4-8',
            'gemini_api_key'     => '',
            'gemini_model'       => 'gemini-2.5-flash',
            'openai_api_key'     => '',
            'openai_model'       => 'gpt-5.1',
            'grok_api_key'       => '',
            'grok_model'         => 'grok-4',
            'custom_base_url'    => '', // OpenAI-compatible endpoint, e.g. https://my-vm.example.com/v1 (Ollama etc.)
            'custom_api_key'     => '', // optional for local models
            'custom_model'       => '',
            'max_tokens'         => 8000,
            'pexels_api_key'     => '', // featured images: first Pexels result for the focus keyword
            'telegram_bot_token' => '',
            'telegram_chat_id'   => '',
            'cron_secret'        => '',
            'webhook_secret'     => '',
            'last_run_date'      => '',
        );
    }

    public static function settings() {
        $s = get_option(self::OPT_SETTINGS, array());
        $s = wp_parse_args(is_array($s) ? $s : array(), self::defaults());
        $dirty = false;
        if (empty($s['cron_secret']))    { $s['cron_secret']    = wp_generate_password(32, false, false); $dirty = true; }
        if (empty($s['webhook_secret'])) { $s['webhook_secret'] = wp_generate_password(32, false, false); $dirty = true; }
        if ($dirty) { update_option(self::OPT_SETTINGS, $s, false); }
        return $s;
    }

    // ------------------------------------------------------------- schedule

    /**
     * Decide who writes today. Returns array with persona key, mode and note.
     * Modes: normal | holiday | solemn
     */
    public static function persona_for_date($dt) {
        $md = $dt->format('m-d');

        $solemn = self::solemn_days();
        if (isset($solemn[$md])) {
            return array('persona' => 'rowan', 'mode' => 'solemn', 'occasion' => $solemn[$md]);
        }

        $holidays = self::holidays_2026();
        if (isset($holidays[$md]) && (int) $dt->format('Y') === 2026) {
            return array('persona' => 'frankie', 'mode' => 'holiday', 'occasion' => $holidays[$md]);
        }

        $dow = (int) $dt->format('N'); // 1 = Monday
        $map = array(
            1 => 'frankie', // Monday: defuse the dread
            2 => 'dana',    // Tuesday: get to work
            3 => 'nadia',   // Wednesday: hump-day warmth
            4 => 'dana',    // Thursday: keep momentum
            5 => 'rowan',   // Friday: wind down
        );
        if (isset($map[$dow])) {
            return array('persona' => $map[$dow], 'mode' => 'normal', 'occasion' => '');
        }

        $keys = array_keys(self::personas());
        return array('persona' => $keys[wp_rand(0, count($keys) - 1)], 'mode' => 'normal', 'occasion' => '');
    }

    // ----------------------------------------------------------- the runner

    /**
     * The whole pipeline: persona -> topic -> Claude -> draft -> Telegram.
     * Returns array('ok' => bool, 'message' => string, 'post_id' => int|null).
     */
    public static function run($force = false, $context = 'manual') {
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $s = self::settings();

        if (!$force && empty($s['enabled'])) {
            return self::finish(false, 'Engine is disabled in settings (cron run skipped).', null, false);
        }
        if (!self::provider_ready($s)) {
            return self::finish(false, 'No API key configured for the selected provider (' . $s['provider'] . ').', null, true);
        }

        $now = new DateTime('now', wp_timezone());
        $today = $now->format('Y-m-d');

        if (!$force && $s['last_run_date'] === $today) {
            return self::finish(false, 'Already ran today (' . $today . '). Use Run Now to force another draft.', null, false);
        }

        $pick = self::persona_for_date($now);
        $personas = self::personas();

        // Solemn days write a fixed-purpose piece and do not consume a queue topic.
        $topic = null;
        if ($pick['mode'] === 'solemn') {
            $topic = array(
                'title' => 'A quiet reflection for ' . $pick['occasion'],
                'notes' => 'This is ' . $pick['occasion'] . ' in Canada, a day of mourning and remembrance. Write a short, respectful, calm reflection. No humour, no product mentions, no calls to action, no manifestation framing. Simply honour the day and invite stillness.',
            );
        } else {
            $topic = self::next_topic();
            // A topic can pin a specific persona, overriding the day-of-week schedule.
            if ($topic && !empty($topic['persona']) && isset($personas[$topic['persona']])) {
                $pick['persona'] = $topic['persona'];
            }
        }

        $persona = $personas[$pick['persona']];
        $author = get_user_by('login', $persona['login']);
        if (!$author) {
            return self::finish(false, 'WP user not found for persona login "' . $persona['login'] . '".', null, true);
        }

        // Queue empty: either the AI invents today's topic, or we stop loudly.
        if (!$topic) {
            if (empty($s['auto_topic'])) {
                return self::finish(false, 'Topic queue is empty - add topics in the Content Engine admin page.', null, true);
            }
            $topic = self::auto_topic($s, $persona, $pick, $now);
            if (is_wp_error($topic)) {
                return self::finish(false, 'Topic queue empty and AI topic pick failed: ' . $topic->get_error_message(), null, true);
            }
        }

        // A topic can also pin a provider (and optionally a model). Fall back to
        // the default provider if the override has no key configured.
        $gen_s = $s;
        if (!empty($topic['provider'])) {
            $gen_s['provider'] = $topic['provider'];
            if (!empty($topic['model'])) {
                $model_keys = array('anthropic' => 'model', 'gemini' => 'gemini_model', 'openai' => 'openai_model', 'grok' => 'grok_model', 'custom' => 'custom_model');
                if (isset($model_keys[$gen_s['provider']])) { $gen_s[$model_keys[$gen_s['provider']]] = $topic['model']; }
            }
            if (!self::provider_ready($gen_s)) {
                self::log('WARN: topic wanted provider "' . $topic['provider'] . '" but it has no key - using the default provider instead.');
                $gen_s = $s;
            }
        }

        $result = self::generate_post($gen_s, $persona, $pick, $topic);
        if (is_wp_error($result)) {
            return self::finish(false, 'AI call failed (' . self::provider_label($gen_s) . '): ' . $result->get_error_message(), null, true);
        }

        // Resolve categories by name, creating them if missing.
        $cat_ids = array();
        $allowed = self::categories();
        foreach ((array) $result['categories'] as $cat_name) {
            if (!in_array($cat_name, $allowed, true)) { continue; }
            $term = get_term_by('name', $cat_name, 'category');
            if (!$term) {
                $created = wp_insert_term($cat_name, 'category');
                if (!is_wp_error($created)) { $cat_ids[] = (int) $created['term_id']; }
            } else {
                $cat_ids[] = (int) $term->term_id;
            }
        }

        $post_id = wp_insert_post(array(
            'post_title'    => wp_strip_all_tags($result['title']),
            'post_name'     => sanitize_title($result['slug']),
            'post_content'  => wp_kses_post($result['content_html']),
            'post_excerpt'  => sanitize_text_field($result['excerpt']),
            'post_status'   => 'draft', // ALWAYS draft. The engine never publishes.
            'post_author'   => $author->ID,
            'post_category' => $cat_ids,
        ), true);

        if (is_wp_error($post_id)) {
            return self::finish(false, 'wp_insert_post failed: ' . $post_id->get_error_message(), null, true);
        }

        if (!empty($result['focus_keyword'])) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($result['focus_keyword']));
        }

        // Video pipeline: solemn pieces get no video; everything else waits for one.
        if ($pick['mode'] !== 'solemn') {
            update_post_meta($post_id, 'mfce_video_status', 'needed');
            if (!empty($result['video_brief']) && is_array($result['video_brief'])) {
                update_post_meta($post_id, 'mfce_video_brief', wp_json_encode($result['video_brief']));
            }
        }

        if ($pick['mode'] !== 'solemn' && isset($topic['id'])) {
            self::mark_topic_used($topic['id'], $post_id);
        }

        // Featured image: first Pexels landscape photo for the focus keyword (best effort).
        $img_query = !empty($result['focus_keyword']) ? $result['focus_keyword'] : $result['title'];
        self::set_featured_from_pexels($post_id, $img_query);

        // Let companion plugins (e.g. the affiliate ad planner) react to the new draft.
        do_action('mfce_draft_created', $post_id, $result, $persona, $pick);

        // Only scheduled runs consume the daily slot: a manual Run Now (or an
        // evening run that lands past midnight server time) must never make
        // the next morning's cron skip.
        if (strpos($context, 'cron') !== false) {
            $s = self::settings();
            $s['last_run_date'] = $today;
            update_option(self::OPT_SETTINGS, $s, false);
        }

        // Running low on topics? Ask the AI for suggestions and offer them in
        // Telegram as one-tap approvals.
        self::maybe_suggest_topics($s);

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        $msg = "Manifested Fit Engine\n"
             . "New draft by {$persona['name']} (" . $now->format('D M j') . ", {$pick['mode']}):\n"
             . '"' . $result['title'] . "\"\n"
             . (!empty($topic['auto']) ? "Topic was AI-chosen (the queue was empty).\n" : '')
             . "Review: {$edit_url}";
        self::telegram_send_draft_notice($s, $post_id, $msg);

        return self::finish(true, 'Draft #' . $post_id . ' created by ' . $persona['name'] . ' via ' . $context . ': "' . $result['title'] . '"', $post_id, false);
    }

    /**
     * Best-effort featured image: search Pexels for the query, sideload the
     * first landscape result into the media library, set it as the thumbnail.
     * Silent no-op when no key is configured or nothing matches.
     */
    /** First matching Pexels landscape photo URL for a query, or false. */
    public static function pexels_photo_url($s, $query, $skip = 0) {
        if (empty($s['pexels_api_key'])) { return false; }
        $response = wp_remote_get(
            'https://api.pexels.com/v1/search?query=' . rawurlencode($query . ' wellness') . '&orientation=landscape&per_page=' . ($skip + 1),
            array('timeout' => 30, 'headers' => array('Authorization' => $s['pexels_api_key']))
        );
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            self::log('WARN: Pexels search failed for "' . $query . '"');
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $photo = isset($data['photos'][$skip]) ? $data['photos'][$skip] : (isset($data['photos'][0]) ? $data['photos'][0] : null);
        if (empty($photo['src'])) {
            self::log('WARN: Pexels had no photo for "' . $query . '"');
            return false;
        }
        return !empty($photo['src']['large2x']) ? $photo['src']['large2x'] : $photo['src']['large'];
    }

    public static function set_featured_from_pexels($post_id, $query) {
        $s = self::settings();
        if (empty($s['pexels_api_key']) || has_post_thumbnail($post_id)) { return false; }
        $url = self::pexels_photo_url($s, $query);
        if (!$url) { return false; }
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        // Pexels CDN URLs have no extension in the path; give sideload a real filename.
        $attachment_id = media_sideload_image($url . '#.jpg', $post_id, $query, 'id');
        if (is_wp_error($attachment_id)) {
            self::log('WARN: featured image sideload failed: ' . $attachment_id->get_error_message());
            return false;
        }
        set_post_thumbnail($post_id, $attachment_id);
        self::log('OK: featured image set on #' . $post_id . ' (Pexels, "' . $query . '")');
        return true;
    }

    /**
     * Front end: never show a raw [VIDEO EMBED] placeholder to readers.
     * While the real video is pending, show a topical Pexels photo in its
     * place instead (cached in post meta; skipped silently without a key).
     * The stored content keeps the placeholder, so the video worker's embed
     * step still finds and replaces it later.
     */
    public static function hide_video_placeholder($content) {
        if (is_admin()) { return $content; }
        if (!preg_match('/<p>\s*\[VIDEO EMBED\]\s*<\/p>/i', $content)) { return $content; }

        $replacement = '';
        $post_id = get_the_ID();
        if ($post_id) {
            $img = get_post_meta($post_id, 'mfce_standin_img', true);
            if ($img === '') {
                // Pick a query that matches the surrounding prose: the first
                // heading after the placeholder, else the focus keyword/title.
                $query = '';
                if (preg_match('/\[VIDEO EMBED\]\s*<\/p>.*?<h[23][^>]*>(.*?)<\/h[23]>/is', $content, $hm)) {
                    $query = wp_strip_all_tags($hm[1]);
                }
                if ($query === '') { $query = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true); }
                if ($query === '') { $query = get_the_title($post_id); }
                $engine_s = self::settings();
                if (!empty($engine_s['pexels_api_key'])) {
                    // Only cache the outcome when a key exists - otherwise the
                    // 'none' would stick forever once a key is finally added.
                    $url = self::pexels_photo_url($engine_s, $query, 1); // skip #0: usually the featured image
                    $img = $url ? $url : 'none';
                    update_post_meta($post_id, 'mfce_standin_img', $img);
                }
            }
            if ($img && $img !== 'none') {
                $replacement = '<figure class="mfce-standin" style="margin:2rem 0"><img src="' . esc_url($img) . '" alt="" loading="lazy" style="width:100%;border-radius:12px"></figure>';
            }
        }
        return preg_replace('/<p>\s*\[VIDEO EMBED\]\s*<\/p>/i', $replacement, $content, 1);
    }

    /** Log + optionally notify Telegram about failures, then return a result array. */
    private static function finish($ok, $message, $post_id, $notify_failure) {
        self::log(($ok ? 'OK: ' : 'SKIP/FAIL: ') . $message);
        if (!$ok && $notify_failure) {
            self::telegram_send(self::settings(), "Manifested Fit Engine problem:\n" . $message);
        }
        return array('ok' => $ok, 'message' => $message, 'post_id' => $post_id);
    }

    // -------------------------------------------------------------- Claude

    private static function generate_post($s, $persona, $pick, $topic) {
        $cats = self::categories();

        $system = "You are a columnist for Manifested Fit (manifestedfit.com/blog), a wellness brand blending manifestation, gentle fitness, and mindset for everyday people.\n\n"
            . "You write as the persona \"{$persona['name']}\". Voice: {$persona['voice']}\n\n"
            . "House rules:\n"
            . "- 700-1200 words (solemn reflections may be shorter).\n"
            . "- Personas are voice bylines only: never claim credentials, cite fake studies, or present yourself as a doctor, therapist, or scientist.\n"
            . "- No medical claims or income promises. Educational, encouraging framing only.\n"
            . "- Practical and genuinely useful: the reader should be able to DO something after reading.\n"
            . "- Mention the free 7-Day Mind-Body Reset at manifestedfit.com once, naturally, near the end (skip this on solemn pieces).\n"
            . "- content_html must be clean HTML using only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>, <a>. No <h1> (the title is separate), no inline styles, no scripts.\n"
            . "- excerpt: one sentence, max 155 characters, written like a meta description.\n"
            . "- slug: lowercase words joined by hyphens.\n"
            . "- focus_keyword: the 2-4 word phrase the post should rank for.\n"
            . "- categories: pick 1 (max 2) from exactly this list: " . implode(', ', $cats) . '.';

        if ($pick['mode'] !== 'solemn') {
            $system .= "\n- Include exactly one paragraph whose entire content is the text [VIDEO EMBED], i.e. <p>[VIDEO EMBED]</p>, placed where a short companion video fits best (usually right after the intro, or just before the step-by-step section). It is replaced with a real YouTube video later, so do not reference \"the video above/below\" anywhere in the text."
                . "\n- video_brief: a production plan for a 60-90 second faceless YouTube companion video for this exact post. youtube_title: max 90 characters, curiosity-driven, not clickbait-dishonest. youtube_description: 2-3 sentences ending with 'Full article: {POST_URL}' (keep that placeholder literally). voiceover_script: only the spoken words, 150-220 words, in the persona's voice, standalone (a viewer who never reads the post should still get value). visual_direction: shot-by-shot guidance (b-roll, stock-footage keywords, on-screen text overlays) that an editor or AI video tool can follow.";
        }

        $user = "Write today's blog post.\n\nTopic: {$topic['title']}";
        if (!empty($topic['notes'])) {
            $user .= "\n\nEditor notes: {$topic['notes']}";
        }
        if ($pick['mode'] === 'holiday') {
            $user .= "\n\nToday is {$pick['occasion']} in Canada - weave a celebratory, feel-good holiday angle into the post.";
        }

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title'         => array('type' => 'string'),
                'slug'          => array('type' => 'string'),
                'excerpt'       => array('type' => 'string'),
                'focus_keyword' => array('type' => 'string'),
                'categories'    => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => $cats)),
                'content_html'  => array('type' => 'string'),
            ),
            'required' => array('title', 'slug', 'excerpt', 'focus_keyword', 'categories', 'content_html'),
            'additionalProperties' => false,
        );

        if ($pick['mode'] !== 'solemn') {
            $schema['properties']['video_brief'] = array(
                'type' => 'object',
                'properties' => array(
                    'youtube_title'       => array('type' => 'string'),
                    'youtube_description' => array('type' => 'string'),
                    'voiceover_script'    => array('type' => 'string'),
                    'visual_direction'    => array('type' => 'string'),
                ),
                'required' => array('youtube_title', 'youtube_description', 'voiceover_script', 'visual_direction'),
                'additionalProperties' => false,
            );
            $schema['required'][] = 'video_brief';
        }

        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed)) { return $parsed; }
        if (empty($parsed['title']) || empty($parsed['content_html'])) {
            return new WP_Error('mfce_parse', 'Structured response was missing title or content.');
        }
        return $parsed;
    }

    /** Low-level Messages API call. Returns the first text block or WP_Error. */
    private static function claude_request($s, $body) {
        $response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
            'timeout' => 280,
            'headers' => array(
                'Content-Type'      => 'application/json',
                'x-api-key'         => $s['anthropic_api_key'],
                'anthropic-version' => '2023-06-01',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return new WP_Error('mfce_api', $api_msg);
        }
        if (isset($data['stop_reason']) && $data['stop_reason'] === 'refusal') {
            return new WP_Error('mfce_refusal', 'The model refused this request.');
        }
        if (isset($data['stop_reason']) && $data['stop_reason'] === 'max_tokens') {
            return new WP_Error('mfce_truncated', 'Output hit max_tokens and is incomplete - raise max_tokens in settings.');
        }

        foreach ((array) $data['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') { return $block['text']; }
        }
        return new WP_Error('mfce_empty', 'No text content in the model response.');
    }

    /** Structured-output call: returns the decoded JSON object or WP_Error. */
    private static function claude_json($s, $system, $user, $schema) {
        $text = self::claude_request($s, array(
            'model'      => $s['model'],
            'max_tokens' => (int) $s['max_tokens'],
            'system'     => $system,
            'messages'   => array(array('role' => 'user', 'content' => $user)),
            'output_config' => array(
                'format' => array('type' => 'json_schema', 'schema' => $schema),
            ),
        ));
        if (is_wp_error($text)) { return $text; }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return new WP_Error('mfce_parse', 'Could not parse structured JSON from the model response.');
        }
        return $parsed;
    }

    /** Plain-text call (Telegram chat). Returns string or WP_Error. */
    private static function claude_text($s, $system, $user) {
        return self::claude_request($s, array(
            'model'      => $s['model'],
            'max_tokens' => 2000,
            'system'     => $system,
            'messages'   => array(array('role' => 'user', 'content' => $user)),
        ));
    }

    // ---------------------------------------------------- provider routing

    public static function provider_ready($s) {
        switch ($s['provider']) {
            case 'gemini': return !empty($s['gemini_api_key']);
            case 'openai': return !empty($s['openai_api_key']);
            case 'grok':   return !empty($s['grok_api_key']);
            case 'custom': return !empty($s['custom_base_url']) && !empty($s['custom_model']); // key optional for local models
            default:       return !empty($s['anthropic_api_key']);
        }
    }

    public static function provider_label($s) {
        switch ($s['provider']) {
            case 'gemini': return 'Gemini / ' . $s['gemini_model'];
            case 'openai': return 'OpenAI / ' . $s['openai_model'];
            case 'grok':   return 'Grok (xAI) / ' . $s['grok_model'];
            case 'custom': return 'Custom / ' . $s['custom_model'] . ' @ ' . $s['custom_base_url'];
            default:       return 'Anthropic / ' . $s['model'];
        }
    }

    /** Route a structured-JSON generation to the selected provider. Public so companion plugins (affiliates) can reuse it. */
    public static function ai_json($s, $system, $user, $schema) {
        switch ($s['provider']) {
            case 'gemini': return self::gemini_json($s, $system, $user, $schema);
            case 'openai':
            case 'grok':
            case 'custom': return self::oai_json($s, $system, $user, $schema);
            default:       return self::claude_json($s, $system, $user, $schema);
        }
    }

    /** Route a plain-text generation to the selected provider. */
    private static function ai_text($s, $system, $user) {
        switch ($s['provider']) {
            case 'gemini': return self::gemini_text($s, $system, $user);
            case 'openai':
            case 'grok':
            case 'custom': return self::oai_request($s, $system, $user, 2000, null);
            default:       return self::claude_text($s, $system, $user);
        }
    }

    // ------------------------------- OpenAI-compatible (OpenAI, Grok, Ollama)

    private static function oai_config($s) {
        if ($s['provider'] === 'openai') {
            // Newer OpenAI models reject max_tokens in favour of max_completion_tokens.
            return array('base' => 'https://api.openai.com/v1', 'key' => $s['openai_api_key'], 'model' => $s['openai_model'], 'max_param' => 'max_completion_tokens');
        }
        if ($s['provider'] === 'grok') {
            return array('base' => 'https://api.x.ai/v1', 'key' => $s['grok_api_key'], 'model' => $s['grok_model'], 'max_param' => 'max_tokens');
        }
        return array('base' => rtrim((string) $s['custom_base_url'], '/'), 'key' => $s['custom_api_key'], 'model' => $s['custom_model'], 'max_param' => 'max_tokens');
    }

    /** Low-level chat-completions call. Returns the reply text or WP_Error. */
    private static function oai_request($s, $system, $user, $max_tokens, $response_format) {
        $cfg = self::oai_config($s);
        if (empty($cfg['base']) || empty($cfg['model'])) {
            return new WP_Error('mfce_oai', 'The custom provider needs a base URL and model name in settings.');
        }
        $body = array(
            'model'    => $cfg['model'],
            'messages' => array(
                array('role' => 'system', 'content' => $system),
                array('role' => 'user',   'content' => $user),
            ),
        );
        $body[$cfg['max_param']] = (int) $max_tokens;
        if ($response_format) { $body['response_format'] = $response_format; }

        $headers = array('Content-Type' => 'application/json');
        if (!empty($cfg['key'])) { $headers['Authorization'] = 'Bearer ' . $cfg['key']; }

        $response = wp_remote_post($cfg['base'] . '/chat/completions', array(
            'timeout' => 280,
            'headers' => $headers,
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($response)) { return $response; }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return new WP_Error($code === 400 ? 'mfce_oai_400' : 'mfce_oai', $api_msg);
        }
        if (empty($data['choices'][0])) {
            return new WP_Error('mfce_oai', 'No choices in the response.');
        }
        $choice = $data['choices'][0];
        if (!empty($choice['message']['refusal'])) {
            return new WP_Error('mfce_refusal', 'The model refused: ' . $choice['message']['refusal']);
        }
        if (isset($choice['finish_reason']) && $choice['finish_reason'] === 'length') {
            return new WP_Error('mfce_truncated', 'Output hit the token limit and is incomplete - raise max_tokens in settings.');
        }
        $text = isset($choice['message']['content']) ? trim((string) $choice['message']['content']) : '';
        if ($text === '') { return new WP_Error('mfce_oai', 'Empty response from the model.'); }
        return $text;
    }

    /**
     * Structured JSON with graceful degradation: strict json_schema (OpenAI/Grok)
     * -> json_object (most Ollama builds) -> plain prompt-enforced JSON.
     */
    private static function oai_json($s, $system, $user, $schema) {
        $prompted = $system . "\n\nRespond with ONLY a single JSON object (no markdown fences, no commentary) matching this JSON schema:\n" . wp_json_encode($schema);
        $attempts = array(
            array($system,   array('type' => 'json_schema', 'json_schema' => array('name' => 'mfce_output', 'strict' => true, 'schema' => $schema))),
            array($prompted, array('type' => 'json_object')),
            array($prompted, null),
        );
        $text = null;
        foreach ($attempts as $attempt) {
            $text = self::oai_request($s, $attempt[0], $user, (int) $s['max_tokens'], $attempt[1]);
            // Only a 400 (unsupported response_format on this server) triggers the next fallback.
            if (!is_wp_error($text) || $text->get_error_code() !== 'mfce_oai_400') { break; }
        }
        if (is_wp_error($text)) { return $text; }

        // Smaller local models sometimes wrap JSON in code fences anyway.
        $text = trim($text);
        if (strpos($text, '```') === 0) {
            $text = preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
        }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return new WP_Error('mfce_parse', 'Could not parse JSON from the model response.');
        }
        return $parsed;
    }

    // -------------------------------------------------------------- gemini

    /** Low-level Gemini generateContent call. Returns text or WP_Error. */
    private static function gemini_request($s, $system, $user, $generation_config) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($s['gemini_model']) . ':generateContent';
        $body = array(
            'system_instruction' => array('parts' => array(array('text' => $system))),
            'contents'           => array(array('role' => 'user', 'parts' => array(array('text' => $user)))),
            'generationConfig'   => $generation_config,
        );
        $response = wp_remote_post($url, array(
            'timeout' => 280,
            'headers' => array(
                'Content-Type'   => 'application/json',
                'x-goog-api-key' => $s['gemini_api_key'],
            ),
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($response)) { return $response; }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $api_msg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $code);
            return new WP_Error('mfce_gemini', $api_msg);
        }
        if (empty($data['candidates'][0])) {
            $reason = isset($data['promptFeedback']['blockReason']) ? $data['promptFeedback']['blockReason'] : 'no candidates';
            return new WP_Error('mfce_gemini', 'Gemini returned nothing (' . $reason . ').');
        }
        $candidate = $data['candidates'][0];
        if (isset($candidate['finishReason']) && !in_array($candidate['finishReason'], array('STOP', 'MAX_TOKENS'), true)) {
            return new WP_Error('mfce_gemini', 'Gemini stopped: ' . $candidate['finishReason']);
        }
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? array()) as $part) {
            if (isset($part['text'])) { $text .= $part['text']; }
        }
        if ($text === '') { return new WP_Error('mfce_gemini', 'Empty Gemini response.'); }
        if (isset($candidate['finishReason']) && $candidate['finishReason'] === 'MAX_TOKENS') {
            return new WP_Error('mfce_truncated', 'Gemini output hit the token limit and is incomplete - raise max_tokens in settings.');
        }
        return $text;
    }

    private static function gemini_json($s, $system, $user, $schema) {
        $text = self::gemini_request($s, $system, $user, array(
            'maxOutputTokens'  => (int) $s['max_tokens'],
            'responseMimeType' => 'application/json',
            'responseSchema'   => self::gemini_schema($schema),
        ));
        if (is_wp_error($text)) { return $text; }
        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return new WP_Error('mfce_parse', 'Could not parse JSON from the Gemini response.');
        }
        return $parsed;
    }

    private static function gemini_text($s, $system, $user) {
        return self::gemini_request($s, $system, $user, array('maxOutputTokens' => 2000));
    }

    /** Convert our JSON schema to Gemini's responseSchema dialect. */
    private static function gemini_schema($schema) {
        $out = array();
        foreach ($schema as $key => $value) {
            if ($key === 'additionalProperties') { continue; } // not supported by Gemini
            if ($key === 'type' && is_string($value)) {
                $out['type'] = strtoupper($value);
            } elseif ($key === 'properties' && is_array($value)) {
                $out['properties'] = array();
                foreach ($value as $prop => $sub) { $out['properties'][$prop] = self::gemini_schema($sub); }
            } elseif ($key === 'items' && is_array($value)) {
                $out['items'] = self::gemini_schema($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Revise an existing draft per instructions sent from Telegram. */
    private static function revise_post($s, $post_id, $instructions) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'draft') {
            return new WP_Error('mfce_gone', 'Post #' . $post_id . ' is not an editable draft anymore.');
        }
        $author = get_user_by('id', $post->post_author);
        $voice = '';
        foreach (self::personas() as $p) {
            if ($author && $p['login'] === $author->user_login) { $voice = 'Persona voice: ' . $p['voice']; break; }
        }

        // Shield the video from the model: swap any embedded video block for the
        // plain placeholder before revising, and restore it afterwards.
        $content_for_ai = $post->post_content;
        $video_block = '';
        if (preg_match('/<!-- wp:embed\b.*?<!-- \/wp:embed -->/s', $content_for_ai, $vm)) {
            $video_block = $vm[0];
            $content_for_ai = str_replace($video_block, '<p>[VIDEO EMBED]</p>', $content_for_ai);
        }

        $system = "You are revising a draft blog post for Manifested Fit (wellness/manifestation blog). {$voice}\n"
            . "Apply the editor's instructions while keeping the persona voice, honesty guardrails (no fabricated credentials, no medical or income claims) and clean HTML (<h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>, <a> only).\n"
            . "If the draft contains a paragraph that is exactly [VIDEO EMBED], keep that paragraph unchanged (you may relocate it only if the instructions ask) - it marks where a companion video sits.";
        $user = "Editor's instructions: {$instructions}\n\nCurrent title: {$post->post_title}\n\nCurrent excerpt: {$post->post_excerpt}\n\nCurrent HTML:\n{$content_for_ai}";
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title'        => array('type' => 'string'),
                'excerpt'      => array('type' => 'string'),
                'content_html' => array('type' => 'string'),
            ),
            'required' => array('title', 'excerpt', 'content_html'),
            'additionalProperties' => false,
        );

        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed)) { return $parsed; }

        $new_content = wp_kses_post($parsed['content_html']);
        if ($video_block !== '') {
            $new_content = self::insert_video_markup($new_content, $video_block);
        }

        kses_remove_filters(); // webhook runs unauthenticated; see attach_video()
        $updated = wp_update_post(array(
            'ID'           => $post_id,
            'post_title'   => wp_strip_all_tags($parsed['title']),
            'post_excerpt' => sanitize_text_field($parsed['excerpt']),
            'post_content' => $new_content,
        ), true);
        kses_init_filters();
        if (is_wp_error($updated)) { return $updated; }

        self::log('OK: draft #' . $post_id . ' revised via Telegram.');
        return $parsed;
    }

    // --------------------------------------------------------------- video

    /** Matches the placeholder the AI is told to leave in every non-solemn post. */
    const VIDEO_PLACEHOLDER = '/<p>\s*\[VIDEO EMBED\]\s*<\/p>|\[VIDEO EMBED\]/i';

    /** Accepts a bare YouTube ID or any usual URL form; returns the ID or ''. */
    public static function youtube_id($input) {
        $input = trim((string) $input);
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $input)) { return $input; }
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $input, $m)) { return $m[1]; }
        return '';
    }

    /** Direct responsive iframe for a YouTube URL. Deliberately NOT an oEmbed /
     * wp:embed block: oEmbed resolution can fail on shared hosting (leaving a
     * bare URL in the post, as happened on mobile), while a plain iframe with
     * inline styles renders in any theme with no oEmbed or theme CSS needed. */
    private static function video_embed_block($url) {
        $id = self::youtube_id($url);
        return '<figure class="mfce-video" style="margin:2em 0;">'
            . '<div style="position:relative;padding-top:56.25%;">'
            . '<iframe src="https://www.youtube.com/embed/' . esc_attr($id) . '" '
            . 'title="Video" loading="lazy" '
            . 'style="position:absolute;top:0;left:0;width:100%;height:100%;border:0;" '
            . 'allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" '
            . 'allowfullscreen></iframe></div></figure>';
    }

    /** Swap the [VIDEO EMBED] placeholder for $markup; if a video was embedded
     * before (either markup generation), replace that; else after first paragraph. */
    private static function insert_video_markup($content, $markup) {
        if (preg_match(self::VIDEO_PLACEHOLDER, $content)) {
            return preg_replace_callback(self::VIDEO_PLACEHOLDER, function () use ($markup) {
                return $markup;
            }, $content, 1);
        }
        // legacy wp:embed block from plugin <= 0.4.1
        $legacy = '/<!-- wp:embed\b.*?<!-- \/wp:embed -->/s';
        if (preg_match($legacy, $content)) {
            return preg_replace_callback($legacy, function () use ($markup) {
                return $markup;
            }, $content, 1);
        }
        $figure = '/<figure class="mfce-video".*?<\/figure>/s';
        if (preg_match($figure, $content)) {
            return preg_replace_callback($figure, function () use ($markup) {
                return $markup;
            }, $content, 1);
        }
        $pos = stripos($content, '</p>');
        if ($pos !== false) {
            return substr_replace($content, "\n" . $markup . "\n", $pos + 4, 0);
        }
        return $markup . "\n" . $content;
    }

    /** Embed an approved YouTube video into a draft and notify Telegram. */
    private static function attach_video($s, $post_id, $youtube_url) {
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error('mfce_gone', 'Post #' . $post_id . ' does not exist.');
        }
        $content = self::insert_video_markup($post->post_content, self::video_embed_block($youtube_url));

        // This REST call runs unauthenticated, so kses would strip the embed block's
        // comments/JSON. The markup is built entirely by us from a validated video ID,
        // so lifting kses for this one controlled update is safe.
        kses_remove_filters();
        $updated = wp_update_post(array('ID' => $post_id, 'post_content' => $content), true);
        kses_init_filters();
        if (is_wp_error($updated)) { return $updated; }

        update_post_meta($post_id, 'mfce_video_status', 'embedded');
        update_post_meta($post_id, 'mfce_youtube_url', esc_url_raw($youtube_url));
        self::log('OK: video embedded in post #' . $post_id . ' (' . $youtube_url . ').');

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        self::telegram_send_draft_notice($s, $post_id,
            "Manifested Fit Engine\nVideo is now embedded in the draft:\n\"{$post->post_title}\"\nWatch: {$youtube_url}\nReview: {$edit_url}");
        return true;
    }

    // -------------------------------------------------------------- topics

    public static function topics() {
        $t = get_option(self::OPT_TOPICS, array());
        return is_array($t) ? $t : array();
    }

    private static function next_topic() {
        foreach (self::topics() as $topic) {
            if (isset($topic['status']) && $topic['status'] === 'pending') { return $topic; }
        }
        return null;
    }

    /**
     * Queue is empty: ask the AI to pick today's topic itself, considering the
     * day of week, holidays/weekends, the persona, and recent posts (so it can
     * avoid repeats or deliberately continue a theme/series). The picked topic
     * is appended to the queue so it shows up in the admin table like any other.
     */
    private static function auto_topic($s, $persona, $pick, $now) {
        $recent = array();
        foreach (get_posts(array('post_status' => array('publish', 'draft', 'pending'), 'numberposts' => 12, 'orderby' => 'date', 'order' => 'DESC')) as $p) {
            $recent[] = $p->post_title;
        }

        $system = 'You are the content planner for Manifested Fit (manifestedfit.com/blog), a wellness brand blending manifestation, gentle fitness, and mindset for everyday people. '
            . 'Pick ONE topic for today\'s post. Practical and doable beats abstract. No medical claims, no income promises. '
            . 'Categories the blog covers: ' . implode(', ', self::categories()) . '.';

        $user = 'Today is ' . $now->format('l, F j, Y') . '.'
            . ($pick['mode'] === 'holiday' ? ' It is ' . $pick['occasion'] . ' in Canada - a celebratory angle is welcome.' : '')
            . ((int) $now->format('N') >= 6 ? ' It is the weekend - lighter, restorative topics fit well.' : '')
            . "\n\nToday's columnist is {$persona['name']}. Voice: {$persona['voice']}"
            . "\n\nRecent post titles (avoid repeating these; if several form a natural theme or series, you MAY continue it with a clearly next-step topic):\n- "
            . ($recent ? implode("\n- ", $recent) : '(no posts yet)')
            . "\n\nReturn the topic title and short editor notes (the angle, and 2-3 things the post must cover).";

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title' => array('type' => 'string'),
                'notes' => array('type' => 'string'),
            ),
            'required' => array('title', 'notes'),
            'additionalProperties' => false,
        );

        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed)) { return $parsed; }
        if (empty($parsed['title'])) {
            return new WP_Error('mfce_autotopic', 'The AI returned no topic title.');
        }

        $topic = array(
            'id'     => uniqid('t'),
            'title'  => sanitize_text_field($parsed['title']),
            'notes'  => sanitize_text_field($parsed['notes'] ?? ''),
            'status' => 'pending',
            'auto'   => 1,
        );
        $topics = self::topics();
        $topics[] = $topic;
        update_option(self::OPT_TOPICS, $topics, false);
        self::log('OK: queue was empty - AI picked today\'s topic: "' . $topic['title'] . '"');
        return $topic;
    }

    /** Append one pending topic to the queue. Returns the new topic. */
    public static function add_topic($title, $notes = '', $flags = array()) {
        $topic = array_merge(array(
            'id'     => uniqid('t'),
            'title'  => sanitize_text_field($title),
            'notes'  => sanitize_text_field($notes),
            'status' => 'pending',
        ), $flags);
        $topics = self::topics();
        $topics[] = $topic;
        update_option(self::OPT_TOPICS, $topics, false);
        return $topic;
    }

    /** How many topics are still pending in the queue. */
    private static function pending_topic_count() {
        $n = 0;
        foreach (self::topics() as $t) {
            if (isset($t['status']) && $t['status'] === 'pending') { $n++; }
        }
        return $n;
    }

    /**
     * Queue running low (<= 2 pending)? Ask the AI for candidate topics and
     * send them to Telegram with one-tap "Add" buttons. At most once a day.
     */
    private static function maybe_suggest_topics($s) {
        if (self::pending_topic_count() > 2) { return; }
        if (get_option('mfce_suggest_date') === current_time('Y-m-d')) { return; }
        if (!self::provider_ready($s)) { return; }

        $recent = array();
        foreach (get_posts(array('post_status' => array('publish', 'draft', 'pending'), 'numberposts' => 12, 'orderby' => 'date', 'order' => 'DESC')) as $p) {
            $recent[] = $p->post_title;
        }
        $pending = array();
        foreach (self::topics() as $t) {
            if (($t['status'] ?? '') === 'pending') { $pending[] = $t['title']; }
        }

        $system = 'You are the content planner for Manifested Fit (manifestedfit.com/blog), a wellness brand blending manifestation, gentle fitness, and mindset for everyday people. '
            . 'Suggest 4 fresh blog topics. Practical and doable beats abstract. No medical claims, no income promises. '
            . 'Categories: ' . implode(', ', self::categories()) . '.';
        $user = "Recent post titles (do not repeat):\n- " . ($recent ? implode("\n- ", $recent) : '(none)')
            . "\n\nTopics already queued:\n- " . ($pending ? implode("\n- ", $pending) : '(none)')
            . "\n\nReturn 4 suggestions, each with a title and short editor notes (angle + 2-3 must-covers).";
        $schema = array(
            'type' => 'object',
            'properties' => array(
                'suggestions' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title' => array('type' => 'string'),
                            'notes' => array('type' => 'string'),
                        ),
                        'required' => array('title', 'notes'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('suggestions'),
            'additionalProperties' => false,
        );
        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed) || empty($parsed['suggestions'])) { return; }

        $store = array();
        $lines = "Topic queue is nearly empty (" . self::pending_topic_count() . " left). Suggestions - tap to add:\n";
        $buttons = array();
        foreach (array_slice($parsed['suggestions'], 0, 4) as $i => $sug) {
            $id = uniqid('s');
            $store[$id] = array('title' => sanitize_text_field($sug['title']), 'notes' => sanitize_text_field($sug['notes'] ?? ''));
            $lines .= "\n" . ($i + 1) . '. ' . $store[$id]['title'] . "\n   " . $store[$id]['notes'] . "\n";
            $buttons[] = array(array('text' => 'Add #' . ($i + 1), 'callback_data' => 'addtopic:' . $id));
        }
        update_option('mfce_tg_suggestions', $store, false);
        update_option('mfce_suggest_date', current_time('Y-m-d'), false);
        self::telegram_send($s, $lines . "\nOr just message me a topic of your own and ask me to queue it.", array(
            'reply_markup' => array('inline_keyboard' => $buttons),
        ));
        self::log('OK: queue low - sent ' . count($store) . ' topic suggestions to Telegram.');
    }

    private static function mark_topic_used($id, $post_id) {
        $topics = self::topics();
        foreach ($topics as &$t) {
            if ($t['id'] === $id) {
                $t['status']  = 'used';
                $t['post_id'] = $post_id;
                $t['used_at'] = current_time('mysql');
            }
        }
        update_option(self::OPT_TOPICS, $topics, false);
    }

    // ------------------------------------------------------------ telegram

    /** Generic Telegram Bot API call. Returns the decoded "result" or false. */
    // -------------------------------------------------------- news rewrites

    /**
     * Wellness-news rewrite engine. The blog theme fires
     * `mf_wellness_news_fetched` whenever the front page pulls a fresh batch
     * of RSS headlines; we queue the ones we have not seen before, then a
     * one-off wp-cron event turns a capped number per day into ORIGINAL,
     * source-credited DRAFT posts (commentary, never reproduction; featured
     * image from Pexels only - publisher photos are agency-licensed and a
     * citation is not a license). Telegram approval still publishes, same as
     * every other draft. Once a rewrite is published, the theme swaps that
     * headline's card to link to our post via news_post_map().
     */

    const OPT_NEWS = 'mfce_news_queue';

    public static function queue_news_items($items) {
        $s = self::settings();
        if (empty($s['news_rewrite']) || !self::provider_ready($s)) { return; }
        $queue = get_option(self::OPT_NEWS, array());
        if (!is_array($queue)) { $queue = array(); }
        $added = 0;
        foreach ((array) $items as $item) {
            if (empty($item['title']) || empty($item['link'])) { continue; }
            $hash = !empty($item['hash']) ? $item['hash'] : md5($item['title']);
            if (isset($queue[$hash])) { continue; }
            $queue[$hash] = array(
                'title'  => sanitize_text_field($item['title']),
                'source' => sanitize_text_field(isset($item['source']) ? $item['source'] : ''),
                'link'   => esc_url_raw($item['link']),
                'time'   => isset($item['time']) ? (int) $item['time'] : 0,
                'status' => 'pending',
                'queued' => time(),
            );
            $added++;
        }
        if ($added) {
            update_option(self::OPT_NEWS, array_slice($queue, -80, null, true), false);
            self::log('News: queued ' . $added . ' new headline(s) for rewrite.');
        }
        $has_pending = false;
        foreach ($queue as $q) {
            if (isset($q['status']) && $q['status'] === 'pending') { $has_pending = true; break; }
        }
        if ($has_pending && !wp_next_scheduled('mfce_process_news')) {
            wp_schedule_single_event(time() + 30, 'mfce_process_news');
        }
    }

    public static function process_news_queue() {
        if (function_exists('set_time_limit')) { @set_time_limit(300); }
        $s = self::settings();
        if (empty($s['news_rewrite']) || !self::provider_ready($s)) { return; }
        $cap = (int) $s['news_max_per_day'];
        if ($cap <= 0) { return; }

        // Solemn days: no news chatter at all (matches the posting schedule).
        $now = new DateTime('now', wp_timezone());
        $pick = self::persona_for_date($now);
        if ($pick['mode'] === 'solemn') { return; }

        $day = get_option('mfce_news_day', array());
        $today = $now->format('Y-m-d');
        if (!is_array($day) || !isset($day['date']) || $day['date'] !== $today) {
            $day = array('date' => $today, 'count' => 0);
        }

        $queue = get_option(self::OPT_NEWS, array());
        if (!is_array($queue) || !$queue) { return; }
        $pending = array_filter($queue, function ($q) {
            return isset($q['status']) && $q['status'] === 'pending';
        });
        uasort($pending, function ($a, $b) { return (int) $b['time'] - (int) $a['time']; });

        foreach ($pending as $hash => $item) {
            if ($day['count'] >= $cap) { break; }
            $result = self::generate_news_post($s, $hash, $item, $pick);
            if (is_wp_error($result)) {
                $queue[$hash]['status'] = 'failed';
                self::log('News rewrite failed ("' . $item['title'] . '"): ' . $result->get_error_message());
            } else {
                $queue[$hash]['status']  = 'done';
                $queue[$hash]['post_id'] = $result;
                $day['count']++;
            }
            update_option(self::OPT_NEWS, $queue, false);
            update_option('mfce_news_day', $day, false);
        }
    }

    /** Best-effort plain text of the source article, or '' when unusable. */
    private static function fetch_article_text($url) {
        $r = wp_remote_get($url, array(
            'timeout'     => 25,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; ManifestedFitBot/1.0; +https://manifestedfit.com)',
        ));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) >= 400) { return ''; }
        $html = (string) wp_remote_retrieve_body($r);
        $html = preg_replace('#<(script|style|nav|header|footer|aside)[^>]*>.*?</\1>#is', ' ', $html);
        if (!preg_match_all('#<p[^>]*>(.*?)</p>#is', $html, $m)) { return ''; }
        $text = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags(implode("\n", $m[1]))));
        if (strlen($text) < 400) { return ''; } // paywall or JS-redirect shell page
        return function_exists('mb_substr') ? mb_substr($text, 0, 5000) : substr($text, 0, 5000);
    }

    private static function generate_news_post($s, $hash, $item, $pick) {
        $personas = self::personas();
        $persona = $personas[$pick['persona']];
        $author = get_user_by('login', $persona['login']);
        if (!$author) { return new WP_Error('mfce_news', 'WP user not found for persona login "' . $persona['login'] . '".'); }

        $article = self::fetch_article_text($item['link']);
        $source_name = $item['source'] !== '' ? $item['source'] : 'the original report';

        $system = "You are a columnist for Manifested Fit (manifestedfit.com/blog), a wellness brand blending manifestation, gentle fitness, and mindset for everyday people.\n\n"
            . "You write as the persona \"{$persona['name']}\". Voice: {$persona['voice']}\n\n"
            . "Task: write an ORIGINAL news-reaction column about a wellness headline. This is commentary, not reproduction:\n"
            . "- Summarize what was reported in YOUR OWN words (2-3 short paragraphs at most). Never copy sentences from the source.\n"
            . "- Credit the source by name in the prose (e.g. \"as reported by {$source_name}\"). A full linked citation is appended automatically, so do not add links yourself.\n"
            . "- Then add the Manifested Fit take: what this means for everyday people, and one practical thing the reader can do today.\n"
            . "- 450-750 words. No medical claims. Personas are voice bylines only and never claim credentials.\n"
            . "- Mention the free 7-Day Mind-Body Reset at manifestedfit.com once, naturally, near the end.\n"
            . "- content_html: only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>. No links, no <h1>, no inline styles.\n"
            . "- excerpt: one sentence, max 155 characters. slug: lowercase-hyphenated. focus_keyword: the 2-4 word phrase to rank for.";

        $user = "Headline: {$item['title']}\nSource: {$source_name}";
        $user .= $article !== ''
            ? "\n\nArticle text (for grounding only - do not copy its wording):\n" . $article
            : "\n\nThe article body could not be retrieved. Write from the headline alone: keep claims about the report modest (\"a new report suggests...\") and focus on the practical wellness angle.";

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'title'         => array('type' => 'string'),
                'slug'          => array('type' => 'string'),
                'excerpt'       => array('type' => 'string'),
                'focus_keyword' => array('type' => 'string'),
                'content_html'  => array('type' => 'string'),
            ),
            'required' => array('title', 'slug', 'excerpt', 'focus_keyword', 'content_html'),
            'additionalProperties' => false,
        );
        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed)) { return $parsed; }
        if (empty($parsed['title']) || empty($parsed['content_html'])) {
            return new WP_Error('mfce_parse', 'News rewrite response was missing title or content.');
        }

        // Guaranteed citation block, whatever the model wrote.
        $citation = '<p class="mfce-news-source"><em>Originally reported by <a href="' . esc_url($item['link']) . '" target="_blank" rel="nofollow noopener">'
            . esc_html($source_name) . ': &ldquo;' . esc_html($item['title']) . '&rdquo;</a>. The summary and takeaways above are our own.</em></p>';

        $cat_id = 0;
        $cat = get_term_by('name', 'Wellness News', 'category');
        if ($cat) {
            $cat_id = (int) $cat->term_id;
        } else {
            $created = wp_insert_term('Wellness News', 'category');
            if (!is_wp_error($created)) { $cat_id = (int) $created['term_id']; }
        }

        $post_id = wp_insert_post(array(
            'post_title'    => wp_strip_all_tags($parsed['title']),
            'post_name'     => sanitize_title($parsed['slug']),
            'post_content'  => wp_kses_post($parsed['content_html']) . "\n" . $citation,
            'post_excerpt'  => sanitize_text_field($parsed['excerpt']),
            'post_status'   => 'draft', // ALWAYS draft. Telegram approval publishes.
            'post_author'   => $author->ID,
            'post_category' => $cat_id ? array($cat_id) : array(),
            'tags_input'    => array('news'),
        ), true);
        if (is_wp_error($post_id)) { return $post_id; }

        update_post_meta($post_id, 'mfce_news_hash', $hash);
        update_post_meta($post_id, 'mfce_news_source_url', esc_url_raw($item['link']));
        update_post_meta($post_id, 'mfce_news_source_name', sanitize_text_field($source_name));
        update_post_meta($post_id, 'mfce_news_source_title', sanitize_text_field($item['title']));
        if (!empty($parsed['focus_keyword'])) {
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($parsed['focus_keyword']));
        }
        // No mfce_video_status meta: news reactions skip the video pipeline.
        self::set_featured_from_pexels($post_id, !empty($parsed['focus_keyword']) ? $parsed['focus_keyword'] : $parsed['title']);

        do_action('mfce_draft_created', $post_id, $parsed, $persona, $pick);

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        self::telegram_send_draft_notice($s, $post_id,
            "Manifested Fit Engine\nNews rewrite draft by {$persona['name']}:\n\"{$parsed['title']}\"\n"
            . "(from {$source_name}: {$item['title']})\nReview: {$edit_url}");
        self::log('OK: news rewrite draft #' . $post_id . ' ("' . $parsed['title'] . '")');
        return (int) $post_id;
    }

    /** hash => permalink for PUBLISHED news rewrites; the theme uses this to swap card links. */
    public static function news_post_map($hashes) {
        if (empty($hashes)) { return array(); }
        $q = new WP_Query(array(
            'post_status'    => 'publish',
            'posts_per_page' => count($hashes),
            'no_found_rows'  => true,
            'meta_query'     => array(array('key' => 'mfce_news_hash', 'value' => array_values($hashes), 'compare' => 'IN')),
        ));
        $map = array();
        foreach ($q->posts as $p) {
            $map[(string) get_post_meta($p->ID, 'mfce_news_hash', true)] = get_permalink($p);
        }
        return $map;
    }

    // ------------------------------------------------------------- telegram

    public static function tg_api($s, $method, $body) {
        if (empty($s['telegram_bot_token'])) { return false; }
        $response = wp_remote_post('https://api.telegram.org/bot' . $s['telegram_bot_token'] . '/' . $method, array(
            'timeout' => 25,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode($body),
        ));
        if (is_wp_error($response)) {
            self::log('Telegram ' . $method . ' failed: ' . $response->get_error_message());
            return false;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['ok'])) {
            self::log('Telegram ' . $method . ' rejected: ' . wp_remote_retrieve_body($response));
            return false;
        }
        return isset($data['result']) ? $data['result'] : true;
    }

    public static function telegram_send($s, $text, $extra = array()) {
        if (empty($s['telegram_chat_id'])) { return false; }
        $body = array_merge(array('chat_id' => $s['telegram_chat_id'], 'text' => $text), $extra);
        return self::tg_api($s, 'sendMessage', $body);
    }

    /** Notify about a draft with approval buttons; remember message_id -> post_id. */
    private static function telegram_send_draft_notice($s, $post_id, $text) {
        // Make it obvious when publishing now would ship the post without its video.
        $awaiting_video = get_post_meta($post_id, 'mfce_video_status', true) === 'needed';
        $keyboard = array('inline_keyboard' => array(array(
            array('text' => $awaiting_video ? 'Publish w/o video' : 'Publish', 'callback_data' => 'publish:' . $post_id),
            array('text' => 'Keep draft', 'callback_data' => 'keep:' . $post_id),
            array('text' => 'Trash',      'callback_data' => 'trash:' . $post_id),
        )));
        if ($awaiting_video) {
            $text .= "\n\nVideo not made yet - run the pipeline from the dashboard on your PC first; it will offer Publish again after the video is embedded.";
        }
        $result = self::telegram_send($s, $text . "\n\nReply to this message with instructions to have the AI revise the draft.", array('reply_markup' => $keyboard));
        if (is_array($result) && isset($result['message_id'])) {
            $map = get_option('mfce_tg_map', array());
            if (!is_array($map)) { $map = array(); }
            $map[(string) $result['message_id']] = (int) $post_id;
            update_option('mfce_tg_map', array_slice($map, -30, null, true), false);
        }
        return $result;
    }

    // ----------------------------------------------------------------- log

    private static function log($message) {
        $log = get_option(self::OPT_LOG, array());
        if (!is_array($log)) { $log = array(); }
        array_unshift($log, current_time('mysql') . ' - ' . $message);
        update_option(self::OPT_LOG, array_slice($log, 0, 20), false);
    }

    // ---------------------------------------------------------------- REST

    public static function register_rest() {
        register_rest_route('mfce/v1', '/run', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true', // guarded by the secret below
            'callback'            => function ($request) {
                $s = self::settings();
                $secret = (string) $request->get_param('secret');
                if (empty($s['cron_secret']) || !hash_equals($s['cron_secret'], $secret)) {
                    return new WP_Error('mfce_forbidden', 'Bad secret.', array('status' => 403));
                }
                $force = (bool) $request->get_param('force');
                $result = self::run($force, 'rest-cron');
                return rest_ensure_response($result);
            },
        ));

        register_rest_route('mfce/v1', '/telegram', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by Telegram's secret-token header below
            'callback'            => array(__CLASS__, 'handle_telegram_webhook'),
        ));

        // ------------------------- video pipeline (external workflow talks to these)

        // What needs a video? Returns briefs plus the current approval status,
        // so the workflow can also see vidok/vidno button decisions.
        register_rest_route('mfce/v1', '/video-queue', array(
            'methods'             => 'GET',
            'permission_callback' => '__return_true', // guarded by the cron secret
            'callback'            => function ($request) {
                $err = self::check_cron_secret($request);
                if ($err) { return $err; }
                $posts = get_posts(array(
                    'post_status' => array('draft', 'pending', 'publish'),
                    'numberposts' => 20,
                    'orderby'     => 'ID',
                    'order'       => 'ASC',
                    'meta_query'  => array(array(
                        'key'     => 'mfce_video_status',
                        'value'   => array('needed', 'review', 'approved', 'rejected'),
                        'compare' => 'IN',
                    )),
                ));
                $out = array();
                foreach ($posts as $p) {
                    $brief = json_decode((string) get_post_meta($p->ID, 'mfce_video_brief', true), true);
                    $out[] = array(
                        'post_id'      => $p->ID,
                        'title'        => $p->post_title,
                        'persona'      => get_the_author_meta('display_name', $p->post_author),
                        'post_status'  => $p->post_status,
                        'permalink'    => get_permalink($p->ID),
                        'video_status' => get_post_meta($p->ID, 'mfce_video_status', true),
                        'preview_url'  => get_post_meta($p->ID, 'mfce_video_preview_url', true),
                        'video_brief'  => is_array($brief) ? $brief : null,
                    );
                }
                return rest_ensure_response($out);
            },
        ));

        // The workflow rendered/uploaded a preview: ask the human to approve it.
        register_rest_route('mfce/v1', '/video-ready', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by the cron secret
            'callback'            => function ($request) {
                $err = self::check_cron_secret($request);
                if ($err) { return $err; }
                $post_id = (int) $request->get_param('post_id');
                $preview = esc_url_raw((string) $request->get_param('preview_url'));
                $post    = get_post($post_id);
                if (!$post) {
                    return new WP_Error('mfce_gone', 'Post not found.', array('status' => 404));
                }
                if (!$preview) {
                    return new WP_Error('mfce_bad', 'preview_url is required.', array('status' => 400));
                }
                update_post_meta($post_id, 'mfce_video_status', 'review');
                update_post_meta($post_id, 'mfce_video_preview_url', $preview);
                self::log('OK: video for post #' . $post_id . ' is awaiting approval.');
                $s = self::settings();
                $keyboard = array('inline_keyboard' => array(array(
                    array('text' => 'Approve video', 'callback_data' => 'vidok:' . $post_id),
                    array('text' => 'Reject video',  'callback_data' => 'vidno:' . $post_id),
                )));
                self::telegram_send($s,
                    "Manifested Fit Engine\nVideo ready for review (post: \"{$post->post_title}\"):\n{$preview}\n\nApprove to have it uploaded/embedded, or reject to regenerate.",
                    array('reply_markup' => $keyboard));
                return rest_ensure_response(array('ok' => true, 'video_status' => 'review'));
            },
        ));

        // Final step: swap the [VIDEO EMBED] placeholder for the real YouTube embed.
        register_rest_route('mfce/v1', '/video-embed', array(
            'methods'             => 'POST',
            'permission_callback' => '__return_true', // guarded by the cron secret
            'callback'            => function ($request) {
                $err = self::check_cron_secret($request);
                if ($err) { return $err; }
                $post_id = (int) $request->get_param('post_id');
                $vid     = self::youtube_id((string) $request->get_param('youtube_url'));
                if (!$vid) {
                    return new WP_Error('mfce_bad', 'youtube_url must be a YouTube URL or video ID.', array('status' => 400));
                }
                $result = self::attach_video(self::settings(), $post_id, 'https://www.youtube.com/watch?v=' . $vid);
                if (is_wp_error($result)) {
                    return new WP_Error('mfce_embed', $result->get_error_message(), array('status' => 400));
                }
                return rest_ensure_response(array('ok' => true, 'video_status' => 'embedded'));
            },
        ));
    }

    /** Shared guard for the cron/video REST endpoints. Returns WP_Error or null. */
    private static function check_cron_secret($request) {
        $s = self::settings();
        $secret = (string) $request->get_param('secret');
        if (empty($s['cron_secret']) || !hash_equals($s['cron_secret'], $secret)) {
            return new WP_Error('mfce_forbidden', 'Bad secret.', array('status' => 403));
        }
        return null;
    }

    // ---------------------------------------------------- telegram webhook

    public static function handle_telegram_webhook($request) {
        $s = self::settings();

        // Telegram echoes back the secret we register with setWebhook.
        $header = (string) $request->get_header('x-telegram-bot-api-secret-token');
        if (empty($s['webhook_secret']) || !hash_equals($s['webhook_secret'], $header)) {
            return new WP_Error('mfce_forbidden', 'Bad webhook secret.', array('status' => 403));
        }

        $update = $request->get_json_params();
        if (!is_array($update)) { return rest_ensure_response(array('ok' => true)); }

        // Telegram retries on timeout; slow AI calls make duplicates likely. Dedupe by update_id.
        if (isset($update['update_id'])) {
            $seen = get_option('mfce_tg_updates', array());
            if (!is_array($seen)) { $seen = array(); }
            if (in_array((int) $update['update_id'], $seen, true)) {
                return rest_ensure_response(array('ok' => true, 'dedup' => true));
            }
            $seen[] = (int) $update['update_id'];
            update_option('mfce_tg_updates', array_slice($seen, -50), false);
        }

        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        if (isset($update['callback_query'])) {
            self::handle_tg_callback($s, $update['callback_query']);
        } elseif (isset($update['message'])) {
            self::handle_tg_message($s, $update['message']);
        }
        return rest_ensure_response(array('ok' => true));
    }

    /** Only the configured chat may drive the bot. */
    private static function tg_authorized($s, $chat_id) {
        return !empty($s['telegram_chat_id']) && (string) $chat_id === (string) $s['telegram_chat_id'];
    }

    /** Button taps: publish / keep / trash. Publishing here IS the human approval. */
    private static function handle_tg_callback($s, $cb) {
        $chat_id = isset($cb['message']['chat']['id']) ? $cb['message']['chat']['id'] : '';
        if (!self::tg_authorized($s, $chat_id)) { return; }

        self::tg_api($s, 'answerCallbackQuery', array('callback_query_id' => $cb['id']));

        // One-tap topic approval from a suggestions message.
        if (preg_match('/^addtopic:([a-z0-9]+)$/', (string) $cb['data'], $m)) {
            $store = get_option('mfce_tg_suggestions', array());
            if (!is_array($store) || !isset($store[$m[1]])) {
                self::telegram_send($s, 'That suggestion has expired - ask me for fresh topic ideas any time.');
                return;
            }
            $sug = $store[$m[1]];
            self::add_topic($sug['title'], $sug['notes'], array('suggested' => 1));
            unset($store[$m[1]]);
            update_option('mfce_tg_suggestions', $store, false);
            self::log('OK: topic added via Telegram suggestion: "' . $sug['title'] . '"');
            self::telegram_send($s, 'Queued: "' . $sug['title'] . '" (' . self::pending_topic_count() . ' topics now pending).');
            return;
        }

        if (!preg_match('/^(publish|keep|trash|vidok|vidno):(\d+)$/', (string) $cb['data'], $m)) { return; }
        $action  = $m[1];
        $post_id = (int) $m[2];
        $post    = get_post($post_id);
        if (!$post) {
            self::telegram_send($s, 'Post #' . $post_id . ' no longer exists.');
            return;
        }

        if ($action === 'vidok') {
            update_post_meta($post_id, 'mfce_video_status', 'approved');
            self::log('OK: video for post #' . $post_id . ' APPROVED via Telegram.');
            self::telegram_send($s, "Video approved for \"{$post->post_title}\". The video workflow will upload it to YouTube and embed it in the draft on its next pass, then notify you here.");
            return;
        }
        if ($action === 'vidno') {
            update_post_meta($post_id, 'mfce_video_status', 'rejected');
            self::log('OK: video for post #' . $post_id . ' rejected via Telegram.');
            self::telegram_send($s, "Video rejected for \"{$post->post_title}\". The workflow will see the rejection and can regenerate it. If the article itself needs changes, reply to its draft notification.");
            return;
        }

        if ($action === 'publish') {
            if ($post->post_status === 'publish') {
                self::telegram_send($s, 'Already published: ' . get_permalink($post_id));
                return;
            }
            wp_publish_post($post_id);
            self::log('OK: draft #' . $post_id . ' PUBLISHED via Telegram approval.');
            self::telegram_send($s, "Published: \"{$post->post_title}\"\n" . get_permalink($post_id) . "\n\nRemember to clear SpeedyCache if it doesn't show.");
        } elseif ($action === 'trash') {
            wp_trash_post($post_id);
            self::log('OK: draft #' . $post_id . ' trashed via Telegram.');
            self::telegram_send($s, "Trashed: \"{$post->post_title}\". The topic stays marked used - re-add it to the queue if you want a rewrite.");
        } else {
            self::telegram_send($s, "Kept as draft: \"{$post->post_title}\". Reply to the original notification with instructions if you want the AI to revise it.");
        }
    }

    /** Text messages: reply-to-a-draft-notice = revise that draft; anything else = AI chat. */
    private static function handle_tg_message($s, $msg) {
        $chat_id = isset($msg['chat']['id']) ? $msg['chat']['id'] : '';
        if (!self::tg_authorized($s, $chat_id)) { return; }

        $text = isset($msg['text']) ? trim((string) $msg['text']) : '';
        if ($text === '' || $text === '/start') { return; }

        if (!self::provider_ready($s)) {
            self::telegram_send($s, 'No API key configured for the selected provider yet, so I cannot ask the AI anything.');
            return;
        }

        // Revision flow: message is a reply to one of our draft notifications.
        if (isset($msg['reply_to_message']['message_id'])) {
            $map = get_option('mfce_tg_map', array());
            $key = (string) $msg['reply_to_message']['message_id'];
            if (is_array($map) && isset($map[$key])) {
                $post_id = (int) $map[$key];
                self::telegram_send($s, 'Revising draft #' . $post_id . ' - give me a minute or two...');
                $result = self::revise_post($s, $post_id, $text);
                if (is_wp_error($result)) {
                    self::telegram_send($s, 'Revision failed: ' . $result->get_error_message());
                } else {
                    $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
                    self::telegram_send_draft_notice($s, $post_id, "Revised draft:\n\"{$result['title']}\"\nReview: {$edit_url}");
                }
                return;
            }
        }

        // General chat with the configured model - aware of the blog's state
        // and able to queue topics via ADD_TOPIC directive lines.
        $recent = array();
        foreach (get_posts(array('post_status' => array('publish', 'draft', 'pending'), 'numberposts' => 8, 'orderby' => 'date', 'order' => 'DESC')) as $p) {
            $recent[] = $p->post_title . ' [' . $p->post_status . ']';
        }
        $pending = array();
        foreach (self::topics() as $t) {
            if (($t['status'] ?? '') === 'pending') { $pending[] = $t['title']; }
        }
        $personas_txt = array();
        foreach (self::personas() as $p) { $personas_txt[] = $p['name']; }

        $system = 'You are the Manifested Fit content engine assistant, chatting with the site owner (Jonathan) over Telegram. '
            . 'You ARE the AI inside the WordPress plugin that drafts the daily blog post for manifestedfit.com/blog, a wellness/manifestation brand with a faceless YouTube strategy. '
            . 'Four columnist personas write the posts: ' . implode(', ', $personas_txt) . '. Schedule: Mon Frankie, Tue Dana, Wed Nadia, Thu Dana, Fri Rowan, weekends random. '
            . 'Blog categories: ' . implode(', ', self::categories()) . '. '
            . "\n\nCurrent state:\nPending topic queue (" . count($pending) . '): ' . ($pending ? implode(' | ', $pending) : '(empty - the AI picks daily topics itself)')
            . "\nRecent posts: " . ($recent ? implode(' | ', $recent) : '(none)')
            . "\n\nYou CAN add topics to the drafting queue. When the owner asks you to queue/add a topic (or asks for a recommendation and clearly wants it queued), end your reply with one line per topic, exactly in this format:\nADD_TOPIC: topic title | short editor notes"
            . "\nThose lines are executed and removed before the owner sees your message, so also mention in your normal text what you queued. Do not use ADD_TOPIC when merely brainstorming unless asked to queue."
            . "\n\nOther actions (publish/trash/revise/video approval) happen via the buttons on notification messages, not from chat. "
            . 'Be helpful and concise (Telegram-sized answers, plain text, no markdown).';
        $reply = self::ai_text($s, $system, $text);
        if (is_wp_error($reply)) {
            self::telegram_send($s, 'AI call failed: ' . $reply->get_error_message());
            return;
        }
        // Execute any ADD_TOPIC directive lines, then strip them from the reply.
        $added = 0;
        if (preg_match_all('/^\s*ADD_TOPIC:\s*(.+)$/mi', $reply, $mm)) {
            foreach ($mm[1] as $line) {
                $parts = array_map('trim', explode('|', $line, 2));
                if ($parts[0] === '') { continue; }
                self::add_topic($parts[0], $parts[1] ?? '', array('via' => 'telegram-chat'));
                $added++;
            }
            $reply = trim(preg_replace('/^\s*ADD_TOPIC:.*$/mi', '', $reply));
            self::log('OK: ' . $added . ' topic(s) queued via Telegram chat.');
        }
        if ($added > 0) {
            $reply .= "\n\n(" . $added . ' topic' . ($added > 1 ? 's' : '') . ' queued - ' . self::pending_topic_count() . ' now pending.)';
        }
        // Telegram messages cap at 4096 chars.
        self::telegram_send($s, mb_substr($reply, 0, 4000));
    }

    // --------------------------------------------------------------- admin

    public static function admin_menu() {
        add_menu_page('Content Engine', 'Content Engine', 'manage_options', 'mfce', array(__CLASS__, 'render_admin'), 'dashicons-superhero', 58);
    }

    public static function handle_save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_save_settings');
        $s = self::settings();
        $s['enabled']            = isset($_POST['enabled']) ? 1 : 0;
        $s['auto_topic']         = isset($_POST['auto_topic']) ? 1 : 0;
        $s['news_rewrite']       = isset($_POST['news_rewrite']) ? 1 : 0;
        $s['news_max_per_day']   = max(0, (int) ($_POST['news_max_per_day'] ?? $s['news_max_per_day']));
        $provider                = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'anthropic'));
        $s['provider']           = in_array($provider, array('anthropic', 'gemini', 'openai', 'grok', 'custom'), true) ? $provider : 'anthropic';
        // Model fields: dropdown of known ids + free-text override (override wins when filled).
        foreach (array('model', 'gemini_model', 'openai_model', 'grok_model') as $model_field) {
            $custom = sanitize_text_field(wp_unslash($_POST[$model_field . '_custom'] ?? ''));
            $picked = sanitize_text_field(wp_unslash($_POST[$model_field] ?? $s[$model_field]));
            $s[$model_field] = $custom !== '' ? $custom : ($picked !== '' ? $picked : $s[$model_field]);
        }
        $s['custom_model']       = sanitize_text_field(wp_unslash($_POST['custom_model'] ?? $s['custom_model']));
        $s['custom_base_url']    = esc_url_raw(trim((string) wp_unslash($_POST['custom_base_url'] ?? $s['custom_base_url'])));
        $s['max_tokens']         = max(1000, (int) ($_POST['max_tokens'] ?? $s['max_tokens']));
        $s['telegram_chat_id']   = sanitize_text_field(wp_unslash($_POST['telegram_chat_id'] ?? ''));
        // Secret fields: keep the stored value when the input is left blank.
        foreach (array('anthropic_api_key', 'gemini_api_key', 'openai_api_key', 'grok_api_key', 'custom_api_key', 'pexels_api_key', 'telegram_bot_token') as $secret_field) {
            $val = trim((string) wp_unslash($_POST[$secret_field] ?? ''));
            if ($val !== '') { $s[$secret_field] = $val; }
        }
        update_option(self::OPT_SETTINGS, $s, false);
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=saved'));
        exit;
    }

    public static function handle_add_topics() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_add_topics');
        $raw = (string) wp_unslash($_POST['topics'] ?? '');
        $topics = self::topics();
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            // Optional "Title | editor notes" format.
            $parts = array_map('trim', explode('|', $line, 2));
            $topics[] = array(
                'id'     => uniqid('t'),
                'title'  => sanitize_text_field($parts[0]),
                'notes'  => isset($parts[1]) ? sanitize_text_field($parts[1]) : '',
                'status' => 'pending',
            );
        }
        update_option(self::OPT_TOPICS, $topics, false);
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=topics'));
        exit;
    }

    /** Edit a pending topic in place: title, notes, persona/provider/model overrides. */
    public static function handle_update_topic() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_update_topic');
        $id = sanitize_text_field(wp_unslash($_POST['topic_id'] ?? ''));
        $personas = self::personas();
        $topics = self::topics();
        foreach ($topics as &$t) {
            if ($t['id'] !== $id) { continue; }
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            if ($title !== '') { $t['title'] = $title; }
            $t['notes'] = sanitize_text_field(wp_unslash($_POST['notes'] ?? ''));
            $persona = sanitize_text_field(wp_unslash($_POST['persona'] ?? ''));
            $t['persona'] = isset($personas[$persona]) ? $persona : '';
            $provider = sanitize_text_field(wp_unslash($_POST['provider'] ?? ''));
            $t['provider'] = in_array($provider, array('anthropic', 'gemini', 'openai', 'grok', 'custom'), true) ? $provider : '';
            $t['model'] = sanitize_text_field(wp_unslash($_POST['model'] ?? ''));
        }
        unset($t);
        update_option(self::OPT_TOPICS, $topics, false);
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=topicsaved'));
        exit;
    }

    public static function handle_delete_topic() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_delete_topic');
        $id = sanitize_text_field(wp_unslash($_GET['topic'] ?? ''));
        $topics = array_values(array_filter(self::topics(), function ($t) use ($id) {
            return $t['id'] !== $id;
        }));
        update_option(self::OPT_TOPICS, $topics, false);
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=deleted'));
        exit;
    }

    /**
     * Ask the AI to plan/replace the topic queue. The proposal is stored and
     * shown in a review popup - nothing is overwritten until Apply is clicked.
     */
    public static function handle_plan_topics() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_plan_topics');
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        $s = self::settings();
        if (!self::provider_ready($s)) {
            wp_safe_redirect(admin_url('admin.php?page=mfce&notice=planfail'));
            exit;
        }
        $instructions = sanitize_textarea_field(wp_unslash($_POST['instructions'] ?? ''));

        $pending = array();
        foreach (self::topics() as $t) {
            if ($t['status'] === 'pending') {
                $pending[] = $t['title'] . ($t['notes'] !== '' ? ' | ' . $t['notes'] : '');
            }
        }
        $recent = array();
        foreach (get_posts(array('post_status' => array('publish', 'draft', 'pending'), 'numberposts' => 12, 'orderby' => 'date', 'order' => 'DESC')) as $p) {
            $recent[] = $p->post_title;
        }
        $personas = self::personas();
        $voices = array();
        foreach ($personas as $key => $p) { $voices[] = $key . ' = ' . $p['name'] . ': ' . $p['voice']; }
        $now = new DateTime('now', wp_timezone());

        $system = 'You are the content planner for Manifested Fit (manifestedfit.com/blog), a wellness brand blending manifestation, gentle fitness, and mindset for everyday people. '
            . 'Plan the upcoming topic queue: one blog post is published per day, drafted by that day\'s persona. '
            . "Persona schedule: Mon frankie, Tue dana, Wed nadia, Thu dana, Fri rowan, weekends random. Voices:\n- " . implode("\n- ", $voices) . "\n"
            . 'Rules: practical and doable beats abstract; no medical claims or income promises; categories covered: ' . implode(', ', self::categories()) . '. '
            . 'Return 10-20 topics in the order they should be written (topic 1 = the next post). For each: a compelling title, short editor notes (angle + 2-3 must-cover points; if the topic is part of a series, say "Part N of X: <series name>" in the notes), and persona - use "" to accept the day-of-week default, or a persona key only when the topic clearly belongs to that voice.';

        $user = 'Today is ' . $now->format('l, F j, Y') . " (Canada).\n\nCurrent pending queue (you are replacing this - keep whatever is still good, drop or improve the weak ones, note that stray lines may be leftover notes accidentally added as topics):\n"
            . ($pending ? '- ' . implode("\n- ", $pending) : '(empty)')
            . "\n\nRecent post titles (avoid repeats):\n" . ($recent ? '- ' . implode("\n- ", $recent) : '(none yet)')
            . ($instructions !== '' ? "\n\nEditor's special instructions (follow these):\n" . $instructions : '');

        $schema = array(
            'type' => 'object',
            'properties' => array(
                'topics' => array(
                    'type'  => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'title'   => array('type' => 'string'),
                            'notes'   => array('type' => 'string'),
                            'persona' => array('type' => 'string', 'enum' => array_merge(array(''), array_keys($personas))),
                        ),
                        'required' => array('title', 'notes', 'persona'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('topics'),
            'additionalProperties' => false,
        );

        $parsed = self::ai_json($s, $system, $user, $schema);
        if (is_wp_error($parsed) || empty($parsed['topics']) || !is_array($parsed['topics'])) {
            self::log('FAIL: AI topic planning failed: ' . (is_wp_error($parsed) ? $parsed->get_error_message() : 'no topics returned'));
            wp_safe_redirect(admin_url('admin.php?page=mfce&notice=planfail'));
            exit;
        }

        $proposal = array();
        foreach ($parsed['topics'] as $t) {
            if (empty($t['title'])) { continue; }
            $persona = isset($t['persona'], $personas[$t['persona']]) ? $t['persona'] : '';
            $proposal[] = array(
                'title'   => sanitize_text_field($t['title']),
                'notes'   => sanitize_text_field($t['notes'] ?? ''),
                'persona' => $persona,
            );
        }
        update_option('mfce_topic_plan', array('topics' => $proposal, 'instructions' => $instructions, 'created' => current_time('mysql')), false);
        self::log('OK: AI proposed a topic plan (' . count($proposal) . ' topics) - awaiting review.');
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=planned'));
        exit;
    }

    /** Apply the reviewed plan: replaces all PENDING topics; used history is kept. */
    public static function handle_apply_plan() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_apply_plan');
        $plan = get_option('mfce_topic_plan', array());
        if (empty($plan['topics'])) {
            wp_safe_redirect(admin_url('admin.php?page=mfce&notice=planfail'));
            exit;
        }
        $kept = array_values(array_filter(self::topics(), function ($t) { return $t['status'] !== 'pending'; }));
        foreach ($plan['topics'] as $t) {
            $kept[] = array(
                'id'      => uniqid('t'),
                'title'   => $t['title'],
                'notes'   => $t['notes'],
                'persona' => $t['persona'],
                'status'  => 'pending',
            );
        }
        update_option(self::OPT_TOPICS, $kept, false);
        delete_option('mfce_topic_plan');
        self::log('OK: AI topic plan applied (' . count($plan['topics']) . ' pending topics).');
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=planapplied'));
        exit;
    }

    public static function handle_discard_plan() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_discard_plan');
        delete_option('mfce_topic_plan');
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=plandiscarded'));
        exit;
    }

    public static function handle_run_now() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_run_now');
        $result = self::run(true, 'run-now');
        $notice = $result['ok'] ? 'ran' : 'runfail';
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=' . $notice));
        exit;
    }

    public static function handle_test_telegram() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_test_telegram');
        $ok = self::telegram_send(self::settings(), 'Manifested Fit Engine: Telegram test from the WordPress plugin. Wiring works.');
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=' . ($ok ? 'tgok' : 'tgfail')));
        exit;
    }

    /**
     * Point the Telegram bot's webhook at this site so button taps and replies
     * reach the plugin. Replaces any previous webhook/getUpdates polling.
     */
    public static function handle_register_webhook() {
        if (!current_user_can('manage_options')) { wp_die('Nope.'); }
        check_admin_referer('mfce_register_webhook');
        $s = self::settings();
        $result = self::tg_api($s, 'setWebhook', array(
            'url'             => rest_url('mfce/v1/telegram'),
            'secret_token'    => $s['webhook_secret'],
            'allowed_updates' => array('message', 'callback_query'),
        ));
        if ($result) {
            self::telegram_send($s, 'Two-way mode is on: this chat can now approve drafts with the buttons, revise drafts by replying to a notification, or just ask the AI something.');
        }
        wp_safe_redirect(admin_url('admin.php?page=mfce&notice=' . ($result ? 'whok' : 'whfail')));
        exit;
    }

    /** Model picker: dropdown of known ids + free-text override (override wins when filled). */
    private static function model_select($field, $provider, $current) {
        $known  = self::known_models();
        $models = isset($known[$provider]) ? $known[$provider] : array();
        if ($current !== '' && !in_array($current, $models, true)) { array_unshift($models, $current); }
        ?>
        <select name="<?php echo esc_attr($field); ?>">
            <?php foreach ($models as $m) : ?>
                <option value="<?php echo esc_attr($m); ?>" <?php selected($current, $m); ?>><?php echo esc_html($m); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="<?php echo esc_attr($field); ?>_custom" placeholder="or type a newer model id" style="max-width:220px">
        <p class="description">Pick from the list; the text box overrides the dropdown when filled (for models newer than this plugin).</p>
        <?php
    }

    public static function render_admin() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $topics = self::topics();
        $pending = array_filter($topics, function ($t) { return $t['status'] === 'pending'; });
        $now = new DateTime('now', wp_timezone());
        $pick = self::persona_for_date($now);
        $personas = self::personas();
        $log = get_option(self::OPT_LOG, array());
        $cron_url = rest_url('mfce/v1/run') . '?secret=' . rawurlencode($s['cron_secret']);

        $notices = array(
            'saved'   => array('success', 'Settings saved.'),
            'topics'  => array('success', 'Topics added to the queue.'),
            'topicsaved' => array('success', 'Topic updated.'),
            'deleted' => array('success', 'Topic removed.'),
            'planned' => array('success', 'The AI proposed a new topic plan - review it below and Apply or Discard.'),
            'planfail' => array('error', 'AI topic planning failed - check the provider key and the activity log.'),
            'planapplied' => array('success', 'Topic plan applied - the pending queue was replaced.'),
            'plandiscarded' => array('success', 'Topic plan discarded - your queue is unchanged.'),
            'ran'     => array('success', 'Engine ran - check the log below and your Telegram.'),
            'runfail' => array('error',   'Engine run did not create a draft - see the log below.'),
            'tgok'    => array('success', 'Telegram test message sent.'),
            'tgfail'  => array('error',   'Telegram test failed - check token and chat id.'),
            'whok'    => array('success', 'Telegram webhook registered - two-way mode (approve buttons, revise-by-reply, AI chat) is on.'),
            'whfail'  => array('error',   'Webhook registration failed - check the bot token and the activity log.'),
        );
        $notice = sanitize_text_field(wp_unslash($_GET['notice'] ?? ''));
        ?>
        <div class="wrap">
            <style>
                details.mfce-acc { background:#fff; border:1px solid #c3c4c7; border-radius:2px; margin:12px 0; max-width:1100px; box-shadow:0 1px 1px rgba(0,0,0,.04); }
                details.mfce-acc > summary { cursor:pointer; padding:10px 14px; font-size:14px; font-weight:600; background:#f6f7f7; user-select:none; }
                details.mfce-acc[open] > summary { border-bottom:1px solid #c3c4c7; }
                details.mfce-acc > .mfce-inside { padding:6px 14px 14px; }
                .mfce-topic-input { width:100%; }
                .mfce-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:100000; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; overflow:auto; }
                .mfce-modal { background:#fff; border-radius:4px; max-width:860px; width:100%; padding:18px 22px; box-shadow:0 8px 30px rgba(0,0,0,.35); }
                .mfce-modal h2 { margin-top:0; }
            </style>
            <h1>Manifested Fit Content Engine</h1>
            <?php if (isset($notices[$notice])) : ?>
                <div class="notice notice-<?php echo esc_attr($notices[$notice][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$notice][1]); ?></p></div>
            <?php endif; ?>

            <?php $plan = get_option('mfce_topic_plan', array()); ?>
            <?php if (!empty($plan['topics'])) : ?>
            <div class="mfce-modal-backdrop">
                <div class="mfce-modal">
                    <h2>AI topic plan - review before applying</h2>
                    <p class="description">Proposed <?php echo (int) count($plan['topics']); ?> topics (<?php echo esc_html($plan['created'] ?? ''); ?>).
                        <?php if (!empty($plan['instructions'])) : ?>Your instructions: "<?php echo esc_html($plan['instructions']); ?>"<?php endif; ?>
                        Applying <strong>replaces all pending topics</strong>; used-topic history is kept. You can still edit topics after applying.</p>
                    <table class="widefat striped">
                        <thead><tr><th style="width:34%">Topic</th><th>Notes</th><th style="width:110px">Persona</th></tr></thead>
                        <tbody>
                        <?php foreach ($plan['topics'] as $pt) : ?>
                            <tr>
                                <td><?php echo esc_html($pt['title']); ?></td>
                                <td><?php echo esc_html($pt['notes']); ?></td>
                                <td><?php echo esc_html($pt['persona'] !== '' && isset($personas[$pt['persona']]) ? $personas[$pt['persona']]['name'] : 'Schedule'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p style="margin-top:14px">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('mfce_apply_plan'); ?>
                            <input type="hidden" name="action" value="mfce_apply_plan">
                            <button class="button button-primary">Apply plan (replace pending topics)</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                            <?php wp_nonce_field('mfce_discard_plan'); ?>
                            <input type="hidden" name="action" value="mfce_discard_plan">
                            <button class="button">Discard</button>
                        </form>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <details class="mfce-acc" open>
                <summary>Status &amp; actions</summary>
                <div class="mfce-inside">
                    <table class="widefat striped" style="max-width:720px">
                        <tbody>
                            <tr><td>Engine</td><td><?php echo $s['enabled'] ? '<strong style="color:green">Enabled</strong>' : '<strong style="color:#b00">Disabled</strong> (cron runs are skipped; Run Now still works)'; ?></td></tr>
                            <tr><td>Today's persona</td><td><?php echo esc_html($personas[$pick['persona']]['name'] . ' (' . $pick['mode'] . ($pick['occasion'] ? ': ' . $pick['occasion'] : '') . ')'); ?></td></tr>
                            <tr><td>Pending topics</td><td><?php echo (int) count($pending); ?><?php echo (!$pending && !empty($s['auto_topic'])) ? ' (the AI will pick tomorrow\'s topic itself)' : ''; ?></td></tr>
                            <tr><td>Last run</td><td><?php echo esc_html($s['last_run_date'] ?: 'never'); ?></td></tr>
                            <tr><td>Default provider / model</td><td><?php echo esc_html(self::provider_label($s)); ?> <span class="description">(individual topics can override this)</span></td></tr>
                            <tr><td>API key (default provider)</td><td><?php echo self::provider_ready($s) ? 'set (hidden)' : '<strong style="color:#b00">missing</strong>'; ?></td></tr>
                            <tr><td>Telegram</td><td><?php echo ($s['telegram_bot_token'] && $s['telegram_chat_id']) ? 'configured' : '<strong style="color:#b00">not configured</strong>'; ?></td></tr>
                        </tbody>
                    </table>

                    <p style="margin-top:12px">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                            <?php wp_nonce_field('mfce_run_now'); ?>
                            <input type="hidden" name="action" value="mfce_run_now">
                            <button class="button button-primary" onclick="this.disabled=true;this.form.submit();this.textContent='Writing... (takes a minute or two)'">Run Now (writes one draft)</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                            <?php wp_nonce_field('mfce_test_telegram'); ?>
                            <input type="hidden" name="action" value="mfce_test_telegram">
                            <button class="button">Send Telegram test</button>
                        </form>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;margin-left:8px">
                            <?php wp_nonce_field('mfce_register_webhook'); ?>
                            <input type="hidden" name="action" value="mfce_register_webhook">
                            <button class="button">Enable two-way Telegram (register webhook)</button>
                        </form>
                    </p>
                    <p class="description" style="max-width:720px">Two-way mode: draft notifications get Publish / Keep / Trash buttons (tapping Publish is your approval - nothing publishes without it), replying to a notification sends revision instructions to the AI, and any other message to the bot is answered by the configured model.</p>
                </div>
            </details>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1100px">
                <?php wp_nonce_field('mfce_save_settings'); ?>
                <input type="hidden" name="action" value="mfce_save_settings">
                <details class="mfce-acc">
                <summary>Engine &amp; AI provider settings</summary>
                <div class="mfce-inside">
                <table class="form-table">
                    <tr><th>Enabled (daily cron)</th><td><label><input type="checkbox" name="enabled" <?php checked($s['enabled'], 1); ?>> Allow the daily cron to create a draft</label></td></tr>
                    <tr><th>AI topic fallback</th><td><label><input type="checkbox" name="auto_topic" <?php checked($s['auto_topic'], 1); ?>> If the topic queue is empty, let the AI pick today's topic (day of week, holidays/weekends, persona, and recent posts considered; the pick is added to the queue and flagged in the Telegram notice)</label></td></tr>
                    <tr><th>News rewrites</th><td><label><input type="checkbox" name="news_rewrite" <?php checked($s['news_rewrite'], 1); ?>> Rewrite fresh Wellness News headlines into original, source-credited draft posts (queued when the blog front page pulls new RSS; drafts go through the usual Telegram approval; published rewrites replace the external link on the news grid)</label>
                        <p class="description">Max news drafts per day: <input type="number" name="news_max_per_day" min="0" max="12" value="<?php echo (int) $s['news_max_per_day']; ?>" style="width:70px"> (0 pauses processing without forgetting the queue)</p></td></tr>
                    <tr><th>Default AI provider</th><td>
                        <select name="provider">
                            <option value="anthropic" <?php selected($s['provider'], 'anthropic'); ?>>Anthropic (Claude)</option>
                            <option value="gemini" <?php selected($s['provider'], 'gemini'); ?>>Google Gemini</option>
                            <option value="openai" <?php selected($s['provider'], 'openai'); ?>>OpenAI</option>
                            <option value="grok" <?php selected($s['provider'], 'grok'); ?>>Grok (xAI)</option>
                            <option value="custom" <?php selected($s['provider'], 'custom'); ?>>Custom / local (OpenAI-compatible, e.g. Ollama)</option>
                        </select>
                        <p class="description">Which AI writes posts, revises drafts, and answers Telegram chat by default. Only the selected provider's key is required; keys for other providers may still be saved, and individual topics in the queue can override the provider/model per post.</p>
                    </td></tr>
                    <tr><th>Anthropic API key</th><td><input type="password" name="anthropic_api_key" class="regular-text" placeholder="<?php echo $s['anthropic_api_key'] ? 'saved - leave blank to keep' : 'sk-ant-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Anthropic model</th><td><?php self::model_select('model', 'anthropic', $s['model']); ?></td></tr>
                    <tr><th>Gemini API key</th><td><input type="password" name="gemini_api_key" class="regular-text" placeholder="<?php echo $s['gemini_api_key'] ? 'saved - leave blank to keep' : 'AIza...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Gemini model</th><td><?php self::model_select('gemini_model', 'gemini', $s['gemini_model']); ?></td></tr>
                    <tr><th>OpenAI API key</th><td><input type="password" name="openai_api_key" class="regular-text" placeholder="<?php echo $s['openai_api_key'] ? 'saved - leave blank to keep' : 'sk-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>OpenAI model</th><td><?php self::model_select('openai_model', 'openai', $s['openai_model']); ?></td></tr>
                    <tr><th>Grok API key</th><td><input type="password" name="grok_api_key" class="regular-text" placeholder="<?php echo $s['grok_api_key'] ? 'saved - leave blank to keep' : 'xai-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Grok model</th><td><?php self::model_select('grok_model', 'grok', $s['grok_model']); ?></td></tr>
                    <tr><th>Custom base URL</th><td><input type="text" name="custom_base_url" class="regular-text" value="<?php echo esc_attr($s['custom_base_url']); ?>" placeholder="https://my-vm.example.com/v1">
                        <p class="description">Any OpenAI-compatible endpoint (Ollama: <code>http://host:11434/v1</code>). Must be reachable FROM the Bluehost server - a home PC needs a tunnel (Tailscale Funnel / cloudflared) or the VM a public IP. Use HTTPS or a private tunnel; never a bare public HTTP endpoint.</p></td></tr>
                    <tr><th>Custom API key (optional)</th><td><input type="password" name="custom_api_key" class="regular-text" placeholder="<?php echo $s['custom_api_key'] ? 'saved - leave blank to keep' : 'leave empty for local models'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Custom model</th><td><input type="text" name="custom_model" class="regular-text" value="<?php echo esc_attr($s['custom_model']); ?>" placeholder="llama3.1:8b"></td></tr>
                    <tr><th>Max tokens</th><td><input type="number" name="max_tokens" value="<?php echo esc_attr($s['max_tokens']); ?>"></td></tr>
                    <tr><th>Pexels API key</th><td><input type="password" name="pexels_api_key" class="regular-text" placeholder="<?php echo $s['pexels_api_key'] ? 'saved - leave blank to keep' : 'free key from pexels.com/api'; ?>" autocomplete="off">
                        <p class="description">When set, each new draft gets a featured image: the first Pexels photo matching the post's focus keyword. Same key the video worker uses.</p></td></tr>
                </table>
                </div>
                </details>

                <details class="mfce-acc">
                <summary>Telegram settings</summary>
                <div class="mfce-inside">
                <table class="form-table">
                    <tr><th>Telegram bot token</th><td><input type="password" name="telegram_bot_token" class="regular-text" placeholder="<?php echo $s['telegram_bot_token'] ? 'saved - leave blank to keep' : '123456:ABC...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Telegram chat id</th><td><input type="text" name="telegram_chat_id" class="regular-text" value="<?php echo esc_attr($s['telegram_chat_id']); ?>">
                        <p class="description">Your personal chat id with the bot (message @userinfobot to find it) - never the bot's own id.</p></td></tr>
                </table>
                </div>
                </details>
                <p><button class="button button-primary">Save settings</button> <span class="description">Saves both sections above.</span></p>
            </form>

            <details class="mfce-acc">
            <summary>Bluehost cron &amp; video pipeline endpoints</summary>
            <div class="mfce-inside">
            <h3>Bluehost cron</h3>
            <p style="max-width:720px">Create a daily cron job in cPanel (e.g. 6:00 AM) with this command:</p>
            <code style="display:block;max-width:720px;padding:8px;background:#fff;word-break:break-all">curl -s "<?php echo esc_html($cron_url); ?>" &gt; /dev/null 2&gt;&amp;1</code>
            <p class="description">The engine runs at most once per calendar day, so an accidental second cron hit is harmless.</p>

            <h3>Video pipeline endpoints</h3>
            <p style="max-width:720px">For the external video workflow (Make.com, a local script, etc.). All share the cron secret. Statuses flow: <code>needed</code> → <code>review</code> (after video-ready) → <code>approved</code>/<code>rejected</code> (Telegram buttons) → <code>embedded</code> (after video-embed).</p>
            <table class="widefat striped" style="max-width:900px">
                <tbody>
                    <tr><td>Poll queue + briefs + approval status</td><td><code style="word-break:break-all">GET <?php echo esc_html(rest_url('mfce/v1/video-queue') . '?secret=' . rawurlencode($s['cron_secret'])); ?></code></td></tr>
                    <tr><td>Announce a rendered video for approval</td><td><code style="word-break:break-all">POST <?php echo esc_html(rest_url('mfce/v1/video-ready') . '?secret=' . rawurlencode($s['cron_secret'])); ?>&amp;post_id=ID&amp;preview_url=URL</code></td></tr>
                    <tr><td>Embed the approved YouTube video</td><td><code style="word-break:break-all">POST <?php echo esc_html(rest_url('mfce/v1/video-embed') . '?secret=' . rawurlencode($s['cron_secret'])); ?>&amp;post_id=ID&amp;youtube_url=URL</code></td></tr>
                </tbody>
            </table>
            </div>
            </details>

            <details class="mfce-acc" <?php echo $pending ? '' : 'open'; ?>>
            <summary>Topic queue (<?php echo (int) count($pending); ?> pending)</summary>
            <div class="mfce-inside">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
                <?php wp_nonce_field('mfce_add_topics'); ?>
                <input type="hidden" name="action" value="mfce_add_topics">
                <textarea name="topics" rows="4" class="large-text" placeholder="One topic per line. Optional editor notes after a pipe:&#10;The 5-minute morning reset | angle: for people who hate mornings"></textarea>
                <p><button class="button">Add topics</button></p>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px;margin-top:8px;padding:10px 12px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px">
                <?php wp_nonce_field('mfce_plan_topics'); ?>
                <input type="hidden" name="action" value="mfce_plan_topics">
                <strong>Or let the AI plan the queue.</strong>
                <p class="description" style="margin:4px 0 6px">The AI reads your current queue, recent posts, the persona schedule, season and holidays, then proposes a fresh 10-20 topic queue for your review (nothing is replaced until you approve the popup). Optional extra instructions:</p>
                <textarea name="instructions" rows="2" class="large-text" placeholder="e.g. Also make a couple of series based on the season and have them last a week each. Include at least two recipes."></textarea>
                <p><button class="button" onclick="this.disabled=true;this.form.submit();this.textContent='Planning... (takes a minute)'">Ask AI to plan the queue</button></p>
            </form>
            <?php $providers = array('anthropic' => 'Anthropic', 'gemini' => 'Gemini', 'openai' => 'OpenAI', 'grok' => 'Grok', 'custom' => 'Custom/local'); ?>
            <p class="description">Pending topics are editable in place. Persona and provider/model default to the day's schedule and the settings above; set them only when a specific topic should override.</p>
            <table class="widefat striped">
                <thead><tr><th style="width:26%">Topic</th><th style="width:26%">Notes</th><th>Persona</th><th>Provider / model</th><th>Status</th><th style="width:130px"></th></tr></thead>
                <tbody>
                <?php if (!$topics) : ?>
                    <tr><td colspan="6">Queue is empty<?php echo !empty($s['auto_topic']) ? ' - the AI will pick each day\'s topic until you add some' : ''; ?>.</td></tr>
                <?php endif; ?>
                <?php foreach ($topics as $t) : $fid = 'tf-' . $t['id']; ?>
                    <?php if ($t['status'] === 'pending') : ?>
                    <tr>
                        <td><input type="text" name="title" form="<?php echo esc_attr($fid); ?>" value="<?php echo esc_attr($t['title']); ?>" class="mfce-topic-input"></td>
                        <td><input type="text" name="notes" form="<?php echo esc_attr($fid); ?>" value="<?php echo esc_attr($t['notes']); ?>" class="mfce-topic-input"></td>
                        <td>
                            <select name="persona" form="<?php echo esc_attr($fid); ?>">
                                <option value="">Schedule default</option>
                                <?php foreach ($personas as $pkey => $p) : ?>
                                    <option value="<?php echo esc_attr($pkey); ?>" <?php selected($t['persona'] ?? '', $pkey); ?>><?php echo esc_html($p['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="provider" form="<?php echo esc_attr($fid); ?>">
                                <option value="">Default</option>
                                <?php foreach ($providers as $prov_key => $prov_label) : ?>
                                    <option value="<?php echo esc_attr($prov_key); ?>" <?php selected($t['provider'] ?? '', $prov_key); ?>><?php echo esc_html($prov_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="model" form="<?php echo esc_attr($fid); ?>" value="<?php echo esc_attr($t['model'] ?? ''); ?>" placeholder="model (optional)" style="margin-top:4px;max-width:140px">
                        </td>
                        <td>pending<?php echo !empty($t['auto']) ? '<br><em>(AI-picked)</em>' : ''; ?></td>
                        <td>
                            <button class="button button-small button-primary" form="<?php echo esc_attr($fid); ?>">Save</button>
                            <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mfce_delete_topic&topic=' . $t['id']), 'mfce_delete_topic')); ?>">Delete</a>
                        </td>
                    </tr>
                    <?php else : ?>
                    <tr>
                        <td><?php echo esc_html($t['title']); ?></td>
                        <td><?php echo esc_html($t['notes']); ?></td>
                        <td><?php echo esc_html(isset($t['persona'], $personas[$t['persona']]) ? $personas[$t['persona']]['name'] : '-'); ?></td>
                        <td><?php echo esc_html(!empty($t['provider']) ? $t['provider'] . (!empty($t['model']) ? ' / ' . $t['model'] : '') : '-'); ?></td>
                        <td><?php echo esc_html($t['status'] . (isset($t['post_id']) ? ' (draft #' . $t['post_id'] . ')' : '') . (!empty($t['auto']) ? ', AI-picked' : '')); ?></td>
                        <td></td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php foreach ($topics as $t) : if ($t['status'] !== 'pending') { continue; } ?>
                <form id="tf-<?php echo esc_attr($t['id']); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('mfce_update_topic'); ?>
                    <input type="hidden" name="action" value="mfce_update_topic">
                    <input type="hidden" name="topic_id" value="<?php echo esc_attr($t['id']); ?>">
                </form>
            <?php endforeach; ?>
            </div>
            </details>

            <details class="mfce-acc">
            <summary>Recent activity</summary>
            <div class="mfce-inside">
            <table class="widefat striped">
                <tbody>
                <?php if (!$log) : ?>
                    <tr><td>No activity yet.</td></tr>
                <?php endif; ?>
                <?php foreach ((array) $log as $line) : ?>
                    <tr><td><?php echo esc_html($line); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            </details>
        </div>
        <?php
    }
}

MFCE_Engine::init();
