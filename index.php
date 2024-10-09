<?php
/*
Plugin Name: Email Validation by emaillistverify
Description: Validates the email address using emaillistverify.com during user registration, and prevents registration if invalid.
Version: 1.3
Author: Mahmudul Hasan Rafi
Plugin URI: https://github.com/mh-rafi/wordpress-email-validation
*/

// Function to validate email using EmailListVerify API
function validate_email_with_emaillistverify($email) {
    $api_key = get_option('emaillistverify_api_key', ''); // Get API key from WordPress options
    if (empty($api_key)) {
        error_log('EmailListVerify API key is not set');
        return true; // Allow registration if API key is not set
    }

    // Check if the validation result is cached (transient)
    $cached_result = get_transient('email_validation_' . md5($email));

    if ($cached_result !== false) {
        // Return cached result if available
        return $cached_result === 'ok';
    }

    // Transient not set, validate with EmailListVerify
    $email_validation_url = add_query_arg(
        array(
            'secret' => $api_key,
            'email' => urlencode($email),
            'timeout' => 15
        ),
        'https://apps.emaillistverify.com/api/verifyEmail'
    );

    $response = wp_remote_get($email_validation_url, array(
        'timeout' => 20,
        'sslverify' => false
    ));

    if (is_wp_error($response)) {
        error_log('EmailListVerify API request failed: ' . $response->get_error_message());
        return true; // Allow registration if API request fails
    }

    $response_body = wp_remote_retrieve_body($response);

    // Cache the result (transient) for 1 hour (3600 seconds)
    set_transient('email_validation_' . md5($email), $response_body, 3600);

    // Check if the email is valid
    return $response_body === 'ok';
}

// Hook into registration_errors to validate email before registration
function check_email_during_registration($errors, $sanitized_user_login, $user_email) {
    // Check if WordPress has already added an error for existing email
    $existing_errors = $errors->get_error_messages('email_exists');
    
    if (empty($existing_errors)) {
        // If WordPress hasn't added an error, check if the email exists
        if (email_exists($user_email)) {
            // Email already exists, no need to validate with API
            $errors->add('email_exists', __('This email address is already registered. Please choose another one or log in with this address.'));
        } else {
            // Email doesn't exist, proceed with API validation
            if (!validate_email_with_emaillistverify($user_email)) {
                // Add an error message if the email is invalid
                $errors->add('invalid_email', __('The email address you entered is invalid or cannot be verified. Please use a valid email address.'));
            }
        }
    }

    return $errors;
}
add_filter('registration_errors', 'check_email_during_registration', 20, 3);

// Hook into wp_mail to prevent sending verification email to invalid emails
function prevent_email_if_invalid($args) {
    // Get the email address from the args
    $to_email = isset($args['to']) ? $args['to'] : '';

    // Check if the email was validated and stored
    if ($to_email && !validate_email_with_emaillistverify($to_email)) {
        // Stop the email from being sent if the email is invalid
        return false;  // Abort sending the email
    }

    // Continue sending the email if valid
    return $args;
}
add_filter('wp_mail', 'prevent_email_if_invalid');

// Add settings page for API key
function emaillistverify_settings_page() {
    add_options_page('EmailListVerify Settings', 'Email Verify', 'manage_options', 'emaillistverify-settings', 'emaillistverify_settings_page_content');
}
add_action('admin_menu', 'emaillistverify_settings_page');

function emaillistverify_settings_page_content() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['emaillistverify_api_key'])) {
        update_option('emaillistverify_api_key', sanitize_text_field($_POST['emaillistverify_api_key']));
        echo '<div class="updated"><p>API key updated.</p></div>';
    }

    $api_key = get_option('emaillistverify_api_key', '');
    ?>
    <div class="wrap">
        <h1>EmailListVerify Settings</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="emaillistverify_api_key">API Key</label></th>
                    <td><input type="text" id="emaillistverify_api_key" name="emaillistverify_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text"></td>
                </tr>
            </table>
            <?php submit_button('Save API Key'); ?>
        </form>
        <p>Get your api key from <a href="https://www.emaillistverify.com">emaillistverify</a></p>
    </div>
    <?php
}

?>
