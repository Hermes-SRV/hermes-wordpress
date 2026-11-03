<?php
/**
 * Plugin Name: Hermes Connector
 * Plugin URI:  https://github.com/Hermes-SRV/hermes-wordpress
 * Description: Connects WordPress to a Hermes web widget inbox through API v2 and identifies logged-in users. Based on the Chatwoot Plugin for WordPress.
 * Version:     1.0.0
 * Requires at least: 5.7
 * Requires PHP: 7.4
 * Author:      Hermes
 * Author URI:  https://github.com/Hermes-SRV
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hermes-connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Derived from chatwoot/wp-plugin v0.2.2 (GPL-2.0-or-later). See README.md for
 * the differences from the upstream plugin.
 */
final class Hermes_Connector {
	public const VERSION = '1.0.0';
	public const OPTION = 'hermes_connector';
	public const TOKEN_OPTION = 'hermes_connector_api_token';
	public const INBOXES_TRANSIENT = 'hermes_connector_inboxes';
	public const SEARCH_PATH = '/api/v2/inbox/web-widget/search';
	public const IDENTIFIER_PREFIX = 'wordpress';

	/** True while this class saves values that were already sanitized. */
	private static $saving_sanitized = false;

	private const PAGE_SLUG = 'hermes-connector';
	private const NOTICE_TRANSIENT_PREFIX = 'hermes_connector_notice_';
	private const PAGE_LIMIT = 100;
	private const MAX_PAGES = 10;
	private const LOCALES = array(
		'ar'    => 'العربية (ar)',
		'ca'    => 'Català (ca)',
		'cs'    => 'čeština (cs)',
		'da'    => 'dansk (da)',
		'de'    => 'Deutsch (de)',
		'el'    => 'ελληνικά (el)',
		'en'    => 'English (en)',
		'es'    => 'Español (es)',
		'fa'    => 'فارسی (fa)',
		'fi'    => 'suomi, suomen kieli (fi)',
		'fr'    => 'Français (fr)',
		'hi'    => 'हिन्दी (hi)',
		'hu'    => 'magyar nyelv (hu)',
		'id'    => 'Bahasa Indonesia (id)',
		'it'    => 'Italiano (it)',
		'ja'    => '日本語 (ja)',
		'ko'    => '한국어 (ko)',
		'ml'    => 'മലയാളം (ml)',
		'nl'    => 'Nederlands (nl)',
		'no'    => 'norsk (no)',
		'pl'    => 'język polski (pl)',
		'pt_BR' => 'Português Brasileiro (pt-BR)',
		'pt'    => 'Português (pt)',
		'ro'    => 'Română (ro)',
		'ru'    => 'русский (ru)',
		'sv'    => 'Svenska (sv)',
		'ta'    => 'தமிழ் (ta)',
		'tr'    => 'Türkçe (tr)',
		'vi'    => 'Tiếng Việt (vi)',
		'zh_CN' => '中文 (zh-CN)',
		'zh_TW' => '中文 (台湾) (zh-TW)',
		'zh'    => '中文 (zh)',
	);

	/** Register the WordPress hooks. */
	public static function bootstrap() {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_widget' ) );
		add_action( 'admin_menu', array( self::class, 'add_settings_page' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin_styles' ) );
		add_action( 'admin_notices', array( self::class, 'render_upstream_notice' ) );
		add_action( 'admin_post_hermes_connect', array( self::class, 'handle_connect' ) );
		add_action( 'admin_post_hermes_select_inbox', array( self::class, 'handle_select_inbox' ) );
	}

	/** Copy the upstream plugin settings once. The upstream options stay unchanged. */
	public static function activate() {
		if ( false !== get_option( self::OPTION, false ) ) {
			return;
		}

		add_option(
			self::OPTION,
			self::sanitize_stored(
				array(
					'url'           => (string) get_option( 'chatwootSiteURL', '' ),
					'website_token' => (string) get_option( 'chatwootSiteToken', '' ),
					'locale'        => (string) get_option( 'chatwootWidgetLocale', 'auto' ),
					'widget_type'   => (string) get_option( 'chatwootWidgetType', 'standard' ),
					'position'      => (string) get_option( 'chatwootWidgetPosition', 'right' ),
					'launcher_text' => (string) get_option( 'chatwootLauncherText', '' ),
					'logged_in_only' => '1' === get_option( 'chatwootLoggedInOnly', '0' ),
				)
			)
		);
	}

	/** Default values for each stored setting. */
	public static function defaults() {
		return array(
			'url'            => '',
			'website_token'  => '',
			'hmac_token'     => '',
			'inbox_id'       => '',
			'inbox_name'     => '',
			'locale'         => 'auto',
			'widget_type'    => 'standard',
			'position'       => 'right',
			'launcher_text'  => '',
			'logged_in_only' => false,
			'identify_users' => true,
			'share_email'    => false,
		);
	}

	/** Read the stored settings with defaults for missing keys. */
	public static function settings() {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Accept HTTPS, or HTTP only for loopback development. The API token travels
	 * to this address, so any other address is rejected and returns ''.
	 */
	public static function normalize_url( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

		return "{$scheme}://{$host}{$port}{$path}";
	}

	/** Use the selected locale, or map the site locale when the mode is automatic. */
	public static function resolve_locale( $selected, $site_locale ) {
		if ( isset( self::LOCALES[ $selected ] ) ) {
			return $selected;
		}

		$site_locale = (string) $site_locale;
		if ( isset( self::LOCALES[ $site_locale ] ) ) {
			return $site_locale;
		}

		$language = strtok( $site_locale, '_' );

		return isset( self::LOCALES[ $language ] ) ? $language : 'en';
	}

	/**
	 * Build the signed widget identity.
	 *
	 * Hermes merges contacts that share an email address. WordPress does not
	 * always verify email addresses, so the address is sent only when the site
	 * owner enables it. Otherwise a user could claim the conversations of
	 * another contact by entering that contact's address.
	 */
	public static function build_identity( $user_id, $display_name, $email, $site_host, $hmac_token, $share_email ) {
		$identifier = implode( ':', array( self::IDENTIFIER_PREFIX, $site_host, (int) $user_id ) );
		$user       = array(
			'name'            => (string) $display_name,
			'identifier_hash' => hash_hmac( 'sha256', $identifier, (string) $hmac_token ),
		);
		if ( $share_email && '' !== (string) $email ) {
			$user['email'] = (string) $email;
		}

		return array(
			'identifier' => $identifier,
			'user'       => $user,
		);
	}

	/**
	 * Convert a decoded search response into validated inbox items.
	 *
	 * @return array|WP_Error Page data with items and count.
	 */
	public static function parse_search_response( $status, $body ) {
		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'hermes_unauthorized', __( 'Hermes rejected the API token.', 'hermes-connector' ) );
		}
		if ( 404 === $status ) {
			return new WP_Error( 'hermes_endpoint_missing', __( 'This Hermes installation does not have the web widget API. Update Hermes or check the URL.', 'hermes-connector' ) );
		}

		$decoded = json_decode( (string) $body, true );
		if ( 200 !== $status || ! is_array( $decoded ) || true !== ( $decoded['ok'] ?? null ) || ! is_array( $decoded['data']['items'] ?? null ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'hermes_invalid_response', sprintf( __( 'Hermes returned an unexpected response (HTTP %d).', 'hermes-connector' ), (int) $status ) );
		}

		$items = array();
		foreach ( $decoded['data']['items'] as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['website_token'] ) ) {
				continue;
			}
			$items[] = array(
				'id'              => (string) $item['id'],
				'name'            => (string) ( $item['name'] ?? '' ),
				'account_name'    => (string) ( $item['account']['name'] ?? '' ),
				'website_token'   => (string) $item['website_token'],
				'hmac_token'      => (string) ( $item['hmac_token'] ?? '' ),
				'allowed_domains' => (string) ( $item['allowed_domains'] ?? '' ),
			);
		}

		return array(
			'items' => $items,
			'count' => (int) ( $decoded['data']['count'] ?? count( $items ) ),
		);
	}

	/** An empty allow-list permits every domain, the same as the Hermes widget. */
	public static function domain_allowed( $allowed_domains, $host ) {
		$allowed_domains = trim( (string) $allowed_domains );
		if ( '' === $allowed_domains ) {
			return true;
		}

		foreach ( explode( ',', $allowed_domains ) as $domain ) {
			$domain = strtolower( preg_replace( '#^https?://#i', '', trim( $domain ) ) );
			if ( $domain === $host || '*' === $domain ) {
				return true;
			}
			if ( 0 === strpos( $domain, '*.' ) && substr( $host, -strlen( $domain ) + 1 ) === substr( $domain, 1 ) ) {
				return true;
			}
		}

		return false;
	}

	/** Load the widget on public pages. */
	public static function enqueue_widget() {
		$settings = self::settings();
		if ( '' === $settings['url'] || '' === $settings['website_token'] ) {
			return;
		}
		// Two active plugins would render two chat bubbles.
		if ( function_exists( 'chatwoot_load' ) ) {
			return;
		}
		if ( $settings['logged_in_only'] && ! is_user_logged_in() ) {
			return;
		}

		wp_enqueue_script(
			'hermes-widget',
			plugins_url( 'js/hermes-widget.js', __FILE__ ),
			array(),
			self::VERSION,
			true
		);
		wp_add_inline_script(
			'hermes-widget',
			'window.hermesWidget = ' . wp_json_encode( self::widget_config( $settings ) ) . ';',
			'before'
		);
	}

	/** Public widget configuration. It never contains the API token or HMAC token. */
	public static function widget_config( $settings ) {
		$config = array(
			'baseUrl'      => $settings['url'],
			'websiteToken' => $settings['website_token'],
			'settings'     => array(
				'locale'        => self::resolve_locale( $settings['locale'], get_locale() ),
				'type'          => $settings['widget_type'],
				'position'      => $settings['position'],
				'launcherTitle' => $settings['launcher_text'],
			),
			'identity'     => null,
		);

		if ( $settings['identify_users'] && '' !== $settings['hmac_token'] && is_user_logged_in() ) {
			$user     = wp_get_current_user();
			$identity = self::build_identity(
				$user->ID,
				$user->display_name,
				$user->user_email,
				(string) wp_parse_url( home_url(), PHP_URL_HOST ),
				$settings['hmac_token'],
				$settings['share_email']
			);

			/**
			 * Filter the signed identity sent to the widget.
			 * Do not change `identifier` without also recomputing `identifier_hash`.
			 */
			$config['identity'] = apply_filters( 'hermes_connector_identity', $identity, $user );
		}

		return $config;
	}

	/** Add the settings page. */
	public static function add_settings_page() {
		add_options_page(
			__( 'Hermes Connector', 'hermes-connector' ),
			__( 'Hermes Connector', 'hermes-connector' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_settings_page' )
		);
	}

	/** Load the styles only on the settings page. */
	public static function enqueue_admin_styles( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'hermes-connector-admin', plugins_url( 'admin.css', __FILE__ ), array(), self::VERSION );
	}

	/** Register the widget form. */
	public static function register_settings() {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_widget_form' ),
				'default'           => self::defaults(),
			)
		);
	}

	/** Normalize a complete settings array. */
	public static function sanitize_stored( $values ) {
		$values = array_merge( self::defaults(), $values );

		return array(
			'url'            => self::normalize_url( $values['url'] ),
			'website_token'  => sanitize_text_field( $values['website_token'] ),
			'hmac_token'     => sanitize_text_field( $values['hmac_token'] ),
			'inbox_id'       => sanitize_text_field( $values['inbox_id'] ),
			'inbox_name'     => sanitize_text_field( $values['inbox_name'] ),
			'locale'         => isset( self::LOCALES[ $values['locale'] ] ) ? $values['locale'] : 'auto',
			'widget_type'    => 'expanded_bubble' === $values['widget_type'] ? 'expanded_bubble' : 'standard',
			'position'       => 'left' === $values['position'] ? 'left' : 'right',
			'launcher_text'  => sanitize_text_field( $values['launcher_text'] ),
			'logged_in_only' => (bool) $values['logged_in_only'],
			'identify_users' => (bool) $values['identify_users'],
			'share_email'    => (bool) $values['share_email'],
		);
	}

	/**
	 * Apply the widget form. Connection values stay unchanged because this form
	 * does not contain them. An empty HMAC field keeps the stored secret.
	 */
	public static function sanitize_widget_form( $input ) {
		// update_option also runs this callback, and connection values are not form input.
		if ( self::$saving_sanitized ) {
			return $input;
		}

		$current = self::settings();
		$input   = is_array( $input ) ? $input : array();

		$next = array_merge(
			$current,
			array(
				'website_token'  => $input['website_token'] ?? $current['website_token'],
				'locale'         => $input['locale'] ?? $current['locale'],
				'widget_type'    => $input['widget_type'] ?? $current['widget_type'],
				'position'       => $input['position'] ?? $current['position'],
				'launcher_text'  => $input['launcher_text'] ?? $current['launcher_text'],
				'logged_in_only' => ! empty( $input['logged_in_only'] ),
				'identify_users' => ! empty( $input['identify_users'] ),
				'share_email'    => ! empty( $input['share_email'] ),
			)
		);

		if ( ! empty( $input['hmac_token'] ) ) {
			$next['hmac_token'] = $input['hmac_token'];
		}
		if ( $next['website_token'] !== $current['website_token'] ) {
			$next['inbox_id']   = '';
			$next['inbox_name'] = '';
		}

		return self::sanitize_stored( $next );
	}

	/** Store settings from the connection handlers. */
	private static function save_settings( $settings ) {
		self::$saving_sanitized = true;
		update_option( self::OPTION, self::sanitize_stored( $settings ) );
		self::$saving_sanitized = false;
	}

	/** Save the connection and load the web widget inboxes from Hermes. */
	public static function handle_connect() {
		self::authorize_request( 'hermes_connect' );

		$settings = self::settings();
		$url      = self::normalize_url( wp_unslash( $_POST['url'] ?? '' ) );
		if ( '' === $url ) {
			self::redirect_with_notice( 'error', __( 'Enter a valid HTTPS Hermes URL.', 'hermes-connector' ) );
		}

		// Credentials of one server must never be sent to another server.
		if ( $url !== $settings['url'] ) {
			delete_option( self::TOKEN_OPTION );
			delete_transient( self::INBOXES_TRANSIENT );
			$settings = array_merge( $settings, array( 'website_token' => '', 'hmac_token' => '', 'inbox_id' => '', 'inbox_name' => '' ) );
		}
		$settings['url'] = $url;
		self::save_settings( $settings );

		if ( ! empty( $_POST['remove_token'] ) ) {
			delete_option( self::TOKEN_OPTION );
			delete_transient( self::INBOXES_TRANSIENT );
			self::redirect_with_notice( 'success', __( 'The API token was removed. The widget keeps its current inbox.', 'hermes-connector' ) );
		}

		$new_token = sanitize_text_field( wp_unslash( $_POST['api_token'] ?? '' ) );
		if ( '' !== $new_token ) {
			// Not autoloaded: public page loads do not need this credential.
			delete_option( self::TOKEN_OPTION );
			add_option( self::TOKEN_OPTION, $new_token, '', 'no' );
		}

		$token = (string) get_option( self::TOKEN_OPTION, '' );
		if ( '' === $token ) {
			self::redirect_with_notice( 'success', __( 'The URL was saved. Enter an API token to load the inboxes.', 'hermes-connector' ) );
		}

		$inboxes = self::fetch_inboxes( $url, $token );
		if ( is_wp_error( $inboxes ) ) {
			self::redirect_with_notice( 'error', $inboxes->get_error_message() );
		}

		$public = array();
		foreach ( $inboxes as $inbox ) {
			$public[] = array(
				'id'           => $inbox['id'],
				'name'         => $inbox['name'],
				'account_name' => $inbox['account_name'],
			);
		}
		set_transient( self::INBOXES_TRANSIENT, $public, DAY_IN_SECONDS );

		self::redirect_with_notice(
			'success',
			sprintf(
				/* translators: %d: number of inboxes. */
				_n( 'Connected. %d web widget inbox found.', 'Connected. %d web widget inboxes found.', count( $public ), 'hermes-connector' ),
				count( $public )
			)
		);
	}

	/** Copy the widget credentials of the selected inbox from Hermes. */
	public static function handle_select_inbox() {
		self::authorize_request( 'hermes_select_inbox' );

		$settings = self::settings();
		$token    = (string) get_option( self::TOKEN_OPTION, '' );
		$inbox_id = sanitize_text_field( wp_unslash( $_POST['inbox_id'] ?? '' ) );
		if ( '' === $token || '' === $inbox_id ) {
			self::redirect_with_notice( 'error', __( 'Connect to Hermes and select an inbox.', 'hermes-connector' ) );
		}

		$inboxes = self::fetch_inboxes( $settings['url'], $token );
		if ( is_wp_error( $inboxes ) ) {
			self::redirect_with_notice( 'error', $inboxes->get_error_message() );
		}

		foreach ( $inboxes as $inbox ) {
			if ( $inbox['id'] !== $inbox_id ) {
				continue;
			}

			$settings = array_merge(
				$settings,
				array(
					'inbox_id'      => $inbox['id'],
					'inbox_name'    => $inbox['name'],
					'website_token' => $inbox['website_token'],
					'hmac_token'    => $inbox['hmac_token'],
				)
			);
			self::save_settings( $settings );

			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! self::domain_allowed( $inbox['allowed_domains'], $host ) ) {
				self::redirect_with_notice(
					'warning',
					/* translators: %s: site host name. */
					sprintf( __( 'The inbox was saved, but its allowed domains do not include %s. The widget will not open until you add this domain in Hermes.', 'hermes-connector' ), $host )
				);
			}
			self::redirect_with_notice( 'success', __( 'The widget now uses the selected inbox.', 'hermes-connector' ) );
		}

		self::redirect_with_notice( 'error', __( 'Hermes did not return the selected inbox. Connect again.', 'hermes-connector' ) );
	}

	/**
	 * Load all web widget inboxes that the token can administer.
	 *
	 * @return array|WP_Error
	 */
	private static function fetch_inboxes( $url, $token ) {
		$items = array();
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			// wp_safe_remote_post blocks private addresses. Redirects are off so
			// that the token cannot be forwarded to another host.
			$response = wp_safe_remote_post(
				$url . self::SEARCH_PATH,
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'headers'     => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body'        => wp_json_encode(
						array(
							'search' => array(
								'page'  => $page,
								'limit' => self::PAGE_LIMIT,
							),
						)
					),
				)
			);
			if ( is_wp_error( $response ) ) {
				/* translators: %s: transport error. */
				return new WP_Error( 'hermes_unreachable', sprintf( __( 'Could not reach Hermes: %s', 'hermes-connector' ), $response->get_error_message() ) );
			}

			$parsed = self::parse_search_response( (int) wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_body( $response ) );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}

			$items = array_merge( $items, $parsed['items'] );
			if ( empty( $parsed['items'] ) || $page * self::PAGE_LIMIT >= $parsed['count'] ) {
				break;
			}
		}

		return $items;
	}

	/** Warn while the upstream plugin is active because it blocks this widget. */
	public static function render_upstream_notice() {
		if ( ! function_exists( 'chatwoot_load' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Hermes Connector does not load its widget while the Chatwoot Plugin is active. Deactivate the Chatwoot Plugin to use it.', 'hermes-connector' ) . '</p></div>';
	}

	/** Render the settings page. */
	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings  = self::settings();
		$has_token = '' !== (string) get_option( self::TOKEN_OPTION, '' );
		$inboxes   = get_transient( self::INBOXES_TRANSIENT );
		$inboxes   = is_array( $inboxes ) ? $inboxes : array();
		$name      = self::OPTION;
		$notice    = self::take_notice();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hermes Connector', 'hermes-connector' ); ?></h1>
			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hermes--form">
				<h2><?php esc_html_e( 'Connection', 'hermes-connector' ); ?></h2>
				<input type="hidden" name="action" value="hermes_connect" />
				<?php wp_nonce_field( 'hermes_connect' ); ?>
				<div class="form--input">
					<label for="hermes-url"><?php esc_html_e( 'Hermes URL', 'hermes-connector' ); ?></label>
					<input id="hermes-url" type="url" name="url" required value="<?php echo esc_attr( $settings['url'] ); ?>" />
				</div>
				<div class="form--input">
					<label for="hermes-api-token"><?php esc_html_e( 'API token', 'hermes-connector' ); ?></label>
					<input id="hermes-api-token" type="password" name="api_token" autocomplete="off" value="" placeholder="<?php echo esc_attr( $has_token ? __( 'Stored. Leave empty to keep it.', 'hermes-connector' ) : '' ); ?>" />
					<p class="description"><?php esc_html_e( 'Use a token of an account administrator. It is sent only to the Hermes URL above.', 'hermes-connector' ); ?></p>
				</div>
				<?php if ( $has_token ) : ?>
					<div class="form--input">
						<label><input type="checkbox" name="remove_token" value="1" /> <?php esc_html_e( 'Remove the stored API token', 'hermes-connector' ); ?></label>
					</div>
				<?php endif; ?>
				<?php submit_button( __( 'Connect to Hermes', 'hermes-connector' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( $has_token && $inboxes ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hermes--form">
					<h2><?php esc_html_e( 'Inbox', 'hermes-connector' ); ?></h2>
					<input type="hidden" name="action" value="hermes_select_inbox" />
					<?php wp_nonce_field( 'hermes_select_inbox' ); ?>
					<div class="form--input">
						<label for="hermes-inbox"><?php esc_html_e( 'Web widget inbox', 'hermes-connector' ); ?></label>
						<select id="hermes-inbox" name="inbox_id">
							<?php foreach ( $inboxes as $inbox ) : ?>
								<option value="<?php echo esc_attr( $inbox['id'] ); ?>" <?php selected( $settings['inbox_id'], $inbox['id'] ); ?>><?php echo esc_html( $inbox['account_name'] . ' / ' . $inbox['name'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php submit_button( __( 'Use this inbox', 'hermes-connector' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="hermes--form">
				<h2><?php esc_html_e( 'Widget', 'hermes-connector' ); ?></h2>
				<?php settings_fields( self::PAGE_SLUG ); ?>
				<div class="form--input">
					<label for="hermes-website-token"><?php esc_html_e( 'Website token', 'hermes-connector' ); ?></label>
					<input id="hermes-website-token" type="text" name="<?php echo esc_attr( $name ); ?>[website_token]" value="<?php echo esc_attr( $settings['website_token'] ); ?>" />
					<?php if ( '' !== $settings['inbox_name'] ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: inbox name. */ __( 'Current inbox: %s', 'hermes-connector' ), $settings['inbox_name'] ) ); ?></p>
					<?php endif; ?>
				</div>
				<div class="form--input">
					<label for="hermes-hmac-token"><?php esc_html_e( 'Identity validation token', 'hermes-connector' ); ?></label>
					<input id="hermes-hmac-token" type="password" name="<?php echo esc_attr( $name ); ?>[hmac_token]" autocomplete="off" value="" placeholder="<?php echo esc_attr( '' !== $settings['hmac_token'] ? __( 'Stored. Leave empty to keep it.', 'hermes-connector' ) : '' ); ?>" />
				</div>
				<div class="form--input">
					<label for="hermes-type"><?php esc_html_e( 'Widget design', 'hermes-connector' ); ?></label>
					<select id="hermes-type" name="<?php echo esc_attr( $name ); ?>[widget_type]">
						<option value="standard" <?php selected( $settings['widget_type'], 'standard' ); ?>><?php esc_html_e( 'Standard', 'hermes-connector' ); ?></option>
						<option value="expanded_bubble" <?php selected( $settings['widget_type'], 'expanded_bubble' ); ?>><?php esc_html_e( 'Expanded bubble', 'hermes-connector' ); ?></option>
					</select>
				</div>
				<div class="form--input">
					<label for="hermes-launcher"><?php esc_html_e( 'Expanded bubble text (optional)', 'hermes-connector' ); ?></label>
					<input id="hermes-launcher" type="text" name="<?php echo esc_attr( $name ); ?>[launcher_text]" value="<?php echo esc_attr( $settings['launcher_text'] ); ?>" />
				</div>
				<div class="form--input">
					<label for="hermes-position"><?php esc_html_e( 'Widget position', 'hermes-connector' ); ?></label>
					<select id="hermes-position" name="<?php echo esc_attr( $name ); ?>[position]">
						<option value="left" <?php selected( $settings['position'], 'left' ); ?>><?php esc_html_e( 'Left', 'hermes-connector' ); ?></option>
						<option value="right" <?php selected( $settings['position'], 'right' ); ?>><?php esc_html_e( 'Right', 'hermes-connector' ); ?></option>
					</select>
				</div>
				<div class="form--input">
					<label for="hermes-locale"><?php esc_html_e( 'Language', 'hermes-connector' ); ?></label>
					<select id="hermes-locale" name="<?php echo esc_attr( $name ); ?>[locale]">
						<option value="auto" <?php selected( $settings['locale'], 'auto' ); ?>><?php esc_html_e( 'Same as the site', 'hermes-connector' ); ?></option>
						<?php foreach ( self::LOCALES as $code => $label ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $settings['locale'], $code ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form--input">
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[logged_in_only]" value="1" <?php checked( $settings['logged_in_only'] ); ?> /> <?php esc_html_e( 'Show the widget only to logged-in users', 'hermes-connector' ); ?></label>
				</div>
				<div class="form--input">
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[identify_users]" value="1" <?php checked( $settings['identify_users'] ); ?> /> <?php esc_html_e( 'Identify logged-in users in Hermes', 'hermes-connector' ); ?></label>
				</div>
				<div class="form--input">
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[share_email]" value="1" <?php checked( $settings['share_email'] ); ?> /> <?php esc_html_e( 'Send the user email address', 'hermes-connector' ); ?></label>
					<p class="description"><?php esc_html_e( 'Hermes merges contacts that have the same email address. Enable this only if this site verifies every email address.', 'hermes-connector' ); ?></p>
				</div>
				<?php submit_button( null, 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/** Stop the request unless an administrator sent a valid nonce. */
	private static function authorize_request( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this action.', 'hermes-connector' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/** Keep one message for the next settings page view and stop the request. */
	private static function redirect_with_notice( $type, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/** Read and clear the pending message. */
	private static function take_notice() {
		$key    = self::NOTICE_TRANSIENT_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( $key );

		return $notice;
	}
}

register_activation_hook( __FILE__, array( 'Hermes_Connector', 'activate' ) );
Hermes_Connector::bootstrap();
