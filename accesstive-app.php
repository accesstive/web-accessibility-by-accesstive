<?php
/**
 * Plugin Name: Web Accessibility Compliance Management by Accesstive: WCAG, ADA, EAA & BFSG Support
 * Plugin URI: https://accesstive.com/integration/wordpress/
 * Description: Find, fix and prove website accessibility with a WCAG 2.2 checker and free toolkit. Support ADA, AODA, EAA & BFSG.
 * Version: 1.0.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Accesstive
 * Author URI: https://accesstive.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: web-accessibility-by-accesstive
 * Domain Path: /languages
 *
 * @package AccesstiveApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACCESSTIVE_APP_VERSION', '1.0.3' );
define( 'ACCESSTIVE_APP_FILE', __FILE__ );
define( 'ACCESSTIVE_APP_PATH', plugin_dir_path( __FILE__ ) );
define( 'ACCESSTIVE_APP_DEFAULT_URL', 'https://app.accesstive.com/' );

require_once ACCESSTIVE_APP_PATH . 'includes/class-security.php';
require_once ACCESSTIVE_APP_PATH . 'includes/class-toolkit.php';
require_once ACCESSTIVE_APP_PATH . 'includes/class-embed.php';

final class Accesstive_App_Plugin {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @var Accesstive_App_Toolkit
	 */
	private $toolkit;

	/**
	 * @var Accesstive_App_Embed
	 */
	private $embed;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->toolkit = new Accesstive_App_Toolkit();
		$this->toolkit->register_hooks();

		$this->embed = new Accesstive_App_Embed();
		$this->embed->register_hooks();

		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ACCESSTIVE_APP_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function plugin_action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=accesstive-app' ) ) . '">' . esc_html__( 'Open App', 'web-accessibility-by-accesstive' ) . '</a>'
		);
		return $links;
	}

	/**
	 * @return string
	 */
	public static function get_capability() {
		return apply_filters( 'accesstive_app_capability', 'manage_options' );
	}

	/**
	 * @return string
	 */
	public static function get_app_url() {
		$url = apply_filters( 'accesstive_app_url', ACCESSTIVE_APP_DEFAULT_URL );
		$safe = Accesstive_App_Security::sanitize_embed_url( $url );

		return '' !== $safe ? $safe : Accesstive_App_Security::sanitize_embed_url( ACCESSTIVE_APP_DEFAULT_URL );
	}

	/**
	 * @return string
	 */
	public static function get_site_url() {
		return apply_filters( 'accesstive_app_site_url', home_url( '/' ) );
	}

	public function register_admin_menu() {
		add_menu_page(
			__( 'Accesstive', 'web-accessibility-by-accesstive' ),
			__( 'Accesstive', 'web-accessibility-by-accesstive' ),
			self::get_capability(),
			'accesstive-app',
			array( $this, 'render_admin_page' ),
			'dashicons-universal-access-alt',
			30
		);
	}

	/**
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( 'toplevel_page_accesstive-app' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'accesstive-app-admin',
			plugins_url( 'assets/css/admin.css', __FILE__ ),
			array(),
			ACCESSTIVE_APP_VERSION
		);

		wp_enqueue_script(
			'accesstive-app-bridge',
			plugins_url( 'assets/js/admin-bridge.js', __FILE__ ),
			array(),
			ACCESSTIVE_APP_VERSION,
			true
		);

		wp_localize_script(
			'accesstive-app-bridge',
			'AccesstiveAppBridge',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'accesstive_app_bridge' ),
				'toolkitActive' => $this->toolkit->is_installed(),
				'siteUrl'       => self::get_site_url(),
				'platform'      => 'wordpress',
				'i18n'          => array(
					'installFailed'        => __( 'Install failed.', 'web-accessibility-by-accesstive' ),
					'installRequestFailed' => __( 'Install request failed.', 'web-accessibility-by-accesstive' ),
					'loadFailed'           => __( 'Failed to load Accesstive app.', 'web-accessibility-by-accesstive' ),
				),
			)
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( self::get_capability() ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'web-accessibility-by-accesstive' ) );
		}
		?>
		<div class="accesstive-app-wrap">
			<div
				id="accesstive-app-embed"
				class="accesstive-app-embed"
				role="region"
				aria-label="<?php esc_attr_e( 'Accesstive', 'web-accessibility-by-accesstive' ); ?>"
				aria-busy="true"
			>
				<div class="accesstive-app-loading">
					<?php esc_html_e( 'Loading Accesstive…', 'web-accessibility-by-accesstive' ); ?>
				</div>
			</div>
		</div>
		<?php
	}
}

Accesstive_App_Plugin::instance();
