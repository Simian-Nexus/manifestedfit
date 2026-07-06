<?php
/**
 * CLI cron entry point (alternative to the REST endpoint).
 *
 * Use this if the curl/REST cron ever times out on shared hosting - PHP CLI
 * has no web-server timeout. Bluehost cron command example:
 *
 *   php -q /home2/USERNAME/public_html/blog/wp-content/plugins/manifested-fit-content-engine/cron-runner.php YOUR_CRON_SECRET
 *
 * The secret is the same one shown on the plugin's admin page (in the cron URL).
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

// Walk up to the WordPress root: plugins/mfce -> plugins -> wp-content -> root.
$wp_load = dirname(__DIR__, 3) . '/wp-load.php';
if (!file_exists($wp_load)) {
    fwrite(STDERR, "Could not find wp-load.php at {$wp_load}\n");
    exit(1);
}
require $wp_load;

$settings = MFCE_Engine::settings();
$secret   = isset($argv[1]) ? (string) $argv[1] : '';
if (empty($settings['cron_secret']) || !hash_equals($settings['cron_secret'], $secret)) {
    fwrite(STDERR, "Bad or missing secret argument.\n");
    exit(1);
}

$result = MFCE_Engine::run(false, 'cli-cron');
echo ($result['ok'] ? 'OK: ' : 'SKIP/FAIL: ') . $result['message'] . "\n";
exit($result['ok'] ? 0 : 0); // non-fatal skips shouldn't alarm cron; failures are Telegram-notified
