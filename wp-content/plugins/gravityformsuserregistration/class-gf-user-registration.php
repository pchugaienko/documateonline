<?php

defined( 'ABSPATH' ) || die();

// Includes the feeds portion of the add-on framework
GFForms::include_feed_addon_framework();

// Includes deprecated functionality for backwards compatibility
require_once( plugin_dir_path( __FILE__ ) . 'includes/deprecated.php');

/**
 * User Registration functionality using the add-on framework
 *
 * Contains most of the functionality of the add-on
 *
 * @see GFFeedAddOn
 */
class GF_User_Registration extends GFFeedAddOn {

	protected $_version                  = GF_USER_REGISTRATION_VERSION;
	protected $_min_gravityforms_version = '2.2.6';
	protected $_slug                     = 'gravityformsuserregistration';
	protected $_path                     = 'gravityformsuserregistration/userregistration.php';
	protected $_full_path                = __FILE__;
	protected $_url                      = 'http://www.gravityforms.com';
	protected $_title                    = 'User Registration Add-On';
	protected $_short_title              = 'User Registration';
	protected $_single_feed_submission   = true;
	protected $_enable_rg_autoupgrade    = true;
	protected $login_form                = array();

	// Members plugin integration
	protected $_capabilities = array( 'gravityforms_user_registration', 'gravityforms_user_registration_uninstall' );

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_user_registration';
	protected $_capabilities_form_settings = 'gravityforms_user_registration';
	protected $_capabilities_uninstall     = 'gravityforms_user_registration_uninstall';

	private static $_instance = null;

	/**
	 * Creates a new instance of the GF_User_Registration
	 *
	 * Only creates a new instance if it does not already exist
	 *
	 * @static
	 *
	 * @return object The GF_User_Registration class object
	 */
	public static function get_instance() {

		if ( self::$_instance == null ) {
			self::$_instance = new self;
		}

		return self::$_instance;
	}

	/**
	 * Handles anything which requires early initialization such as including the username field.
	 */
	public function pre_init() {
		parent::pre_init();

		if ( $this->is_gravityforms_supported() && class_exists( 'GF_Field' ) ) {
			require_once 'includes/class-gf-field-username.php';
			add_action( 'parse_request', array( $this, 'redirect_legacy_activation_page' ) );
		}

		if ( ! wp_next_scheduled ( 'gform_userregistration_cron' ) ) {
			wp_schedule_event( time(), 'twicedaily', 'gform_userregistration_cron' );
		}

		if ( $this->is_gravityforms_supported( '2.7.0.2' ) ) {
			// Only enabling for GF versions that call `delay_feed()` when adding the feed to the queue.
			$this->_async_feed_processing = true;
		}

	}

	/**
	 * Initializes GFAddon and adds the actions that we need
	 *
	 * @see GFAddon
	 */
	public function init() {

		// Add functionality from the parent GFAddon class
		parent::init();

		$this->add_delayed_payment_support(
			array(
				'option_label' => esc_html__( 'Process the feed only when a payment is received.', 'gravityformsuserregistration' ),
			)
		);

		add_filter( 'gaddon_no_output_field_properties', array( $this, 'no_output_field_properties' ) );
		add_filter( 'gform_enable_password_field', '__return_true' );

		add_action( 'wp',        array( $this, 'maybe_activate_user' ) );
		add_action( 'wp_loaded', array( $this, 'custom_registration_page' ) );

		add_action( 'gform_pre_render',                   array( __class__, 'maybe_prepopulate_form' ) );
		add_filter( 'gform_validation',                   array( $this, 'validate' ) );
		add_action( 'gform_pre_submission',               array( $this, 'handle_existing_images_submission' ) );
		add_filter( 'gform_field_input',                  array( $this, 'maybe_update_field_input' ), 10, 5 );

		add_action( 'gform_user_registration_validation', array( $this, 'validate_multisite_submission' ), 10, 3 );
		add_action( 'gform_user_registered',              array( $this, 'create_site' ), 10, 4 );
		add_action( 'gform_user_updated',                 array( $this, 'create_site' ), 10, 4 );

		add_action( 'gform_after_create_post', array( $this, 'set_user_as_post_author' ), 10, 3 );

		// If BuddyPress is active, adds additional actions
		if ( self::is_bp_active() ) {
			add_action( 'gform_user_registered', array( $this, 'update_buddypress_data' ), 10, 3 );
			add_action( 'gform_user_updated',    array( $this, 'update_buddypress_data' ), 10, 3);
			add_action( 'gform_user_registered', array( $this, 'do_buddypress_user_signup' ) );
			add_action( 'bp_core_activated_user', array( $this, 'add_meta_to_bp_user' ), 10, 3 );
		}

		// process users from unspammed entries
		add_action( 'gform_update_status', array( $this, 'process_feed_when_unspammed' ), 10, 3 );

		// PayPal options
		if ( $this->is_gravityforms_supported( '2.0-beta-2' ) ) {
			if ( method_exists( $this, 'add_post_payment_actions' ) ) {
				remove_filter( 'gform_addon_feed_settings_fields', array( $this, 'add_post_payment_actions' ) );
			} else {
				remove_filter( 'gform_gravityformspaypal_feed_settings_fields', array( $this, 'add_paypal_post_payment_actions' ) );
			}
		} else {
			remove_action( 'gform_paypal_action_fields', array( $this, 'add_paypal_settings' ), 10 );
			remove_filter( 'gform_paypal_save_config', array( $this, 'save_paypal_settings' ) );
		}

		if ( method_exists( $this, 'add_post_payment_actions' ) ) {
			add_filter( 'gform_addon_feed_settings_fields', array( $this, 'add_feed_settings' ), 10, 2 );
		} else {
			add_filter( 'gform_gravityformspaypal_feed_settings_fields', array( $this, 'add_paypal_settings' ), 10, 2 );
		}

		// add paypal ipn hooks
		add_action( 'gform_subscription_canceled', array( $this, 'downgrade_user' ), 10, 2 );
		add_action( 'gform_subscription_canceled', array( $this, 'downgrade_site' ), 10, 2 );

		// Add user meta shortcode
		add_filter( 'gform_shortcode_user', array( $this, 'parse_user_meta_shortcode' ), 10, 3 );

		// Add login form shortcode and sign on hooks
		add_filter( 'gform_shortcode_login', array( $this, 'parse_login_shortcode' ), 10, 3 );
		add_action( 'wp', array( $this, 'handle_login_submission' ) );

		$this->load_pending_activations();

		// Add support for data retention.
		add_filter( 'gform_entry_ids_automatic_deletion', array( $this, 'filter_gform_entry_ids_automatic_deletion' ) );

		// add support for UR related merge tags
		add_filter( 'gform_replace_merge_tags', array( $this, 'replace_merge_tags' ), 10, 7 );

		$this->define_gf_new_user_notification();

	}

	/**
	 * Enqueues required JavaScript
	 *
	 * Defines required scripts for the User Registration add-on, and adds them to scripts in GFFeedAddOn
	 *
	 * @see GFFeedAddOn::scripts()
	 *
	 * @return array Contains the scripts to be enqueued
	 */
	public function scripts() {

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

		$scripts = array(
			array(
				'handle'  => 'gform_user_registration_widget_editor',
				'src'     => $this->get_base_url() . "/js/widget_editor{$min}.js",
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array( $this, 'can_enqueue_widget_editor_script' ),
					array( 'admin_page' => array( 'customizer' ) ),
				),
			),
			array(
				'handle'  => 'gform_user_registration_feed_settings',
				'src'     => $this->get_base_url() . '/js/feed_settings.js',
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
			array(
				'handle'  => 'gform_user_registration_merge_tags',
				'src'     => $this->get_base_url() . "/js/gfuserregistration_merge_tags{$min}.js",
				'version' => $this->_version,
				'deps'    => array( 'jquery' ),
				'enqueue' => array(
					array( 'admin_page' => array( 'form_settings' ) ),
				),
				'strings' => array(
					'user_activation_url' => esc_html__( 'User Activation URL', 'gravityformsuserregistration' ),
					'set_password_url'    => esc_html__( 'Set Password URL', 'gravityformsuserregistration' ),
				),
			),
		);

		return array_merge( parent::scripts(), $scripts );

	}

	/**
	 * Enqueues required styleseheets
	 *
	 * Defines required styles for the User Registration add-on, and adds them to styles in GFFeedAddOn
	 *
	 * @see GFFeedAddOn::styles()
	 *
	 * @return array Contains the styles to be enqueued
	 */
	public function styles() {

		$min = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || isset( $_GET['gform_debug'] ) ? '' : '.min';

		$styles = array(
			array(
				'handle'  => 'gform_user_registration_widget_editor',
				'src'     => $this->get_base_url() . "/css/widget_editor{$min}.css",
				'version' => $this->_version,
				'enqueue' => array(
					array( $this, 'can_enqueue_widget_editor_script' ),
					array( 'admin_page' => array( 'customizer' ) ),
				)
			),
			array(
				'handle'  => 'gform_user_registration_feed_settings',
				'src'     => $this->get_base_url() . '/css/feed_settings.css',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' ),
						'tab'        => $this->_slug,
					),
				),
			),
		);

		return array_merge( parent::styles(), $styles );

	}

	/**
	 * Return the plugin's icon for the plugin/form settings menu.
	 *
	 * @since 4.5
	 *
	 * @return string
	 */
	public function get_menu_icon() {

		return $this->is_gravityforms_supported( '2.5-beta-4' ) ? 'gform-icon--how-to-reg' : 'dashicons-admin-generic';

	}

	/**
	 * Determines if the current screen is the widget editor
	 *
	 * @return bool True if current screen is the widget editor.  Otherwise, false
	 */
	public function can_enqueue_widget_editor_script() {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		/* Get the current screen. */
		$screen = get_current_screen();

		return isset( $screen ) && is_object( $screen ) ? $screen->id === 'widgets' : false;

	}

	/**
	 * Loads the pending user activations object
	 *
	 * @see GF_Pending_Activations
	 *
	 * @return object The GF_Pending_Activations class object
	 */
	public function load_pending_activations() {
		require_once( 'includes/class-gf-pending-activations.php' );
		gf_pending_activations();
	}

	/**
	 * Downgrades the role of a user
	 *
	 * Used when a subscription is canceled
	 *
	 * @param array $entry The Entry object
	 * @param array $feed  The Feed object
	 */
	public function downgrade_user( $entry, $feed ) {

		if ( ! $feed || ! rgars( $feed, 'meta/cancellationActionUserEnable' ) ) {
			return;
		}

		$user = $this->get_user_by_entry_id( $entry['id'] );
		if ( ! $user || is_wp_error( $user ) ) {
			$this->log( 'No user found.' );
			return;
		}

		$user->set_role( rgars( $feed, 'meta/cancellationActionUserValue' ) );

	}

	/**
	 * Downgrades a site within a multisite installation
	 *
	 * Used when a subscription is canceled
	 *
	 * @param array $entry The Entry object
	 * @param array $feed  The Feed object
	 */
	public function downgrade_site( $entry, $feed ) {
		global $current_site;

		if ( ! is_multisite() ) {
			return;
		}

		if ( ! rgars( $feed, 'meta/cancellationActionSiteEnable' ) ) {
			return;
		}

		$site_id = $this->get_site_by_entry_id( $entry['id'] );

		// Log the error if site is not found
		if ( ! $site_id ) {
			$this->log( 'No site found.' );
			return;
		}

		// Gets the action defined in the feed
		$action  = rgars( $feed, 'meta/cancellationActionSiteValue' );
		// Checks the action to take defined within a feed
		switch( $action ) {
			case 'deactivate':
				/** This action is documented in /wp-admin/network/sites */
				do_action( 'deactivate_blog', $site_id );
				update_blog_status( $site_id, 'deleted', '1' );
				break;
			case 'delete':
				require_once( ABSPATH . 'wp-admin/includes/ms.php' );
				if ( $site_id != '0' && $site_id != $current_site->blog_id ) {
					wpmu_delete_blog( $site_id, true );
				}
				break;
		}

	}

	/**
	 * Redirect to the custom registration page as specified in the User Registration settings.
	 *
	 * By default, this function checks if the user is accessing the default WP registration page
	 * "/wp-login.php?action=register" and if so, processes the redirect.
	 *
	 * If BuddyPress is active, it checks if the current page is the the BP registration page
	 * (as specified in the BP Page settings) and if so, processes the redirect. We also check
	 * to ensure that the User Registration Custom Registration Page ID is not the same as the
	 * BP Register Page ID.
	 *
	 * @global object $bp The BuddyPress object
	 *
	 * @see self::is_bp_active()
	 * @see $this->get_plugin_settings()
	 */
	public function custom_registration_page() {
		if ( is_user_logged_in() ) {
			return;
		}

		global $bp;

		$action   = rgget( 'action' );
		$redirect = false;

		// If BuddyPress is active and this is the registration page, redirect
		if ( self::is_bp_active() && bp_is_register_page() ) {
			$redirect = true;
		}

		// if "wp-login.php?action=register", aka default WP registration page
		$script_name = substr( $_SERVER['SCRIPT_NAME'], - 12, 12 ); // get last 12 characters of script name (we want wp-login.php);
		if ( $script_name == 'wp-login.php' && $action == 'register' ) {
			$redirect = true;
		}

		// add support for multi-site
		$script_name = substr( $_SERVER['SCRIPT_NAME'], - 13, 13 ); // get last 12 characters of script name (we want wp-login.php);
		if ( is_multisite() && $script_name == 'wp-signup.php' ) {
			$redirect = true;
		}

		if ( ! $redirect ) {
			return;
		}

		$ur_settings  = $this->get_plugin_settings();
		$reg_page_id  = rgar( $ur_settings, 'custom_registration_page' );
		$reg_page_url = rgar( $ur_settings, 'custom_registration_page_custom' );

		if ( empty( $ur_settings ) || ! rgar( $ur_settings, 'custom_registration_page_enable' ) ) {
			return;
		}

		// if BP is active, BP Register Page is set and BP Register Page ID is the same as the UR Register Page ID, cancel redirect
		if ( self::is_bp_active() && isset( $bp->pages->register->id ) && $bp->pages->register->id == $reg_page_id ) {
			return;
		}

		if ( 'gf_custom' === $reg_page_id ) {
			wp_redirect( $reg_page_url );
		} else {
			wp_redirect( get_permalink( $reg_page_id ) );
		}

		exit;
	}

	// # USER CREATION -------------------------------------------------------------------------------------------------

	/**
	 * Adds custom validation to gform_validation
	 *
	 * @see filter gform_validation
	 * @see GFFormsModel::get_current_lead()
	 * @see GFFormsModel::get_field()
	 * @see GFFormsModel::is_field_hidden()
	 * @see $this->get_meta_value
	 *
	 * @param array $validation_result The validation result passed from the gform_validation filter
	 *
	 * @return array $validation_result The validation result after completion
	 */
	public function validate( $validation_result ) {

		$form  = $validation_result['form'];
		$entry = GFFormsModel::get_current_lead();
		$feed  = $this->get_filtered_single_submission_feed( $entry, $form );

		if ( ! $feed ) {
			return $validation_result;
		}


		return $this->do_validate( $validation_result, $feed, $form, $entry );
	}

	public function do_validate( $validation_result, $feed, $form, $entry ) {

		$submitted_page = GFFormDisplay::get_source_page( $form['id'] );

		$username_field = GFFormsModel::get_field( $form, rgars( $feed, 'meta/username' ) );
		$email_field    = GFFormsModel::get_field( $form, rgars( $feed, 'meta/email' ) );
		$password_field = GFFormsModel::get_field( $form, $feed['meta']['password'] );

		$is_username_hidden = GFFormsModel::is_field_hidden( $form, $username_field, array() );
		$is_email_hidden    = !$email_field || GFFormsModel::is_field_hidden( $form, $email_field, array() );
		$is_password_hidden = GFFormsModel::is_field_hidden( $form, $password_field, array() );

		$username   = $this->get_meta_value( 'username', $feed, $form, $entry );
		$user_email = $this->get_meta_value( 'email', $feed, $form, $entry );
		$user_pass  = $this->get_meta_value( 'password', $feed, $form, $entry );

		/**
		 * Filters the username of the user being registered
		 *
		 * @param int    $form     ['id'] The ID of the form being submitted
		 * @param string $username The username of the being created
		 * @param array  $feed     The Feed object
		 * @param array  $form     The Form object
		 * @param array  $entry    The Entry object
		 */
		$username = gf_apply_filters( 'gform_username', $form['id'], $username, $feed, $form, $entry );

		if ( !function_exists( 'username_exists' ) ) {
			require_once( ABSPATH . WPINC . '/registration.php' );
		}

		if ( ! $is_password_hidden && $password_field && $password_field->pageNumber <= $submitted_page ) {
			if ( strpos( $user_pass, "\\" ) !== false ) {
				$form = $this->add_validation_error( $password_field->id, $form, __( 'Passwords may not contain the character "\"', 'gravityformsuserregistration' ) );
			}
		}

		$user_id = $this->get_update_user_id( $form, $feed, $entry );

		// Additional processing of multisite installs
		if ( is_multisite() ) {

			// Convert username to lowercase
			$username = strtolower( $username );

			$result = wpmu_validate_user_signup( $username, $user_email );
			$errors = $result['errors']->errors;

			// Validation overrides for feeds configured for user updates
			if ( $this->is_update_feed( $feed ) ) {

				// Avoid validating user update feeds
				if ( isset( $errors['user_name'] ) ) {
					unset( $errors['user_name'] );
				}

				// Check if the email already belongs to a user.
				if ( isset( $errors['user_email'] ) ) {

					for ( $i = count( $errors['user_email'] ) - 1; $i >= 0; $i -- ) {

						$error_message = $errors['user_email'][ $i ];

						// If the user is submitting their own email address, allow it by removing the error entry.
						if ( $error_message == __( 'Sorry, that email address is already used!' ) && $this->is_users_email( $user_email, $user_id ) ) {
							unset( $errors['user_email'][ $i ] );
						} // Same as the above, but for a different message.
						elseif ( $error_message == __( 'That email address has already been used. Please check your inbox for an activation email. It will become available in a couple of days if you do nothing.' ) && $this->is_users_email( $user_email, $user_id ) ) {
							unset( $errors['user_email'][ $i ] );
						} // Same as the above, but for a different message.
						elseif ( $error_message == sprintf( __( '<strong>Error:</strong> This email address is already registered. %sLog in%s with this address or choose another one.' ), '<a href="' . wp_login_url() . '">', '</a>' ) && $this->is_users_email( $user_email, $user_id ) ) {
							unset( $errors['user_email'][ $i ] );
						}
					}

					// If there aren't any errors left, remove the key completely
					if ( count( $errors['user_email'] ) <= 0 ) {
						unset( $errors['user_email'] );
					}

				}

			}

			// Check if there are any errors
			if ( !empty( $errors ) ) {

				foreach ( $errors as $type => $error_msgs ) {
					foreach ( $error_msgs as $error_msg ) {
						// Depending on the error type, display a different validation error.
						switch ( $type ) {
							case 'user_name':
								if ( !$is_username_hidden && ( $username_field->pageNumber <= $submitted_page ) ) {
									$form = $this->add_validation_error( $feed['meta']['username'], $form, $error_msg );
								}
								break;
							case 'user_email':
								if ( !$is_email_hidden && $email_field->pageNumber <= $submitted_page ) {
									$form = $this->add_validation_error( $feed['meta']['email'], $form, $error_msg );
								}
								break;
						}
					}
				}

			}

			// Validation if multisite is not enabled
		} else {

			// Validation for email fields
			if ( !$is_email_hidden && $email_field->pageNumber <= $submitted_page ) {
				$email_valid  = true;
				$email_exists = email_exists( $user_email );

				// Throws an error if the email was not entered
				if ( !$user_email ) {
					$email_valid = false;
					$form        = $this->add_validation_error( $feed['meta']['email'], $form, __( 'The email address can not be empty', 'gravityformsuserregistration' ) );
				}

				// Throws an error if the email is valid, but is already pending activation
				if ( $email_valid && $this->pending_activation_exists( 'user_email', $user_email ) ) {
					$email_valid = false;
					$form        = $this->add_validation_error( $feed['meta']['email'], $form, __( 'That email address has already been used. Please check your inbox for an activation email. It will become available in a couple of days if you do nothing.' ) );
				}

				// Throws an error if the email is already registered
				if ( $email_valid && !$this->is_update_feed( $feed ) && $email_exists ) {
					$form = $this->add_validation_error( $feed['meta']['email'], $form, __( 'This email address is already registered', 'gravityformsuserregistration' ) );
				} elseif ( $email_valid && $this->is_update_feed( $feed ) && $email_exists && !$this->is_users_email( $user_email, $user_id ) ) {
					$form = $this->add_validation_error( $feed['meta']['email'], $form, __( 'This email address is already registered', 'gravityformsuserregistration' ) );
				}

			}

			// Validation for username fields.  Ignores user update feeds
			if ( !$this->is_update_feed( $feed ) && !$is_username_hidden && $username_field->pageNumber <= $submitted_page ) {
				$username_valid = true;

				// Throws an error if the username wasn't submitted
				if ( empty( $username ) ) {
					$username_valid = false;
					$form           = $this->add_validation_error( $feed['meta']['username'], $form, __( 'The username can not be empty', 'gravityformsuserregistration' ) );
				}

				// Throws an error if the username contains invalid characters
				if ( $username_valid && ! validate_username( $username ) ) {
					$username_valid = false;
					$form           = $this->add_validation_error( $feed['meta']['username'], $form, __( 'This username is invalid because it uses illegal characters. Please enter a valid username.', 'gravityformsuserregistration' ) );
				}

				// Throws an error if a user on a BuddyPress site contains a space or other invalid characters
				if ( $username_valid && self::is_bp_active() && strpos( $username, " " ) !== false ) {
					$username_valid = false;
					$form           = $this->add_validation_error( $feed['meta']['username'], $form, __( 'The username can only contain alphanumeric characters (A-Z, 0-9), underscores and dashes', 'gravityformsuserregistration' ) );
				}

				// Throws an error if the username already exists
				if ( $username_valid && username_exists( $username ) ) {
					$username_valid = false;
					$form           = $this->add_validation_error( $feed['meta']['username'], $form, __( 'This username is already registered', 'gravityformsuserregistration' ) );
				}

				// Throws an error if the user is pending activation
				if ( $username_valid && $this->pending_activation_exists( 'user_login', $username ) ) {
					$form = $this->add_validation_error( $feed['meta']['username'], $form, __( 'That username is currently reserved but may be available in a couple of days' ) );
				}
			}

		}

		/**
		 * Filters the form object, allowing for extended validation of user registration submissions
		 *
		 * @param array $form           The Form object
		 * @param array $feed           The Feed object
		 * @param int   $submitted_page The ID of the form page that was submitted
		 */
		$form                          = apply_filters( 'gform_user_registration_validation', $form, $feed, $submitted_page );
		$validation_result['is_valid'] = $this->is_form_valid( $form );
		$validation_result['form']     = $form;

		return $validation_result;
	}

	/**
	 * Processes the feed for the User Registration add-on if delayed
	 *
	 * @see GFFeedAddOn->delay_feed()
	 *
	 * @param array $feed  The Feed object
	 * @param array $entry The Entry object
	 * @param array $form  The Form object
	 */
	public function delay_feed( $feed, $entry, $form ) {

		$user_data = $this->get_user_data( $entry, $form, $feed );
		$password  = rgar( $user_data, 'password' );

		if ( $password ) {
			gform_update_meta( $entry['id'], 'userregistration_password', wp_hash_password( $password ) );
		}

	}

	/**
	 * Processed the feed for the User Registration add-on
	 *
	 * @see GFFeedAddOn->process_feed()
	 *
	 * @param array $feed  The Feed object
	 * @param array $entry The Entry object
	 * @param array $form  The Form object
	 */
	public function process_feed( $feed, $entry, $form ) {

		// Log that the feed is being processed
		$this->log( "form #{$form['id']} - starting process_feed()." );

		// Get user data.  If none found, log the error
		$user_data = $this->get_user_data( $entry, $form, $feed );
		if ( ! $user_data ) {
			$this->log( 'aborting. user_login or user_email are empty.' );
			return;
		}

		/**
		 * Disables registration.
		 *
		 * Defaults to false unless overridden
		 *
		 * @param bool false Registration enabled.  Change to true to disable
		 * @param array $form The Form object
		 * @param array $entry The Entry object
		 * @param null $fulfilled Deprecated
		 */
		$disable_registration = apply_filters( 'gform_disable_registration', false, $form, $entry, null /* $fullfilled deprecated */ );
		if ( $disable_registration ) {
			$this->log( 'aborting. gform_disable_registration hook was used.' );
			return;
		}

		if ( $this->is_update_feed( $feed ) ) {

			$this->update_user( $entry, $form, $feed );

		} else {

			$is_user_activation = rgars( $feed, 'meta/userActivationEnable' ) == true;

			if ( $is_user_activation ) {

				$this->log( 'Calling handle_user_activation().' );

				$this->handle_user_activation( $entry, $form, $feed );

			} else {

				$this->log( 'Calling create_user().' );

				$this->create_user( $entry, $form, $feed );

			}

		}

		// password will be stored in entry meta for delayed feeds, delete after processing feed
		gform_delete_meta( $entry['id'], 'userregistration_password' );

	}

	public function process_feed_when_unspammed( $entry_id, $status, $prev_status ) {

		$is_unspammed = $prev_status == 'spam' && $status == 'active';
		if ( ! $is_unspammed ) {
			return;
		}

		$this->log( sprintf( 'Entry has been unspammed (ID: %d).', $entry_id ) );

		// check if user has already been created for this entry (prevents multiple users being created if an entry has been unspammed before)
		if ( $this->get_user_by_entry_id( $entry_id, true ) ) {
			return;
		}

		$this->log( sprintf( 'User has not been created for this entry (ID: %d).', $entry_id ) );

		$entry = GFAPI::get_entry( $entry_id );
		$form  = GFAPI::get_form( $entry['form_id'] );

		$this->log( 'Calling maybe_process_feed(). The first feed with matching conditions will be processed.' );

		$this->maybe_process_feed( $entry, $form );

	}

	/**
	 * Create a WordPress user from the given entry and feed.
	 *
	 * @since 4.4.3 The `$password_hash` parameter was added.
	 *
	 * @param array  $entry         Current entry.
	 * @param array  $form          Current form.
	 * @param bool   $feed          Current User Registration feed.
	 * @param string $password      Deprecated. Specified when creating a user via User Activation.
	 * @param string $password_hash Optional. Specified when creating a user via User Activation.
	 *
	 * @return array|bool
	 */
	public function create_user( $entry, $form, $feed = false, $password = '', $password_hash = '' ) {

		$this->log( sprintf( 'Start with form id: %s; entry: %s', $form['id'], print_r( $entry, true ) ) );

		if ( ! $feed ) {
			$feed = $this->get_filtered_single_submission_feed( $entry, $form );
		}

		$meta      = rgar( $feed, 'meta' );
		$user_data = $this->get_user_data( $entry, $form, $feed );

		if ( ! empty( $password ) ) {
			$user_data['password'] = $password;
		}

		if ( ! empty ( $password_hash ) ) {
			$user_data['password_hash'] = $password_hash;
		}

		$user_id = $this->user_login_exists( $user_data['user_login'] );
		if ( $user_id ) {
			$this->log( sprintf( 'User already exists. User ID: %s', $user_id ) );
			return false;
		}

		$this->log( sprintf( 'Calling wp_create_user() for login "%s" with email "%s".', $user_data['user_login'], $user_data['user_email'] ) );

		$password = $user_data['password'] ? $user_data['password'] : wp_generate_password( 24, false );
		$user_id  = wp_create_user( $user_data['user_login'], $password, $user_data['user_email'] );

		if ( is_wp_error( $user_id ) ) {
			$this->log( 'Aborting; wp_create_user() returned an error: ' .  print_r( $user_id, 1 ) );

			return false;
		}

		$this->add_user_meta( $user_id, $feed, $form, $entry, array() );

		if ( $user_data['password_hash'] ) {
			$this->log( sprintf( 'Setting hashed password for user ID %d.', $user_id ) );
			$this->set_hashed_password( $user_id, $user_data['password_hash'] );
		}

		clean_user_cache( $user_id );

		// Updating display name after user meta because of dependency.
		$update_args = array(
			'ID'           => $user_id,
			'display_name' => $this->get_display_name( $user_id, $feed ),
		);

		$role = rgar( $meta, 'role' );
		if ( $role ) {
			$this->log( sprintf( 'Setting role: %s', $role ) );
			$update_args['role'] = $role;
		}

		wp_update_user( $update_args );

		$this->send_wp_new_user_notification( $entry, $form, $feed, $user_id );

		GFAPI::send_notifications( $form, $entry, 'gfur_user_registered' );

		/**
		 * Determine if entry creator should be associated with newly created user.
		 *
		 * @since 4.0.9
		 *
		 * @param bool  $update_entry_creator If entry creator should be associated with user.
		 * @param int   $user_id              New user ID.
		 * @param array $feed                 The Feed object.
		 * @param array $entry                The Entry object.
		 * @param array $form                 The Form object.
		 */
		$update_entry_creator = apply_filters( 'gform_userregistration_associate_entry_with_user', ! rgar( $entry, 'created_by' ), $user_id, $feed, $entry, $form );

		// Update entry creator.
		if ( $update_entry_creator ) {
			GFAPI::update_entry_property( $entry['id'], 'created_by', $user_id );
			$entry['created_by'] = $user_id;
		}

		// set post author if feed was delayed by PayPal or entry was marked as spam
		if ( ! rgempty( 'post_id', $entry ) && rgar( $meta, 'setPostAuthor' ) ) {
			$this->attribute_post_author( $user_id, $entry['post_id'] );
		}

		do_action( 'gform_user_registered', $user_id, $feed, $entry, $password );

		$user_data['user_id'] = $user_id;

		return $user_data;
	}

	/**
	 * Maybe set the post author.
	 *
	 * @param int $post_id The ID of the post which was created from the entry.
	 * @param array $entry The entry currently being processed.
	 * @param array $form The form for the current entry.
	 */
	public function set_user_as_post_author( $post_id, $entry, $form ) {

		$feed = $this->get_filtered_single_submission_feed( $entry, $form );

		if ( $feed && ! $this->is_update_feed( $feed ) && rgars( $feed, 'meta/setPostAuthor' ) ) {
			$user_id = $this->get_user_by_entry_id( $entry['id'], true );

			if ( $user_id ) {
				$this->attribute_post_author( $user_id, $post_id );
			}
		}

	}

	public function add_user_meta( $user_id, $feed, $form, $entry, $name_fields ) {

		$is_update_feed = $this->is_update_feed( $feed );
		$this->log( sprintf( '%s user meta.', $is_update_feed ? 'Updating' : 'Adding' ) );

		$standard_meta = array(
			'first_name',
			'last_name',
			'nickname'
		);

		foreach ( $standard_meta as $meta_key ) {
			$this->log( sprintf( 'Processing meta item: %s', $meta_key ) );

			if ( ! $this->is_meta_key_mapped( $meta_key, $feed ) ) {
				$this->log( 'Meta item is empty. Skipping it.' );
				continue;
			}

			$meta_value = rgars( $feed, "meta/$meta_key" );
			$value      = $this->get_meta_value( $meta_key, $feed, $form, $entry );
			$this->log( sprintf( 'Meta item mapped to field: %s; Value: %s', $meta_value, $value ) );

			$result = update_user_meta( $user_id, $meta_key, $value );
			$this->log( sprintf( 'Result: %s', var_export( (bool) $result, 1 ) ) );
		}

		// to track which entry the user was registered/updated from
		if ( $is_update_feed ) {
			update_user_meta( $user_id, '_gform-update-entry-id', $entry['id'] );
		} else {
			update_user_meta( $user_id, 'entry_id', $entry['id'] ); // @deprecated
			update_user_meta( $user_id, '_gform-entry-id', $entry['id'] ); // @future
		}

		$custom_meta = $this->get_prepared_generic_map_values( 'userMeta', $feed, $form, $entry );

		if ( empty( $custom_meta ) ) {
			return;
		}

		foreach ( $custom_meta as $item ) {
			$meta_key = rgar( $item, 'meta_key' );
			$this->log( sprintf( 'Adding meta item: %s', $meta_key ) );

			// skip empty meta items
			if ( ! $meta_key || empty( $item['value_key'] ) ) {
				$this->log( 'Meta item is empty. Skipping it.' );
				continue;
			}

			$value = rgar( $item, 'meta_value' );
			$this->log( sprintf( 'Meta item mapped to field: %s; value: %s', $item['value_key'], var_export( $value, true ) ) );

			if ( $value && in_array( $meta_key, array( 'user_url', 'user_nicename' ) ) ) {
				$result = $this->update_user_property( $user_id, $meta_key, $value );
			} elseif ( rgblank( $value ) ) {
				$result = $is_update_feed ? delete_user_meta( $user_id, $meta_key ) : true;
			} else {
				$result = update_user_meta( $user_id, $meta_key, $value );
			}

			$this->log( sprintf( 'Result: %s', var_export( (bool) $result, 1 ) ) );

		}

	}


	/**
	 * Add meta values to BuddyPress user.
	 *
	 * If a user is activated via BuddyPress's Pending Signups page, add all of the user-submitted data to the user profile.
	 *
	 * @since 4.8.4
	 *
	 * @param int    $user_id  The user ID
	 * @param string $key      The activation key
	 */
	public function add_meta_to_bp_user( $user_id, $key ) {
		$this->log( 'User activated via BuddyPress.  Adding user meta from form submission.' );

		global $wpdb;
		$signup_table = $wpdb->base_prefix . 'signups';
		$signup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$signup_table} WHERE activation_key = %s", $key ) );

		if ( is_wp_error( $signup ) ) {
			$this->log( 'Error finding signup: ' . $signup );
			return;
		}

		if ( ! $signup ) {
			$this->log( 'Could not find that pending user.' );
			return;
		}

		$meta = maybe_unserialize( $signup->meta );

		if ( ! is_array( $meta ) || ! $meta ) {
			$this->log( 'No user meta found.' );
			return;
		}

		$entry = rgar( $meta, 'entry_id' ) ? GFAPI::get_entry( $meta['entry_id'] ) : '';
		if ( is_wp_error( $entry ) || ! $entry ) {
			$this->log( 'Could not find the form entry for this pending signup.' );
			return;
		}

		$form = rgar( $entry, 'form_id' ) ? GFAPI::get_form( $entry['form_id'] ) : '';
		if ( ! $form ) {
			$this->log( 'Could not find the form for this pending signup.' );
			return;
		}

		$feed = $this->get_filtered_single_submission_feed( $entry, $form );
		if ( empty( $feed ) ) {
			$this->log( 'Could not find the User Registration feed for this form.' );
			return;
		}

		$this->add_user_meta( $user_id, $feed, $form, $entry, '' );

		if ( rgar( $meta, 'password_hash' ) ) {
			$this->set_hashed_password( $user_id, $meta['password_hash'] );
		}

		clean_user_cache( $user_id );

		GFAPI::send_notifications( $form, $entry, 'gfur_user_registered' );
		GFAPI::send_notifications( $form, $entry, 'gfur_user_activated' );

	}

	/**
	 * Get the type of New User notification that should be sent (if any).
	 *
	 * @since 4.4.3
	 *
	 * @param $feed
	 *
	 * @return string
	 */
	public function get_send_email_type( $feed ) {

		// If new meta is set, use it.
		if ( isset( $feed['meta']['sendEmailEnable'] ) ) {
			return (bool) $feed['meta']['sendEmailEnable'] ? rgar( $feed['meta'], 'sendEmailValue' ) : '';
		}

		// If the user hasn't updated their feed since upgrading to GFUR 4.5, they might still have the deprecated
		// Auto Generate Password option selected. If so, treat it the same as if Email Link is selected.
		if ( $feed['meta']['password'] === 'generatepass' ) {
			return 'default';
		}

		// If not, default to old meta.
		return rgar( $feed['meta'], 'sendEmail' );
	}

	/**
	 * Prepare and send WordPress' New User notification email modified according to the given User Registration feed.
	 *
	 * @since 4.4.3
	 *
	 * @param array $entry   Current entry.
	 * @param array $form    Current form.
	 * @param array $feed    Current User Registration feed.
	 * @param int   $user_id ID of the user to/for whom the email should be sent.
	 */
	public function send_wp_new_user_notification( $entry, $form, $feed, $user_id ) {

		$this->log( sprintf( 'Calling wp_new_user_notification() for user id: %s', $user_id ) );

		$email_type = $this->get_send_email_type( $feed );

		// If Password setting is configured for a field, let's modify the new user notification mesage.
		if ( is_numeric( rgars( $feed, 'meta/password' ) ) ) {
			add_filter( 'wp_new_user_notification_email', array( gf_user_registration(), 'modify_wp_new_user_notification_message' ), 10, 2 );
		}

		// Always send admin email as WordPress does by default. If feed is configured to send user email, email both.
		wp_new_user_notification( $user_id, null, $email_type && $email_type === 'default' ? 'both' : 'admin' );

		remove_filter( 'wp_new_user_notification_email', array( gf_user_registration(), 'modify_wp_new_user_notification_message' ) );

		$this->log( 'Done with wp_new_user_notification(). Email with username should have been sent.' );

	}

	/**
	 * Modify WordPress' New User notification message to indicate the user set their password at registration.
	 *
	 * @param array    $email Values that will be used to send the email (e.g. subject, message).
	 * @param \WP_User $user  User object for which the email will be sent.
	 *
	 * @return array $email
	 */
	public function modify_wp_new_user_notification_message( $email, $user ) {

		$message  = sprintf( __( 'Username: %s' ), $user->user_login ) . "\r\n\r\n";
		$message .= __( 'Use the password you set at registration to login.', 'gravityformsuserregistration' ) . "\r\n\r\n";
		$message .= wp_login_url() . "\r\n\r\n";

		$reset_url = $this->get_set_password_url( $user );
		if ( ! empty( $reset_url ) ) {
				$message .= __( 'Forgot your password? Visit the following address to reset your password:', 'gravityformsuserregistration' ) . "\r\n\r\n";
				$message .= $reset_url;
		}
		$email['message'] = $message;

		return $email;
	}

	/**
	 * Update the given user's password directly in the database.
	 *
	 * @param int    $user_id         ID of the user to update.
	 * @param string $hashed_password Hashed password to be set.
	 *
	 * @return false|int
	 */
	public function set_hashed_password( $user_id, $hashed_password ) {
		global $wpdb;
		return $wpdb->update( $wpdb->users, array( 'user_pass' => $hashed_password ), array( 'ID' => $user_id ), '%s' );
	}





	// # USER UPDATE ---------------------------------------------------------------------------------------------------

	public static function maybe_prepopulate_form( $form ) {

		if ( empty( $form['id'] ) ) {
			return $form;
		}

		$feed = gf_user_registration()->get_update_feed( $form['id'] );

		// if no feed, return form unmodified
		if ( ! $feed ) {
			return $form;
		} else {

			$user_id = gf_user_registration()->get_update_user_id( $form, $feed );

			// add action to hide form and display error message if no user id supplied
			if ( empty( $user_id ) ) {
				add_action( 'gform_get_form_filter_' . $form['id'], array( gf_user_registration(), 'hide_form' ) );

				return $form;
			} else {
				// prepopulate the form
				$form = gf_user_registration()->prepopulate_form( $form, $feed, $user_id );
			}
		}

		return $form;
	}

	public function hide_form( $form_string ) {
		return __( 'Oops! You need to be logged in to use this form.', 'gravityformsuserregistration' );
	}

	public static function prepopulate_form( $form, $feed, $user_id = false ) {
		$mapped_fields = array();
		$meta          = rgar( $feed, 'meta' );
		$user          = empty( $user_id ) ? wp_get_current_user() : get_userdata( $user_id );

		foreach ( array( 'username', 'last_name', 'first_name', 'email', 'nickname' ) as $meta_key ) {
			if ( $field_id = rgar( $meta, $meta_key ) ) {
				switch ( $meta_key ) {
					case 'email':
						$meta_key = 'user_email';
						break;
				}
				$mapped_fields[ (string) $field_id ] = $user->get( $meta_key );
			}
		}

		$custom_meta = gf_user_registration()->get_dynamic_field_map_fields( $feed, 'userMeta' );
		foreach ( $custom_meta as $meta_key => $meta_value ) {
			if ( $meta_value ) {
				$field_id                            = $meta_value;
				$mapped_fields[ (string) $field_id ] = $user->get( $meta_key );
			}
		}

		if ( function_exists( 'xprofile_get_field_data' ) ) {

			$bp_meta = gf_user_registration()->get_buddypress_meta( $feed );

			foreach ( $bp_meta as $bp_field_id => $gf_field_id ) {

				$bp_field = xprofile_get_field( $bp_field_id );

				if ( ! $bp_field ) {
					continue;
				}

				if ( in_array( $bp_field->type, array( 'url', 'datebox' ) ) ) {
					$value = BP_XProfile_ProfileData::get_value_byid( $bp_field_id, $user->ID );
				} else {
					$value = xprofile_get_field_data( $bp_field_id, $user->ID );
				}

				if ( $bp_field->type == 'datebox' && $value ) {
					$gf_field = GFFormsModel::get_field( $form, $gf_field_id );
					$value    = explode( ' ', $value );
					$value    = GFCommon::date_display( array_shift( $value ), 'ymd_dash', $gf_field->dateFormat ? $gf_field->dateFormat : 'mdy' );
				}

				if ( ! empty( $value ) ) {
					$mapped_fields[ (string) $gf_field_id ] = is_array( $value ) ? array_map( 'html_entity_decode', $value ) : html_entity_decode( $value );
				}

			}

		}

		$mapped_fields = apply_filters( 'gform_user_registration_user_data_pre_populate', $mapped_fields, $form, $feed );

		// get all fields for cheap check inside field loop
		$mapped_field_ids = array_map( 'intval', array_keys( $mapped_fields ) );

		foreach ( $form['fields'] as &$field ) {

			if ( ! in_array( $field->id, $mapped_field_ids ) ) {
				continue;
			}

			$value = false;

			switch ( $field->get_input_type() ) {

				case 'fileupload':

					$value = rgar( $mapped_fields, $field->id );

					if ( empty( $value ) ) {
						break;
					}

					// check if file has been "deleted" via form UI
					$upload_files = json_decode( rgpost( 'gform_uploaded_files' ), ARRAY_A );
					$input_name   = "input_{$field->id}";
					if ( is_array( $upload_files ) && array_key_exists( $input_name, $upload_files ) && ! $upload_files[ $input_name ] ) {
						break;
					}

					// if $uploaded_files array is not set for this form at all, init as array
					if ( ! isset( GFFormsModel::$uploaded_files[ $form['id'] ] ) ) {
						GFFormsModel::$uploaded_files[ $form['id'] ] = array();
					}

					// check if this field's key has been set in the $uploaded_files array, if not add this file (otherwise, a new image may have been uploaded so don't overwrite)
					if ( ! isset( GFFormsModel::$uploaded_files[ $form['id'] ]["input_{$field->id}"] ) ) {
						GFFormsModel::$uploaded_files[ $form['id'] ]["input_{$field->id}"] = self::get_value_for_uploaded_files( $value, $field );
					}

					break;

				case 'checkbox':

					$value = rgar( $mapped_fields, $field->id );

					if ( empty( $value ) ) {
						$value = array();
						foreach ( $field->inputs as $input ) {
							$val = rgar( $mapped_fields, (string) $input['id'] );
							if ( is_array( $val ) ) {
								$val = GFCommon::implode_non_blank( ',', $val );
							}
							$value[] = $val;
						}
					}

					break;

				case 'list':

					$value = rgar( $mapped_fields, $field->id );
					if ( gf_user_registration()->is_json( $value ) ) {
						$value = json_decode( $value, true );
					} elseif ( is_serialized( $value ) ) {
						$value       = unserialize( $value );
						$list_values = array();

						if ( is_array( $value ) ) {
							foreach ( $value as $vals ) {
								if ( ! is_array( $vals ) ) {
									$vals = array( $vals );
								}
								$list_values = array_merge( $list_values, array_values( $vals ) );
							}
							$value = $list_values;
						}
					} else {
						$value = array_map( 'trim', explode( ',', $value ) );
					}

					break;

				case 'date':
					$value = GFCommon::date_display( rgar( $mapped_fields, $field->id ), $field->dateFormat, false );
					break;

				default:

					// handle complex fields
					$inputs = $field->get_entry_inputs();
					if ( is_array( $inputs ) ) {
						foreach ( $inputs as $key => $input ) {
							$filter_name              = self::prepopulate_input( $input['id'], rgar( $mapped_fields, (string) $input['id'] ) );
							$field->allowsPrepopulate = true;
							$inputs[ $key ]['name']   = $filter_name;
						}
						$field->inputs = $inputs;
					} else {

						$value = is_array( rgar( $mapped_fields, $field->id ) ) ? implode( ',', rgar( $mapped_fields, $field->id ) ) : rgar( $mapped_fields, $field->id );

					}

			}

			if ( rgblank( $value ) ) {
				continue;
			}

			$value                    = self::maybe_get_category_id( $field, $value );
			$filter_name              = self::prepopulate_input( $field->id, $value );
			$field->allowsPrepopulate = true;
			$field->inputName         = $filter_name;

		}

		return $form;
	}

	/**
	 * Retrieves the correctly-formatted value for uploaded files, taking into account
	 * whether the field is multi-enabled or not.
	 *
	 * @since 4.8.4
	 *
	 * @param string   $path  The file path(s).
	 * @param GF_Field $field The field object.
	 *
	 * @return array|mixed
	 */
	public static function get_value_for_uploaded_files( $path, $field ) {
		$paths  = explode( ',', $path );
		$values = array();

		foreach ( $paths as $path ) {
			$path_info = pathinfo( trim( $path ) );
			$values[]  = array( 'uploaded_filename' => $path_info['basename'] );
		}

		if ( $field->multipleFiles ) {
			return $values;
		}

		return $values[0]['uploaded_filename'];
	}

	public static function prepopulate_input( $input_id, $value ) {

		$filter_name = 'gfur_field_' . str_replace( '.', '_', $input_id );
		add_filter( "gform_field_value_{$filter_name}", array( new GF_User_Registration_Dynamic_Hook( $value, __CLASS__ ), 'filter_gform_field_value' ) );

		return $filter_name;
	}

	/**
	 * Populates the input with the current user meta value.
	 *
	 * @since 3.9.4
	 *
	 * @param array $filter_args The args passed by the filter.
	 * @param mixed $value       The value to be populated.
	 *
	 * @return mixed
	 */
	public static function filter_gform_field_value( $filter_args, $value ) {
		return $value;
	}

	/**
	 * Update the user based on the currently submitted lead.
	 *
	 * Update the user meta first as the display name is dependent on the first and last name user meta. Afterwards,
	 * update the "core" user properties.
	 *
	 * @param $entry
	 * @param $form
	 * @param bool $feed
	 * @return array
	 */
	public function update_user( $entry, $form, $feed = false ) {

		if ( ! $feed ) {
			$feed = $this->get_filtered_single_submission_feed( $entry, $form );
		}

		$meta    = rgar( $feed, 'meta' );
		$user_id = $this->get_update_user_id( $form, $feed, $entry );

		// update user meta before display name due to dependency
		$this->add_user_meta( $user_id, $feed, $form, $entry, array() );

		// refreshing $user variable because it might have changed during add_user_meta
		$user_obj  = new WP_User( $user_id );
		$user      = get_object_vars( $user_obj->data );
		$user_data = $this->get_user_data( $entry, $form, $feed, $user_id );

		$user['user_email'] = $user_data['user_email'];

		// If a display name option is provided and it is not the preserve option, update the display name. */
		if ( rgar( $meta, 'displayname' ) && rgar( $meta, 'displayname' ) !== 'gfur_preserve_display_name' ) {
			$user['display_name'] = $this->get_display_name( $user['ID'], $feed );
		}

		// if password provided, store it for update in $user array
		if ( $user_data['password'] ) {
			$user['user_pass'] = $user_data['password'];
		} else {
			unset( $user['user_pass'] );
		}

		$user_id = wp_update_user( $user );
		$role    = rgar( $meta, 'role' );

		if ( ! empty( $user_data['password_hash'] ) ) {
			$this->log( sprintf( 'Setting hashed password for user ID %d.', $user_id ) );
			$this->set_hashed_password( $user_id, $user_data['password_hash'] );
			clean_user_cache( $user_id );
		}

		// if a role is provided and it is not the 'preserve' option, update the role
		if ( $role && $role != 'gfur_preserve_role' ) {
			$this->log( sprintf( 'Setting role: %s', $role ) );
			$user_obj->set_role( $role );
		}

		// Send notifications
		GFAPI::send_notifications( $form, $entry, 'gfur_user_updated' );

		do_action( 'gform_user_updated', $user_id, $feed, $entry, $user_data['password'] );

		// return array with user_id, user_login, user_email, and password
		return array_merge( array( 'user_id' => $user_id ), $user_data );
	}

	public function handle_existing_images_submission( $form ) {

		$feed = $this->get_update_feed( $form['id'] );

		if ( ! $feed ) {
			return;
		}

		$user_id = $this->get_update_user_id( $form, $feed );
		if ( empty( $user_id ) ) {
			return;
		}

		$meta = $this->get_dynamic_field_map_fields( $feed, 'userMeta' );
		if ( ! empty( $meta ) ) {
			$this->set_gf_uploaded_files( $meta, $form, false, $user_id );
		}

		if ( $this->is_bp_active() ) {
			$bp_meta = $this->get_buddypress_meta( $feed );
			if ( ! empty( $bp_meta ) ) {
				$this->set_gf_uploaded_files( $bp_meta, $form, 'buddypress', $user_id );
			}
		}

	}

	public function set_gf_uploaded_files( $meta, $form, $format = false, $user_id = false ) {
		global $_gf_uploaded_files;

		// get UR config
		// get all fileupload fields mapped in the UR config
		// foreach loop through and see if the image has been:
		//  - resubmitted           populate the existing image data into the $_gf_uploaded_files
		//  - deleted               do nothing
		//  - new image submitted   do nothing

		if ( empty( $_gf_uploaded_files ) ) {
			$_gf_uploaded_files = array();
		}

		foreach ( $meta as $meta_key => $meta_value ) {

			$field = GFFormsModel::get_field( $form, $meta_value );

			if ( ! is_object( $field ) || $field->get_input_type() != 'fileupload' ) {
				continue;
			}

			$input_name = "input_{$field->id}";

			if ( $this->is_prepopulated_file_upload( $form['id'], $input_name ) ) {
				if ( $format == 'buddypress' ) {
					$_gf_uploaded_files[ $input_name ] = BP_XProfile_ProfileData::get_value_byid( $meta_key, $user_id );
				} else {
					$_gf_uploaded_files[ $input_name ] = get_user_meta( $user_id, $meta_key, true );
				}
			}

		}

	}

	public function is_new_file_upload( $form_id, $input_name ) {
		$file_info          = GFFormsModel::get_temp_filename( $form_id, $input_name );
		$is_new_file_upload = false;

		if ( isset( $file_info['uploaded_filename'] ) && is_array( $file_info['uploaded_filename'] ) ) {
			foreach ( $file_info['uploaded_filename'] as $file ) {
				$temp_filename = rgar( $file, 'temp_filename' );

				if ( ! $temp_filename ) {
					continue;
				}

				$temp_filepath = GFFormsModel::get_upload_path( $form_id ) . '/tmp/' . $temp_filename;

				if ( $file_info && file_exists( $temp_filepath ) ) {
					$is_new_file_upload = true;
					break;
				}
			}
		} else if ( ! empty( $_FILES[ $input_name ]['name'] ) ) {
			$is_new_file_upload = true;
		}

		/**
		 * Filter whether new files have been uploaded or not. If new files are uploaded, the meta value will be
		 * replaced with the value submitted in the form.
		 *
		 * @since 4.9
		 *
		 * @param boolean $is_new_file_upload  Whether new files have been uploaded.
		 * @param int     $form_id             The current form ID
		 * @param string  $input_name          The current input name.
		 */
		return gf_apply_filters( array(
			'gform_user_registration_is_new_file_upload',
			$form_id
		), $is_new_file_upload, $form_id, $input_name );
	}

	public function is_prepopulated_file_upload( $form_id, $input_name ) {

		// prepopulated files will be stored in the 'gform_uploaded_files' field
		$uploaded_files = json_decode( rgpost( 'gform_uploaded_files' ), ARRAY_A );

		// file is prepopulated if it is present in the 'gform_uploaded_files' field AND is not a new file upload
		$in_uploaded_files = is_array( $uploaded_files ) && array_key_exists( $input_name, $uploaded_files ) && ! empty( $uploaded_files[ $input_name ] );
		$is_prepopulated   = $in_uploaded_files && ! $this->is_new_file_upload( $form_id, $input_name );

		return $is_prepopulated;
	}

	public static function maybe_get_category_id( $field, $category_name ) {

		if ( $field->type == 'post_category' ) {

			if ( in_array( $field->get_input_type(), array( 'multiselect', 'checkbox' ) ) ) {
				$category_names = explode( ',', $category_name );
			} else {
				$category_names = array( $category_name );
			}

			$cat_ids = array();
			foreach ( $category_names as $name ) {
				$id = get_cat_ID( $name );
				if ( ! empty( $id ) ) {
					$cat_ids[] = $id;
				}
			}

			return implode( ',', $cat_ids );
		}

		return $category_name;
	}





	// # MULTISITE FUNCTIONALITY ---------------------------------------------------------------------------------------

	public function validate_multisite_submission( $form, $feed, $pagenum ) {

		$meta = $feed['meta'];

		// make sure multisite create site option is set
		if ( empty( $meta['createSite'] ) ) {
			return $form;
		}

		$entry = GFFormsModel::get_current_lead();

		$site_address_field = GFFormsModel::get_field( $form, $meta['siteAddress'] );
		$site_address       = $this->get_meta_value( 'siteAddress', $meta, $form, $entry );

		$site_title_field = GFFormsModel::get_field( $form, $meta['siteTitle'] );
		$site_title       = $this->get_meta_value( 'siteTitle', $meta, $form, $entry );

		// get validation result for multi-site fields
		$validation_result = wpmu_validate_blog_signup( $site_address, $site_title, wp_get_current_user() );

		// site address validation, only if on correct page
		if ( $pagenum == 0 || $site_address_field->pageNumber == $pagenum ) {

			$error_msg = isset( $validation_result['errors']->errors['blogname'][0] ) ? $validation_result['errors']->errors['blogname'][0] : false;

			if ( $error_msg != false ) {
				$form = $this->add_validation_error( $meta['siteAddress'], $form, $error_msg );
			}

		}

		// site title validation, only if on correct page
		if ( $pagenum == 0 || $site_title_field->pageNumber == $pagenum ) {

			$error_msg = isset( $validation_result['errors']->errors['blog_title'][0] ) ? $validation_result['errors']->errors['blog_title'][0] : false;

			if ( $error_msg != false ) {
				$form = $this->add_validation_error( $meta['siteTitle'], $form, $error_msg );
			}

		}

		return $form;
	}

	public function create_site( $user_id, $feed, $entry, $password ) {
		global $current_site;

		$form         = GFFormsModel::get_form_meta( $entry['form_id'] );
		$meta         = $feed['meta'];
		$set_password = false;

		if ( ! $password ) {
			$password     = $this->get_meta_value( 'password', $meta, $form, $entry );
			$set_password = $password == true;
		}

		// @review, verify what this is doing and notate here
		if ( ! $set_password ) {
			remove_filter( 'update_welcome_email', 'bp_core_filter_blog_welcome_email' );
		}

		// is create site option enabled?
		if ( ! rgar( $meta, 'createSite' ) ) {
			return false;
		}

		$site_data = $this->get_site_data( $entry, $form, $feed );
		if ( ! $site_data ) {
			return false;
		}

		// create the new site!
		/**
		 * Allows modifications to the new site meta
		 *
		 * @param array An array of new site arguments (ex. if the site is public => 1)
		 * @param array $form The Form Object to filter through
		 * @param array $entry The Entry Object to filter through
		 * @param array $feed The Feed Object to filter through
		 * @param int $user_id Filer through the ID of the user who creates the site
		 */
		$site_meta = apply_filters( 'gform_user_registration_new_site_meta', array( 'public' => 1 ), $form, $entry, $feed, $user_id, $this->is_update_feed( $feed ) );
		$blog_id   = wpmu_create_blog( $site_data['domain'], $site_data['path'], $site_data['title'], $user_id, $site_meta, $current_site->id );

		if ( is_wp_error( $blog_id ) ) {
			return false;
		}

		if ( $this->is_update_feed( $feed ) ) {
			update_blog_option( $blog_id, '_gform-update-entry-id', $entry['id'] );
		} else {
			// backwords compat
			update_blog_option( $blog_id, 'entry_id', $entry['id'] );

			// future use
			update_blog_option( $blog_id, '_gform-entry-id', $entry['id'] );
		}

		if ( ! is_super_admin( $user_id ) && get_user_option( 'primary_blog', $user_id ) == $current_site->blog_id ) {
			update_user_option( $user_id, 'primary_blog', $blog_id, true );
		}

		$site_role = rgar( $meta, 'siteRole' );
		if ( $site_role ) {
			$this->log( sprintf( 'Setting site role: %s', $site_role ) );
			$user = new WP_User( $user_id, null, $blog_id );
			$user->set_role( $site_role );
		}

		$root_role = rgar( $meta, 'rootRole' );
		// if no root role, remove user from current site
		if ( ! $root_role ) {
			remove_user_from_blog( $user_id );
		} // preserve role, aka do nothing
		elseif ( $root_role == 'gfur_preserve_role' ) {
		} // otherwise, update their role on current site
		else {
			$this->log( sprintf( 'Setting root role: %s', $root_role ) );
			$user = new WP_User( $user_id );
			$user->set_role( $root_role );
		}

		// Send new site email if enabled
		if ( rgar( $meta, 'sendSiteEmail') ) {
			$this->log( sprintf( 'Calling wpmu_welcome_notification to send multisite welcome - blog_id: %d user_id: %d', $blog_id, $user_id ) );
			wpmu_welcome_notification( $blog_id, $user_id, $password, $site_data['title'], array( 'public' => 1 ) );
			$this->log( 'Done with wpmu_welcome_notification().' );
		}

		// Send "site_created" notifications
		GFAPI::send_notifications( $form, $entry, 'gfur_site_created' );

		do_action( 'gform_site_created', $blog_id, $user_id, $entry, $feed, $password );

		// return new blog ID
		return $blog_id;
	}





	// # USER ACTIVATION -----------------------------------------------------------------------------------------------

	public function handle_user_activation( $entry, $form, $feed ) {
		global $wpdb;

		require_once( $this->get_base_path() . '/includes/signups.php' );
		GFUserSignups::prep_signups_functionality();

		$user_data = $this->get_user_data( $entry, $form, $feed );

		if( empty( $user_data['password_hash'] ) && $user_data['password'] ) {
			$user_data['password_hash'] = wp_hash_password( $user_data['password'] );
		}

		$meta = array(
			'lead_id'       => $entry['id'],
			'entry_id'      => $entry['id'],
			'user_login'    => $user_data['user_login'],
			'email'         => $user_data['user_email'],
			'password'      => null,
			'password_hash' => $user_data['password_hash'],
		);

		/**
		 * A filter that allows modification of the signup meta data
		 *
		 * @param int $form['id'] The current form ID (Or one you can specify)
		 * @param array $meta All the metadata in an array (user login, email, password, etc)
		 * @param array $form The current Form object
		 * @param array $entry The Entry object (To pull the meta from the entry array)
		 * @param array $feed The Feed object
		 */
		$meta       = gf_apply_filters( 'gform_user_registration_signup_meta', $form['id'], $meta, $form, $entry, $feed );
		$ms_options = rgars( $feed, 'meta/multisite_options' );

		// save current user details in wp_signups for future activation
		if ( is_multisite() && rgar( $ms_options, 'create_site' ) && $site_data = $this->get_site_data( $entry, $form, $feed ) ) {

			// buddpress will prevent wpmu_signup_blog sending the notification by returning false to the wpmu_signup_blog_notification filter
			if ( $this->is_bp_active() ) {
				remove_filter( 'wpmu_signup_blog_notification', 'bp_core_activation_signup_blog_notification', 1 );
			}

			/*
			 * In WP 4.4, wpmu_signup_blog() no longer automatically sends user notification email unless in MS
			 * Let's check if the action has been bound, if not, let's bind it ourselves
			 */
			if ( ! has_action( 'after_signup_site', 'wpmu_signup_blog_notification' ) ) {
				add_action( 'after_signup_site', 'wpmu_signup_blog_notification', 10, 7 );
			}

			wpmu_signup_blog( $site_data['domain'], $site_data['path'], $site_data['title'], $user_data['user_login'], $user_data['user_email'], $meta );

		} else {

			// If user already exists, do not create pending activation.
			if ( $this->activation_email_exists( $user_data['user_email'] ) ) {
				$this->log( sprintf( 'Aborting; User already exists for %s.', $user_data['user_email'] ) );
				return;
			}

			// wpmu_signup_user() does the following sanitization of the user_login before saving it to the database,
			// we can run this same code here to allow successful retrievel of the activation_key without actually
			// changing the user name when it is activated. 'd smith' => 'dsmith', but when activated, username is 'd smith'.
			$user_data['user_login'] = preg_replace( '/\s+/', '', sanitize_user( $user_data['user_login'], true ) );

			$this->log( sprintf( 'Calling wpmu_signup_user() (sends email with activation link) with login: %s; email: %s; meta: %s', $user_data['user_login'], $user_data['user_email'], print_r( $meta, true ) ) );

			// buddpress will prevent wpmu_signup_user sending the notification by returning false to the wpmu_signup_user_notification filter
			if ( $this->is_bp_active() ) {
				remove_filter( 'wpmu_signup_user_notification', 'bp_core_activation_signup_user_notification', 1 );
			}

			/*
			 * In WP 4.4, wpmu_signup_user() no longer automatically sends user notification email unless in MS
			 * Let's check if the action has been bound, if not, let's bind it ourselves
			 */
			if ( ! has_action( 'after_signup_user', 'wpmu_signup_user_notification' ) ) {
				add_action( 'after_signup_user', 'wpmu_signup_user_notification', 10, 4 );
			}

			wpmu_signup_user( $user_data['user_login'], $user_data['user_email'], $meta );

			$this->log( 'Done with wpmu_signup_user()' );

		}

		$sql            = $wpdb->prepare( "SELECT activation_key FROM {$wpdb->signups} WHERE user_login = %s ORDER BY registered DESC LIMIT 1", $user_data['user_login'] );
		$activation_key = $wpdb->get_var( $sql );

		// used for filtering on activation listing UI
		GFUserSignups::add_signup_meta( $entry['id'], $activation_key );

		// Send notifications
		GFAPI::send_notifications( $form, $entry, 'gfur_user_activation' );

	}

	/**
	 * Displays the activation page.
	 *
	 * @since unknown
	 */
	public function maybe_activate_user() {

		if ( isset( $_GET[ $this->get_activation_var() ] ) ) {
			require_once( $this->get_base_path() . '/includes/activate.php' );
			exit();
		}

	}

	/**
	 * Redirects the legacy page=gf_activation URL to current activation page.
	 *
	 * @since 4.6
	 */
	public function redirect_legacy_activation_page() {
		if ( rgget( 'page' ) === 'gf_activation' ) {
			wp_safe_redirect( $this->get_activation_url( rgget( 'key' ) ) );
			exit();
		}
	}

	/**
	 * Returns the activation URL.
	 *
	 * @since 4.6
	 *
	 * @param string $key The activation key or an empty string for the activation form to be displayed.
	 *
	 * @return string
	 */
	public function get_activation_url( $key = '' ) {
		return add_query_arg( $this->get_activation_var(), $key, home_url( '/' ) );
	}

	/**
	 * Returns the string to be used as the query var in the activation URL.
	 *
	 * @since 4.6
	 *
	 * @return string
	 */
	public function get_activation_var() {
		return 'gfur_activation';
	}

	/**
	 * Determines if a user already exists for the supplied email address.
	 *
	 * @since 4.6
	 *
	 * @param string $email The email address to be checked.
	 *
	 * @return bool
	 */
	public function activation_email_exists( $email ) {
		/**
		 * Allows to the user to disable the check to see if the email is being already used by a previously registered user.
		 *
		 * @since 2.4.2
		 * @since 4.6 Moved from GFUserSignups::activate_signup().
		 *
		 * @param bool $check_email Set to false to disable the email checking.
		 */
		$check_email = apply_filters( 'gform_user_registration_check_email_pre_signup_activation', true );

		if ( ! $check_email ) {
			$this->log( 'Email exists check disabled.' );
		}

		return $check_email && email_exists( $email );
	}



	// # BUDDYPRESS FUNCTIONALITY --------------------------------------------------------------------------------------

	public function get_buddypress_fields() {
		$this->maybe_include_bp_xp_class( 'BP_XProfile_Group' );

		// get BP field groups
		$groups = BP_XProfile_Group::get( array( 'fetch_fields' => true ) );

		$fields = array(
			array(
				'label' => esc_html__( 'Select BuddyPress Field', 'gravityformsuserregistration' ),
				'value' => ''
			),
		);

		foreach ( $groups as $group ) {
			if ( ! is_array( $group->fields ) || empty( $group->fields ) ) {
				continue;
			}

			$group_choices = array();

			foreach ( $group->fields as $field ) {
				$group_choices[] = array(
					'label' => $field->name,
					// Value is a string, so existing mappings will be disabled in the select when configuring other mappings.
					'value' => (string) $field->id,
				);
			}

			$fields[] = array(
				/* Translators: %s: BuddyPress group name. %d: BuddPress group ID.*/
				'label'   => sprintf( esc_html__( '%s (ID: %d)', 'gravityformsuserregistration' ), $group->name, $group->id ),
				'choices' => $group_choices,
				'id'      => (int) $group->id,
			);
		}

		return $fields;
	}

	public function update_buddypress_data( $user_id, $feed, $entry ) {

		// required for user to display in the directory
		if ( function_exists( 'bp_update_user_last_activity' ) ) {
			bp_update_user_last_activity( $user_id );
		} else {
			bp_update_user_meta( $user_id, 'last_activity', true );
		}

		$bp_data = $this->prepare_buddypress_data( $user_id, $feed, $entry );

		$this->insert_buddypress_data( $bp_data );

	}

	public function insert_buddypress_data( $bp_data ) {
		if ( empty( $bp_data ) ) {
			return;
		}

		$this->log( 'Running.' );

		global $wpdb, $bp;

		if ( ! function_exists( 'xprofile_set_field_data' ) ) {
			require_once( WP_PLUGIN_DIR . '/buddypress/bp-xprofile/bp-xprofile-functions.php' );
		}

		foreach ( $bp_data as $item ) {
			$this->log( 'Setting value for BP field: ' . $item['field_id'] );
			$success = xprofile_set_field_data( $item['field_id'], $item['user_id'], $item['value'] );
			$this->log( 'Result: ' . var_export( (bool) $success, true ) );
			$this->log( 'Setting visibility for BP field: ' . $item['field_id'] );
			$success = xprofile_set_field_visibility_level( $item['field_id'], $item['user_id'], $item['field']->default_visibility );
			$this->log( 'Result: ' . var_export( (bool) $success, true ) );
		}

	}

	public function prepare_buddypress_data( $user_id, $feed, $entry ) {
		$this->log( 'Running.' );
		$bp_data = array();
		$meta    = $this->get_buddypress_meta( $feed );

		if ( empty( $meta ) ) {
			$this->log( 'Aborting; no mapped fields.' );
			return $bp_data;
		}

		$form = GFFormsModel::get_form_meta( $entry['form_id'] );

		foreach ( $meta as $bp_field_id => $gf_field_id ) {
			$this->log( sprintf( 'Processing BP field: %s; GF field: %s;', $bp_field_id, $gf_field_id ) );

			if ( empty( $bp_field_id ) || empty( $gf_field_id ) ) {
				$this->log( 'skipping; invalid mapping');
				continue;
			}

			if ( version_compare( BP_VERSION, '1.6', '<' ) ) {
				$bp_field = new BP_XProfile_Field();
				$bp_field->bp_xprofile_field( $bp_field_id );
			} else {
				$this->maybe_include_bp_xp_class( 'BP_XProfile_Field' );
				$bp_field = new BP_XProfile_Field( $bp_field_id );
			}

			if ( strpos( $gf_field_id, 'gf_custom|' ) === 0 ) {
				$meta_value = str_replace( 'gf_custom|', '', $gf_field_id );
				if ( empty( $meta_value ) ) {
					$this->log( 'skipping; invalid custom value mapping' );
                    continue;
				}

				$meta_value = GFCommon::replace_variables( $meta_value, $form, $entry, false, false, false, 'text' );
				$meta_value = $this->filter_prepared_meta_value( $meta_value, null, $gf_field_id, $bp_field_id, $meta, $form, $entry );
			} else {
				// get GF and BP fields
				$gform_field = GFFormsModel::get_field( $form, $gf_field_id );

				// if BuddyPress field is a checkbox AND GF field is a checkbox, get array of input values
				$input_type = is_object( $gform_field ) ? $gform_field->get_input_type() : '';

				if ( in_array( $bp_field->type, array(
						'checkbox',
						'multiselectbox'
					) ) && in_array( $input_type, array( 'checkbox', 'multiselect' ) )
				) {

					$meta_value = GFFormsModel::get_lead_field_value( $entry, $gform_field );

					if ( $input_type === 'multiselect' ) {
						$meta_value = $gform_field->to_array( $meta_value );
					}

					if ( ! is_array( $meta_value ) ) {
						$meta_value = explode( ',', $meta_value );
					}

					$meta_value = $this->maybe_get_category_name( $gform_field, $meta_value );
					$meta_value = array_values( array_filter( $meta_value, array( $this, 'not_empty' ) ) );

				} elseif ( $bp_field->type == 'datebox' && $input_type == 'date' ) {
					if ( version_compare( BP_VERSION, '2.1.1', '<' ) ) {
						$meta_value = strtotime( $this->get_meta_value( $bp_field_id, $meta, $form, $entry ) );
					} else {
						$meta_value = $this->get_meta_value( $bp_field_id, $meta, $form, $entry ) . ' 00:00:00';
					}
				} else {
					$meta_value = $this->get_meta_value( $bp_field_id, $meta, $form, $entry );
				}
            }

			$item             = array();
			$item['field_id'] = $bp_field_id;
			$item['user_id']  = $user_id;

			$this->log( 'Value: ' . var_export( $meta_value, true ) );

			$item['value']       = $meta_value;
			$item['last_update'] = date( 'Y-m-d H:i:s' );
			$item['field']       = $bp_field;

			$bp_data[] = $item;

		}

		return $bp_data;
	}

	/**
	 * Believe this was added to trigger a notification in the BP activity feed for new signups.
	 *
	 * @param mixed $user_id
	 */
	public function do_buddypress_user_signup( $user_id ) {

		// this function overwrites the default meta we've just added
		remove_action( 'bp_core_activated_user', 'xprofile_sync_wp_profile' );
		remove_action( 'bp_core_activated_user', 'bp_members_add_role_after_activation', 1 );

		do_action( 'bp_core_activated_user', $user_id, null, new WP_User( $user_id ) );
	}

	public function not_empty( $value ) {
		return rgblank( $value ) ? false : $value;
	}

	public function get_buddypress_meta( $feed ) {
		return $this->prepare_dynamic_meta( rgars( $feed, 'meta/bpMeta' ) );
	}

	/**
	 * If not already available include the specified BuddyPress XProfile class.
	 *
	 * @since 3.7.5
	 *
	 * @param string $class The BuddyPress XProfile class name.
	 */
	public function maybe_include_bp_xp_class( $class ) {
		if ( class_exists( $class ) ) {
			return;
		}

		if ( is_dir( WP_PLUGIN_DIR . '/buddypress/bp-xprofile/classes' ) ) {
			$class = strtolower( str_replace( 'BP_XProfile_', '', $class ) );
			require_once( WP_PLUGIN_DIR . '/buddypress/bp-xprofile/classes/class-bp-xprofile-' . $class . '.php' );
		} else {
			require_once( WP_PLUGIN_DIR . '/buddypress/bp-xprofile/bp-xprofile-classes.php' );
		}
	}



	// # LOGIN ---------------------------------------------------------------------------------------------------------

	public function parse_login_shortcode( $shortcode_string, $attributes, $content = null ) {

		/* Get shortcode attributes. */
		$args = shortcode_atts(
			array(
				'title'                     => true,
				'description'               => false,
				'logged_in_avatar'          => true,
				'logged_in_message'         => '',
				'login_redirect'            => rgget( 'redirect_to' ) ? rgget( 'redirect_to' ) : $_SERVER['REQUEST_URI'],
				'logout_redirect'           => rgget( 'redirect_to' ) ? rgget( 'redirect_to' ) : $_SERVER['REQUEST_URI'],
				'registration_link_display' => 'true',
				'registration_link_text'    => esc_html__( 'Register', 'gravityformsuserregistration' ),
				'forgot_password_display'   => 'true',
				'forgot_password_text'      => esc_html__( 'Forgot Password', 'gravityformsuserregistration' ),
				'tabindex'                  => null,
			), $attributes, 'gravityforms'
		);

		/* Adjust argument names to match standard login form arguments. */
		$args['display_title'] = $args['title'];
		unset( $args['title'] );

		$args['display_description'] = $args['description'];
		unset( $args['description'] );

		if ( $args['registration_link_display'] == 'true' ) {

			$args['logged_out_links'][] = array(
				'text'    => $args['registration_link_text'],
				'url'     => '{register_url}',
			);

		}
		unset( $args['registration_link_display'], $args['registration_link_text'] );

		if ( $args['forgot_password_display'] == 'true' ) {

			$args['logged_out_links'][] = array(
				'text'    => $args['forgot_password_text'],
				'url'     => '{password_url}',
			);

		}
		unset( $args['forgot_password_display'], $args['forgot_password_text'] );


		/* Return the login form. */
		return $this->get_login_html( $args );

	}

	/**
	 * Get HTML for login section, either logged in or logged out view.
	 *
	 * @access public
	 * @param array $args (default: array())
	 * @return string $html
	 */
	public function get_login_html( $args = array() ) {

		/* Prepare arguments. */
		$args = wp_parse_args(
			$args,
			array(
				'display_title'         => true,
				'display_description'   => false,
				'display_lost_password' => true,
				'logged_in_avatar'      => true,
				'logged_in_links'       => array(),
				'logged_in_message'     => '',
				'logged_out_links'      => array(),
				'login_redirect'        => ( isset( $_SERVER['HTTPS'] ) ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
				'logout_redirect'       => ( isset( $_SERVER['HTTPS'] ) ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
				'tabindex'              => null,
			)
		);

		/**
		 * Modify login form arguments.
		 *
		 * @since 3.2
		 *
		 * @param array $args Array of login form arguments.
		 */
		$args = apply_filters( 'gform_user_registration_login_args', $args );

		/* If the user is logged in, display their avatar with a link to logout. */
		if ( is_user_logged_in() ) {

			global $current_user;

			// Prepare logout URL.
			if ( empty( $args['logout_redirect'] ) ) {
				$logout_url = wp_logout_url( ( isset( $_SERVER['HTTPS'] ) ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
			} else {
				$logout_url = wp_logout_url( $args['logout_redirect'] );
			}

			/* If a logged in template exists, display that. */
			if ( file_exists( get_stylesheet_directory() . '/gravityformsuserregistration-loggedin.php' ) ) {

				ob_start();

				include( get_stylesheet_directory() . '/gravityformsuserregistration-loggedin.php' );

				$html = ob_get_contents();
				ob_end_clean();

				return $html;

			}

			/* Prepare the logged in message. */
			if ( rgblank( $args['logged_in_message'] ) ) {
				$logged_in_message = sprintf(
					esc_html__( 'You are currently logged in as %s%s%s. %sLog out?%s', 'gravityformsuserregistration' ),
					'<strong>', $current_user->display_name, '</strong>',
					'<a href="' . esc_url( $logout_url ) . '">', '</a>'
				);
			} else {
				$logged_in_message = str_replace( '{logout_url}', '<a href="' . esc_attr( $logout_url ) . '" title="' . esc_attr__( 'Logout', 'gravityformsuserregistration' ) . '">' . esc_html__( 'Logout', 'gravityformsuserregistration' ) . '</a>', $args['logged_in_message'] );
				$logged_in_message = GFCommon::replace_variables( $logged_in_message, array(), array(), false, false, false, 'text' );
			}

			/* Display the avatar and logged in message. */
			$html  = '<p>';
			$html .= filter_var( $args['logged_in_avatar'], FILTER_VALIDATE_BOOLEAN ) ? get_avatar( $current_user->ID ) . '<br />' : null;
			$html .= $logged_in_message;
			$html .= '</p>';

			/* Display links. */
			if ( ! empty( $args['logged_in_links'] ) && is_array( $args['logged_in_links'] ) ) {

				foreach ( $args['logged_in_links'] as $link ) {

					$link['url']  = str_replace( '{logout_url}', esc_attr( $logout_url ), $link['url'] );
					$link['url']  = GFCommon::replace_variables( $link['url'], array(), array(), false, false, false, 'text' );
					$html        .= '<a href="' . esc_attr( $link['url'] ) . '" title="' . esc_attr( $link['text'] ) . '">' . esc_html( $link['text'] ) . '</a><br />';

				}

			}

			return $html;

		} else {

			/* Load the login form template file if it exists. */
			if ( file_exists( get_stylesheet_directory() . '/gravityformsuserregistration-login.php' ) ) {

				ob_start();

				include get_stylesheet_directory() . '/gravityformsuserregistration-login.php';

				$html = ob_get_contents();
				ob_end_clean();

				return $html;

			} else {

				return $this->get_login_form_html( $args );

			}

		}

	}

	/**
	 * Get HTML for login form.
	 *
	 * @access public
	 * @return string $html
	 */
	public function get_login_form_html( $args = array() ) {
		if ( ! class_exists( 'GFFormDisplay' ) ) {
			require_once( GFCommon::get_base_path() . '/form_display.php' );
		}

		extract( $args );

		/* Get the login form. */
		$form = $this->login_form_object();

		/* Set the tab index. */
		GFCommon::$tab_index = gf_apply_filters( array( 'gform_tabindex', $form['id'] ), $tabindex, $form );

		/* Enqueue needed scripts. */
		GFFormDisplay::enqueue_form_scripts( $form, false );

		/* Prepare the form wrapper class. */
		$wrapper_css_class = GFCommon::get_browser_class() . ' gform_wrapper';

		/* Ensure login redirect URL isn't empty. */
		if ( rgblank( $login_redirect ) ) {
			$login_redirect = ( isset( $_SERVER['HTTPS'] ) ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
		}

		/* Open Gravity Form wrapper and form tag. */
		$html  = "<div class='{$wrapper_css_class} gf_login_form gravity-theme' id='gform_wrapper_{$form['id']}'>";
		$html .= "<form method='post' id='gform_{$form['id']}'>";
		$html .= "<input type='hidden' name='login_redirect' value='" . esc_attr( sanitize_text_field( $login_redirect ) ) . "' />";

		// Convert display title and description to boolean valudes.
		$display_title       = filter_var( $display_title, FILTER_VALIDATE_BOOLEAN );
		$display_description = filter_var( $display_description, FILTER_VALIDATE_BOOLEAN );

		/* Insert form heading if needed. */
		if ( $display_title || $display_description ) {
			$html .= "<div class='gform_heading'>";
			$html .= $display_title ? "<h3 class='gform_title'>" . esc_html( $form['title'] ) . "</h3>" : "";
			$html .= $display_description ? "<span class='gform_description'>" . esc_html( $form['description'] ) . "</span>" : "";
			$html .= "</div>";
		}

		/* Insert form body. */
		$form_fields_container_tag = $this->is_gravityforms_supported( '2.5' ) ? 'div' : 'ul';

		$html .= "<div class='gform_body'>";
		$html .= "<{$form_fields_container_tag} id='gform_fields_login' class='gform_fields top_label'>";
		foreach ( $form['fields'] as $field ) {
			$field_value = GFFormsModel::get_field_value( $field );
			$field_html  = GFFormDisplay::get_field( $field, $field_value, false, $form );
			$field_html  = str_replace( "<span class='gfield_required'>*</span>", '', $field_html );
			$html       .= $field_html;
		}
		$html .= "</{$form_fields_container_tag}></div>";
		$html .= GFFormDisplay::gform_footer( $form, 'gform_footer top_label', false, array(), '', false, false, 0 );
		/* Close Gravity Form wrapper and form tag. */
		$html .= '</form>';
		$html .= '</div>';

		/* Display links. */
		if ( ! empty( $logged_out_links ) && is_array( $logged_out_links ) ) {

			if ( $this->get_plugin_setting( 'custom_registration_page_enable' ) == '1' ) {
				$registration_page = $this->get_plugin_setting( 'custom_registration_page' );
				$register_url      = 'gf_custom' === $registration_page ? $this->get_plugin_setting( 'custom_registration_page_custom' ) : get_permalink( $registration_page );
			} else {
				$register_url = wp_registration_url();
			}

			$html .= '<nav class="gf_login_links">';

			foreach ( $logged_out_links as $link ) {

				$link['url']  = str_replace( '{register_url}', esc_attr( $register_url ), $link['url'] );
				$link['url']  = str_replace( '{password_url}', esc_attr( wp_lostpassword_url() ), $link['url'] );
				$html        .= '<a href="' . esc_attr( $link['url'] ) . '" title="' . esc_attr( $link['text'] ) . '">' . esc_html( $link['text'] ) . '</a><br />';

			}

			$html .= '</nav>';

		}

		return $html;

	}

	/**
	 * Get login form object.
	 *
	 * @access public
	 * @return array $form
	 */
	public function login_form_object() {

		/* Initalize existing form object. */
		if ( ! empty( $this->login_form ) ) {
			/**
			 * Filter the login form object.
			 *
			 * @since 3.7.9
			 *
			 * @param array $form The login form object.
			 */
			return apply_filters( 'gform_userregistration_login_form', $this->login_form );
		}

		/* Create form object. */
		$form = array(
			'id'           => 0,
			'title'        => gf_apply_filters( array( 'gform_user_registration_login_form_title' ), esc_html__( 'Login Form', 'gravityformsuserregistration' ) ),
			'description'  => gf_apply_filters( array( 'gform_user_registration_login_form_description' ), '' ),
			'button'       => array(
				'type' => 'text',
				'text' => esc_html__( 'Login', 'gravityformsuserregistration' )
			),
			'markupVersion' => $this->is_gravityforms_supported( '2.5' ) ? 2 : 1,
		);

		/* Create username field. */
		$username_field = new GF_Field_Text();
		$username_field->id = 1;
		$username_field->formId = 0;
		$username_field->isRequired = true;
		$username_field->label = esc_html__( 'Username', 'gravityformsuserregistration' );

		/* Create password field. */
		$password_field = new GF_Field_Text();
		$password_field->id = 2;
		$password_field->formId = 0;
		$password_field->isRequired = true;
		$password_field->enablePasswordInput = true;
		$password_field->label = esc_html__( 'Password', 'gravityformsuserregistration' );

		/* Create remember me field. */
		$remember_field = new GF_Field_RememberMe();
		$remember_field->id = 3;
		$remember_field->formId = 0;
		$remember_field->labelPlacement = 'hidden_label';
		$remember_field->choices = array(
			array(
				'text'  => esc_html__( 'Remember Me', 'gravityformsuserregistration' ),
				'value' => '1',
			)
		);
		$remember_field->inputs = array(
			array(
				'id'    => '3.1',
				'label' => esc_html__( 'Remember Me', 'gravityformsuserregistration' )
			)
		);

		$form['fields'][] = $username_field;
		$form['fields'][] = $password_field;
		$form['fields'][] = $remember_field;

		/**
		 * Filter the login form object.
		 *
		 * @since 3.7.9
		 *
		 * @param array $form The login form object.
		 */
		$this->login_form = apply_filters( 'gform_userregistration_login_form', $form );

		return $this->login_form;

	}

	/**
	 * Attempt to login user when login form is submitted.
	 *
	 * @since  3.2
	 * @access public
	 *
	 * @uses GFCommon::get_base_path()
	 * @uses GFFormDisplay::validate()
	 */
	public function handle_login_submission() {

		// Get the form ID.
		$form_id = isset( $_POST['gform_submit'] ) ? absint( rgpost( 'gform_submit' ) ) : null;

		// If form ID is not 0, exit.
		if ( $form_id !== 0 ) {
			return;
		}

		// Load the form display class.
		if ( ! class_exists( 'GFFormDisplay' ) ) {
			require_once( GFCommon::get_base_path() . '/form_display.php' );
		}

		// Get the form field values and validate the form.
		$form         = $this->login_form_object();
		$field_values = array(
			'1'   => sanitize_text_field( rgpost( 'input_1' ) ),
			'2'   => trim( rgpost( 'input_2', false ) ),
			'3_1' => sanitize_text_field( rgpost( 'input_3_1' ) )
		);
		$is_valid     = GFFormDisplay::validate( $form, $field_values );

		// If the form is valid, sign in.
		if ( $is_valid ) {

			$sign_on = wp_signon(
				array(
					'user_login'    => $field_values['1'],
					'user_password' => $field_values['2'],
					'remember'      => $field_values['3_1'] == '1' ? true : false
				)
			);

			if ( is_wp_error( $sign_on ) ) {

				foreach ( $sign_on->get_error_codes() as $code ) {
					$field_key = $code === 'incorrect_password' ? 1 : 0;

					$form['fields'][ $field_key ]->failed_validation  = true;
					$form['fields'][ $field_key ]->validation_message = $sign_on->get_error_message( $code );
				}

			} else {
				/**
				 * Filters the redirect URL after a user is logged in.
				 *
				 * @param string $login_redirect The URL to redirect to. Defaults to what is sent in the POST request.
				 * @param object $sign_on        The response from wp_signon.  WP_User object on success.  Error on failure.
				 */
				$redirect_url = gf_apply_filters( array( 'gform_user_registration_login_redirect_url' ), rgpost( 'login_redirect' ), $sign_on );
				wp_safe_redirect( $redirect_url );

			}

		}

	}





	// # AJAX FUNCTIONS ------------------------------------------------------------------------------------------------

	public function init_ajax() {

		parent::init_ajax();

		add_action( 'wp_ajax_gf_user_activate', array( __class__, 'activate_user' ) );

	}

	public static function activate_user() {

		// Verify nonce.
		if ( false === wp_verify_nonce( rgpost( 'nonce' ), 'gf_user_activate' ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( __( 'Access denied.', 'gravityformsuserregistration' ) ) ) );
		}

		// If user is not authorized, exit.
		if ( ! GFCommon::current_user_can_any( array( 'promote_users', 'gravityforms_user_registration' ) ) ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( __( 'Access denied.', 'gravityformsuserregistration' ) ) ) );
		}

		require_once( gf_user_registration()->get_base_path() . '/includes/signups.php' );

		GFUserSignups::prep_signups_functionality();

		$key           = rgpost( 'key' );
		$userdata      = GFUserSignups::activate_signup( $key );
		$error_message = '';

		if ( is_wp_error( $userdata ) ) {
			wp_send_json_error( array( 'message' => $userdata->get_error_message() ) );
		} else {
			wp_send_json_success();
		}

	}





	// # USER DETAILS --------------------------------------------------------------------------------------------------

	public function parse_user_meta_shortcode( $shortcode_string, $attributes, $content = null ) {

		extract(
			shortcode_atts(
				array(
					'id'     => null,
					'key'    => null,
					'output' => 'raw',
				), $attributes, 'gravityforms'
			)
		);

		// If no meta key is set or the meta key is the user password, return.
		if ( rgblank( $key ) || $key === 'user_pass' ) {
			return;
		}

		// Get the user.
		$user = rgblank( $id ) ? wp_get_current_user() : get_user_by( 'id', $id );

		// If the user doesn't exist, return.
		if ( ! $user->ID ) {
			return;
		}

		// Get meta single type.
		$single_meta = ! in_array( $output, array( 'csv', 'list' ) );

		// Get the meta value.
		$value = isset( $user->{$key} ) ? $user->{$key} : get_user_meta( $user->ID, $key, $single_meta );

		// If the meta key doesn't exist, return.
		if ( rgblank( $value ) ) {
			return;
		}

		// Parse out list data based on output type.
		if ( $output === 'csv' || $output === 'list' ) {

			// Decode JSON value.
			$value = $this->maybe_decode_json( $value );

			// If value is not an array, default to raw output.
			if ( ! is_array( $value ) ) {
				return esc_html( $value );
			}

			// Escape all values.
			$value = array_map( 'esc_html', $value );

			// Present data based on output type.
			if ( $output === 'csv' ) {
				return implode( ', ', $value );
			} else if ( $output === 'list' ) {
				$html  = '<ul>';
				foreach ( $value as $v ) {
					$html .= '<li>' . $v . '</li>';
				}
				$html .= '</ul>';
				return $html;
			}

		}

		return esc_html( $value );

	}





	// # PLUGIN SETTINGS -----------------------------------------------------------------------------------------------

	public function plugin_settings_fields() {

		// Prepare Registration Page dependency.
		if ( version_compare( GFForms::$version, '2.5-dev-1', '<' ) ) {
			$registration_deps = array(
				'field'  => 'custom_registration_page_enable',
				'values' => '1',
			);
		} else {
			$registration_deps = array(
				'live'   => true,
				'fields' => array(
					array(
						'field' => 'custom_registration_page_enable',
					),
				),
			);
		}

		return array(
			array(
				'title'       => esc_html__( 'Global Settings', 'gravityformsuserregistration' ),
				'description' => '',
				'fields'      => array(
					array(
						'name'    => 'custom_registration_page_enable',
						'label'   => esc_html__( 'Custom Registration Page', 'gravityformsuserregistration' ),
						'type'    => 'checkbox',
						'choices' => array(
							array(
								'label' => esc_html__( 'Enable Custom Registration Page', 'gravityformsuserregistration' ),
								'name'  => 'custom_registration_page_enable',
							),
						),
						'tooltip' => sprintf( '<h6>%s</h6> %s', esc_html__( 'Custom Registration Page', 'gravityformsuserregistration' ), esc_html__( 'When users attempt to access the default WordPress registration page, they will be redirected to this custom page instead.', 'gravityformsuserregistration' ) ),
						'onclick' => version_compare( GFForms::$version, '2.5-dev-1', '<' ) ? 'jQuery( "form#gform-settings" ).submit();' : '',
					),
					array(
						'name'       => 'custom_registration_page',
						'label'      => esc_html__( 'Registration Page', 'gravityformsuserregistration' ),
						'type'       => 'select_custom',
						'choices'    => $this->get_pages_as_choices( esc_html__( 'Select a Page', 'gravityformsuserregistration' ) ),
						'dependency' => $registration_deps,
						'required'   => true,
					),
				),
			),
		);
	}

	public function get_pages_as_choices( $default_option = false ) {

		$pages = get_pages( array(
			'post_status' => 'publish',
			'nopaging'    => true
		) );

		require_once( $this->get_base_path() . '/includes/class-gf-page-choice-walker.php' );

		$walker = new GF_Page_Choice_Walker;

		$choices = $walker->walk( $pages, 9 );

		// Move choices to a separate array.
		$choices = array(
			array(
				'label'   => esc_html__( 'Pages', 'gravityformsuserregistration' ),
				'choices' => $choices
			),
		);

		if ( $default_option ) {
			if ( ! is_array( $default_option ) ) {
				$default_option = array(
					'label'  => $default_option,
					'value' => ''
				);
			}
			array_unshift( $choices, $default_option );
		}

		// Add custom choice group.
		$choices[] = array(
			'label'   => esc_html__( 'Other', 'gravityformsuserregistration' ),
			'choices' => array(
				array(
					'label' => esc_html__( 'Custom URL', 'gravityformsuserregistration' ),
					'value' => 'gf_custom'
				),
			),
		);

		return $choices;
	}





	// # FEED SETTINGS -------------------------------------------------------------------------------------------------

	public function feed_settings_fields() {

		$is_update_feed = $this->is_update_feed( $this->get_current_feed() ) || $this->get_setting( 'feedType' ) == 'update';

		// Auto Generate Password is no longer supported for the Password setting. Let's default these to the new Set Password via Email Link option.
		if ( $this->get_setting( 'password' ) === 'generatepass' ) {
			$settings = $this->get_current_settings();
			$settings['password'] = 'email_link';
			$this->set_settings( $settings );
		}

		$fields = array();

		$fields['feed_settings'] = array(
			'title'       => esc_html__( 'Feed Settings', 'gravityformsuserregistration' ),
			'description' => '',
			'fields'      => array(
				array(
					'name'     => 'feedName',
					'label'    => esc_html__( 'Name', 'gravityformsuserregistration' ),
					'type'     => 'text',
					'required' => true,
					'class'    => 'medium',
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Name', 'gravityformsuserregistration' ), esc_html__( 'Enter a feed name to uniquely identify this setup.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'     => 'feedType',
					'label'    => esc_html__( 'Action', 'gravityformsuserregistration' ),
					'type'     => 'radio',
					'required' => true,
					'tooltip'  => sprintf(
						'<h6>%s</h6> %s <p><em>%s</em></p>',
						esc_html__( 'Action', 'gravityformsuserregistration' ),
						esc_html__( 'Select the type of feed you would like to create. "Create" feeds will create a new user. "Update" feeds will update users.', 'gravityformsuserregistration' ),
						__( 'A form can have multiple "Create" feeds <strong>or</strong> a single "Update" feed. A form cannot have both a "Create" feed and an "Update" feed.', 'gravityformsuserregistration' )
					),
					'choices'  => $this->get_available_feed_actions(),
					'onchange' => 'jQuery( this ).parents( "form" ).submit();'
				)
			)
		);

		$fields['user_settings'] = array(
			'title'       => esc_html__( 'User Settings', 'gravityformsuserregistration' ),
			'description' => '',
			'dependency'  => array(
				'field'   => 'feedType',
				'values'  => '_notempty_'
			),
			'fields'      => array(
				array(
					'name'     => 'username',
					'label'    => esc_html__( 'Username', 'gravityformsuserregistration' ),
					'type'     => 'field_select',
					'args'     => array(
						'callback' => array( $this, 'is_applicable_field_for_field_select' )
					),
					'default_value' => $this->get_username_field_for_feed_settings(),
					'required' => true,
					'class'    => 'medium',
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Username', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s username.', 'gravityformsuserregistration' ) ),
					'dependency' => array(
						'field'  => 'feedType',
						'values' => 'create'
					),
				),
				array(
					'name'     => 'first_name',
					'label'    => esc_html__( 'First Name', 'gravityformsuserregistration' ),
					'type'     => 'field_select',
					'args'     => array(
						'callback' => array( $this, 'is_applicable_field_for_field_select' )
					),
					'class'    => 'medium',
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'First Name', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s first name.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'     => 'last_name',
					'label'    => esc_html__( 'Last Name', 'gravityformsuserregistration' ),
					'type'     => 'field_select',
					'args'     => array(
						'callback' => array( $this, 'is_applicable_field_for_field_select' )
					),
					'class'    => 'medium',
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Last Name', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s last name.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'     => 'nickname',
					'label'    => esc_html__( 'Nickname', 'gravityformsuserregistration' ),
					'type'     => 'field_select',
					'args'     => array(
						'callback' => array( $this, 'is_applicable_field_for_field_select' )
					),
					'class'    => 'medium',
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Nickname', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s nickname.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'     => 'displayname',
					'label'    => esc_html__( 'Display Name', 'gravityformsuserregistration' ),
					'type'     => 'select',
					'class'    => 'medium',
					'choices'  => $this->get_display_name_choices(),
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Display Name', 'gravityformsuserregistration' ), esc_html__( 'Select how the user\'s name should be displayed publicly.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'        => 'email',
					'label'       => esc_html__( 'Email Address', 'gravityformsuserregistration' ),
					'type'        => 'field_select',
					'args'        => array(
						'disable_first_choice' => true,
						'input_types'          => array( 'email' ),
						'append_choices'       => $this->get_extra_email_choice()
					),
					'required'    => ! $is_update_feed,
					'class'       => 'medium',
					'no_choices'  => esc_html__( 'You must have at least one Email Address field.', 'gravityformsuserregistration' ),
					'tooltip'     => sprintf( '<h6>%s</h6> %s', esc_html__( 'Email Address', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s email address.', 'gravityformsuserregistration' ) )
				),
				array(
					'name'     => 'password',
					'label'    => esc_html__( 'Password', 'gravityformsuserregistration' ),
					'type'     => 'field_select',
					'class'    => 'medium',
					'args'     => array(
						'disable_first_choice' => true,
						'input_types'          => array( 'password' ),
						'append_choices'       => $this->get_extra_password_choices()
					),
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Password', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the user\'s password or select "Set Password via Email Link" to have a URL emailed to the user where they can set their password.', 'gravityformsuserregistration' ) ),
				),
				array(
					'name'     => 'role',
					'label'    => esc_html__( 'Role', 'gravityformsuserregistration' ),
					'type'     => 'select',
					'required' => true,
					'class'    => 'medium',
					'choices'  => $this->get_roles_as_choices(),
					'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Role', 'gravityformsuserregistration' ), esc_html__( 'Select the role the user should be assigned.', 'gravityformsuserregistration' ) ),
					'default_value'    => 'gfur_preserve_role',
					'dependency' => array(
						'field'  => 'createSite',
						'values' => false
					)
				),
			)
		);

		$fields['user_meta'] = array(
			'title'       => esc_html__( 'User Meta', 'gravityformsuserregistration' ),
			'description' => '',
			'dependency'  => array(
				'field'   => 'feedType',
				'values'  => '_notempty_'
			),
			'fields'      => array(
				array(
					'name'      => 'userMeta',
					'label'     => '',
					'type'      => 'generic_map',
					'field_map' => $this->get_user_meta_choices(),
				)
			)
		);

		if ( $this->is_bp_active() ) {

			$fields['bp_meta'] = array(
				'title'       => esc_html__( 'BuddyPress Profile', 'gravityformsuserregistration' ),
				'description' => '',
				'dependency'  => array(
					'field'  => 'feedType',
					'values' => '_notempty_',
				),
				'fields'      => array(
					array(
						'name'          => 'bpMeta',
						'label'         => '',
						'type'          => 'generic_map',
						'key_field'     => array(
							'choices'     => $this->get_buddypress_fields(),
							'placeholder' => esc_html__( 'Name or Name|GroupID', 'gravityformsuserregistration' ),
						),
						'save_callback' => array( $this, 'create_new_bp_fields' ),
					),
				),
			);
		}

		$enable_multisite_section = apply_filters( 'gform_user_registration_enable_multisite_section', $this->is_root_site() );

		if ( is_multisite() && $enable_multisite_section ) {
			$fields['network_settings'] = array(
				'title'       => esc_html__( 'Network Options', 'gravityformsuserregistration' ),
				'description' => '',
				'dependency'  => array(
					'field'   => 'feedType',
					'values'  => '_notempty_'
				),
				'fields'      => array(
					array(
						'name'      => 'createSite',
						'label'     => esc_html__( 'Create Site', 'gravityformsuserregistraiton' ),
						'type'      => 'checkbox',
						'choices'   => array(
							array(
								'label' => esc_html__( 'Create new site when a user registers.', 'gravityformsuserregistration' ),
								'name'  => 'createSite',
								'value' => 1,
								'onclick' => 'jQuery( this ).parents( "form" ).attr( "action", "#gaddon-setting-row-createSite" ).submit();'
							)
						)
					),
					array(
						'label'    => esc_html__( 'Site Address', 'gravityformsuserregistration' ),
						'name'     => 'siteAddress',
						'required' => true,
						'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Site Address', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the site address.', 'gravityformsuserregistration' ) ),
						'type'     => 'field_select',
						'class'    => 'medium',
						'dependency'  => array(
							'field'   => 'createSite',
							'values'  => 1
						)
					),
					array(
						'label'    => esc_html__( 'Site Title', 'gravityformsuserregistration' ),
						'name'     => 'siteTitle',
						'required' => true,
						'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Site Title', 'gravityformsuserregistration' ), esc_html__( 'Select the form field that should be used for the site title.', 'gravityformsuserregistration' ) ),
						'type'     => 'field_select',
						'class'    => 'medium',
						'dependency'  => array(
							'field'   => 'createSite',
							'values'  => 1
						)
					),
					array(
						'name'     => 'siteRole',
						'label'    => esc_html__( 'Site Role', 'gravityformsuserregistration' ),
						'type'     => 'select',
						'class'    => 'medium',
						'choices'  => $this->get_roles_as_choices(),
						'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Site Role', 'gravityformsuserregistration' ), esc_html__( 'Select role the user should be assigned on the newly created site.', 'gravityformsuserregistration' ) ),
						'required' => true,
						'dependency'  => array(
							'field'   => 'createSite',
							'values'  => 1
						)
					),
					array(
						'name'     => 'rootRole',
						'label'    => esc_html__( 'Current Site Role', 'gravityformsuserregistration' ),
						'type'     => 'select',
						'class'    => 'medium',
						'choices'  => $this->get_roles_as_choices( true ),
						'tooltip'  => sprintf( '<h6>%s</h6> %s', esc_html__( 'Current Site Role', 'gravityformsuserregistration' ), esc_html__( 'Select role the user should be assigned on the site they registered from. This option overrides the "Role" option under User Settings.', 'gravityformsuserregistration' ) ),
						'default_value' => $is_update_feed ? 'gfur_preserve_role' : '',
						'dependency'  => array(
							'field'   => 'createSite',
							'values'  => 1
						)
					),
					array(
						'name'      => 'sendSiteEmail',
						'label'     => esc_html__( 'Send Email?', 'gravityformsuserregistration' ),
						'type'      => 'checkbox',
						'choices'   => array(
							array(
								'label'         => esc_html__( 'Send the new site details to the user by email.', 'gravityformsuserregistration' ),
								'value'         => 1,
								'name'          => 'sendSiteEmail',
								'default_value' => 1
							)
						),
						'tooltip' => sprintf( '<h6>%s</h6> %s', esc_html__( 'Send Email?', 'gravityformsuserregistration' ), sprintf( esc_html__( 'Specify whether to send the new site details to the user by email. %sEnabled by default.%s', 'gravityformsuserregistration' ), '<em class="enabled-by-default">', '</em>' ) ),
					),
				)
			);
		}

		$fields['additional_settings'] = array(
			'title'       => esc_html__( 'Additional Options', 'gravityformsuserregistration' ),
			'description' => '',
			'dependency'  => array(
				'field'   => 'feedType',
				'values'  => '_notempty_'
			),
			'fields'      => array(
				array(
					'name'      => 'sendEmail',
					'label'     => esc_html__( 'Send Email?', 'gravityformsuserregistration' ),
					'type'      => 'send_email',
					'checkbox'  => array(
						'label'         => esc_html__( 'Send the new user an email about their account.', 'gravityformsuserregistration' ),
						'enabledLabel'  => esc_html__( 'Send the new user an email about their account:', 'gravityformsuserregistration' ),
						'value'         => 1,
						'default_value' => 1
					),
					'select'    => array(
						'choices' => array(
							array(
								'label' => esc_html__( 'by email from WordPress', 'gravityformsuserregistration' ),
								'value' => 'default'
							),
							array(
								'label' => esc_html__( 'by form notification', 'gravityformsuserregistration' ),
								'value' => 'manual'
							)
						),
						'tooltip' => sprintf(
							'<h6>%s</h6> %s',
							esc_html__( 'Send Email Options', 'gravityformsuserregistration' ),
							sprintf(
								esc_html__( '%3$s
									%1$sBy email from WordPress:%2$s%3$s WordPress will send the user an email with their login and a login URL. If the %1$sPassword%2$s setting is configured to "Set Password via Email Link", WordPress will include a link where the user can set their password.%3$s%3$s
									%1$sBy form notification:%2$s%3$s Configure a form notification for the desired User Registration event. If the %1$sPassword%2$s setting is configured to "Set Password via Email Link", you must include the %4$s{set_password_url}%5$s merge tag in your notification to provide a URL where the user can set their password.',
									'gravityformsuserregistration'
								),
								'<strong>', '</strong>', '<br>', '<code>', '</code>'
							) )
					),
					'tooltip' => sprintf( '<h6>%s</h6> %s', esc_html__( 'Send Email?', 'gravityformsuserregistration' ), sprintf( esc_html__( 'Specify whether an email (or notification) should be sent to the new user about their account. %sEnabled by default.%s', 'gravityformsuserregistration' ), '<em class="enabled-by-default">', '</em>' ) ),
					'dependency'  => array(
						'field'   => 'feedType',
						'values'  => 'create'
					),
				),
				array(
					'name'      => 'userActivation',
					'label'     => esc_html__( 'User Activation', 'gravityformsuserregistration' ),
					'type'      => 'checkbox_and_select',
					'checkbox'  => array(
						'label'        => esc_html__( 'Enable user activation.', 'gravityformsuserregistration' ),
						'enabledLabel' => esc_html__( 'Enable user activation:', 'gravityformsuserregistration' ),
					),
					'select'    => array(
						'choices' => array(
							array(
								'label' => esc_html__( 'by email from WordPress', 'gravityformsuserregistration' ),
								'value' => 'email'
							),
							array(
								'label' => esc_html__( 'manually or by form notification', 'gravityformsuserregistration' ),
								'value' => 'manual'
							)
						),
						'tooltip' => sprintf( '<h6>%s</h6> %s',
							esc_html__( 'User Activation Type', 'gravityformsuserregistration' ),
							sprintf(
								esc_html__( '%3$s
								%1$sBy Email:%2$s%3$sWordPress will send the user an email with an activation link.%3$s%3$s
								%1$sManually:%2$s%3$sActivate each user manually from the Pending Activations page or send a form notification for the User Activation event with an activation link (via the %4$s{activation_url}%5$s merge tag).', 'gravityformsuserregistration' ),
								'<strong>', '</strong>', '<br>', '<code>', '</code>'
							)
						)
					),
					'tooltip'     => sprintf( '<h6>%s</h6> %s', esc_html__( 'User Activation', 'gravityformsuserregistration' ), esc_html__( 'Send users an email with an activation link. Users are only registered once they have activated their accounts.', 'gravityformsuserregistration' ) ),
					'dependency'  => array(
						'field'   => 'feedType',
						'values'  => 'create'
					),
				),
				array(
					'name'           => 'registrationCondition',
					'label'          => $is_update_feed ? esc_html__( 'Update Condition', 'gravityformsuserregistration' ) : esc_html__( 'Registration Condition', 'gravityformsuserregistration' ),
					'type'           => 'feed_condition',
					'checkbox_label' => esc_html__( 'Enable', 'gravityformsuserregistration' ),
					'instructions'   => $is_update_feed ? esc_html__( 'Update user if', 'gravityformsuserregistration' ) : esc_html__( 'Register user if', 'gravityformsuserregistration' ),
					'tooltip'        => $is_update_feed ?
						sprintf( '<h6>%s</h6> %s', esc_html__( 'Update Condition', 'gravityformsuserregistration' ), esc_html__( 'When the update condition is enabled, form submissions will only update the user when the condition is met. The user data will always be populated into the form.', 'gravityformsuserregistration' ) ) :
						sprintf( '<h6>%s</h6> %s', esc_html__( 'Registration Condition', 'gravityformsuserregistration' ), esc_html__( 'When the registration condition is enabled, form submissions will only register the user when the condition is met.', 'gravityformsuserregistration' ) )
				)
			)
		);

		$form = $this->get_current_form();

		if ( GFCommon::has_post_field( $form['fields'] ) || ! empty( $this->get_feeds_by_slug( 'gravityformsadvancedpostcreation', $form['id'] ) ) ) {
			$set_post_author_field = array(
				'name'      => 'setPostAuthor',
				'label'     => esc_html__( 'Set Post Author', 'gravityformsuserregistration' ),
				'type'      => 'checkbox',
				'choices'   => array(
					array(
						'label'         => esc_html__( 'Set user as post author.', 'gravityformsuserregistration' ),
						'name'          => 'setPostAuthor',
						'value'         => 1,
						'default_value' => 1
					)
				),
				'tooltip' => sprintf( '<h6>%s</h6> %s', esc_html__( 'Set Post Author', 'gravityformsuserregistration' ), sprintf( esc_html__( 'When a form submission creates a post and registers a user, set the new user as the post author. %sEnabled by default.%s', 'gravityformsuserregistration' ), '<em class="enabled-by-default">', '</em>' ) ),
				'dependency'  => array(
					'field'   => 'feedType',
					'values'  => 'create'
				),
			);
			$fields = $this->add_field_after( 'sendEmail', $set_post_author_field, $fields );
		}

		$fields['save'] = array(
			'fields' => array(
				array(
					'type' => 'save',
					'onclick' => '( function( $, elem, event ) {
						var $form       = $( elem ).parents( "form" ),
							action      = $form.attr( "action" ),
							hashlessUrl = document.URL.replace( window.location.hash, "" );

						if( ! action && hashlessUrl != document.URL ) {
							event.preventDefault();
							$form.attr( "action", hashlessUrl );
							$( elem ).click();
						};

					} )( jQuery, this, event );'
				)
			)
		);

		/**
		 * Filter the setting fields that appears on the feed page.
		 *
		 * @since 3.0.beta1.1
		 *
		 * @param array $fields An array of setting fields.
		 * @param array $form Form object to which the current feed belongs.
		 *
		 * @see https://gist.github.com/spivurno/15592a66497096338864
		 */
		$fields = apply_filters( 'gform_userregistration_feed_settings_fields', $fields, $form );

		// sections cannot be an associative array
		return array_values( $fields );
	}

	/**
	 * Create new BuddyPress profile fields when feed settings are saved.
	 *
	 * @since 5.4
	 *
	 * @param \Gravity_Forms\Gravity_Forms\Settings\Fields\Generic_Map $setting_field Field object.
	 * @param array                                                    $field_value   Posted field value.
	 *
	 * @return array
	 */
	public function create_new_bp_fields( $setting_field, $field_value = array() ) {
		if ( empty( $setting_field->key_field ) || empty( $field_value ) ) {
			return $field_value;
		}

		if ( ! function_exists( 'xprofile_insert_field' ) ) {
			require_once BP_PLUGIN_DIR . '/bp-xprofile/bp-xprofile-functions.php';
		}

		foreach ( $field_value as &$field ) {
			if ( rgar( $field, 'key' ) !== 'gf_custom' ) {
				// Ensure keys are strings, so they will be disabled in the select when configuring other mappings.
				$field['key'] = (string) $field['key'];
				continue;
			}

			$custom_key = trim( rgar( $field, 'custom_key', '' ) );
			if ( empty( $custom_key ) || ( absint( $custom_key ) == $custom_key ) ) {
				continue;
			}

			list( $name, $group_id ) = rgexplode( '|', $custom_key, 2 );
			if ( $group_id ) {
				$group_id = absint( $group_id );
			}
			if ( empty( $group_id ) ) {
				$group_id = 1;
			}

			$field_id = xprofile_insert_field( array(
				'name'           => $name,
				'field_group_id' => $group_id,
				'type'           => 'textbox',
			) );

			if ( ! $field_id ) {
				$this->log_error( __METHOD__ . '(): Unable to create field: ' . $name );
				continue;
			}

			$this->log_debug( __METHOD__ . "(): New field created. Name: {$name}; ID: " . $field_id );

			// Add to key field choices, so it will be selected when the setting is rendered.
			foreach ( $setting_field->key_field['choices'] as &$group ) {
				if ( rgar( $group, 'id' ) === $group_id ) {
					$group['choices'][] = array(
						'label' => $name,
						'value' => (string) $field_id,
					);
					break;
				}
			}

			// Update field in $field_value array.
			$field['key']        = (string) $field_id;
			$field['custom_key'] = '';
		}

		return $field_value;
	}

	/**
	 * Custom feed setting for the Send Email option.
	 *
	 * @param      $field
	 * @param bool $echo
	 *
	 * @return string
	 */
	public function settings_send_email( $field, $echo = true ) {

		$html = sprintf( '<div>%s</div>', $this->settings_checkbox_and_select( $field, false ) );

		if ( version_compare( GFForms::$version, '2.5-dev-1', '<' ) ) {
			$html .= sprintf( '<div id="sendEmailLocked" class="alert_yellow" style="display:none;margin-top: 15px;padding: 15px;">%s</div>',
				sprintf( esc_html__( 'This setting is locked when %sPassword%s is configured to "Set Password via Email Link".' ), '<strong>', '</strong>' )
			);
		} else {
			$html .= sprintf( '<div id="sendEmailLocked" class="alert warning" style="display:none;">%s</div>',
				sprintf( esc_html__( 'This setting is locked when %sPassword%s is configured to "Set Password via Email Link".' ), '<strong>', '</strong>' )
			);
		}

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	/**
	 * Custom feed setting for a checkbox that when checked reveals a select.
	 *
	 * @param array $field
	 * @param bool  $echo
	 *
	 * @return string
	 */
	public function settings_checkbox_and_select( $field, $echo = true ) {

		$field = $this->prepare_settings_checkbox_and_select( $field );

		$checkbox_field = $field['checkbox'];
		$select_field = $field['select'];

		$is_enabled = $this->get_setting( $checkbox_field['name'] );

		$html = sprintf(
			'%s <span id="%s" style="%s">%s %s</span>',
			$this->settings_checkbox( $checkbox_field, false ),
			$select_field['name'] . 'Span',
			$is_enabled ? '' : 'display:none;',
			$this->settings_select( $select_field, false ),
			$this->maybe_get_tooltip( $select_field )
		);

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	/**
	 * Prepare the individual checkbox and select fields for use in a custom Checkbox Select feed setting.
	 *
	 * @param $field
	 *
	 * @return mixed
	 */
	public function prepare_settings_checkbox_and_select( $field ) {

		// Prepare checkbox.
		$checkbox_field = wp_parse_args( rgars( $field, 'checkbox' ), array(
			'type'       => 'checkbox',
			'name'       => $field['name'] . 'Enable',
			'label'      => esc_html__( 'Enable', 'gravityforms' ),
			'horizontal' => true,
			'value'      => '1',
			'choices'    => false,
			'tooltip'    => false,
			'class'      => '',
		) );

		if( isset( $checkbox_field['enabledLabel'] ) ) {
			$checkbox_field['data-enabledLabel'] = $checkbox_field['enabledLabel'];
			$checkbox_field['data-label'] = $checkbox_field['label'];
			unset( $checkbox_field['enabledLabel'] );
		}

		$checkbox_field['class'] .= 'gfur-cs-checkbox';

		if( empty( $checkbox_field['choices'] ) ) {
			$checkbox_field['choices'] = array(
				array(
					'name'  => $checkbox_field['name'],
					'label' => $checkbox_field['label'],
				)
			);
		}

		// Prepare select.
		$select_field = wp_parse_args( rgars( $field, 'select' ), array(
			'name'    => $field['name'] . 'Value',
			'type'    => 'select',
			'class'   => '',
			'tooltip' => false
		) );

		$select_field['class'] .= ' ' . $field['name'] . 'Value';

		$field['select'] = $select_field;
		$field['checkbox'] = $checkbox_field;

		return $field;
	}

	public function get_username_field_for_feed_settings() {

		$form = GFAPI::get_form( rgget( 'id' ) );

		foreach ( $form['fields'] as $field ) {

			if ( 'username' === $field->type || 'username' === strtolower( $field->label ) ) {
				return $field->id;
			}

		}

		return null;

	}

	function get_update_user_actions_choices() {

		$choices = $this->get_roles_as_choices();

		foreach ( $choices as &$choice ) {
			$choice['label'] = sprintf( esc_html__( 'Set as %s', 'gravityformsuserregistration' ), $choice['label'] );
		}

		return $choices;
	}

	function get_update_site_actions_choices() {

		$choices = array(
			array(
				'label' => esc_html__( 'Deactivate site', 'gravityformsuserregistration' ),
				'value' => 'deactivate'
			),
			array(
				'label' => esc_html__( 'Delete site', 'gravityformsuserregistration' ),
				'value' => 'delete'
			)
		);

		return $choices;
	}

	function is_applicable_field_for_field_select( $is_applicable_field, $field ) {

		if ( rgobj( $field, 'multipleFiles' ) ) {
			$is_applicable_field = false;
		}

		return $is_applicable_field;
	}

	public function get_user_meta_choices() {
		global $wpdb;

		// standard meta
		$standard_meta = array(
			'label'   => esc_html__( 'Standard User Meta', 'gravityformsuserregistration' ),
			'choices' => array(
				array(
					'label' => esc_html__( 'Website', 'gravityformsuserregistration' ),
					'value' => 'user_url'
				),
				array(
					'label' => esc_html__( 'AIM', 'gravityformsuserregistration' ),
					'value' => 'aim'
				),
				array(
					'label' => esc_html__( 'Yahoo', 'gravityformsuserregistration' ),
					'value' => 'yim'
				),
				array(
					'label' => esc_html__( 'Jabber / Google Talk', 'gravityformsuserregistration' ),
					'value' => 'jabber'
				),
				array(
					'label' => esc_html__( 'Biographical Information', 'gravityformsuserregistration' ),
					'value' => 'description'
				)
			)
		);

		// other meta

		$keys = array();

		/**
		 * Allows the options for the "Other User Meta" group in the User Meta setting on the settings page to be set before running the query against the usermeta table for existing meta keys.
		 *
		 * Return false to skip the query and remove the option group entirely. Return an array of option details to skip the query.
		 *
		 * This is useful;
		 * 1. when the usermeta table is very big and the query is taking too long.
		 * 2. if you need to populate the options with user meta keys that have never been added to a user profile.
		 *
		 * Return an array of arrays containing the option details:
		 * array(
		 *     array(
		 *          'name'     => 'the_meta_key',
		 *          'label'    => 'The Label For the Meta',
		 *          'required' => false // or true
		 *     )
		 * )
		 *
		 * @since 3.3.5
		 *
		 * @param array $keys
		 */
		$keys = apply_filters( 'gform_user_registration_user_meta_options', $keys );

		if ( is_array( $keys ) && empty( $keys ) ) {
			$raw_keys = $wpdb->get_results( sprintf( 'select distinct meta_key from %s where meta_key not like "%s" order by meta_key asc', $wpdb->usermeta, '\_%' ) );
			$exclude  = array( 'user_url', 'aim', 'yim', 'jabber', 'description' );

			foreach ( $raw_keys as $key ) {
				if ( ! in_array( $key->meta_key, $exclude ) ) {
					$keys[] = array(
						'name'     => $key->meta_key,
						'label'    => $key->meta_key,
						'required' => false
					);
				}
			}
		}

		$other_meta = null;

		if ( ! empty( $keys ) ) {
			$other_meta = array(
				'label'   => esc_html__( 'Other User Meta', 'gravityformsuserregistration' ),
				'choices' => $keys
			);
		}

		// custom option to add custom meta key
		$add_custom_meta = array(
			'label' => esc_html__( 'Add Custom Meta', 'gravityformsuserregistration' ),
			'value' => 'gf_custom'
		);

		$empty_choice = array(
			'label' => esc_html__( 'Select Meta Key', 'gravityformsuserregistration' ),
			'value' => ''
		);

		$choices   = array();
		$choices[] = $empty_choice;
		$choices[] = $standard_meta;
		if ( ! empty( $other_meta ) ) {
			$choices[] = $other_meta;
		}
		$choices[] = $add_custom_meta;

		return $choices;
	}

	public static function get_field_map_choices( $form_id, $field_type = null, $exclude_field_types = null ) {

		$choices = parent::get_field_map_choices( $form_id, $field_type );
		$form    = GFAPI::get_form( $form_id );

		for ( $i = count( $choices ) - 1; $i >= 0; $i -- ) {

			if ( ! is_numeric( $choices[ $i ]['value'] ) ) {
				continue;
			}

			$id    = $choices[ $i ]['value'];
			$field = GFFormsModel::get_field( $form, $id );

			if ( ! $field ) {
				continue;
			}

			if ( ! gf_user_registration()->is_applicable_field_for_field_select( true, $field ) || ( $field->get_input_type() === 'address' && $id == intval( $id ) ) ) {
				unset( $choices[ $i ] );
			}

		}

		return $choices;
	}

	public function no_output_field_properties( $props ) {
		$props[] = 'args';
		return $props;
	}

	public function get_available_feed_actions() {

		// forms can have multiple "create" feeds
		// forms can have only a single "update" feed
		// any given form can only have one type of feed, a form with a "create" feed can not have an "update" feed and vice versa
		$actions = $this->get_feed_actions();

		$form = GFAPI::get_form( rgget( 'id' ) );
		if ( is_wp_error( $form ) ) {
			return $actions;
		}

		$feed = $this->get_current_feed();

		if ( $this->has_feed_type( 'create', $form, rgar( $feed, 'id' ) ) ) {
			$actions['update']['disabled'] = true;
		}

		return $actions;
	}

	/**
	 * Returns the "preserve current email" choice if this is an update type feed.
	 *
	 * @since 3.8.2
	 *
	 * @return array
	 */
	public function get_extra_email_choice() {

		$choices   = array();
		$feed_type = $this->get_setting( 'feedType' );

		if ( $feed_type == 'update' ) {
			$choices[] = array(
				'label' => esc_html__( '&mdash; Preserve current email &mdash;', 'gravityformsuserregistration' ),
				'value' => ''
			);
		}


		return $choices;
	}

	public function get_extra_password_choices() {

		$choices   = array();
		$feed_type = $this->get_setting( 'feedType' );

		if ( $feed_type == 'update' ) {
			$choices[] = array(
				'label' => esc_html__( '&mdash; Preserve current password &mdash;', 'gravityformsuserregistration' ),
				'value' => ''
			);
		} elseif ( $feed_type == 'create' ) {
			$choices[] = array(
				'label' => esc_html__( 'Set Password via Email Link', 'gravityformsuserregistration' ),
				'value' => 'email_link'
			);
		}

		return $choices;
	}

	public function get_feed_actions() {
		return array(
			'create' => array(
				'label' => esc_html__( 'Create User', 'gravityformsuserregistration' ),
				'value' => 'create',
				'icon'  => 'fa-user-plus',
			),
			'update' => array(
				'label' => esc_html__( 'Update User', 'gravityformsuserregistration' ),
				'value' => 'update',
				'icon'  => 'fa-refresh',
			)
		);
	}

	/**
	 * Determine if feed can be created.
	 *
	 * Note: Many "create" user registration feeds can exist, but only one update feed is allowed at a time. Because
	 * Gravity Forms calls can_create_feed to check whether a feed can be edited, too, we need to first determine
	 * whether the current view is the feed edit page. GF 2.5 introduces the `is_feed_edit_page` method, but we include
	 * its logic here as a fallback to get this to work pre-2.5.
	 *
	 * @since 4.5
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		// Allow users to edit an existing create or update feed.
		if (
			( $this->is_gravityforms_supported( '2.5-beta' ) && $this->is_feed_edit_page() )
			|| (
				( $this->is_detail_page() || $this->_multiple_feeds === false )
				&& rgget( 'subview' ) === $this->get_slug()
			)
		) {
			return true;
		}

		// Prevent creation of new feeds if an update feed already exists.
		$form = GFAPI::get_form( rgget( 'id' ) );

		return ! $this->has_feed_type( 'update', $form );

	}

	public function can_duplicate_feed( $feed ) {

		/* Get the feed. */
		$feed = is_array( $feed ) ? $feed : $this->get_feed( $feed );

		if ( rgars( $feed, 'meta/feedType' ) == 'update' ) {
			return false;
		}

		return true;

	}

	public function has_feed_type( $feed_type, $form, $current_feed_id = false ) {

		$feeds = $this->get_feeds( $form['id'] );

		foreach ( $feeds as $feed ) {

			// skip current feed as it may be changing feed type
			if ( $current_feed_id && $feed['id'] == $current_feed_id ) {
				continue;
			}

			// if there is no feed type specified, default to "create"
			if ( ! rgars( $feed, 'meta/feedType' ) ) {
				$feed['meta']['feedType'] = 'create';
			}

			if ( rgars( $feed, 'meta/feedType' ) == $feed_type ) {
				return true;
			}

		}

		return false;
	}

	public function get_display_name_choices() {

		$display_names = $this->get_display_names();
		$choices       = array();

		foreach ( $display_names as $value => $label ) {
			$choices[] = array(
				'label' => $label,
				'value' => $value
			);
		}

		return $choices;
	}

	public function get_display_names() {

		$feed_type = $this->get_setting( 'feedType' );
		$choices   = array();

		if ( $feed_type == 'update' ) {
			$choices['gfur_preserve_display_name'] = esc_html__( '&mdash; Preserve current display name &mdash;', 'gravityformsuserregistration' );
		}

		$choices['nickname']  = '{nickname}';
		$choices['username']  = '{username}';
		$choices['firstname'] = '{first name}';
		$choices['lastname']  = '{last name}';
		$choices['firstlast'] = '{first name} {last name}';
		$choices['lastfirst'] = '{last name} {first name}';

		return $choices;

	}

	public function get_roles_as_choices( $include_no_role_option = false ) {

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/user.php' );
		}

		$roles   = array_reverse( get_editable_roles() );
		$choices = array(
			array(
				'label' => esc_html__( 'Select a Role', 'gravityformsuserregistration' ),
				'value' => ''
			)
		);

		foreach ( $roles as $role => $details ) {
			$name      = translate_user_role( $details['name'] );
			$choices[] = array(
				'label' => $name,
				'value' => $role
			);
		}

		$feed_type = $this->get_setting( 'feedType' );

		if ( $feed_type == 'update' ) {
			$choices[] = array(
				'label' => esc_html__( '&mdash; Preserve current role &mdash;', 'gravityformsuserregistration' ),
				'value' => 'gfur_preserve_role'
			);
		}

		if ( $include_no_role_option ) {
			$choices[] = array(
				'label' => esc_html__( '&mdash; No role for this site &mdash;', 'gravityformsuserregistration' ),
				'value' => ''
			);
		}

		return $choices;
	}

	public function feed_list_columns() {

		$columns = array(
			'feedName' => esc_html__( 'Name', 'gravityformsuserregistration' ),
			'feedType' => esc_html__( 'Action', 'gravityformsuserregistration' )
		);

		return $columns;
	}

	/**
	 * Overrides the behavior in parent GFFeedAddOn class.
	 *
	 * There are no messages we need to display in the feed list outside of the invitation to create a feed. Thus,
	 * we simply return false here to render the feed list if feeds exist.
	 *
	 * @since 4.5.7
	 *
	 * @return string|false
	 */
	public function feed_list_message() {

		return false;

	}

	public function get_column_value_feedType( $item ) {

		$feed_types        = $this->get_feed_actions();
		$feed_type         = $item['meta']['feedType'];
		$feed_type_options = $feed_types[ $feed_type ];

		return $feed_type_options['label'];
	}

	public function get_select_option( $choice, $selected_value ) {

		if ( is_array( $selected_value ) ) {
			$selected = in_array( $choice['value'], $selected_value ) ? "selected='selected'" : '';
		} else {
			$selected = selected( $selected_value, $choice['value'], false );
		}

		$disabled = rgar( $choice, 'disabled' ) == true ? 'disabled="disabled"' : '';

		return sprintf( '<option value="%1$s" %2$s %4$s>%3$s</option>', esc_attr( $choice['value'] ), $selected, $choice['label'], $disabled );
	}

	public function settings_dynamic_field_map( $field, $echo = true ) {
		$html = parent::settings_dynamic_field_map( $field, false );
		ob_start();
		?>

		<style type="text/css">
			.settings-field-map-table .value { width: 308px; max-width: 100%; }
			.settings-field-map-table .repeater-buttons { margin-left: 16px; }
		</style>

		<?php
		$html .= ob_get_clean();

		if ( $echo ) {
			echo $html;
		}

		return $html;
	}

	/**
	 * Add new settings section to the payment feed.
	 *
	 * @since unknown
	 * @since 4.4     Call $this->add_feed_settings().
	 *
	 * @param array   $feed_settings_fields The add-on feed settings.
	 * @param GFAddOn $addon                The current instance of the add-on (i.e. GF_User_Registration, GFPayPal).
	 *
	 * @return array
	 */
	public function add_paypal_settings( $feed_settings_fields, $addon ) {

		return $this->add_feed_settings( $feed_settings_fields, $addon );
	}

	/**
	 * Add new settings section to the payment feed.
	 *
	 * @since 4.4
	 *
	 * @param array   $feed_settings_fields The add-on feed settings.
	 * @param GFAddOn $addon                The current instance of the add-on (i.e. GF_User_Registration, GFPayPal).
	 *
	 * @return array
	 */
	public function add_feed_settings( $feed_settings_fields, $addon ) {

		if ( ! $addon instanceof GFPaymentAddOn ) {
			return $feed_settings_fields;
		}

		$config = array();

		if ( method_exists( $addon, 'get_post_payment_actions_config' ) ) {
			$config = $addon->get_post_payment_actions_config( $this->get_slug() );
		} elseif ( $addon instanceof GFPayPal ) {
			$config = array(
				'position' => 'after',
				'setting'  => 'options',
			);
		}
		// $config is just for checking if we should add the UR settings, no actually use here.
		if ( empty( $config ) ) {
			return $feed_settings_fields;
		}

		$ur_settings = array(
			'title'      => esc_html__( 'User Registration Options', 'gravityformsuserregistration' ),
			/* translators: 1. HTML H6 open tag 2. HTML H6 close tag 3. The payment addon title. */
			'tooltip'    => sprintf( esc_html__( '%1$sUser Registration Options%2$sThe selected form also has a User Registration feed. These options allow you to specify how you would like the %3$s and User Registration Add-ons to work together.', 'gravityformuserregistration' ), '<h6>', '</h6>', $addon->get_short_title() ),
			'fields'     => array(),
			'dependency' => 'transactionType',
		);

		$ur_settings['fields'][] = array(
			'name'    => "delay_{$this->_slug}",
			'label'   => esc_html__( 'Delay User Registration', 'gravityformsuserregistration' ),
			'type'    => 'checkbox',
			'choices' => array(
				array(
					'name'  => "delay_{$this->_slug}",
					'label' => rgar( $this->delayed_payment_integration, 'option_label' ),
					'value' => 1,
				),
			),
		);

		$ur_settings['fields'][] = array(
			'name'       => 'cancellationActionUser',
			'label'      => esc_html__( 'Update User on Cancel', 'gravityformsuserregistration' ),
			'type'       => 'checkbox_and_select',
			'checkbox'   => array(
				'label' => esc_html__( 'Update user when subscription is cancelled.', 'gravityformsuserregistration' ),
			),
			'select'     => array(
				'choices' => $this->get_update_user_actions_choices(),
			),
			'dependency' => array( 'field' => 'transactionType', 'values' => array( 'subscription' ), ),
		);

		if ( is_multisite() ) {
			$ur_settings['fields'][] = array(
				'name'       => 'cancellationActionSite',
				'label'      => esc_html__( 'Update Site on Cancel', 'gravityformsuserregistration' ),
				'type'       => 'checkbox_and_select',
				'checkbox'   => array(
					'label' => esc_html__( 'Update site when subscription is cancelled.', 'gravityformsuserregistration' ),
				),
				'select'     => array(
					'choices' => $this->get_update_site_actions_choices(),
				),
				'dependency' => array(
					'field'  => 'transactionType',
					'values' => array( 'subscription' ),
				),
			);
		}

		$feed_settings_fields = array_merge( $feed_settings_fields, array( $ur_settings ) );

		return $feed_settings_fields;
	}





	// # HELPERS -------------------------------------------------------------------------------------------------------

	public function log_wp_mail( $result, $type ) {
		$calling_method = $this->get_calling_method( 2 );

		$this->log_debug( sprintf( '%s(): Result from wp_mail() for the %s email: %s', $calling_method, $type, is_wp_error( $result ) ? $result->get_error_message() : $result ) );
		if ( ! is_wp_error( $result ) && $result ) {
			$this->log_debug( $calling_method . '(): Mail was passed from WordPress to the mail server.' );
		} else {
			$this->log_error( $calling_method . '(): The mail message was passed off to WordPress for processing, but WordPress was unable to send the message.' );
		}

		if ( has_filter( 'phpmailer_init' ) ) {
			$this->log_debug( $calling_method . '(): The WordPress phpmailer_init hook has been detected, usually used by SMTP plugins, it can impact mail delivery.' );
		}

		global $phpmailer;
		if ( ! empty( $phpmailer->ErrorInfo ) ) {
			$this->log_error( $calling_method . '(): PHPMailer class returned an error message: ' . $phpmailer->ErrorInfo );
		}
	}

	public function log( $message ) {
		$calling_method = $this->get_calling_method( 2 );
		$this->log_debug( sprintf( '%s(): %s', $calling_method, $message ) );
	}

	/**
	 * Return the name of the method which called the current method.
	 *
	 * @props http://stackoverflow.com/a/9133897/227711
	 *
	 * @param int $back_trace_count Defaults to 1. Set to a higher number to get methods higher in the stack trace.
	 *
	 * @return mixed
	 */
	public function get_calling_method( $back_trace_count = 1 ) {

		$e         = new Exception();
		$trace     = $e->getTrace();
		$last_call = $trace[ $back_trace_count ];

		if ( rgempty( 'class', $last_call ) ) {
			return $last_call['function'];
		}

		return sprintf( '%s::%s', $last_call['class'], $last_call['function'] );
	}

	public function is_bp_active() {
		return defined( 'BP_VERSION' );
	}

	public function user_login_exists( $user_login ) {

		if ( ! function_exists( 'username_exists' ) ) {
			require_once( ABSPATH . WPINC . '/registration.php' );
		}

		return username_exists( $user_login );
	}

	public function is_users_email( $email, $user_id = false ) {

		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$user = new WP_User( $user_id );

		return strtolower( $user->get( 'user_email' ) ) == strtolower( $email );
	}

	public function is_meta_key_mapped( $meta_key, $feed ) {
		return rgars( $feed, "meta/$meta_key" ) == true;
	}

	public function add_validation_error( $field_id, $form, $message ) {

		foreach ( $form['fields'] as &$field ) {
			if ( $field->id == $field_id && ! $field->failed_validation ) {
				$field->failed_validation  = true;
				$field->validation_message = apply_filters( 'gform_user_registration_validation_message', $message, $form );
				break;
			}
		}

		return $form;
	}

	public function is_form_valid( $form ) {

		foreach ( $form['fields'] as $field ) {
			if ( $field->failed_validation ) {
				return false;
			}
		}

		return true;
	}

	public static function is_root_site() {
		global $current_blog, $current_site;

		return ! is_multisite() || $current_site->blog_id == $current_blog->blog_id;
	}

	public function is_update_feed( $feed ) {
		return rgars( $feed, 'meta/feedType' ) == 'update';
	}

	public function get_active_feed( $entry, $form = false ) {

		$submission_feed = false;

		if ( $entry['id'] ) {
			$feeds           = $this->get_feeds_by_entry( $entry['id'] );
			$submission_feed = empty( $feeds ) ? false : $this->get_feed( $feeds[0] );
		} else if ( $form ) {

			// getting all feeds
			$feeds = $this->get_feeds( $form['id'] );

			foreach ( $feeds as $feed ) {
				if ( $feed['is_active'] && $this->is_feed_condition_met( $feed, $form, $entry ) ) {
					$submission_feed = $feed;
					break;
				}
			}

		}

		return $submission_feed;
	}

	public function get_update_feed( $form_id ) {

		$feeds = $this->get_feeds( $form_id );

		foreach ( $feeds as $feed ) {
			if ( $feed['is_active'] && rgars( $feed, 'meta/feedType' ) == 'update' ) {
				return $feed;
			}
		}

		return false;
	}

	public function get_user_data( $entry, $form, $feed, $user_id = false ) {

		$user_email = $this->get_meta_value( 'email', $feed, $form, $entry );

		if ( $this->is_update_feed( $feed ) ) {
			$user       = new WP_User( $user_id ? $user_id : $entry['created_by'] );
			$user_login = $user->get( 'user_login' );
			$user_email = $user_email ? $user_email : $user->get( 'user_email' );
		} else {
			$user_login = $this->get_meta_value( 'username', $feed, $form, $entry );
			$user_login = gf_apply_filters( 'gform_username', $form['id'], $user_login, $feed, $form, $entry );
			/* @deprecated */
			$user_login = gf_apply_filters( 'gform_user_registration_username', $form['id'], $user_login, $feed, $form, $entry );
		}

		$password = $this->get_meta_value( 'password', $feed, $form, $entry );
		if ( empty( $password ) ) {
			$password = false;
		} else {
			// Slashes were removed by GF during submission.
			// Adding them back to prevent an invalid password error on login.
			$password = addslashes( $password );
		}

		// Password will be stored in entry meta for delayed feeds.
		$password_hash = gform_get_meta( $entry['id'], 'userregistration_password' );

		if ( empty( $user_login ) || empty( $user_email ) ) {
			return false;
		}

		return array(
			'user_login'    => $user_login,
			'user_email'    => $user_email,
			'password'      => $password,
			'password_hash' => $password_hash,
		);
	}

	public function get_site_data( $entry, $form, $feed ) {
		global $current_site;

		$blog_address = '';
		$user_data    = $this->get_user_data( $entry, $form, $feed );
		$address      = $this->get_meta_value( 'siteAddress', $feed, $form, $entry );

		if ( ! preg_match( '/(--)/', $address ) && preg_match( '|^([a-zA-Z0-9-])+$|', $address ) ) {
			$blog_address = strtolower( $address );
		}

		$blog_title = $this->get_meta_value( 'siteTitle', $feed, $form, $entry );

		if ( empty( $blog_address ) || empty( $user_data['user_email'] ) || ! is_email( $user_data['user_email'] ) ) {
			return array();
		}

		if ( is_subdomain_install() ) {
			$blog_domain = $blog_address . '.' . preg_replace( '|^www\.|', '', $current_site->domain );
			$path        = $current_site->path;
		} else {
			$blog_domain = $current_site->domain;
			$path        = trailingslashit( $current_site->path ) . $blog_address . '/';
		}

		return array(
			'domain' => $blog_domain,
			'path'   => $path,
			'title'  => $blog_title,
			'email'  => $user_data['user_email']
		);
	}

	/**
	 * Retrieves value from post to be populated as user meta.
	 *
	 * @param mixed $meta_key The meta key as specified in the $feed
	 * @param mixed $meta The array of meta mappings stored in the $feed
	 * @param mixed $form The current form object
	 * @param mixed $entry The current lead object
	 * @return mixed The value matching the meta mapping for the given meta key or if not found, an empty string
	 */
	public function get_meta_value( $meta_key, $meta, $form, $entry ) {
		return $this->get_prepared_value( $meta_key, $meta, $form, $entry );
	}

	public function get_prepared_value( $meta_key, $meta, $form, $entry, $is_username = null ) {

		// support legacy usage where feed was passed as $meta parameter
		$meta = isset( $meta['meta'] ) ? $meta['meta'] : $meta;

		if ( $is_username === null ) {
			$is_username = $meta_key == 'username';
		}

		$input_id = rgar( $meta, $meta_key );
		$field    = GFFormsModel::get_field( $form, $input_id );
		$value    = $this->get_mapped_field_value( $meta_key, $form, $entry, $meta );

		// Post Category fields come with Category Name and ID in the value (i.e. Austin:51); only return the name
		$value = $this->maybe_get_category_name( $field, $value );

		if ( $this->is_bp_active() && $is_username ) {
			$value = str_replace( ' ', '', $value );
		}

		return $this->filter_prepared_meta_value( $value, $field, $input_id, $meta_key, $meta, $form, $entry, $is_username );
	}

	/**
	 * Allows the meta value to be overridden before it is saved to the database.
	 *
	 * @since 5.4
	 *
	 * @param string        $value       The meta value to be saved.
	 * @param null|GF_Field $field       Null or a field object.
	 * @param string        $input_id    The mapping value such as gf_custom or the ID of the field or entry property.
	 * @param string        $meta_key    The meta key the value is to be saved for.
	 * @param array         $meta        The mappings from a generic_map type setting.
	 * @param array         $form        The form currently being processed.
	 * @param array         $entry       The entry currently being processed.
	 * @param bool          $is_username Indicates if the meta key is the username.
	 *
	 * @return string
	 */
	public function filter_prepared_meta_value( $value, $field, $input_id, $meta_key, $meta, $form, $entry, $is_username = false ) {
		/**
		 * Allows the meta value to be overridden before it is saved to the database.
		 *
		 * @since unknown
		 *
		 * @param string        $value       The meta value to be saved.
		 * @param null|GF_Field $field       Null or a field object.
		 * @param string        $input_id    The mapping value such as gf_custom or the ID of the field or entry property.
		 * @param array         $entry       The entry currently being processed.
		 * @param bool          $is_username Indicates if the meta key is the username.
		 */
		$value = apply_filters( 'gform_user_registration_prepared_value', $value, $field, $input_id, $entry, $is_username );

		/**
		 * Allows the meta value to be overridden before it is saved to the database.
		 *
		 * @since unknown
		 *
		 * @param string $value       The meta value to be saved.
		 * @param string $meta_key    The meta key the value is to be saved for.
		 * @param array  $meta        The mappings from the generic_map type setting.
		 * @param array  $form        The form currently being processed.
		 * @param array  $entry       The entry currently being processed.
		 * @param bool   $is_username Indicates if the meta key is the username.
		 */
		return apply_filters( 'gform_user_registration_meta_value', $value, $meta_key, $meta, $form, $entry, $is_username );
	}

	/**
	 * Returns the prepared values for the specified generic_map type setting.
	 *
	 * @since 5.4
	 *
	 * @param string $field_name The name of the generic_map type setting.
	 * @param array  $feed       The feed currently being processed.
	 * @param array  $form       The form currently being processed.
	 * @param array  $entry      The entry currently being processed.
	 *
	 * @return array
	 */
	public function get_prepared_generic_map_values( $field_name, $feed, $form, $entry ) {
		$mappings = rgars( $feed, 'meta/' . $field_name );
		if ( empty( $mappings ) ) {
			return array();
		}

		$values = array();

		foreach ( $mappings as $mapping ) {
			$input_id = rgar( $mapping, 'value' );

			if ( $input_id === 'gf_custom' ) {
				$custom_value = rgar( $mapping, 'custom_value', '' );
				$value        = $custom_value ? GFCommon::replace_variables( $custom_value, $form, $entry, false, false, false, 'text' ) : '';
				$field        = null;
				if ( $value !== $custom_value ) {
					$input_id .= '|' . $custom_value;
				}
			} else {
				$field = GFFormsModel::get_field( $form, $input_id );
				$value = $this->maybe_get_category_name( $field, $this->get_field_value( $form, $entry, $input_id ) );
			}

			$meta_key = rgar( $mapping, 'key' ) === 'gf_custom' ? rgar( $mapping, 'custom_key', '' ) : $mapping['key'];
			$values[] = array(
				'meta_key'   => $meta_key,
				'meta_value' => $this->filter_prepared_meta_value( $value, $field, $input_id, $meta_key, $mappings, $form, $entry ),
				'value_key'  => $input_id,
			);
		}

		return $values;
	}

	public function get_custom_meta( $feed ) {
		return $this->prepare_dynamic_meta( rgars( $feed, 'meta/userMeta' ) );
	}

	/**
	 * Takes array like:
	 *
	 *  array(
	 *      array(
	 *          'key'        => 'key1',
	 *          'value'      => 'value1',
	 *          'custom_key' => ''
	 *      ),
	 *      array(
	 *          'key'        => '',
	 *          'value'      => 'value2',
	 *          'custom_key' => 'my_custom_key'
	 *      )
	 *  )
	 *
	 * And converts it to:
	 *
	 * array(
	 *      'key1'          => 'value1',
	 *      'my_custom_key' => 'value2'
	 *  )
	 *
	 */
	public function prepare_dynamic_meta( $dyn_meta ) {

		$meta = array();

		if ( empty( $dyn_meta ) ) {
			return $meta;
		}

		foreach ( $dyn_meta as $meta_item ) {
			$meta_key        = rgar( $meta_item, 'key' );
			$meta_value      = rgar( $meta_item, 'value' );
			$custom_meta_key = rgar( $meta_item, 'custom_key' );
			$meta_key        = $custom_meta_key ? $custom_meta_key : $meta_key;

			if ( $meta_value === 'gf_custom' ) {
				$meta_value .= '|' . rgar( $meta_item, 'custom_value', '' );
			}

			$meta[ $meta_key ] = $meta_value;
		}

		return $meta;
	}

	public function update_user_property( $user_id, $prop_key, $prop_value ) {

		if ( ! $user_id ) {
			return false;
		}

		$user = new WP_User( $user_id );

		$new_data            = new stdClass();
		$new_data->ID        = $user->ID;
		$new_data->$prop_key = $prop_value;

		$result = wp_update_user( get_object_vars( $new_data ) );

		return $result;
	}

	public function get_display_name( $user_id, $feed ) {

		$display_format = rgars( $feed, 'meta/displayname' );
		$user           = new WP_User( $user_id );

		switch( $display_format ) {
			case 'firstname':
				$display_name = $user->first_name;
				break;
			case 'lastname':
				$display_name = $user->last_name;
				break;
			case 'firstlast':
				$display_name = $user->first_name . ' ' . $user->last_name;
				break;
			case 'lastfirst':
				$display_name = $user->last_name . ' ' . $user->first_name;
				break;
			case 'nickname':
				$display_name = $user->nickname;
				break;
			default:
				$display_name = $user->user_login;
				break;
		}

		return $display_name;
	}

	public function attribute_post_author( $user_id, $post_id ) {

		$this->log( 'Attributing post to user id: ' . $user_id );

		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return false;
		}

		$post->post_author = $user_id;
		$result            = wp_update_post( $post );

		if ( is_wp_error( $result ) ) {
		$errors = $result->get_error_messages();
			foreach ($errors as $error) {
				$this->log( 'An error occurred while doing author attribution: ' . $error );
			}
		}

		return $result;
	}

	public static function get_user_id_by_meta( $key, $value ) {
		global $wpdb;

		$user = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s", $key, $value ) );

		return $user ? $user : false;
	}

	public static function is_pending_activation_enabled( $feed ) {
		return rgars( $feed, 'meta/userActivationEnable' ) == true;
	}

	public static function get_user_by_entry_id( $entry_id, $id_only = false ) {
		global $wpdb;
		if ( is_multisite() ) {
			$user_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} as meta1
							WHERE ( meta1.meta_key = 'entry_id' OR meta1.meta_key = '_gform-entry-id' OR meta1.meta_key = '_gform-update-entry-id' ) 
							AND ( meta1.meta_value = %d ) 
							AND EXISTS ( SELECT 1 FROM {$wpdb->usermeta} as meta2 WHERE meta1.user_id = meta2.user_id and meta2.meta_key = '" . $wpdb->get_blog_prefix() . "capabilities' )",
					$entry_id
				)
			);
		} else {
			$user_id = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE ( meta_key = 'entry_id' OR meta_key = '_gform-entry-id' OR meta_key = '_gform-update-entry-id' ) AND meta_value = %d LIMIT 1", $entry_id ) );
		}

		if ( ! $user_id ) {
			return false;
		} elseif ( $id_only ) {
			return $user_id;
		}

		$user = new WP_User( $user_id );

		return $user;
	}

	public static function get_site_by_entry_id( $entry_id ) {
		global $wpdb;

		$site_id = $wpdb->get_var( $wpdb->prepare( "SELECT site_id FROM $wpdb->sitemeta WHERE ( meta_key = 'entry_id' OR meta_key = '_gform-entry_id' OR meta_key = '_gform-update-entry-id' ) AND meta_value = %d", $entry_id ) );

		if ( ! $site_id ) {
			$user = self::get_user_by_entry_id( $entry_id );
			if ( $user && ! is_wp_error( $user ) ) {
				$sites = get_blogs_of_user( $user->ID );

				if ( $sites ) {
					foreach ( $sites as $_site_id => $site ) {
						if ( $entry_id == get_blog_option( $_site_id, 'entry_id' ) || $entry_id == get_blog_option( $_site_id, '_gform-entry_id' ) || $entry_id == get_blog_option( $_site_id, '_gform-update-entry-id' ) ) {
							$site_id = $_site_id;

							break;
						}
					}
				}
			}
		}

		return $site_id;
	}

	/**
	 * Used to check if a pending activation exists for the specified user_login or user_email.
	 *
	 * @param string $key user_login or user_email.
	 * @param string $value
	 *
	 * @return bool
	 */
	public function pending_activation_exists( $key, $value ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'signups';

		if ( $this->table_exists( $table_name ) && in_array( $key, array( 'user_login', 'user_email' ) ) ) {
			if ( $key == 'user_login' ) {
				$value = preg_replace( '/\s+/', '', sanitize_user( $value, true ) );
			}

			$signup = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_name} WHERE active=0 AND {$key}=%s", $value ) );
			if ( $signup != null ) {
				$diff = current_time( 'timestamp', true ) - mysql2date( 'U', $signup->registered );

				/**
				 * Allows the expiration period for the pending activation to be overridden.
				 *
				 * @since 4.5
				 *
				 * @param int $expiration_time Expiration time in seconds. Defaults to 172800 (2 days).
				 */
				$expiration = apply_filters( 'gform_user_registration_pending_activation_expiration', 2 * DAY_IN_SECONDS );

				// If registered more than two days ago, cancel registration and let this signup go through.
				if ( $diff > $expiration ) {
					$wpdb->delete( $table_name, array( $key => $value ) );
				} else {
					return true;
				}
			}
		}

		return false;
	}

	public function add_message_once( $message, $is_error = false ) {
		global $ur_messages;

		if( ! is_array( $ur_messages ) ) {
			$ur_messages = array();
		}

		if( in_array( $message, $ur_messages ) ) {
			return false;
		}

		if( $is_error ) {
			GFCommon::add_error_message( $message );
		} else {
			GFCommon::add_message( $message );
		}

		$ur_messages[] = $message;

	}

	public function add_error_message_once( $error ) {
		return $this->add_message_once( $error, true );
	}

	/**
	 * Prevent the multiselect field value being formatted by $field->get_value_export().
	 *
	 * @param array $entry The Entry currently being processed.
	 * @param string $field_id The ID of the Field currently being processed.
	 * @param GF_Field_MultiSelect $field The Field currently being processed.
	 *
	 * @return string
	 */
	public function get_multiselect_field_value( $entry, $field_id, $field ) {

		return rgar( $entry, $field_id );
	}

	/**
	 * Format the number field value according to the format selected on the field.
	 *
	 * @since 3.5.5
	 *
	 * @param array           $entry    The Entry currently being processed.
	 * @param string          $field_id The ID of the Field currently being processed.
	 * @param GF_Field_Number $field    The Field currently being processed.
	 *
	 * @return string
	 */
	public function get_number_field_value( $entry, $field_id, $field ) {

		return $field->get_value_entry_detail( rgar( $entry, $field_id ), rgar( $entry, 'currency' ) );
	}

	public static function maybe_get_category_name( $field, $entry_value ) {

		if ( is_object( $field ) && $field->type == 'post_category' ) {
			if ( is_array( $entry_value ) ) {
				foreach ( $entry_value as &$value ) {
					list( $value, $cat_id ) = explode( ':', $value );
				}
			} else {
				list( $entry_value, $cat_id ) = explode( ':', $entry_value );
			}
		}

		return $entry_value;
	}

	public function supported_notification_events( $form ) {

		$events = array();

		/* If this form does not have any UR feeds, return the events. */
		if ( ! $this->has_feed( $form['id'] ) ) {
			return $events;
		}

		$events['gfur_site_created']            = __( 'Site is created', 'gravityformsuserregistration' );
		$events['gfur_user_activation']         = __( 'User is pending activation', 'gravityformsuserregistration' );
		$events['gfur_user_activated']          = __( 'User is activated', 'gravityformsuserregistration' );
		$events['gfur_user_registered']         = __( 'User is registered', 'gravityformsuserregistration' );
		$events['gfur_user_updated']            = __( 'User is updated', 'gravityformsuserregistration' );
		$events['gfur_user_activation_deleted'] = __( 'User pending activation deleted', 'gravityformsuserregistration' );

		return $events;

	}

	/**
	 * Gets the feed for this entry and passes it through the gform_addon_pre_process_feeds filters.
	 *
	 * @param array $entry The entry currently being processed.
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function get_filtered_single_submission_feed( $entry, $form ) {
		$feed  = $this->get_single_submission_feed( $entry, $form );
		$feeds = $this->pre_process_feeds( array( $feed ), $entry, $form );

		return rgar( $feeds, 0, array() );
	}



	// # MERGE TAGS ----------------------------------------------------------------------------------------------------

	/**
	 * Include UR related merge tags in the merge tag drop downs in the form settings area.
	 *
	 * @deprecated 4.7 No replacement.
	 *
	 * @param array $form The current form object.
	 *
	 * @since 3.4.4 Added support for {set_password_url}
	 * @since 3.2.0
	 *
	 * @return array
	 */
	public function add_merge_tags( $form ) {
		_deprecated_function( __METHOD__, '4.7' );
		return $form;
	}

	/**
	 * Replace the UR merge tags.
	 *
	 * @param string $text The current text in which merge tags are being replaced.
	 * @param array $form The current form object.
	 * @param array $entry The current entry object.
	 * @param bool $url_encode Whether or not to encode any URLs found in the replaced value.
	 * @param bool $esc_html Whether or not to encode HTML found in the replaced value.
	 * @param bool $nl2br Whether or not to convert newlines to break tags.
	 * @param string $format The format requested for the location the merge is being used. Possible values: html, text or url.
	 *
	 * @since 3.4.4 Added support for {set_password_url}
	 * @since 3.2.0
	 *
	 * @return string
	 */
	public function replace_merge_tags( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

		if ( empty( $entry ) || empty( $form ) ) {
			return $text;
		}

		$activation_url_merge_tag = '{activation_url}';
		if ( strpos( $text, $activation_url_merge_tag ) !== false ) {
			$key = gform_get_meta( $entry['id'], 'activation_key' );
			$url = empty( $key ) ? '' : $this->get_activation_url( $key );

			$text = str_replace( $activation_url_merge_tag, $url, $text );
		}

		$set_password_url_merge_tag = '{set_password_url}';
		if ( strpos( $text, $set_password_url_merge_tag ) !== false ) {
			$user = $this->get_user_by_entry_id( $entry['id'] );
			$url  = $user && ! is_wp_error( $user ) ? $this->get_set_password_url( $user ) : '';

			$text = str_replace( $set_password_url_merge_tag, $url, $text );
		}

		return $text;
	}





	// # PERSONAL DATA -------------------------------------------------------------------------------------------------

	/**
	 * Prevent entries with a pending user activation from being automatically deleted.
	 *
	 * @since 3.9.7
	 *
	 * @param array $entry_ids Array of entry IDs to be deleted.
	 *
	 * @return array
	 */
	public function filter_gform_entry_ids_automatic_deletion( $entry_ids = array() ) {

		// Load Pending Activations class.
		if ( ! function_exists( 'gf_pending_activations' ) ) {
			$this->load_pending_activations();
		}

		// Loop through entry IDs.
		foreach ( $entry_ids as $i => $entry_id ) {

			// Get entry object.
			$entry = GFAPI::get_entry( $entry_id );

			// Find pending activations for entry.
			$pending_activations_found = gf_pending_activations()->get_pending_activations( $entry['form_id'], array( 'lead_id' => $entry['id'], 'get_total' => true ) ) > 0;

			// Remove entry from deletion.
			if ( $pending_activations_found ) {
				unset( $entry_ids[ $i ] );
			}

		}

		return $entry_ids;
	}





	// # UPGRADE PAYPAL FEEDS ------------------------------------------------------------------------------------------

	public function upgrade( $previous_version ) {

		if ( empty( $previous_version ) ) {
			$previous_version = get_option( 'gf_user_registration_version' );
		}

		$previous_is_pre_addon_framework = ! empty( $previous_version ) && version_compare( $previous_version, '3.0.dev1', '<' );
		if ( $previous_is_pre_addon_framework ) {
			$this->upgrade_from_pre_addon_framework();
		}

		$previous_is_pre_32 = ! empty( $previous_version ) && version_compare( $previous_version, '3.2dev1', '<' );
		if ( $previous_is_pre_32 ) {
			$this->upgrade_from_pre_32();
		}

		// create signups table for non-multisite installs
		if( ! is_multisite() ) {
			require_once( 'includes/signups.php' );
			GFUserSignups::create_signups_table();
		}

	}

	public function upgrade_from_pre_addon_framework() {

		//get old feeds
		$old_feeds = $this->get_old_feeds();

		if ( $old_feeds ) {

			$counter = 1;
			foreach ( $old_feeds as $old_feed ) {

				$feed_name  = 'Feed ' . $counter;
				$form_id    = $old_feed['form_id'];
				$is_active  = rgar( $old_feed, 'is_active' ) ? '1' : '0';

				$new_meta = array(
					'feedName'             => $feed_name,
					'feedType'             => rgars( $old_feed, 'meta/feed_type' ),
					'username'             => rgars( $old_feed, 'meta/username' ),
					'first_name'           => rgars( $old_feed, 'meta/firstname' ),
					'last_name'            => rgars( $old_feed, 'meta/lastname' ),
					'email'                => rgars( $old_feed, 'meta/email' ),
					'displayname'          => rgars( $old_feed, 'meta/displayname' ),
					'password'             => rgars( $old_feed, 'meta/password' ),
					'role'                 => rgars( $old_feed, 'meta/role' ),
					'sendEmail'            => rgars( $old_feed, 'meta/notification' ),
					'setPostAuthor'        => rgars( $old_feed, 'meta/set_post_author' ),
					'userActivationEnable' => rgars( $old_feed, 'meta/user_activation' ),
					'userActivationValue'  => rgars( $old_feed, 'meta/user_activation_type' ),
					'createSite'           => rgars( $old_feed, 'meta/multisite_options/create_site' ),
					'siteAddress'          => rgars( $old_feed, 'meta/multisite_options/site_address' ),
					'siteTitle'            => rgars( $old_feed, 'meta/multisite_options/site_title' ),
					'siteRole'             => rgars( $old_feed, 'meta/multisite_options/site_role' ),
					'rootRole'             => rgars( $old_feed, 'meta/multisite_options/root_role' )
				);

				$user_meta     = array_filter( (array) rgars( $old_feed, 'meta/user_meta' ) );
				$new_user_meta = array();

				foreach ( $user_meta as $_meta ) {
					if ( $_meta['meta_name'] && $_meta['meta_value'] ) {
						$new_user_meta[] = array(
							'key'        => $_meta['meta_name'],
							'value'      => $_meta['meta_value'],
							'custom_key' => rgar( $_meta, 'custom' ) ? $_meta['meta_name'] : ''
						);
					}
				}

				if ( ! empty( $new_user_meta ) ) {
					$new_meta['userMeta'] = $new_user_meta;
				}

				$bp_meta     = array_filter( (array) rgars( $old_feed, 'meta/buddypress_meta' ) );
				$new_bp_meta = array();

				foreach ( $bp_meta as $_meta ) {
					if ( $_meta['meta_name'] && $_meta['meta_value'] ) {
						$new_bp_meta[] = array(
							'key'        => $_meta['meta_name'],
							'value'      => $_meta['meta_value'],
							'custom_key' => rgar( $_meta, 'custom' ) ? $_meta['meta_name'] : ''
						);
					}
				}

				if ( ! empty( $new_bp_meta ) ) {
					$new_meta['bpMeta'] = $new_bp_meta;
				}

				//add conditional logic, legacy only allowed one condition
				$conditional_enabled = rgars( $old_feed, 'meta/reg_condition_enabled' );
				if ( $conditional_enabled ) {
					$new_meta['feed_condition_conditional_logic']        = 1;
					$new_meta['feed_condition_conditional_logic_object'] = array(
						'conditionalLogic' =>
							array(
								'actionType' => 'show',
								'logicType'  => 'all',
								'rules'      => array(
									array(
										'fieldId'  => rgar( $old_feed['meta'], 'reg_condition_field_id' ),
										'operator' => rgar( $old_feed['meta'], 'reg_condition_operator' ),
										'value'    => rgar( $old_feed['meta'], 'reg_condition_value' )
									),
								)
							)
					);
				} else {
					$new_meta['feed_condition_conditional_logic'] = 0;
				}

				$this->insert_feed( $form_id, $is_active, $new_meta );
				$counter ++;

			}

			// set paypal delay setting
			$this->update_paypal_settings( array(
				"delay_{$this->_slug}"         => 'delay_registration',
				'cancellationActionUserEnable' => 'update_site_action',
				'cancellationActionUserValue'  => 'update_site_action',
				'cancellationActionSiteEnable' => 'update_user_action',
				'cancellationActionSiteValue'  => 'update_user_action'
			) );

		}

	}

	public function update_paypal_settings( $settings ) {
		global $wpdb;

		$this->log( 'Checking to see if there are any settings that need to be migrated for PayPal Standard.' );

		//get paypal feeds from new framework table
		$paypal_feeds = $this->get_feeds_by_slug( 'gravityformspaypal' );
		if ( empty( $paypal_feeds ) ) {
			return;
		}

		$this->log( 'New feeds found for ' . $this->_slug . ' - copying over delay settings.' );

		foreach ( $paypal_feeds as $feed ) {

			$meta            = $feed['meta'];
			$requires_update = false;

			foreach ( $settings as $new_key => $old_key ) {
				if ( isset( $meta[ $old_key ] ) ) {
					$meta[ $new_key ] = $meta[ $old_key ];
					$requires_update  = true;
				}
			}

			if ( $requires_update ) {
				$this->update_feed_meta( $feed['id'], $meta );
			}

		}

	}

	public function get_old_feeds() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'rg_userregistration';
		if ( ! $this->table_exists( $table_name ) ) {
			return false;
		}

		$form_table_name = GFFormsModel::get_form_table_name();
		$sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
							FROM $table_name s
							INNER JOIN $form_table_name f ON s.form_id = f.id";

		$results = $wpdb->get_results( $sql, ARRAY_A );

		$count = sizeof( $results );
		for ( $i = 0; $i < $count; $i ++ ) {
			$results[ $i ]['meta'] = maybe_unserialize( $results[ $i ]['meta'] );
		}

		return $results;
	}

	/**
	 * Upgrade feeds from prior to version 3.2.
	 * (Enables "Send Email?" field to site creation feeds.)
	 *
	 * @access public
	 * @return void
	 */
	public function upgrade_from_pre_32() {

		// Get feeds.
		$feeds = $this->get_feeds();

		foreach ( $feeds as $feed ) {

			$meta            = $feed['meta'];
			$requires_update = false;

			if ( rgar( $meta, 'createSite' ) == '1' ) {
				$meta['sendSiteEmail'] = '1';
				$requires_update       = true;
			}

			if ( $requires_update ) {
				$this->update_feed_meta( $feed['id'], $meta );
			}

		}

	}

	public function define_gf_new_user_notification() {

		if ( ! function_exists( 'gf_new_user_notification' ) ) {

			/**
			 * Overrides wp_new_user_notification to allow sending passwords in plain text
			 *
			 * Forked from WordPress 4.4.1
			 *
			 * @see wp_new_user_notification()
			 * @see GF_User_Registration->log_wp_mail()
			 *
			 * @param int    $user_id        The ID of the user that the notification is being sent to.
			 * @param string $plaintext_pass The password being sent to the user.
			 * @param string $notify         Whether the admin should be notified.
			 *                               If 'admin', only the admin. If 'both', user and admin.
			 */
			function gf_new_user_notification( $user_id, $plaintext_pass = '', $notify = '' ) {
				$user = get_userdata( $user_id );

				// The blogname option is escaped with esc_html on the way into the database in sanitize_option
				// we want to reverse this for the plain text arena of emails.
				$blogname = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

				$message = sprintf( __( 'New user registration on your site %s:' ), $blogname ) . "\r\n\r\n";
				$message .= sprintf( __( 'Username: %s' ), $user->user_login ) . "\r\n\r\n";
				$message .= sprintf( __( 'Email: %s' ), $user->user_email ) . "\r\n";

				$result = @wp_mail( get_option( 'admin_email' ), sprintf( __( '[%s] New User Registration' ), $blogname ), $message );
				gf_user_registration()->log_wp_mail( $result, 'admin' );

				if ( 'admin' === $notify || ( empty( $plaintext_pass ) && empty( $notify ) ) ) {
					return;
				}

				$message = sprintf( __( 'Username: %s' ), $user->user_login ) . "\r\n\r\n";

				if ( empty( $plaintext_pass ) ) {
					$message .= __( 'To set your password, visit the following address:' ) . "\r\n\r\n";
					$message .= '<' . gf_user_registration()->get_set_password_url( $user ) . ">\r\n\r\n";
				} else {
					$message .= sprintf( __( 'Password: %s' ), $plaintext_pass ) . "\r\n\r\n";
				}

				$message .= wp_login_url() . "\r\n";

				$result = wp_mail( $user->user_email, sprintf( __( '[%s] Your username and password info' ), $blogname ), $message );
				gf_user_registration()->log_wp_mail( $result, 'user' );
			}

		}

	}

	/**
	 * Retrieve the set password url for the specified user.
	 *
	 *
	 * @param WP_User $user The user object.
	 *
	 * @since 3.4.4.
	 * @since 4.6 Updated to use get_password_reset_key().
	 *
	 * @return string
	 */
	public function get_set_password_url( $user ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return '';
		}

		return network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' );
	}

	/**
	 * Tweak field inputs.
	 *
	 * @since 4.0.2
	 *
	 * @param string   $input   The markup. Defaults to an empty string.
	 * @param GF_Field $field   The Field Object.
	 * @param mixed    $value   The field value.
	 * @param int      $lead_id The entry ID.
	 * @param int      $form_id The form ID.
	 *
	 * @return string
	 */
	public function maybe_update_field_input( $input, $field, $value, $lead_id, $form_id ) {
		if ( ! $this->is_updateable_consent_field( $field, $value ) ) {
			return $input;
		}

		$feed = $this->get_update_feed( $form_id );
		if ( ! $feed ) {
			return $input;
		}

		$form    = GFAPI::get_form( $form_id );
		$user_id = $this->get_update_user_id( $form, $feed );
		if ( ! $user_id ) {
			return $input;
		}

		$entry = $this->get_entry_by_user_id( $user_id );
		if ( is_wp_error( $entry ) ) {
			return $input;
		}

		// Get consent inputs values from entry meta, since it's not stored in the user meta.
		$value["{$field->id}.1"] = '1';
		$value["{$field->id}.2"] = rgar( $entry, "{$field->id}.2" );
		$value["{$field->id}.3"] = rgar( $entry, "{$field->id}.3" );

		return $field->get_field_input( $form, $value, $entry );
	}

	/**
	 * Determines if this is a consent field which may require additional processing.
	 *
	 * @since 4.7
	 *
	 * @param GF_Field $field The field being prepared for display.
	 * @param mixed    $value The field value.
	 *
	 * @return bool
	 */
	private function is_updateable_consent_field( $field, $value ) {
		if ( $field->get_input_type() !== 'consent' || $field->is_entry_detail() || $field->is_form_editor() ) {
			return false;
		}

		return ! isset( $_POST["input_{$field->id}_1"] ) && rgar( $value, "{$field->id}.1" ) === esc_html__( 'Checked', 'gravityforms' );
	}

	/**
	 * Returns the ID of the user to be processed by the update type feed.
	 *
	 * @since 4.7
	 *
	 * @param array $form  The form currently being processed.
	 * @param array $feed  The feed currently being processed.
	 * @param array $entry An empty array or the entry currently being processed.
	 *
	 * @return int
	 */
	public function get_update_user_id( $form, $feed, $entry = array() ) {
		$user_id = (int) rgar( $entry, 'created_by' );
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		/**
		 * Allows the ID of the user to be updated to be overridden.
		 *
		 * @since 3.0
		 *
		 * @param int   $user_id The ID of the user to be updated.
		 * @param array $entry   An empty array or the entry currently being processed.
		 * @param array $form    The form currently being processed.
		 * @param array $feed    The feed currently being processed.
		 */
		return (int) gf_apply_filters( array( 'gform_user_registration_update_user_id', (int) rgar( $form, 'id' ) ), $user_id, $entry, $form, $feed );
	}

	/**
	 * Returns the entry which updated or created the specified user.
	 *
	 * @since 4.7
	 *
	 * @param int $user_id The ID of the user updated or created by the entry to be retrieved.
	 *
	 * @return array|WP_Error
	 */
	public function get_entry_by_user_id( $user_id ) {
		$entry_id = get_user_meta( $user_id, '_gform-update-entry-id', true );
		if ( ! $entry_id ) {
			$entry_id = get_user_meta( $user_id, '_gform-entry-id', true );
		}

		return GFAPI::get_entry( $entry_id );
	}

	/**
	 * Initializing translations.
	 *
	 * @todo remove once min GF version reaches 2.0.7.
	 */
	public function load_text_domain() {
		GFCommon::load_gf_text_domain( $this->_slug, plugin_basename( dirname( $this->_full_path ) ) );
	}


	// # DEPRECATED ----------------------------------------------------------------------------------------------------

	public static function get_active_config( $form, $entry = false ) {

		_deprecated_function( __FUNCTION__, '3.0', 'get_single_submission_feed' );

		return gf_user_registration()->get_single_submission_feed( $entry, $form );
	}

	public static function get_config( $form_id ) {

		_deprecated_function( __FUNCTION__, '3.0', 'get_feeds' );

		$feeds = gf_user_registration()->get_feeds( $form_id );
		$feed  = false;

		if ( is_array( $feeds ) ) {
			$feed = array_shift( $feeds );
		}

		return $feed;
	}

	public static function add_validation_failure( $field_id, $form, $message ) {

		_deprecated_function( __FUNCTION__, '3.0', 'add_validation_error' );

		return gf_user_registration()->add_validation_error( $field_id, $form, $message );
	}

	public static function create_new_multisite( $user_id, $feed, $entry, $password ) {

		_deprecated_function( __FUNCTION__, '3.0', 'create_site' );

		return gf_user_registration()->create_site( $user_id, $feed, $entry, $password );
	}

	public static function get_pending_activation_forms() {

		_deprecated_function( __FUNCTION__, '3.0' );

		$forms = GFFormsModel::get_forms( null, 'title' );
		$feeds = gf_user_registration()->get_feeds();

		$available_form_ids = array();
		foreach ( $feeds as $feed ) {
			if ( self::is_pending_activation_enabled( $feed ) ) {
				$available_form_ids[] = $feed['form_id'];
			}
		}

		$available_form_ids = array_unique( $available_form_ids );

		$available_forms = array();
		foreach ( $forms as $form ) {
			if ( in_array( $form->id, $available_form_ids ) ) {
				$available_forms[] = $form;
			}
		}

		return $available_forms;
	}

	public static function gf_create_user( $entry, $form ) {

		_deprecated_function( __FUNCTION__, '3.0', 'maybe_process_feed' );

		return gf_user_registration()->maybe_process_feed( $entry, $form );
	}

	public static function get_gravityforms_db_version() {

		if ( method_exists( 'GFFormsModel', 'get_database_version' ) ) {
			$db_version = GFFormsModel::get_database_version();
		} else {
			$db_version = GFForms::$version;
		}

		return $db_version;
	}

}

class GFUser extends GF_User_Registration {}
