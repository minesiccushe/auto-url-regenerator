<?php
/**
 * Test for aurg_register_scripts
 */

// Mock WordPress constants and functions
if (!defined('ABSPATH')) define( 'ABSPATH', __DIR__ . '/../' );
if (!defined('WPINC')) define( 'WPINC', 'wp-includes' );

$registered_styles = [];
$registered_scripts = [];

function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
    global $registered_styles;
    $registered_styles[$handle] = [
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
        'media' => $media,
    ];
}

function wp_register_script( $handle, $src, $deps = [], $ver = false, $in_footer = false ) {
    global $registered_scripts;
    $registered_scripts[$handle] = [
        'src' => $src,
        'deps' => $deps,
        'ver' => $ver,
        'in_footer' => $in_footer,
    ];
}

function plugins_url( $path = '', $plugin = '' ) {
    return 'http://example.com/wp-content/plugins/auto-url-regenerator' . $path;
}

function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
    // Mock add_action
}

function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
    // Mock add_filter
}

function register_deactivation_hook( $file, $function ) {
    // Mock register_deactivation_hook
}

function get_option( $option, $default = false ) {
    return [];
}

function __ ( $text, $domain = 'default' ) {
    return $text;
}

function is_admin() {
    return true;
}

function load_plugin_textdomain( $domain, $deprecated = false, $plugin_rel_path = false ) {
    // Mock load_plugin_textdomain
}

class WP_Rewrite {
    public $permalink_structure = '/%postname%/';
    public $rewritecode = [];
    public $rewritereplace = [];
}
$wp_rewrite = new WP_Rewrite();

// Include the plugin file
require_once __DIR__ . '/../auto-url-regenerator.php';

// Instantiate the class
$aurg = new Auto_URL_Regenerator();

// Run the test
$aurg->aurg_register_scripts();

// Assertions
$errors = [];

// Verify Style
if (!isset($registered_styles['aurg_style'])) {
    $errors[] = "Style 'aurg_style' not registered.";
} else {
    $style = $registered_styles['aurg_style'];
    if ($style['src'] !== 'http://example.com/wp-content/plugins/auto-url-regenerator/admin.css') {
        $errors[] = "Style source mismatch. Expected 'http://example.com/wp-content/plugins/auto-url-regenerator/admin.css', got '{$style['src']}'.";
    }
    if ($style['deps'] !== []) {
        $errors[] = "Style dependencies mismatch. Expected [], got " . json_encode($style['deps']) . ".";
    }
    if ($style['ver'] !== AUTO_URL_REGENERATOR_CURRENT_VERSION) {
        $errors[] = "Style version mismatch. Expected '" . AUTO_URL_REGENERATOR_CURRENT_VERSION . "', got '{$style['ver']}'.";
    }
}

// Verify Script
if (!isset($registered_scripts['aurg_script'])) {
    $errors[] = "Script 'aurg_script' not registered.";
} else {
    $script = $registered_scripts['aurg_script'];
    if ($script['src'] !== 'http://example.com/wp-content/plugins/auto-url-regenerator/admin.js') {
        $errors[] = "Script source mismatch. Expected 'http://example.com/wp-content/plugins/auto-url-regenerator/admin.js', got '{$script['src']}'.";
    }
    if ($script['deps'] !== ['jquery']) {
        $errors[] = "Script dependencies mismatch. Expected ['jquery'], got " . json_encode($script['deps']) . ".";
    }
    if ($script['ver'] !== AUTO_URL_REGENERATOR_CURRENT_VERSION) {
        $errors[] = "Script version mismatch. Expected '" . AUTO_URL_REGENERATOR_CURRENT_VERSION . "', got '{$script['ver']}'.";
    }
    if ($script['in_footer'] !== true) {
        $errors[] = "Script in_footer mismatch. Expected true, got " . json_encode($script['in_footer']) . ".";
    }
}

if (empty($errors)) {
    echo "Test passed!\n";
    exit(0);
} else {
    echo "Test failed:\n";
    foreach ($errors as $error) {
        echo "- $error\n";
    }
    exit(1);
}
