<?php
/**
 * The header for our theme
 *
 * This is the template that displays all of the <head> section and everything up until <div id="content">
 *
 * @link https://developer.wordpress.org/themes/basics/template-files/#template-partials
 *
 * @package Documate_online
 */

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">

	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div id="page" class="site">
	<a class="skip-link screen-reader-text" href="#primary"><?php esc_html_e( 'Skip to content', 'documate-online' ); ?></a>

	<header id="masthead" class="site-header">
		<div class="site-branding">
			<?php
			the_custom_logo();
			if ( is_front_page() && is_home() ) :
				?>
				<h1 class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></h1>
				<?php
			else :
				?>
				<p class="site-title"><a href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home"><?php bloginfo( 'name' ); ?></a></p>
				<?php
			endif;
			$documate_online_description = get_bloginfo( 'description', 'display' );
			if ( $documate_online_description || is_customize_preview() ) :
				?>
				<p class="site-description"><?php echo $documate_online_description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			<?php endif; ?>
		</div><!-- .site-branding -->

		<nav id="site-navigation" class="main-navigation">
			<button class="menu-toggle" aria-controls="primary-menu" aria-expanded="false"><?php esc_html_e( 'Primary Menu', 'documate-online' ); ?></button>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'menu-1',
					'menu_id'        => 'primary-menu',
				)
			);
			?>
		</nav><!-- #site-navigation -->

        <a href="<?php echo esc_url(home_url('/govua-login')); ?>">login via ID.gov.ua</a>


        <?php
        session_start();

        // Генеруємо випадковий state для захисту від CSRF-атак
        $state = bin2hex(random_bytes(32));
        $_SESSION['oauth2state'] = $state;
        ?>

        <a href="https://test.id.gov.ua/?response_type=code&client_id=e4fb44cced44cc211c4451ad3b4238f3&auth_type=dig_sign&state=<?= $state ?>&redirect_uri=https://documate.online/my-account/">
            Увійти через id.gov.ua
        </a>




    </header><!-- #masthead -->
