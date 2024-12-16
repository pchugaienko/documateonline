<?php
/* Template Name: ID Gov UA Redirect */
session_start();

// Перевіряємо, чи є авторизаційний код і чи співпадає state
if (isset($_GET['code']) && isset($_GET['state'])) {
    if ($_GET['state'] !== $_SESSION['oauth2state']) {
        // Видаляємо state із сесії для безпеки
        unset($_SESSION['oauth2state']);
        exit('Невірний параметр state');
    }

    // Отримуємо код авторизації
    $auth_code = $_GET['code'];

    // URL для отримання маркера доступу
    $token_url = 'https://test.id.gov.ua/get-access-token';

    // Дані для запиту
    $data = [
        'grant_type' => 'authorization_code',
        'client_id' => 'e4fb44cced44cc211c4451ad3b4238f3',  // Введіть ваш client_id
        'client_secret' => '66e291165e9cb86e394075e31cc70253984f8c61',  // Введіть ваш client_secret
        'code' => $auth_code,
        'redirect_uri' => 'https://documate.online/my-new-page-redirect/'
    ];

    // Відправляємо POST-запит для отримання access_token
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($data)
        ]
    ];

    $context = stream_context_create($options);
    $result = file_get_contents($token_url, false, $context);
    $response = json_decode($result, true);

    // Якщо маркер доступу отримано
    if (isset($response['access_token'])) {
        $access_token = $response['access_token'];
        $user_id = $response['user_id'];

        // Отримуємо дані користувача за допомогою access_token
        $user_info_url = 'https://test.id.gov.ua/get-user-info';
        $user_data = [
            'access_token' => $access_token,
            'user_id' => $user_id,
            'fields' => 'lastname,givenname,middlename,email,drfocode'
        ];

        $options = [
            'http' => [
                'header' => "Authorization: Bearer $access_token\r\n",
                'method' => 'GET'
            ]
        ];

        $context = stream_context_create($options);
        $user_info_result = file_get_contents($user_info_url . '?' . http_build_query($user_data), false, $context);
        $user_info = json_decode($user_info_result, true);

        // Якщо вдалося отримати дані користувача
        if ($user_info) {
            $user_email = $user_info['email'];
            $user_firstname = $user_info['givenname'];
            $user_lastname = $user_info['lastname'];

            // Перевіряємо, чи існує користувач з такою електронною поштою
            $user = get_user_by('email', $user_email);

            if (!$user) {
                // Якщо користувач не існує, створюємо нового
                $random_password = wp_generate_password(12, false);
                $user_id = wp_create_user($user_email, $random_password, $user_email);
                wp_update_user([
                    'ID' => $user_id,
                    'first_name' => $user_firstname,
                    'last_name' => $user_lastname
                ]);

                // Автоматично авторизуємо користувача
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id);
            } else {
                // Якщо користувач вже існує, авторизуємо його
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
            }

            // Перенаправляємо користувача на головну сторінку
            wp_redirect(home_url());
            exit;
        } else {
            exit('Не вдалося отримати дані користувача');
        }
    } else {
        exit('Не вдалося отримати маркер доступу');
    }
} else {
    exit('Відсутній код авторизації або state');
}
?>
