<?php
/**
 * Plugin Name: Manifested Fit Content Engine
 * Description: Supervised AI drafting engine. A daily cron (or the Run Now button) picks the day's persona and next queued topic, asks Claude to write the post, saves it as a DRAFT under the persona's byline, and sends a Telegram notification for review. Nothing is ever auto-published.
 * Version: 0.1.0
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
        add_action('admin_post_mfce_delete_topic', array(__CLASS__, 'handle_delete_topic'));
        add_action('admin_post_mfce_run_now', array(__CLASS__, 'handle_run_now'));
        add_action('admin_post_mfce_test_telegram', array(__CLASS__, 'handle_test_telegram'));
        add_action('admin_post_mfce_register_webhook', array(__CLASS__, 'handle_register_webhook'));
        add_action('rest_api_init', array(__CLASS__, 'register_rest'));
    }

    public static function defaults() {
        return array(
            'enabled'            => 0,
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
        $persona = $personas[$pick['persona']];

        $author = get_user_by('login', $persona['login']);
        if (!$author) {
            return self::finish(false, 'WP user not found for persona login "' . $persona['login'] . '".', null, true);
        }

        // Solemn days write a fixed-purpose piece and do not consume a queue topic.
        $topic = null;
        if ($pick['mode'] === 'solemn') {
            $topic = array(
                'title' => 'A quiet reflection for ' . $pick['occasion'],
                'notes' => 'This is ' . $pick['occasion'] . ' in Canada, a day of mourning and remembrance. Write a short, respectful, calm reflection. No humour, no product mentions, no calls to action, no manifestation framing. Simply honour the day and invite stillness.',
            );
        } else {
            $topic = self::next_topic();
            if (!$topic) {
                return self::finish(false, 'Topic queue is empty - add topics in the Content Engine admin page.', null, true);
            }
        }

        $result = self::generate_post($s, $persona, $pick, $topic);
        if (is_wp_error($result)) {
            return self::finish(false, 'Claude call failed: ' . $result->get_error_message(), null, true);
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

        if ($pick['mode'] !== 'solemn' && isset($topic['id'])) {
            self::mark_topic_used($topic['id'], $post_id);
        }

        $s = self::settings();
        $s['last_run_date'] = $today;
        update_option(self::OPT_SETTINGS, $s, false);

        $edit_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        $msg = "Manifested Fit Engine\n"
             . "New draft by {$persona['name']} (" . $now->format('D M j') . ", {$pick['mode']}):\n"
             . '"' . $result['title'] . "\"\n"
             . "Review: {$edit_url}";
        self::telegram_send_draft_notice($s, $post_id, $msg);

        return self::finish(true, 'Draft #' . $post_id . ' created by ' . $persona['name'] . ' via ' . $context . ': "' . $result['title'] . '"', $post_id, false);
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

    /** Route a structured-JSON generation to the selected provider. */
    private static function ai_json($s, $system, $user, $schema) {
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

        $system = "You are revising a draft blog post for Manifested Fit (wellness/manifestation blog). {$voice}\n"
            . "Apply the editor's instructions while keeping the persona voice, honesty guardrails (no fabricated credentials, no medical or income claims) and clean HTML (<h2>, <h3>, <p>, <ul>, <ol>, <li>, <blockquote>, <strong>, <em>, <a> only).";
        $user = "Editor's instructions: {$instructions}\n\nCurrent title: {$post->post_title}\n\nCurrent excerpt: {$post->post_excerpt}\n\nCurrent HTML:\n{$post->post_content}";
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

        $updated = wp_update_post(array(
            'ID'           => $post_id,
            'post_title'   => wp_strip_all_tags($parsed['title']),
            'post_excerpt' => sanitize_text_field($parsed['excerpt']),
            'post_content' => wp_kses_post($parsed['content_html']),
        ), true);
        if (is_wp_error($updated)) { return $updated; }

        self::log('OK: draft #' . $post_id . ' revised via Telegram.');
        return $parsed;
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
        $keyboard = array('inline_keyboard' => array(array(
            array('text' => 'Publish',    'callback_data' => 'publish:' . $post_id),
            array('text' => 'Keep draft', 'callback_data' => 'keep:' . $post_id),
            array('text' => 'Trash',      'callback_data' => 'trash:' . $post_id),
        )));
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

        if (!preg_match('/^(publish|keep|trash):(\d+)$/', (string) $cb['data'], $m)) { return; }
        $action  = $m[1];
        $post_id = (int) $m[2];
        $post    = get_post($post_id);
        if (!$post) {
            self::telegram_send($s, 'Post #' . $post_id . ' no longer exists.');
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

        // General chat with the configured model.
        $system = 'You are the Manifested Fit content engine assistant, chatting with the site owner over Telegram. '
            . 'Manifested Fit is a wellness/manifestation brand with a WordPress blog and a faceless YouTube strategy. '
            . 'Be helpful and concise (Telegram-sized answers, plain text, no markdown). '
            . 'You cannot take actions from this chat; drafting happens on the daily schedule and approvals happen via the buttons.';
        $reply = self::ai_text($s, $system, $text);
        if (is_wp_error($reply)) {
            self::telegram_send($s, 'AI call failed: ' . $reply->get_error_message());
            return;
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
        $provider                = sanitize_text_field(wp_unslash($_POST['provider'] ?? 'anthropic'));
        $s['provider']           = in_array($provider, array('anthropic', 'gemini', 'openai', 'grok', 'custom'), true) ? $provider : 'anthropic';
        $s['model']              = sanitize_text_field(wp_unslash($_POST['model'] ?? $s['model']));
        $s['gemini_model']       = sanitize_text_field(wp_unslash($_POST['gemini_model'] ?? $s['gemini_model']));
        $s['openai_model']       = sanitize_text_field(wp_unslash($_POST['openai_model'] ?? $s['openai_model']));
        $s['grok_model']         = sanitize_text_field(wp_unslash($_POST['grok_model'] ?? $s['grok_model']));
        $s['custom_model']       = sanitize_text_field(wp_unslash($_POST['custom_model'] ?? $s['custom_model']));
        $s['custom_base_url']    = esc_url_raw(trim((string) wp_unslash($_POST['custom_base_url'] ?? $s['custom_base_url'])));
        $s['max_tokens']         = max(1000, (int) ($_POST['max_tokens'] ?? $s['max_tokens']));
        $s['telegram_chat_id']   = sanitize_text_field(wp_unslash($_POST['telegram_chat_id'] ?? ''));
        // Secret fields: keep the stored value when the input is left blank.
        foreach (array('anthropic_api_key', 'gemini_api_key', 'openai_api_key', 'grok_api_key', 'custom_api_key', 'telegram_bot_token') as $secret_field) {
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
            'deleted' => array('success', 'Topic removed.'),
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
            <h1>Manifested Fit Content Engine</h1>
            <?php if (isset($notices[$notice])) : ?>
                <div class="notice notice-<?php echo esc_attr($notices[$notice][0]); ?> is-dismissible"><p><?php echo esc_html($notices[$notice][1]); ?></p></div>
            <?php endif; ?>

            <h2>Status</h2>
            <table class="widefat striped" style="max-width:720px">
                <tbody>
                    <tr><td>Engine</td><td><?php echo $s['enabled'] ? '<strong style="color:green">Enabled</strong>' : '<strong style="color:#b00">Disabled</strong> (cron runs are skipped; Run Now still works)'; ?></td></tr>
                    <tr><td>Today's persona</td><td><?php echo esc_html($personas[$pick['persona']]['name'] . ' (' . $pick['mode'] . ($pick['occasion'] ? ': ' . $pick['occasion'] : '') . ')'); ?></td></tr>
                    <tr><td>Pending topics</td><td><?php echo (int) count($pending); ?></td></tr>
                    <tr><td>Last run</td><td><?php echo esc_html($s['last_run_date'] ?: 'never'); ?></td></tr>
                    <tr><td>Provider / model</td><td><?php echo esc_html(self::provider_label($s)); ?></td></tr>
                    <tr><td>API key (selected provider)</td><td><?php echo self::provider_ready($s) ? 'set (hidden)' : '<strong style="color:#b00">missing</strong>'; ?></td></tr>
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

            <h2>Settings</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
                <?php wp_nonce_field('mfce_save_settings'); ?>
                <input type="hidden" name="action" value="mfce_save_settings">
                <table class="form-table">
                    <tr><th>Enabled (daily cron)</th><td><label><input type="checkbox" name="enabled" <?php checked($s['enabled'], 1); ?>> Allow the daily cron to create a draft</label></td></tr>
                    <tr><th>AI provider</th><td>
                        <select name="provider">
                            <option value="anthropic" <?php selected($s['provider'], 'anthropic'); ?>>Anthropic (Claude)</option>
                            <option value="gemini" <?php selected($s['provider'], 'gemini'); ?>>Google Gemini</option>
                            <option value="openai" <?php selected($s['provider'], 'openai'); ?>>OpenAI</option>
                            <option value="grok" <?php selected($s['provider'], 'grok'); ?>>Grok (xAI)</option>
                            <option value="custom" <?php selected($s['provider'], 'custom'); ?>>Custom / local (OpenAI-compatible, e.g. Ollama)</option>
                        </select>
                        <p class="description">Which AI writes posts, revises drafts, and answers Telegram chat. Only the selected provider's key is required.</p>
                    </td></tr>
                    <tr><th>Anthropic API key</th><td><input type="password" name="anthropic_api_key" class="regular-text" placeholder="<?php echo $s['anthropic_api_key'] ? 'saved - leave blank to keep' : 'sk-ant-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Anthropic model</th><td><input type="text" name="model" class="regular-text" value="<?php echo esc_attr($s['model']); ?>"> <p class="description">Default: claude-opus-4-8</p></td></tr>
                    <tr><th>Gemini API key</th><td><input type="password" name="gemini_api_key" class="regular-text" placeholder="<?php echo $s['gemini_api_key'] ? 'saved - leave blank to keep' : 'AIza...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Gemini model</th><td><input type="text" name="gemini_model" class="regular-text" value="<?php echo esc_attr($s['gemini_model']); ?>"> <p class="description">Default: gemini-2.5-flash</p></td></tr>
                    <tr><th>OpenAI API key</th><td><input type="password" name="openai_api_key" class="regular-text" placeholder="<?php echo $s['openai_api_key'] ? 'saved - leave blank to keep' : 'sk-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>OpenAI model</th><td><input type="text" name="openai_model" class="regular-text" value="<?php echo esc_attr($s['openai_model']); ?>"> <p class="description">Default: gpt-5.1</p></td></tr>
                    <tr><th>Grok API key</th><td><input type="password" name="grok_api_key" class="regular-text" placeholder="<?php echo $s['grok_api_key'] ? 'saved - leave blank to keep' : 'xai-...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Grok model</th><td><input type="text" name="grok_model" class="regular-text" value="<?php echo esc_attr($s['grok_model']); ?>"> <p class="description">Default: grok-4</p></td></tr>
                    <tr><th>Custom base URL</th><td><input type="text" name="custom_base_url" class="regular-text" value="<?php echo esc_attr($s['custom_base_url']); ?>" placeholder="https://my-vm.example.com/v1">
                        <p class="description">Any OpenAI-compatible endpoint (Ollama: <code>http://host:11434/v1</code>). Must be reachable FROM the Bluehost server - a home PC needs a tunnel (Tailscale Funnel / cloudflared) or the VM a public IP. Use HTTPS or a private tunnel; never a bare public HTTP endpoint.</p></td></tr>
                    <tr><th>Custom API key (optional)</th><td><input type="password" name="custom_api_key" class="regular-text" placeholder="<?php echo $s['custom_api_key'] ? 'saved - leave blank to keep' : 'leave empty for local models'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Custom model</th><td><input type="text" name="custom_model" class="regular-text" value="<?php echo esc_attr($s['custom_model']); ?>" placeholder="llama3.1:8b"></td></tr>
                    <tr><th>Max tokens</th><td><input type="number" name="max_tokens" value="<?php echo esc_attr($s['max_tokens']); ?>"></td></tr>
                    <tr><th>Telegram bot token</th><td><input type="password" name="telegram_bot_token" class="regular-text" placeholder="<?php echo $s['telegram_bot_token'] ? 'saved - leave blank to keep' : '123456:ABC...'; ?>" autocomplete="off"></td></tr>
                    <tr><th>Telegram chat id</th><td><input type="text" name="telegram_chat_id" class="regular-text" value="<?php echo esc_attr($s['telegram_chat_id']); ?>"></td></tr>
                </table>
                <p><button class="button button-primary">Save settings</button></p>
            </form>

            <h2>Bluehost cron</h2>
            <p style="max-width:720px">Create a daily cron job in cPanel (e.g. 6:00 AM) with this command:</p>
            <code style="display:block;max-width:720px;padding:8px;background:#fff;word-break:break-all">curl -s "<?php echo esc_html($cron_url); ?>" &gt; /dev/null 2&gt;&amp;1</code>
            <p class="description">The engine runs at most once per calendar day, so an accidental second cron hit is harmless.</p>

            <h2>Topic queue (<?php echo (int) count($pending); ?> pending)</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
                <?php wp_nonce_field('mfce_add_topics'); ?>
                <input type="hidden" name="action" value="mfce_add_topics">
                <textarea name="topics" rows="4" class="large-text" placeholder="One topic per line. Optional editor notes after a pipe:&#10;The 5-minute morning reset | angle: for people who hate mornings"></textarea>
                <p><button class="button">Add topics</button></p>
            </form>
            <table class="widefat striped" style="max-width:900px">
                <thead><tr><th>Topic</th><th>Notes</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$topics) : ?>
                    <tr><td colspan="4">Queue is empty.</td></tr>
                <?php endif; ?>
                <?php foreach ($topics as $t) : ?>
                    <tr>
                        <td><?php echo esc_html($t['title']); ?></td>
                        <td><?php echo esc_html($t['notes']); ?></td>
                        <td><?php echo esc_html($t['status'] . (isset($t['post_id']) ? ' (draft #' . $t['post_id'] . ')' : '')); ?></td>
                        <td>
                            <?php if ($t['status'] === 'pending') : ?>
                                <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=mfce_delete_topic&topic=' . $t['id']), 'mfce_delete_topic')); ?>">Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2>Recent activity</h2>
            <table class="widefat striped" style="max-width:900px">
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
        <?php
    }
}

MFCE_Engine::init();
