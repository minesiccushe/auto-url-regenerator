<?php
/**
 * Test script to verify the security fix in save_aurg_checkbox_fields.
 */

// Mock WordPress functions
function wp_verify_nonce($nonce, $action) {
    if ($nonce === 'valid_nonce' && $action === 'aurg_save_post_meta') {
        return 1;
    }
    return false;
}

function current_user_can($capability, $post_id) {
    if ($capability === 'edit_post' && $post_id === 123) {
        return true;
    }
    return false;
}

function update_post_meta($post_id, $meta_key, $meta_value) {
    echo "SUCCESS: update_post_meta called for post $post_id with $meta_key = $meta_value\n";
}

function sanitize_text_field($str) {
    return $str;
}

function __($text, $domain) {
    return $text;
}

// Mock Auto_URL_Regenerator class or just the method
class Mock_Auto_URL_Regenerator {
    public function save_aurg_checkbox_fields( $post_id )
    {
        // Check if nonce is set.
        if ( ! isset( $_POST['aurg_nonce'] ) ) {
            echo "EXPECTED FAILURE: Nonce not set\n";
            return;
        }

        // Verify that the nonce is valid.
        if ( ! wp_verify_nonce( $_POST['aurg_nonce'], 'aurg_save_post_meta' ) ) {
            echo "EXPECTED FAILURE: Invalid nonce\n";
            return;
        }

        // If this is an autosave, our form has not been submitted, so we don't want to do anything.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            echo "EXPECTED FAILURE: Doing autosave\n";
            return;
        }

        // Check the user's permissions.
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            echo "EXPECTED FAILURE: Permission denied\n";
            return;
        }

        if(isset($_POST['aurg_checkbox']) ){
            update_post_meta($post_id, 'aurg_checkbox', sanitize_text_field($_POST['aurg_checkbox'] ) );
        }
    }
}

$aurg = new Mock_Auto_URL_Regenerator();

echo "--- Test 1: No nonce ---\n";
$_POST = array('aurg_checkbox' => '1');
$aurg->save_aurg_checkbox_fields(123);

echo "\n--- Test 2: Invalid nonce ---\n";
$_POST = array('aurg_checkbox' => '1', 'aurg_nonce' => 'invalid');
$aurg->save_aurg_checkbox_fields(123);

echo "\n--- Test 3: Permission denied ---\n";
$_POST = array('aurg_checkbox' => '1', 'aurg_nonce' => 'valid_nonce');
$aurg->save_aurg_checkbox_fields(456);

echo "\n--- Test 5: Valid request ---\n";
$_POST = array('aurg_checkbox' => '1', 'aurg_nonce' => 'valid_nonce');
$aurg->save_aurg_checkbox_fields(123);

echo "\n--- Test 4: Doing autosave ---\n";
define('DOING_AUTOSAVE', true);
$_POST = array('aurg_checkbox' => '1', 'aurg_nonce' => 'valid_nonce');
$aurg->save_aurg_checkbox_fields(123);
