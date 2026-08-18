<?php
/**
 * URL and token validation helpers.
 *
 * @package AccesstiveApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Accesstive_App_Security {

	const DEFAULT_API_URL    = 'https://dashboard.accesstive.com';
	const DEFAULT_CDN_HOSTS  = array( 'cdn.accesstive.com' );
	const DEFAULT_APP_HOSTS  = array( 'app.accesstive.com' );
	const DEFAULT_API_HOSTS  = array( 'dashboard.accesstive.com' );
	const DEFAULT_FONT_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	public static function get_api_base_url() {
		if ( defined( 'ACCESSTIVE_APP_API_URL' ) && is_string( ACCESSTIVE_APP_API_URL ) && ACCESSTIVE_APP_API_URL !== '' ) {
			$url = ACCESSTIVE_APP_API_URL;
		} else {
			$url = apply_filters( 'accesstive_app_api_url', self::DEFAULT_API_URL );
		}

		$safe = self::sanitize_remote_url( (string) $url, self::DEFAULT_API_HOSTS );
		return '' === $safe ? '' : untrailingslashit( $safe );
	}

	public static function sanitize_widget_token( $token ) {
		$token = sanitize_text_field( (string) $token );

		if ( ! preg_match( '/^[A-Za-z0-9._-]{8,255}$/', $token ) ) {
			return '';
		}

		return $token;
	}

	public static function sanitize_embed_url( $url ) {
		$safe = self::sanitize_remote_url( $url, self::DEFAULT_APP_HOSTS );
		return '' === $safe ? '' : trailingslashit( $safe );
	}

	public static function is_allowed_app_asset_url( $url ) {
		return '' !== self::sanitize_remote_url( $url, self::DEFAULT_APP_HOSTS );
	}

	public static function is_allowed_font_stylesheet_url( $url ) {
		$safe = self::sanitize_remote_url( $url, self::DEFAULT_FONT_HOSTS );
		if ( '' === $safe ) {
			return false;
		}

		$host = strtolower( (string) wp_parse_url( $safe, PHP_URL_HOST ) );
		if ( 'fonts.googleapis.com' === $host ) {
			$path = (string) wp_parse_url( $safe, PHP_URL_PATH );
			return (bool) preg_match( '#^/css2?(/|$)#i', $path );
		}

		return true;
	}

	public static function sanitize_cdn_script_url( $url ) {
		$allowed = apply_filters( 'accesstive_app_cdn_allowed_hosts', self::DEFAULT_CDN_HOSTS );
		$safe    = self::sanitize_remote_url( $url, array_map( 'strtolower', (array) $allowed ) );

		if ( '' === $safe ) {
			return '';
		}

		$path = (string) wp_parse_url( $safe, PHP_URL_PATH );
		return ( '' === $path || '/' === $path ) ? '' : $safe;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function validate_token_with_api( $token ) {
		if ( ! apply_filters( 'accesstive_app_validate_token_remotely', true ) ) {
			return true;
		}

		$base = self::get_api_base_url();
		if ( '' === $base ) {
			return new WP_Error(
				'accesstive_app_api_missing',
				__( 'Accesstive API URL is not configured or failed security validation.', 'web-accessibility-by-accesstive' )
			);
		}

		$endpoint = $base . '/api/v2/toolkit/html';
		if ( ! self::sanitize_remote_url( $endpoint, self::DEFAULT_API_HOSTS ) ) {
			return new WP_Error(
				'accesstive_app_api_invalid',
				__( 'Accesstive API URL is not allowed.', 'web-accessibility-by-accesstive' )
			);
		}

		$response = wp_remote_get(
			$endpoint,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'accesstive_app_api_unreachable',
				__( 'Could not verify the Accesstive token. Check that your server can reach the Accesstive API.', 'web-accessibility-by-accesstive' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			return new WP_Error(
				'accesstive_app_token_invalid',
				__( 'This Accesstive token is not valid. Check your Accesstive account and try again.', 'web-accessibility-by-accesstive' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'accesstive_app_api_error',
				__( 'Accesstive token verification failed. Try again later.', 'web-accessibility-by-accesstive' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new WP_Error(
				'accesstive_app_token_invalid',
				__( 'This Accesstive token could not be verified.', 'web-accessibility-by-accesstive' )
			);
		}

		return true;
	}

	/**
	 * @param string   $url           Candidate URL.
	 * @param string[] $allowed_hosts Allowed hostnames.
	 * @return string
	 */
	public static function sanitize_remote_url( $url, array $allowed_hosts ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parsed['scheme'] );
		$host   = strtolower( (string) $parsed['host'] );

		if ( 'https' !== $scheme ) {
			return '';
		}

		if ( ! in_array( $host, array_map( 'strtolower', $allowed_hosts ), true ) ) {
			return '';
		}

		if ( ! empty( $parsed['user'] ) || ! empty( $parsed['pass'] ) ) {
			return '';
		}

		$normalized = esc_url_raw( $url, array( 'https' ) );
		if ( '' === $normalized ) {
			return '';
		}

		if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $normalized ) ) {
			return '';
		}

		return $normalized;
	}
}
