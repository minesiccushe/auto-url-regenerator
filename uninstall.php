<?php
// if uninstall.php is not called by WordPress, die
if (!defined('WP_UNINSTALL_PLUGIN')):
    die;
endif;

$option_name = 'aug_enable';
if (get_option($option_name)) {
    delete_option($option_name);
}

flush_rewrite_rules();