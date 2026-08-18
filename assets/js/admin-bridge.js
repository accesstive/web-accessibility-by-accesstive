( function () {
	'use strict';

	if ( typeof AccesstiveAppBridge === 'undefined' ) {
		return;
	}

	var i18n = AccesstiveAppBridge.i18n || {};
	var ajaxUrl = AccesstiveAppBridge.ajaxUrl || '';
	var pageOrigin = window.location.origin;

	function getEmbed() {
		return document.getElementById( 'accesstive-app-embed' );
	}

	function getAjaxMessage( json ) {
		if ( json && json.data && json.data.message ) {
			return json.data.message;
		}
		if ( json && json.message ) {
			return json.message;
		}
		return '';
	}

	function postToApp( payload ) {
		try {
			window.postMessage( payload, pageOrigin );
		} catch ( e ) {
			// Ignore.
		}
	}

	function notifyHostReady() {
		postToApp( {
			type: 'ACCESSTIVE_HOST_READY',
			siteUrl: AccesstiveAppBridge.siteUrl || '',
			platform: AccesstiveAppBridge.platform || 'wordpress',
			toolkitActive: !! AccesstiveAppBridge.toolkitActive,
		} );
	}

	function scheduleHostReady() {
		var delays = [ 0, 50, 150, 400, 1000, 2000, 4000, 8000 ];
		delays.forEach( function ( delay ) {
			window.setTimeout( notifyHostReady, delay );
		} );
	}

	function installToolkit( token ) {
		var body = new URLSearchParams();
		body.append( 'action', 'accesstive_install_toolkit' );
		body.append( 'nonce', AccesstiveAppBridge.nonce );
		body.append( 'token', token );

		return fetch( AccesstiveAppBridge.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function toolkitStatus() {
		if ( AccesstiveAppBridge.toolkitActive ) {
			return Promise.resolve( {
				success: true,
				data: {
					installed: true,
					siteUrl: AccesstiveAppBridge.siteUrl || '',
				},
			} );
		}

		var body = new URLSearchParams();
		body.append( 'action', 'accesstive_toolkit_status' );
		body.append( 'nonce', AccesstiveAppBridge.nonce );

		return fetch( AccesstiveAppBridge.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function showError( message ) {
		var embed = getEmbed();
		if ( ! embed ) {
			return;
		}
		embed.setAttribute( 'aria-busy', 'false' );
		while ( embed.firstChild ) {
			embed.removeChild( embed.firstChild );
		}
		var err = document.createElement( 'p' );
		err.className = 'accesstive-app-error';
		err.textContent = message || i18n.loadFailed || 'Failed to load Accesstive app.';
		embed.appendChild( err );
	}

	function isAllowedFontHref( href ) {
		if ( ! href || typeof href !== 'string' ) {
			return false;
		}
		try {
			var u = new URL( href, window.location.href );
			if ( u.protocol !== 'https:' ) {
				return false;
			}
			var host = u.hostname.toLowerCase();
			if ( host === 'fonts.googleapis.com' ) {
				return /^\/css2?(\/|$)/i.test( u.pathname );
			}
			return host === 'fonts.gstatic.com';
		} catch ( e ) {
			return false;
		}
	}

	function isAllowedScriptSrc( src ) {
		if ( ! src || typeof src !== 'string' || ! ajaxUrl ) {
			return false;
		}
		try {
			var scriptUrl = new URL( src, window.location.href );
			var bridgeUrl = new URL( ajaxUrl, window.location.href );
			if ( scriptUrl.origin !== bridgeUrl.origin ) {
				return false;
			}
			if ( scriptUrl.pathname !== bridgeUrl.pathname ) {
				return false;
			}
			return scriptUrl.searchParams.get( 'action' ) === 'accesstive_app_asset';
		} catch ( e ) {
			return false;
		}
	}

	function injectStyles( styles ) {
		if ( ! Array.isArray( styles ) ) {
			return;
		}

		styles.forEach( function ( style ) {
			if ( ! style || typeof style !== 'object' ) {
				return;
			}

			if ( style.type === 'link' && style.href && isAllowedFontHref( style.href ) ) {
				var link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = style.href;
				link.setAttribute( 'data-accesstive-embed-style', '1' );
				document.head.appendChild( link );
				return;
			}

			if ( style.type === 'inline' && typeof style.css === 'string' && style.css ) {
				var tag = document.createElement( 'style' );
				tag.setAttribute( 'data-accesstive-embed-style', '1' );
				tag.appendChild( document.createTextNode( style.css ) );
				document.head.appendChild( tag );
			}
		} );
	}

	function injectScripts( scripts ) {
		if ( ! Array.isArray( scripts ) ) {
			return Promise.resolve();
		}

		var chain = Promise.resolve();

		scripts.forEach( function ( script ) {
			if ( ! script || ! script.src || ! isAllowedScriptSrc( script.src ) ) {
				return;
			}

			chain = chain.then( function () {
				return new Promise( function ( resolve, reject ) {
					var el = document.createElement( 'script' );
					el.setAttribute( 'data-accesstive-embed-script', '1' );

					if ( script.type === 'module' ) {
						el.type = 'module';
					}

					var settled = false;
					function done( ok ) {
						if ( settled ) {
							return;
						}
						settled = true;
						if ( ok ) {
							resolve();
						} else {
							reject( new Error( i18n.loadFailed || 'Failed to load Accesstive app.' ) );
						}
					}

					el.onload = function () {
						done( true );
					};
					el.onerror = function () {
						done( false );
					};

					el.src = script.src;
					document.body.appendChild( el );

					window.setTimeout( function () {
						done( true );
					}, 15000 );
				} );
			} );
		} );

		return chain;
	}

	function mountRoot( embed ) {
		while ( embed.firstChild ) {
			embed.removeChild( embed.firstChild );
		}
		var root = document.createElement( 'div' );
		root.id = 'root';
		embed.appendChild( root );
		embed.setAttribute( 'aria-busy', 'false' );
	}

	function mountApp( payload ) {
		var embed = getEmbed();
		if ( ! embed ) {
			return Promise.reject( new Error( i18n.loadFailed || 'Failed to load Accesstive app.' ) );
		}

		injectStyles( payload.styles || [] );
		mountRoot( embed );

		return injectScripts( payload.scripts || [] ).then( function () {
			scheduleHostReady();
		} );
	}

	function fetchApp() {
		var body = new URLSearchParams();
		body.append( 'action', 'accesstive_fetch_app' );
		body.append( 'nonce', AccesstiveAppBridge.nonce );

		return fetch( AccesstiveAppBridge.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
			},
			body: body.toString(),
		} ).then( function ( res ) {
			return res.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success || ! json.data ) {
				throw new Error( getAjaxMessage( json ) || i18n.loadFailed || 'Failed to load Accesstive app.' );
			}
			return json.data;
		} );
	}

	function handleBridgeMessage( event ) {
		if ( event.origin !== pageOrigin ) {
			return;
		}

		if ( event.source && event.source !== window ) {
			return;
		}

		var data = event.data;
		if ( ! data || typeof data.type !== 'string' ) {
			return;
		}

		if ( data.type === 'ACCESSTIVE_INSTALL_TOOLKIT' ) {
			var token = typeof data.token === 'string' ? data.token : '';

			installToolkit( token )
				.then( function ( json ) {
					var msg = getAjaxMessage( json );
					var installed = !!( json.data && json.data.installed );

					if ( json.success && installed ) {
						AccesstiveAppBridge.toolkitActive = true;
					}

					postToApp( {
						type: 'ACCESSTIVE_INSTALL_TOOLKIT_RESULT',
						success: !! json.success,
						message: msg || ( json.success ? '' : ( i18n.installFailed || 'Install failed.' ) ),
						installed: installed,
					} );
				} )
				.catch( function () {
					postToApp( {
						type: 'ACCESSTIVE_INSTALL_TOOLKIT_RESULT',
						success: false,
						message: i18n.installRequestFailed || 'Install request failed.',
						installed: false,
					} );
				} );
			return;
		}

		if ( data.type === 'ACCESSTIVE_TOOLKIT_STATUS' ) {
			toolkitStatus()
				.then( function ( json ) {
					postToApp( {
						type: 'ACCESSTIVE_TOOLKIT_STATUS_RESULT',
						success: !! json.success,
						installed: !!( json.data && json.data.installed ),
						siteUrl: json.data && json.data.siteUrl ? json.data.siteUrl : '',
					} );
				} )
				.catch( function () {
					postToApp( {
						type: 'ACCESSTIVE_TOOLKIT_STATUS_RESULT',
						success: false,
						installed: false,
						siteUrl: '',
					} );
				} );
		}
	}

	window.addEventListener( 'message', handleBridgeMessage );

	fetchApp()
		.then( mountApp )
		.catch( function ( err ) {
			showError( err && err.message ? err.message : ( i18n.loadFailed || 'Failed to load Accesstive app.' ) );
		} );
} )();
