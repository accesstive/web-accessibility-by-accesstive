<?php
/**
 * Toolkit install, token storage, and frontend injection.
 *
 * @package AccesstiveApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Accesstive_App_Toolkit {

	const OPTION_TOKEN   = 'accesstive_app_token';
	const CDN_SCRIPT_URL = 'https://cdn.accesstive.com/assistance.js';
	const SCRIPT_HANDLE  = 'accesstive-app-assistance';

	public function register_hooks() {
		add_action( 'wp_ajax_accesstive_install_toolkit', array( $this, 'ajax_install_toolkit' ) );
		add_action( 'wp_ajax_accesstive_toolkit_status', array( $this, 'ajax_toolkit_status' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_toolkit_script' ) );
	}

	public function ajax_install_toolkit() {
		check_ajax_referer( 'accesstive_app_bridge', 'nonce' );

		if ( ! current_user_can( Accesstive_App_Plugin::get_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'web-accessibility-by-accesstive' ) ),
				403
			);
		}

		$raw_token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$token     = Accesstive_App_Security::sanitize_widget_token( $raw_token );

		if ( '' === $token ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: minimum token length */
						__( 'Invalid Accesstive token (expected %d+ characters).', 'web-accessibility-by-accesstive' ),
						8
					),
				),
				400
			);
		}

		$verified = Accesstive_App_Security::validate_token_with_api( $token );
		if ( is_wp_error( $verified ) ) {
			wp_send_json_error(
				array( 'message' => $verified->get_error_message() ),
				400
			);
		}

		update_option( self::OPTION_TOKEN, $token, false );

		wp_send_json_success(
			array(
				'message'   => __( 'Toolkit installed successfully.', 'web-accessibility-by-accesstive' ),
				'installed' => true,
			)
		);
	}

	public function ajax_toolkit_status() {
		check_ajax_referer( 'accesstive_app_bridge', 'nonce' );

		if ( ! current_user_can( Accesstive_App_Plugin::get_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'web-accessibility-by-accesstive' ) ),
				403
			);
		}

		wp_send_json_success(
			array(
				'installed' => $this->is_installed(),
				'siteUrl'   => Accesstive_App_Plugin::get_site_url(),
			)
		);
	}

	public function enqueue_toolkit_script() {
		if ( is_admin() ) {
			return;
		}

		if ( ! apply_filters( 'accesstive_app_should_inject_toolkit', true ) ) {
			return;
		}

		if ( ! empty( $GLOBALS['accesstive_app_toolkit_injected'] ) ) {
			return;
		}

		$token = self::get_stored_token();
		if ( '' === $token ) {
			return;
		}

		$script_url = apply_filters( 'accesstive_app_cdn_script_url', self::CDN_SCRIPT_URL );
		$script_url = Accesstive_App_Security::sanitize_cdn_script_url( $script_url );

		if ( '' === $script_url ) {
			$script_url = Accesstive_App_Security::sanitize_cdn_script_url( self::CDN_SCRIPT_URL );
		}

		if ( '' === $script_url ) {
			return;
		}

		$GLOBALS['accesstive_app_toolkit_injected'] = true;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			$script_url,
			array(),
			ACCESSTIVE_APP_VERSION,
			false
		);

		add_filter( 'script_loader_tag', array( $this, 'filter_toolkit_script_tag' ), 10, 3 );
	}

	/**
	 * @param string $tag    Script HTML.
	 * @param string $handle Script handle.
	 * @param string $src    Script URL.
	 * @return string
	 */
	public function filter_toolkit_script_tag( $tag, $handle, $src ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( self::SCRIPT_HANDLE !== $handle ) {
			return $tag;
		}

		$token = self::get_stored_token();
		if ( '' === $token ) {
			return $tag;
		}

		$attrs = sprintf(
			' async type="module" data-token="%s"',
			esc_attr( $token )
		);

		return str_replace( ' src=', $attrs . ' src=', $tag );
	}

	public function is_installed() {
		return '' !== self::get_stored_token();
	}

	/**
	 * @return string
	 */
	private static function get_stored_token() {
		return Accesstive_App_Security::sanitize_widget_token(
			(string) get_option( self::OPTION_TOKEN, '' )
		);
	}
}
