<?php
/**
 * GravityView Admin notices
 *
 * @package   GravityView
 * @license   GPL2+
 * @author    Katz Web Services, Inc.
 * @link      http://gravityview.co
 * @copyright Copyright 2015, Katz Web Services, Inc.
 *
 * @since 1.12
 */

/**
 * When the plugin is activated, flush dismissed notices
 * @since 1.15.1
 */
register_activation_hook( GRAVITYVIEW_FILE, array( 'GravityView_Admin_Notices', 'flush_dismissed_notices' ) );

/**
 * Handle displaying and storing of admin notices for GravityView
 * @since 1.12
 */
class GravityView_Admin_Notices {

	/**
	 * @var array
	 */
	static private $admin_notices = array();

	static private $dismissed_notices = array();

	function __construct() {

		$this->add_hooks();
	}

	function add_hooks() {
		add_action( 'network_admin_notices', array( $this, 'dismiss_notice' ), 50 );
		add_action( 'admin_notices', array( $this, 'dismiss_notice' ), 50 );
		add_action( 'admin_notices', array( $this, 'admin_notice' ), 100 );
		add_action( 'network_admin_notices', array( $this, 'admin_notice' ), 100 );
	}

	/**
	 * Clear out the dismissed notices when the plugin gets activated
	 * @see register_activation_hook
	 * @since 1.15.1
	 * @return void
	 */
	static public function flush_dismissed_notices() {
		delete_transient( 'gravityview_dismissed_notices' );
	}

	/**
	 * Dismiss a GravityView notice - stores the dismissed notices for 16 weeks
	 * @since 1.12
	 * @return void
	 */
	public function dismiss_notice() {

		// No dismiss sent
		if( empty( $_GET['gv-dismiss'] ) ) {
			return;
		}

		// Invalid nonce
		if( !wp_verify_nonce( $_GET['gv-dismiss'], 'dismiss' ) ) {
			return;
		}

		$notice_id = esc_attr( $_GET['notice'] );

		//don't display a message if use has dismissed the message for this version
		$dismissed_notices = (array)get_transient( 'gravityview_dismissed_notices' );

		$dismissed_notices[] = $notice_id;

		$dismissed_notices = array_unique( $dismissed_notices );

		// Remind users every 16 weeks
		set_transient( 'gravityview_dismissed_notices', $dismissed_notices, WEEK_IN_SECONDS * 16 );

	}

	/**
	 * Has the notice been dismissed already in the admin?
	 *
	 * If the passed notice array has a `dismiss` key, the notice is dismissable. If it's dismissable,
	 * we check against other notices that have already been dismissed.
	 * @since 1.12
	 * @see GravityView_Admin_Notices::dismiss_notice()
	 * @see GravityView_Admin_Notices::add_notice()
	 * @param  string $notice            Notice array, set using `add_notice()`.
	 * @return boolean                   True: show notice; False: hide notice
	 */
	private function is_notice_dismissed( $notice ) {

		// There are no dismissed notices.
		if( empty( self::$dismissed_notices ) ) {
			return false;
		}

		// Has the
		$is_dismissed = !empty( $notice['dismiss'] ) && in_array( $notice['dismiss'], self::$dismissed_notices );

		return $is_dismissed ? true : false;
	}

	/**
	 * Get admin notices
	 * @since 1.12
	 * @return array
	 */
	public static function get_notices() {
		return self::$admin_notices;
	}

	/**
	 * Handle whether to display notices in Multisite based on plugin activation status
	 *
	 * @uses GravityView_Plugin::is_network_activated
	 *
	 * @since 1.12
	 *
	 * @return bool True: show the notices; false: don't show
	 */
	private function check_show_multisite_notices() {

		if( ! is_multisite() ) {
			return true;
		}

		// It's network activated but the user can't manage network plugins; they can't do anything about it.
		if( GravityView_Plugin::is_network_activated() && ! is_main_site() ) {
			return false;
		}

		// or they don't have admin capabilities
		if( ! is_super_admin() ) {
			return false;
		}

		return true;
	}

	/**
	 * Outputs the admin notices generated by the plugin
	 *
	 * @uses GVCommon::has_cap()
	 * @since 1.12
	 *
	 * @return void
	 */
	public function admin_notice() {

		/**
		 * @filter `gravityview/admin/notices` Modify the notices displayed in the admin
		 * @since 1.12
		 */
		$notices = apply_filters( 'gravityview/admin/notices', self::$admin_notices );

		if( empty( $notices ) || ! $this->check_show_multisite_notices() ) {
			return;
		}

		//don't display a message if use has dismissed the message for this version
		// TODO: Use get_user_meta instead of get_transient
		self::$dismissed_notices = isset( $_GET['show-dismissed-notices'] ) ? array() : (array)get_transient( 'gravityview_dismissed_notices' );

		$output = '';

		foreach( $notices as $notice ) {

			// If the user doesn't have the capability to see the warning
			if( isset( $notice['cap'] ) && false === GVCommon::has_cap( $notice['cap'] ) ) {
				do_action( 'gravityview_log_debug', 'Notice not shown because user does not have the capability to view it.', $notice );
				continue;
			}

			if( true === $this->is_notice_dismissed( $notice ) ) {
				do_action( 'gravityview_log_debug', 'Notice not shown because the notice has already been dismissed.', $notice );
				continue;
			}

			$output .= '<div id="message" style="position:relative" class="notice '. gravityview_sanitize_html_class( $notice['class'] ).'">';

			// Too cute to leave out.
			$output .= gravityview_get_floaty();

			if( !empty( $notice['title'] ) ) {
				$output .= '<h3>'.esc_html( $notice['title'] ) .'</h3>';
			}

			$message = isset( $notice['message'] ) ? $notice['message'] : '';

			if( !empty( $notice['dismiss'] ) ) {

				$dismiss = esc_attr($notice['dismiss']);

				$url = esc_url( add_query_arg( array( 'gv-dismiss' => wp_create_nonce( 'dismiss' ), 'notice' => $dismiss ) ) );

				$align = is_rtl() ? 'alignleft' : 'alignright';
				$message .= '<a href="'.$url.'" data-notice="'.$dismiss.'" class="' . $align . ' button button-link">'.esc_html__('Dismiss', 'gravityview' ).'</a></p>';
			}

			$output .= wpautop( $message );

			$output .= '<div class="clear"></div>';
			$output .= '</div>';

		}

		echo $output;

		unset( $output, $align, $message, $notices );

		//reset the notices handler
		self::$admin_notices = array();
	}

	/**
	 * Add a notice to be displayed in the admin.
	 * @since 1.12 Moved from {@see GravityView_Admin::add_notice() }
	 * @since 1.15.1 Allows for `cap` key, passing capability required to show the message
	 * @param array $notice {
	 *      @type string       $class    HTML class to be used for the notice. Default: 'error'
	 *      @type string       $message  Notice message, not escaped. Allows HTML.
	 *      @type string       $dismiss  Unique key used to determine whether the notice has been dismissed. Set to false if not dismissable.
	 *      @type string|array $cap      The capability or caps required for an user to see the notice
	 * }
	 * @return void
	 */
	public static function add_notice( $notice = array() ) {

		if( !isset( $notice['message'] ) ) {
			do_action( 'gravityview_log_error', 'GravityView_Admin[add_notice] Notice not set', $notice );
			return;
		}

		$notice['class'] = empty( $notice['class'] ) ? 'error' : $notice['class'];

		self::$admin_notices[] = $notice;
	}
}

new GravityView_Admin_Notices;
