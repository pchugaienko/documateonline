<?php
/**
 * Documate online functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Documate_online
 */

if (!defined('_S_VERSION')) {
    // Replace the version number of the theme on each release.
    define('_S_VERSION', '1.0.0');
}

/**
 * Sets up theme defaults and registers support for various WordPress features.
 *
 * Note that this function is hooked into the after_setup_theme hook, which
 * runs before the init hook. The init hook is too late for some features, such
 * as indicating support for post thumbnails.
 */
function documate_online_setup()
{
    /*
        * Make theme available for translation.
        * Translations can be filed in the /languages/ directory.
        * If you're building a theme based on Documate online, use a find and replace
        * to change 'documate-online' to the name of your theme in all the template files.
        */
    load_theme_textdomain('documate-online', get_template_directory() . '/languages');

    // Add default posts and comments RSS feed links to head.
    add_theme_support('automatic-feed-links');

    /*
        * Let WordPress manage the document title.
        * By adding theme support, we declare that this theme does not use a
        * hard-coded <title> tag in the document head, and expect WordPress to
        * provide it for us.
        */
    add_theme_support('title-tag');

    /*
        * Enable support for Post Thumbnails on posts and pages.
        *
        * @link https://developer.wordpress.org/themes/functionality/featured-images-post-thumbnails/
        */
    add_theme_support('post-thumbnails');

    // This theme uses wp_nav_menu() in one location.
    register_nav_menus(
        array(
            'menu-1' => esc_html__('Primary', 'documate-online'),
        )
    );

    /*
        * Switch default core markup for search form, comment form, and comments
        * to output valid HTML5.
        */
    add_theme_support(
        'html5',
        array(
            'search-form',
            'comment-form',
            'comment-list',
            'gallery',
            'caption',
            'style',
            'script',
        )
    );

    // Set up the WordPress core custom background feature.
    add_theme_support(
        'custom-background',
        apply_filters(
            'documate_online_custom_background_args',
            array(
                'default-color' => 'ffffff',
                'default-image' => '',
            )
        )
    );

    // Add theme support for selective refresh for widgets.
    add_theme_support('customize-selective-refresh-widgets');

    /**
     * Add support for core custom logo.
     *
     * @link https://codex.wordpress.org/Theme_Logo
     */
    add_theme_support(
        'custom-logo',
        array(
            'height' => 250,
            'width' => 250,
            'flex-width' => true,
            'flex-height' => true,
        )
    );
}

add_action('after_setup_theme', 'documate_online_setup');

/**
 * Set the content width in pixels, based on the theme's design and stylesheet.
 *
 * Priority 0 to make it available to lower priority callbacks.
 *
 * @global int $content_width
 */
function documate_online_content_width()
{
    $GLOBALS['content_width'] = apply_filters('documate_online_content_width', 640);
}

add_action('after_setup_theme', 'documate_online_content_width', 0);

/**
 * Register widget area.
 *
 * @link https://developer.wordpress.org/themes/functionality/sidebars/#registering-a-sidebar
 */
function documate_online_widgets_init()
{
    register_sidebar(
        array(
            'name' => esc_html__('Sidebar', 'documate-online'),
            'id' => 'sidebar-1',
            'description' => esc_html__('Add widgets here.', 'documate-online'),
            'before_widget' => '<section id="%1$s" class="widget %2$s">',
            'after_widget' => '</section>',
            'before_title' => '<h2 class="widget-title">',
            'after_title' => '</h2>',
        )
    );
}

add_action('widgets_init', 'documate_online_widgets_init');

/**
 * Enqueue scripts and styles.
 */
function documate_online_scripts()
{
    wp_enqueue_style('documate-online-style', get_stylesheet_uri(), array(), _S_VERSION);
    wp_style_add_data('documate-online-style', 'rtl', 'replace');

    wp_enqueue_script('documate-online-jquery', '//code.jquery.com/jquery-3.7.0.min.js', array(), _S_VERSION, true);
    wp_enqueue_script('documate-online-eusign', get_template_directory_uri() . '/assets/eusign.js', array(), _S_VERSION, false);
//	wp_enqueue_script( 'documate-online-UsageJS', get_template_directory_uri() . '/UsageJS/main.js', array(), _S_VERSION, false );
    wp_enqueue_script('documate-online-main', get_template_directory_uri() . '/assets/main.js', array(), _S_VERSION, true);
    wp_enqueue_script('documate-online-navigation', get_template_directory_uri() . '/js/navigation.js', array(), _S_VERSION, true);

    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
    }
}

add_action('wp_enqueue_scripts', 'documate_online_scripts');

/**
 * Implement the Custom Header feature.
 */
require get_template_directory() . '/inc/custom-header.php';

/**
 * Custom template tags for this theme.
 */
require get_template_directory() . '/inc/template-tags.php';

/**
 * Functions which enhance the theme by hooking into WordPress.
 */
require get_template_directory() . '/inc/template-functions.php';

/**
 * Customizer additions.
 */
require get_template_directory() . '/inc/customizer.php';

/**
 * Load Jetpack compatibility file.
 */
if (defined('JETPACK__VERSION')) {
    require get_template_directory() . '/inc/jetpack.php';
}

//**********************AJAX Url**************************
add_action('wp_head', 'documate_online_ajaxurl');
function documate_online_ajaxurl()
{
    echo '<script type="text/javascript">
           var ajaxurl = "' . admin_url('admin-ajax.php') . '";
         </script>';
}

//**********************AJAX Url**************************

add_action('wp_ajax_nopriv_send_pdf', 'send_pdf');
add_action('wp_ajax_send_pdf', 'send_pdf');

function send_pdf()
{
    $name_pdf = $_POST['name_pdf'];

    $filelocation = get_template_directory() . '/PDF-files/';

    mkdir($filelocation, 0777, true);

    $target = $filelocation . $_FILES['file']["name"];
    move_uploaded_file($_FILES['file']["tmp_name"], $target);
//    if(move_uploaded_file($_FILES["tmp_name"], $target)) {
//        $fp = fopen($target, "r");
//    }
//    move_uploaded_file($_FILES["Attach_Files"]["tmp_name"], $target_path)
    $attachments = array(
        $target,
    );
    $to = get_option('admin_email');
    wp_mail($to, 'Подлписаный документ', 'Вложения приложены', array(), $attachments);

    wp_send_json_success([
        'output' => $to,
        unlink($target)
    ]);
}

add_action('show_user_profile', 'my_user_profile_edit_action');
add_action('edit_user_profile', 'my_user_profile_edit_action');
function my_user_profile_edit_action($user)
{
    $checked = (isset($user->artwork_approved) && $user->artwork_approved) ? ' checked="checked"' : '';
    ?>
    <style>
        .admin-form-field {
            display: flex;
            flex-direction: column;
        }

        .admin-form-field label {
            color: #1d2327;
            font-weight: 600;
            display: block;
            padding: 2px 0;
        }

        .admin-form-field input {
            max-width: 300px;
        }
    </style>
    <h3>Other</h3>
    <div class="admin-form-field">
        <label for="surname">
            По батькові
        </label>
        <input type="text" size="30" id="surname" name="surname" placeholder="По батькові"
               value="<?php echo $user->surname ?>">
    </div>
    <div class="admin-form-field">
        <label for="phone">Телефон</label>
        <input type="tel" id="phone" name="phone" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}" required placeholder="Телефон"
               value="<?php echo $user->phone ?>"/>
    </div>
    <div class="admin-form-field">
        <label for="user_number_document">РНОКПП/номер паспорта</label>
        <input id="user_number_document" name="user_number_document" type="number" maxlength="10" required
               oninput="javascript: if (this.value.length > this.maxLength) this.value = this.value.slice(0, this.maxLength);"
               placeholder="РНОКПП/номер паспорта" value="<?php echo $user->user_number_document ?>"/>
    </div>

    <?php
}

add_action('personal_options_update', 'my_user_profile_update_action');
add_action('edit_user_profile_update', 'my_user_profile_update_action');
function my_user_profile_update_action($user_id)
{
    update_user_meta($user_id, 'surname', $_POST['surname']);
    update_user_meta($user_id, 'phone', $_POST['phone']);
    update_user_meta($user_id, 'user_number_document', $_POST['user_number_document']);
}

////Remove error for username, only show error for email only.
//add_filter('registration_errors', function($wp_error, $sanitized_user_login, $user_email){
//    if(isset($wp_error->errors['empty_username'])){
//        unset($wp_error->errors['empty_username']);
//    }
//
//    if(isset($wp_error->errors['username_exists'])){
//        unset($wp_error->errors['username_exists']);
//    }
//    return $wp_error;
//}, 10, 3);
//
//add_action('login_form_register', function(){
//    if(isset($_POST['user_login']) && isset($_POST['user_email']) && !empty($_POST['user_email'])){
//        $_POST['user_login'] = $_POST['user_email'];
//    }
//});
add_action('wp_ajax_nopriv_authenticate_test', 'authenticate_test');
add_action('wp_ajax_authenticate_test', 'authenticate_test');
function authenticate_test()
{
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://test.id.gov.ua/?response_type=code&%20client_id=e4fb44cced44cc211c4451ad3b4238f3&auth_type=bank_id%26&state=xyz1234567890&redirect_uri=https%3A%2F%2Fdocumate.online%2Fmy-account%2F',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Cookie: cookiesession1=678ADA59C3D9ACC97AF2B1E135D6D44F; idgovua=orsa7ca5nmog2rhsvln55ip6sj'
        ),
    ));

    $response = curl_exec($curl);
    wp_send_json_success([
        'output' => $response,

    ]);
}

function get_access_token($code)
{

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://test.id.gov.ua/get-access-token?grant_type=authorization_code&client_id=e4fb44cced44cc211c4451ad3b4238f3&client_secret=66e291165e9cb86e394075e31cc70253984f8c61&code=' . $code,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Cookie: cookiesession1=678ADA596CFF4A911A60CFD801F6186B; idgovua=igl0bs4c6sr5k140h1s7tu9mbg'
        ),
    ));

    $response = curl_exec($curl);
    return $response;

}

function get_user_info($access_token, $user_id)
{

    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://test.id.gov.ua/get-user-info?access_token='.$access_token.'&user_id='.$user_id.'&cert=',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => array(
            'Cookie: cookiesession1=678ADA596CFF4A911A60CFD801F6186B; idgovua=igl0bs4c6sr5k140h1s7tu9mbg'
        ),
    ));

    $response = curl_exec($curl);

    return $response;

}


/**
 * Authorization
 */
require get_template_directory() . '/UsagePHP/OAuth.php';

// 1. redirect to ID.gov.ua
function redirect_to_idgovua_login()
{
    $client_id = 'e4fb44cced44cc211c4451ad3b4238f3';
    $redirect_uri = urlencode(home_url('/govua-callback')); // URL callback
    $authorization_url = "https://id.gov.ua/oauth2/authorize?client_id=$client_id&redirect_uri=$redirect_uri&response_type=code";
    wp_redirect($authorization_url);
    exit;
}

// 2. handler ID.gov.ua
function govua_callback_handler()
{
    if (isset($_GET['code'])) {
        $code = $_GET['code'];
        $client_id = 'e4fb44cced44cc211c4451ad3b4238f3';
        $client_secret = '66e291165e9cb86e394075e31cc70253984f8c61';
        $redirect_uri = home_url('/govua-callback'); // URL callback

        // request token
        $token_url = 'https://id.gov.ua/oauth2/token';
        $response = wp_remote_post($token_url, array(
            'body' => array(
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirect_uri,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
            ),
        ));

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);

        if (isset($data->access_token)) {
            $access_token = $data->access_token;

            // get user data
            $user_info = wp_remote_get('https://id.gov.ua/oauth2/userinfo', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                ),
            ));
            $user_data = json_decode(wp_remote_retrieve_body($user_info));

            // create and auth user
            create_or_login_user($user_data);
        }
    }
}

add_action('init', 'govua_callback_handler');

// 3. create and auth user
function create_or_login_user($user_data)
{
    $user_email = $user_data->email;
    $user_name = $user_data->name;

    if (email_exists($user_email)) {
        $user = get_user_by('email', $user_email);
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
    } else {
        // Создание нового пользователя
        $user_id = wp_create_user($user_name, wp_generate_password(), $user_email);
        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);
    }

    // redirect to google - test
    wp_redirect('https://www.google.com');
    exit;
}


// 4. handler URL logib via ID.gov.ua
add_action('init', function () {
    add_rewrite_rule('govua-login/?$', 'index.php?govua_login=1', 'top');
});

// 5. create variable
add_filter('query_vars', function ($query_vars) {
    $query_vars[] = 'govua_login';
    return $query_vars;
});

// 6. handler login
add_action('template_redirect', function () {
    if (get_query_var('govua_login')) {
        redirect_to_idgovua_login();
    }
});

// 7. handler url callback
add_action('init', function () {
    add_rewrite_rule('govua-callback/?$', 'index.php?govua_callback=1', 'top');
});

// 8. create variable for callback
add_filter('query_vars', function ($query_vars) {
    $query_vars[] = 'govua_callback';
    return $query_vars;
});

// 9. handler callback
add_action('template_redirect', function () {
    if (get_query_var('govua_callback')) {
        govua_callback_handler();
    }
});

