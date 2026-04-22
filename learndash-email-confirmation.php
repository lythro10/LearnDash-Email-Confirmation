<?php



// CARDET LearnDash Registration flow

 //Description: Adds an email confirmation step to LearnDash registration with compatibility for Nextend SSO plugin. Also includes profile completion enforcement for new users and custom profile dropdown widget.

 // Version:     2.0.4

 // Author: Bokis Angelov, CARDET Development Team

if (!defined('ABSPATH')) {
    exit;
}



if (is_admin()) {

    require_once plugin_dir_path(__FILE__) . 'admin/admin-functions.php';
    
}
    
include_once plugin_dir_path(__FILE__) . 'my-profile/crdt-my-profile.php';

// Activation hook
register_activation_hook(__FILE__, function() {

    include_once(ABSPATH . 'wp-admin/includes/plugin.php');

    if (!is_plugin_active('sfwd-lms/sfwd_lms.php')) {

        wp_die('This plugin requires LearnDash LMS to be installed and activated.');

    }

});



/**

 * SOCIAL LOGIN BYPASS LOGIC

 */

$GLOBALS['ld_email_confirmation_social_provider'] = null;



add_filter('nsl_registration_user_data', function ($userData, $provider, $error) {

    $provider_id = '';

    if (is_object($provider)) {

        if (method_exists($provider, 'getId')) {

            $provider_id = (string) $provider->getId();

        } elseif (property_exists($provider, 'id')) {

            $provider_id = (string) $provider->id;

        }

    }

    $provider_id = strtolower(trim($provider_id));

    if (in_array($provider_id, ['google', 'facebook', 'twitter'])) {

        $GLOBALS['ld_email_confirmation_social_provider'] = $provider_id;

    }

    return $userData;

}, 10, 3);



/**

 * REGISTRATION & EMAIL SENDING

 */

function ld_email_confirmation_send_email($user_id) {

    // 1. Nextend SSO Logic

    if (!empty($GLOBALS['ld_email_confirmation_social_provider'])) {

        update_user_meta($user_id, 'has_confirmed_email', 'yes');

        delete_user_meta($user_id, 'confirm_email_key');

        return;

    }

    

    // 2. Regular Registration Logic

    $user_info = get_userdata($user_id);

    $user_email = sanitize_email($user_info->user_email);

    $key = sha1(time() . $user_email . wp_rand());

    

    update_user_meta($user_id, 'has_confirmed_email', 'no');

    update_user_meta($user_id, 'confirm_email_key', $key);



    if (isset($_POST['first_name'])) update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['first_name']));

    if (isset($_POST['last_name'])) update_user_meta($user_id, 'last_name', sanitize_text_field($_POST['last_name']));



    // Save password for standard registration

    if (isset($_POST['password'])) { 

        wp_set_password(sanitize_text_field($_POST['password']), $user_id);

    }



    $confirmation_link = add_query_arg(['action' => 'confirm_email', 'key' => $key, 'user' => $user_id], home_url('/'));

    $site_name = get_bloginfo('name');

    $from_email = 'wordpress@' . parse_url(home_url(), PHP_URL_HOST);



    $subject = sprintf('Confirm Your Email Address for %s', $site_name);

    $message = "Hello {$user_info->user_login},<br><br>Please confirm your email address by clicking the link below:<br><br><a href='{$confirmation_link}'>Confirm Email Address</a>";

    $headers = ['Content-Type: text/html; charset=UTF-8', "From: {$site_name} <{$from_email}>"];



    wp_mail($user_email, $subject, $message, $headers);



    set_transient('ld-registered-notice', true, 60);

    wp_redirect(add_query_arg('registered', 'true', home_url()));

    exit;

}

add_action('user_register', 'ld_email_confirmation_send_email');



/**

 * EMAIL CONFIRMATION HANDLER

 */

add_action('init', function() {

    if (isset($_GET['action'], $_GET['user'], $_GET['key']) && $_GET['action'] === 'confirm_email') {

        $user_id = intval($_GET['user']);

        $key_received = sanitize_text_field($_GET['key']);

        $key_expected = get_user_meta($user_id, 'confirm_email_key', true);



        if ($key_received === $key_expected) {

            update_user_meta($user_id, 'has_confirmed_email', 'yes');

            wp_set_current_user($user_id);

            wp_set_auth_cookie($user_id);



            set_transient('ld-confirmed-notice', true, 60);

            // wp_redirect(add_query_arg('confirmed', 'true', home_url('/')));
            wp_redirect(add_query_arg('confirmed', 'true', home_url('/registration-success/')));

            exit;

        }

    }

});



/**
 * GATEKEEPER: REDIRECT IF PROFILE INCOMPLETE
 * Updated to allow YOOtheme Pro Customizer to function.
 */
add_action('template_redirect', function() {
    
    // 1. EXIT if this is an Admin screen, an AJAX request, or the YOOtheme Customizer
    if ( is_admin() || wp_doing_ajax() || isset($_GET['yootheme']) || isset($_GET['customize_changeset_uuid']) ) {
        return;
    }

    if (is_user_logged_in() && !is_page('my-profile')) {
        
        // 2. Allow the user to see the confirmation success notice
        if (isset($_GET['confirmed']) && $_GET['confirmed'] === 'true') {
            return;
        }

        $user_id = get_current_user_id();
        
        // 3. Skip check for Administrators so the builder doesn't break
        if ( current_user_can('manage_options') ) {
            return;
        }

        $country = get_field('user_country', 'user_' . $user_id);
        
        if (empty($country)) {
            wp_redirect(home_url('/my-profile/?action=complete-profile'));
            exit;
        }
    }
});



/**

 * NOTICES & NOTIFICATION UI

 */

function ld_echo_notice($message, $type) {

    $type_class = ($type === 'success') ? 'uk-alert-success' : 'uk-alert-danger';

    $notice_id = "ld-notice-" . wp_rand(100, 999);



    echo "<div id='{$notice_id}' class='uk-alert {$type_class}' uk-alert style='position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:9999;min-width:300px;'>

            <a class='uk-alert-close' uk-close style='position:absolute;top:10px;right:10px;'></a>

            <p style='margin-right:20px;'>" . esc_html($message) . "</p>

          </div>";



    echo "<script>

        setTimeout(function() {

            var notice = document.getElementById('{$notice_id}');

            if (notice) {

                if (window.UIkit && UIkit.alert) {

                    UIkit.alert(notice).close();

                } else {

                    notice.style.opacity = '0';

                    setTimeout(function(){ notice.remove(); }, 500);

                }

            }

        }, 10000);

    </script>";

}



add_action('wp_footer', function() {

    if (get_transient('ld-registered-notice')) {

        ld_echo_notice('An email confirmation has been sent. Please check your inbox.', 'success');

        delete_transient('ld-registered-notice');

    }

    if (get_transient('ld-confirmed-notice')) {

        ld_echo_notice('Your email has been confirmed!', 'success');

        delete_transient('ld-confirmed-notice');

    }

    if (isset($_GET['profile_updated']) && $_GET['profile_updated'] === 'true') {

        ld_echo_notice('Your profile has been updated successfully!', 'success');

    }

});



/**

 * PROFILE UPDATE HANDLER

 */

add_action('template_redirect', function() {

    if (isset($_POST['action']) && $_POST['action'] === 'ld_update_profile') {

        check_admin_referer('ld_save_profile', 'profile_nonce');

        

        $user_id = get_current_user_id();



        update_user_meta($user_id, 'first_name', sanitize_text_field($_POST['first_name']));

        update_user_meta($user_id, 'last_name', sanitize_text_field($_POST['last_name']));



        if (isset($_POST['acf']) && is_array($_POST['acf'])) {

            foreach ($_POST['acf'] as $field_slug => $value) {

                update_field($field_slug, sanitize_text_field($value), 'user_' . $user_id);

            }

        }



        clean_user_cache($user_id);

        wp_redirect(add_query_arg('profile_updated', 'true', home_url('/my-profile/')));

        exit;

    }

});


// HERE BELOW WHERE THE CUSTOM PROFILE FORM SHORTCODE AND LOGOUT WIDGET:






// NEW: Alter login attempt message to users who haven't confirmed their email - WORKS
 /**
 * Prevent login for unconfirmed users and customize the error message.
 *
 * @param WP_User|WP_Error|null $user     The user object or error.
 * @param string                $username The username provided.
 * @return WP_User|WP_Error
 */
// add_filter('authenticate', function($user, $username) {
//     // 1. If there's already an error (like wrong password), don't change anything.
//     if (is_wp_error($user) || empty($user) || !$user instanceof WP_User) {
//         return $user;
//     }

//     // 2. Check the confirmation meta key
//     $is_confirmed = get_user_meta($user->ID, 'has_confirmed_email', true);

//     // 3. Block login if 'no'
//     if ($is_confirmed === 'no') {
//         return new WP_Error(
//             'confirmation_required',
//             __('Account not yet confirmed. Please check your inbox for the activation link.', 'learndash-registration-flow')
//         );
//     }

//     return $user;
// }, 30, 2);


// /**
//  * 2. Force the custom message into the LearnDash Modal alert box. - WORKS
//  */
// add_filter('wp_login_errors', function($errors) {
//     // If our custom error code is present, replace the default message
//     if ($errors->get_error_message('confirmation_required')) {
//         // Clear all other errors to ensure LearnDash doesn't show 'Incorrect Password'
//         $new_errors = new WP_Error();
//         $new_errors->add(
//             'confirmation_required', 
//             __('Account not yet confirmed. Please check your inbox for the activation link.', 'learndash-registration-flow')
//         );
//         return $new_errors;
//     }
//     return $errors;
// }, 10, 1);


add_filter('authenticate', function($user, $username, $password) {
    
    // CONDITION 1: If credentials are wrong, WordPress returns an error.
    // We stop here so we don't redirect people who just mistyped their password.
    if (is_wp_error($user) || empty($user) || !$user instanceof WP_User) {
        return $user;
    }

    // CONDITION 2: The password is CORRECT. Now we check the plugin's "confirmed" flag.
    $is_confirmed = get_user_meta($user->ID, 'has_confirmed_email', true);

    // ONLY if the flag is explicitly 'no' do we trigger the redirect.
    if ($is_confirmed === 'no') {
        $redirect_url = add_query_arg('user_id', $user->ID, home_url('/confirm-your-account/'));
        wp_redirect($redirect_url);
        exit;
    }

    // For everyone else (confirmed users and SSO users), we return $user 
    // and they log in as normal.
    return $user;
}, 30, 3);


/**
 * Shortcode: [ld_resend_confirmation]
 * Displays the message and the Resend button.
 */
add_shortcode('ld_resend_confirmation', function() {
    $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
    
    if (!$user_id) return 'Invalid request for this page :(  Please use the link from your email.';

    $user = get_userdata($user_id);
    if (!$user) return 'User not found.';

    // Handle the Resend Action
    if (isset($_POST['action']) && $_POST['action'] === 'resend_link') {
        check_admin_referer('resend_email_' . $user_id);
        
        // Use your existing function to send the email
        // Note: ld_email_confirmation_send_email triggers redirects, 
        // so we can't call it directly if it has 'exit'. 
        // Better to trigger a simplified version or the same logic.
        ld_resend_email_logic($user_id);
        
        return '<div class="uk-alert-success" uk-alert>A new confirmation link has been sent to ' . esc_html($user->user_email) . '.</div>';
    }

    ob_start(); ?>
    <div class="uk-card uk-card-default uk-card-body uk-text-center">
        <span uk-icon="icon: mail; ratio: 3"></span>
        <h3 class="uk-card-title">Confirm Your Account</h3>
        <p>Hello <b><?php echo esc_html($user->display_name); ?></b>, your account is almost ready. We sent a link to <b><?php echo esc_html($user->user_email); ?></b>.</p>
        <p>If you didn't receive it, click the button below.</p>
        
        <form method="POST">
            <?php wp_nonce_field('resend_email_' . $user_id); ?>
            <input type="hidden" name="action" value="resend_link">
            <button type="submit" class="uk-button uk-button-primary">Resend Confirmation Email</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
});

/**
 * Helper logic for Resending
 */
function ld_resend_email_logic($user_id) {
    $user_info = get_userdata($user_id);
    $key = get_user_meta($user_id, 'confirm_email_key', true);
    
    // If key is missing for some reason, generate new
    if(!$key) {
        $key = sha1(time() . $user_info->user_email . wp_rand());
        update_user_meta($user_id, 'confirm_email_key', $key);
    }

    $confirmation_link = add_query_arg(['action' => 'confirm_email', 'key' => $key, 'user' => $user_id], home_url('/'));
    $site_name = get_bloginfo('name');
    $subject = "Resend: Confirm Your Email for $site_name";
    $message = "Please confirm your email by clicking here: <a href='{$confirmation_link}'>Confirm Account</a>";
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    wp_mail($user_info->user_email, $subject, $message, $headers);
}



/**
 * LearnDash registration: Cloudflare Turnstile validation.
 *
 */

define('CF_TURNSTILE_SITE_KEY', '0x4AAAAAACXnb3rrE0PCVL7K');
define('CF_TURNSTILE_SECRET_KEY', '0x4AAAAAACXnb4e2-DG3ekZ8rdIUDBkuXO0'); 

add_action('plugins_loaded', function () {
    remove_action('registration_errors', 'cfturnstile_wp_register_check', 10, 3);
}, 20);

//  Validate the Turnstile token on form submission.
add_filter('registration_errors', function ($errors, $sanitized_user_login, $user_email) {

    static $checked = false;
    if ( $checked ) {
        return $errors;
    }
    $checked = true;

    $token = isset($_POST['cf-turnstile-response']) ? sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'])) : '';

    if ( empty($token) ) {
        $errors->add('turnstile_missing', __('ERROR: Please verify that you are human.'));
        return $errors;
    }

    $secret = defined('CF_TURNSTILE_SECRET_KEY') ? CF_TURNSTILE_SECRET_KEY : get_option('cf_turnstile_secret_key');


    $resp = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'timeout' => 10,
        'body'    => [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '',
        ],
    ]);

    if ( is_wp_error($resp) ) {
        $errors->add('turnstile_http_error', __('ERROR: Human verification failed. Please try again.'));
        return $errors;
    }

    $body = wp_remote_retrieve_body($resp);
    $data = json_decode($body, true);


    if ( empty($data['success']) ) {
        $errors->add('turnstile_failed', __('ERROR: Please verify that you are human.'));
    }

    return $errors;
}, 10, 3);
