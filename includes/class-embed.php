<?php
/**
 * Fetches Accesstive app HTML/CSS/JS for AJAX embedding.
 *
 * @package AccesstiveApp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Accesstive_App_Embed {

	const TRANSIENT_PREFIX = 'accesstive_app_asset_';
	const CACHE_GROUP_KEY  = 'accesstive_app_embed_payload';
	const EMBED_CACHE_TTL  = HOUR_IN_SECONDS;
	const ASSET_CACHE_TTL  = 43200; // 12 hours.

	public function register_hooks() {
		add_action( 'wp_ajax_accesstive_fetch_app', array( $this, 'ajax_fetch_app' ) );
		add_action( 'wp_ajax_accesstive_app_asset', array( $this, 'ajax_serve_asset' ) );
	}

	public function ajax_fetch_app() {
		check_ajax_referer( 'accesstive_app_bridge', 'nonce' );

		if ( ! current_user_can( Accesstive_App_Plugin::get_capability() ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'web-accessibility-by-accesstive' ) ),
				403
			);
		}

		$payload = $this->get_embed_payload();
		if ( is_wp_error( $payload ) ) {
			wp_send_json_error(
				array( 'message' => $payload->get_error_message() ),
				502
			);
		}

		wp_send_json_success( $payload );
	}

	public function ajax_serve_asset() {
		if ( ! current_user_can( Accesstive_App_Plugin::get_capability() ) ) {
			status_header( 403 );
			exit;
		}

		$key   = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $key || ! wp_verify_nonce( $nonce, 'accesstive_app_asset_' . $key ) ) {
			status_header( 403 );
			exit;
		}

		$asset = $this->load_asset( $key );
		if ( ! is_array( $asset ) || empty( $asset['body'] ) ) {
			status_header( 404 );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $asset['body'];
		exit;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get_embed_payload() {
		$app_url = Accesstive_App_Plugin::get_app_url();
		if ( '' === $app_url || ! Accesstive_App_Security::is_allowed_app_asset_url( $app_url ) ) {
			return new WP_Error(
				'accesstive_app_url_invalid',
				__( 'Accesstive app URL is not configured or not allowed.', 'web-accessibility-by-accesstive' )
			);
		}

		$site_url  = Accesstive_App_Plugin::get_site_url();
		$cache_key = self::CACHE_GROUP_KEY . '_' . md5( $app_url . '|' . $site_url . '|' . ACCESSTIVE_APP_VERSION );

		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && ! empty( $cached['scripts'] ) ) {
			return $this->refresh_script_nonces( $cached );
		}

		$fetch_url = Accesstive_App_Security::sanitize_remote_url(
			add_query_arg( array( 'domain' => $site_url ), $app_url ),
			Accesstive_App_Security::DEFAULT_APP_HOSTS
		);

		if ( '' === $fetch_url ) {
			return new WP_Error(
				'accesstive_app_url_invalid',
				__( 'Accesstive app URL failed security validation.', 'web-accessibility-by-accesstive' )
			);
		}

		$html = $this->remote_get_body( $fetch_url );
		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$base   = untrailingslashit( $app_url );
		$parsed = $this->parse_document( $html, $base );

		$styles = array();
		foreach ( $parsed['stylesheets'] as $href ) {
			if ( Accesstive_App_Security::is_allowed_font_stylesheet_url( $href ) ) {
				$styles[] = array(
					'type' => 'link',
					'href' => esc_url_raw( $href ),
				);
				continue;
			}

			if ( ! Accesstive_App_Security::is_allowed_app_asset_url( $href ) ) {
				continue;
			}

			$css = $this->remote_get_body( $href );
			if ( is_wp_error( $css ) ) {
				return new WP_Error(
					'accesstive_app_css_failed',
					__( 'Failed to load an Accesstive stylesheet.', 'web-accessibility-by-accesstive' )
				);
			}

			$styles[] = array(
				'type' => 'inline',
				'css'  => $this->rewrite_css_urls( $css, $base ),
			);
		}

		foreach ( $parsed['inline_styles'] as $css ) {
			$styles[] = array(
				'type' => 'inline',
				'css'  => $this->rewrite_css_urls( $css, $base ),
			);
		}

		$scripts = array();
		foreach ( $parsed['scripts'] as $script ) {
			if ( empty( $script['src'] ) || ! Accesstive_App_Security::is_allowed_app_asset_url( $script['src'] ) ) {
				continue;
			}

			$code = $this->remote_get_body( $script['src'] );
			if ( is_wp_error( $code ) ) {
				return new WP_Error(
					'accesstive_app_js_failed',
					__( 'Failed to load the Accesstive application script.', 'web-accessibility-by-accesstive' )
				);
			}

			$code = $this->patch_embed_script( $code );
			$key  = $this->store_asset( $code );

			if ( '' === $key ) {
				return new WP_Error(
					'accesstive_app_store_failed',
					__( 'Could not cache the Accesstive application script.', 'web-accessibility-by-accesstive' )
				);
			}

			$scripts[] = array(
				'type' => ! empty( $script['module'] ) ? 'module' : 'classic',
				'key'  => $key,
				'src'  => $this->asset_proxy_url( $key ),
			);
		}

		if ( empty( $scripts ) ) {
			return new WP_Error(
				'accesstive_app_no_scripts',
				__( 'No allowlisted Accesstive application scripts were found.', 'web-accessibility-by-accesstive' )
			);
		}

		$payload = array(
			'styles'  => $styles,
			'scripts' => $scripts,
		);

		set_transient( $cache_key, $payload, self::EMBED_CACHE_TTL );

		return $payload;
	}

	/**
	 * @param array<string,mixed> $payload Cached payload.
	 * @return array<string,mixed>
	 */
	private function refresh_script_nonces( array $payload ) {
		if ( empty( $payload['scripts'] ) || ! is_array( $payload['scripts'] ) ) {
			return $payload;
		}

		foreach ( $payload['scripts'] as $i => $script ) {
			if ( empty( $script['key'] ) ) {
				continue;
			}
			$key = sanitize_key( $script['key'] );
			$payload['scripts'][ $i ]['key'] = $key;
			$payload['scripts'][ $i ]['src'] = $this->asset_proxy_url( $key );
		}

		return $payload;
	}

	/**
	 * @param string $body JavaScript source.
	 * @return string Asset key or empty on failure.
	 */
	private function store_asset( $body ) {
		$key   = md5( 'application/javascript|' . $body );
		$asset = array(
			'body'         => $body,
			'content_type' => 'application/javascript',
		);

		if ( strlen( $body ) < 900000 ) {
			if ( set_transient( self::TRANSIENT_PREFIX . $key, $asset, self::ASSET_CACHE_TTL ) ) {
				return $key;
			}
		}

		if ( false === add_option( self::TRANSIENT_PREFIX . $key, $asset, '', 'no' ) ) {
			update_option( self::TRANSIENT_PREFIX . $key, $asset, false );
		}

		return $key;
	}

	/**
	 * @param string $key Asset key.
	 * @return array{body:string,content_type:string}|null
	 */
	private function load_asset( $key ) {
		$key   = sanitize_key( $key );
		$asset = get_transient( self::TRANSIENT_PREFIX . $key );

		if ( is_array( $asset ) && ! empty( $asset['body'] ) ) {
			return $asset;
		}

		$asset = get_option( self::TRANSIENT_PREFIX . $key, null );
		if ( is_array( $asset ) && ! empty( $asset['body'] ) ) {
			return $asset;
		}

		return null;
	}

	/**
	 * @param string $key Asset key.
	 * @return string
	 */
	private function asset_proxy_url( $key ) {
		$key = sanitize_key( $key );

		return add_query_arg(
			array(
				'action' => 'accesstive_app_asset',
				'key'    => $key,
				'nonce'  => wp_create_nonce( 'accesstive_app_asset_' . $key ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * @param string $url Absolute URL.
	 * @return string|\WP_Error
	 */
	private function remote_get_body( $url ) {
		if ( ! Accesstive_App_Security::is_allowed_app_asset_url( $url ) ) {
			return new WP_Error(
				'accesstive_app_url_blocked',
				__( 'Blocked a remote URL that is not on the Accesstive allowlist.', 'web-accessibility-by-accesstive' )
			);
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Accept'     => '*/*',
					'User-Agent' => 'AccesstiveAppWordPress/' . ACCESSTIVE_APP_VERSION,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'accesstive_app_fetch_failed',
				__( 'Could not reach the Accesstive app. Check that your server can access app.accesstive.com.', 'web-accessibility-by-accesstive' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'accesstive_app_fetch_http',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Accesstive app returned HTTP %d.', 'web-accessibility-by-accesstive' ),
					$code
				)
			);
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * @param string $html Raw HTML.
	 * @param string $base App origin without trailing slash.
	 * @return array{stylesheets:string[],inline_styles:string[],scripts:array<int,array<string,mixed>>}
	 */
	private function parse_document( $html, $base ) {
		$stylesheets   = array();
		$inline_styles = array();
		$scripts       = array();

		if ( preg_match_all( '/<link\b[^>]*>/i', $html, $link_matches ) ) {
			foreach ( $link_matches[0] as $tag ) {
				if ( ! preg_match( '/\brel\s*=\s*([\'"])stylesheet\1/i', $tag ) ) {
					continue;
				}
				if ( ! preg_match( '/\bhref\s*=\s*([\'"])([^\'"]+)\1/i', $tag, $href_match ) ) {
					continue;
				}
				$abs = $this->absolutize_url( $href_match[2], $base );
				if ( '' !== $abs ) {
					$stylesheets[] = $abs;
				}
			}
		}

		if ( preg_match_all( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $style_matches ) ) {
			foreach ( $style_matches[1] as $css ) {
				$css = trim( $css );
				if ( '' !== $css ) {
					$inline_styles[] = $css;
				}
			}
		}

		if ( preg_match_all( '/<script\b([^>]*)>(.*?)<\/script>/is', $html, $script_matches, PREG_SET_ORDER ) ) {
			foreach ( $script_matches as $match ) {
				$attrs  = $match[1];
				$module = (bool) preg_match( '/\btype\s*=\s*([\'"])module\1/i', $attrs );
				$src    = '';

				if ( preg_match( '/\bsrc\s*=\s*([\'"])([^\'"]+)\1/i', $attrs, $src_match ) ) {
					$src = $this->absolutize_url( $src_match[2], $base );
				}

				if ( '' === $src ) {
					continue;
				}

				$scripts[] = array(
					'src'    => $src,
					'module' => $module,
				);
			}
		}

		return array(
			'stylesheets'   => $stylesheets,
			'inline_styles' => $inline_styles,
			'scripts'       => $scripts,
		);
	}

	/**
	 * @param string $url  Candidate URL.
	 * @param string $base App origin without trailing slash.
	 * @return string
	 */
	private function absolutize_url( $url, $base ) {
		$url = trim( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5 ) );

		if ( '' === $url || 0 === stripos( $url, 'javascript:' ) || 0 === stripos( $url, 'data:' ) ) {
			return '';
		}

		if ( preg_match( '#^(https?:)?//#i', $url ) ) {
			if ( 0 === strpos( $url, '//' ) ) {
				$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
				$url    = ( $scheme ? $scheme : 'https' ) . ':' . $url;
			}
			return $url;
		}

		if ( isset( $url[0] ) && '/' === $url[0] ) {
			return $base . $url;
		}

		return trailingslashit( $base ) . ltrim( $url, '/' );
	}

	/**
	 * @param string $css  Stylesheet contents.
	 * @param string $base App origin without trailing slash.
	 * @return string
	 */
	private function rewrite_css_urls( $css, $base ) {
		$css = preg_replace_callback(
			'#url\(\s*([\'"]?)(/[^)\'"]*)\1\s*\)#i',
			static function ( $matches ) use ( $base ) {
				$asset = $base . $matches[2];
				if ( ! Accesstive_App_Security::is_allowed_app_asset_url( $asset ) ) {
					return 'url()';
				}
				return 'url(' . $matches[1] . $asset . $matches[1] . ')';
			},
			$css
		);

		$imports = array();
		$css     = preg_replace_callback(
			'/@import\s+[^;]+;/i',
			function ( $matches ) use ( &$imports ) {
				$rule = $matches[0];
				if ( preg_match( '#https?://[^\'"\s)]+#i', $rule, $url_match )
					&& Accesstive_App_Security::is_allowed_font_stylesheet_url( $url_match[0] ) ) {
					$imports[] = $rule;
				}
				return '';
			},
			$css
		);

		$scoped = implode( "\n", $imports ) . "\n@scope (.accesstive-app-embed) {\n" . $css . "\n}\n";
		$scoped = preg_replace( '/(^|[,\\s{])body(\\s*[,{])/i', '$1.accesstive-app-embed$2', $scoped );
		$scoped = preg_replace( '/(^|[,\\s{])html(\\s*[,{])/i', '$1.accesstive-app-embed$2', $scoped );

		return is_string( $scoped ) ? $scoped : $css;
	}

	/**
	 * @param string $code JavaScript source.
	 * @return string
	 */
	private function patch_embed_script( $code ) {
		$replacements = array(
			'function Av(){if(typeof window>"u")return!1;try{return window.parent!==window}catch{return!0}}'
				=> 'function Av(){return!0}',
			'function Ei(){if(du)return du;try{const a=window.location.ancestorOrigins;if(a&&a.length>0)return a[0]}catch{}if(document.referrer)try{return new URL(document.referrer).origin}catch{return""}return""}'
				=> 'function Ei(){return window.location.origin}',
			'Qh=()=>location.pathname'
				=> 'Qh=()=>"/"',
		);

		foreach ( $replacements as $search => $replace ) {
			$pos = strpos( $code, $search );
			if ( false !== $pos ) {
				$code = substr_replace( $code, $replace, $pos, strlen( $search ) );
			}
		}

		return $code;
	}
}
