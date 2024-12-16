<?php
/**
 * The main template file
 *
 * This is the most generic template file in a WordPress theme
 * and one of the two required files for a theme (the other being style.css).
 * It is used to display a page when nothing more specific matches a query.
 * E.g., it puts together the home page when no home.php file exists.
 *
 * @link https://developer.wordpress.org/themes/basics/template-hierarchy/
 *
 * @package Documate_online
 */

get_header();
?>
	<main id="primary" class="site-main">
        < <button id="authenticate-btn">Authenticate</button>
        <?php //require_once('../UsagePHP/OAuth.php');
//        $oAuth = new OAuth();
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

        curl_close($curl);
        echo $response;
        //
        //?>
<!--            <input id="auth" type="button" value="Authenticate" onclick="location.href='--><?php //echo $oAuth->getAuthURI(); ?>//'"/>

        <div class="send_pdf_admin">
            <input type="file" id="pdf_file_input" name="upload">
            <button id="create_pdf">Send PDF</button>
        </div>



        <?php echo do_shortcode('[gravityform id="1" title="true"]') ?>

        <!-- <iframe src="https://test.id.gov.ua/sign-widget/v2022testnew/?address=https://documate.online" id="sign-widget" frameborder="0" allowtransparency="true" width="100%" height="100%"></iframe> -->
        

	</main><!-- #main -->

<?php
get_footer();
