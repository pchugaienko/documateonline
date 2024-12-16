<?php

/**
 *  Template Name: My Account
 *  Template Post Type: page
 */

get_header();
?>
<?php
$current_user_id = json_decode(get_access_token($_GET['code']));

var_dump($current_user_id->user_id);
var_dump($current_user_id->access_token);
var_dump(json_decode(get_user_info($current_user_id->access_token, $current_user_id->user_id)));
?>
<?php
global $current_user;
wp_get_current_user();
?>
    <main id="primary" class="site-main">
        <h1>My Account</h1>
        <div class="my_account_content">
            <span>User Name: <strong><?php echo $current_user->display_name; ?></strong></span>
            <br>
            <br>
            <br>
            <h4>ПІБ</h4>
            <div class="user_full_name">
<!--                <input type="text" id="first_name" name="first_name" placeholder="Ім'я">-->
<!--                <input type="text" id="last_name" name="last_name" placeholder="Призвище">-->
<!--                <input type="text" id="surname" name="surname" placeholder="По батькові">-->
                <?php echo $current_user->first_name; ?>
                <?php echo $current_user->surname; ?>
                <?php echo $current_user->last_name; ?>
            </div>
            <h4>Телефон</h4>
            <div class="user_phone">
<!--                <input type="tel" id="phone" name="phone" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}" required />-->
                <?php echo $current_user->phone; ?>
            </div>
            <h4>Електронна адреса</h4>
            <div class="user_email">
                <?php echo $current_user->user_email; ?>
            </div>
            <h4>РНОКПП/номер паспорта</h4>
            <div class="user_number_document">
<!--                <input type="number" maxlength="10" required-->
<!--                       oninput="javascript: if (this.value.length > this.maxLength) this.value = this.value.slice(0, this.maxLength);"/>-->
                <?php echo $current_user->user_number_document; ?>
            </div>
        </div>
    </main>


<?php get_footer();
