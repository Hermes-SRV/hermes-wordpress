<?php

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

define( 'ABSPATH', __DIR__ );

class WP_Error {
	private $message;
	public $code;
	public function __construct( $code, $message ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function __( $text ) { return $text; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { return $default; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }

require dirname( __DIR__ ) . '/hermes-connector.php';

function expect_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

$connector = 'Hermes_Connector';

expect_same( 'https://hermes.example', $connector::normalize_url( ' https://Hermes.example/ ' ), 'URL must be normalized' );
expect_same( 'https://hermes.example:8443/app', $connector::normalize_url( 'https://hermes.example:8443/app/' ), 'URL must keep port and path' );
expect_same( 'http://localhost:3000', $connector::normalize_url( 'http://localhost:3000' ), 'loopback HTTP is allowed' );
expect_same( '', $connector::normalize_url( 'http://hermes.example' ), 'remote HTTP must be rejected' );
expect_same( '', $connector::normalize_url( 'https://user:pass@hermes.example' ), 'URL credentials must be rejected' );
expect_same( '', $connector::normalize_url( 'https://hermes.example/?x=1' ), 'query must be rejected' );
expect_same( '', $connector::normalize_url( 'javascript:alert(1)' ), 'other schemes must be rejected' );
expect_same( '', $connector::normalize_url( '' ), 'empty URL must be rejected' );

expect_same( 'es', $connector::resolve_locale( 'es', 'pt_BR' ), 'selected locale has priority' );
expect_same( 'pt_BR', $connector::resolve_locale( 'auto', 'pt_BR' ), 'automatic mode uses the exact site locale' );
expect_same( 'de', $connector::resolve_locale( 'auto', 'de_DE' ), 'automatic mode falls back to the language' );
expect_same( 'en', $connector::resolve_locale( 'auto', 'xx_YY' ), 'unknown locale falls back to English' );
expect_same( 'en', $connector::resolve_locale( "hi'", 'xx' ), 'upstream malformed locale is not accepted' );

$identity = $connector::build_identity( 7, 'Ana', 'ana@example.com', 'site.example', 'secret', false );
expect_same( 'wordpress:site.example:7', $identity['identifier'], 'identifier must be scoped to the site' );
expect_same( hash_hmac( 'sha256', 'wordpress:site.example:7', 'secret' ), $identity['user']['identifier_hash'], 'hash must match the Hermes check' );
expect_same( false, array_key_exists( 'email', $identity['user'] ), 'email must not be sent by default' );
expect_same( false, strpos( json_encode( $identity ), 'secret' ), 'identity must not contain the HMAC token' );

$with_email = $connector::build_identity( 7, 'Ana', 'ana@example.com', 'site.example', 'secret', true );
expect_same( 'ana@example.com', $with_email['user']['email'], 'email must be sent after opt-in' );

expect_same( 'hermes_unauthorized', $connector::parse_search_response( 401, '' )->code, '401 must map to unauthorized' );
expect_same( 'hermes_unauthorized', $connector::parse_search_response( 403, '' )->code, '403 must map to unauthorized' );
expect_same( 'hermes_endpoint_missing', $connector::parse_search_response( 404, '' )->code, '404 must map to missing endpoint' );
expect_same( 'hermes_invalid_response', $connector::parse_search_response( 200, 'not json' )->code, 'invalid JSON must be rejected' );
expect_same( 'hermes_invalid_response', $connector::parse_search_response( 200, '{"ok":false}' )->code, 'failed envelope must be rejected' );
expect_same( 'hermes_invalid_response', $connector::parse_search_response( 500, '{"ok":true,"data":{"items":[]}}' )->code, '500 must be rejected' );

$parsed = $connector::parse_search_response(
	200,
	json_encode(
		array(
			'ok'   => true,
			'data' => array(
				'count' => 2,
				'items' => array(
					array(
						'id'              => 'inbox-uuid',
						'name'            => 'Site',
						'account'         => array( 'name' => 'Acme' ),
						'website_token'   => 'public',
						'hmac_token'      => 'private',
						'allowed_domains' => 'site.example',
					),
					array( 'id' => 'no-token' ),
				),
			),
		)
	)
);
expect_same( 1, count( $parsed['items'] ), 'items without a website token must be ignored' );
expect_same( 2, $parsed['count'], 'count must come from Hermes' );
expect_same( 'Acme', $parsed['items'][0]['account_name'], 'account name must be mapped' );

expect_same( true, $connector::domain_allowed( '', 'site.example' ), 'empty list allows every domain' );
expect_same( true, $connector::domain_allowed( 'other.example, site.example', 'site.example' ), 'list entry must match' );
expect_same( true, $connector::domain_allowed( 'https://site.example', 'site.example' ), 'scheme must be ignored' );
expect_same( true, $connector::domain_allowed( '*.example', 'site.example' ), 'wildcard must match a subdomain' );
expect_same( false, $connector::domain_allowed( '*.example', 'badexample' ), 'wildcard must not match a name suffix' );
expect_same( false, $connector::domain_allowed( 'other.example', 'site.example' ), 'other domain must not match' );

$sanitized = $connector::sanitize_stored(
	array(
		'url'           => 'http://remote.example',
		'launcher_text' => '<script>x</script>Chat',
		'locale'        => "hi'",
		'widget_type'   => 'x',
		'position'      => 'x',
	)
);
expect_same( '', $sanitized['url'], 'stored URL must be validated' );
expect_same( 'xChat', $sanitized['launcher_text'], 'stored text must be sanitized' );
expect_same( 'auto', $sanitized['locale'], 'stored locale must be validated' );
expect_same( 'standard', $sanitized['widget_type'], 'stored type must be validated' );
expect_same( 'right', $sanitized['position'], 'stored position must be validated' );

$asset_url = 'https://github.com/Hermes-SRV/hermes-wordpress/releases/download/v1.2.3/hermes-connector.zip';
$release   = array(
	'tag_name'     => 'v1.2.3',
	'body'         => 'Notes',
	'published_at' => '2026-11-03T00:00:00Z',
	'assets'       => array(
		array( 'name' => 'other.zip', 'browser_download_url' => 'https://github.com/Hermes-SRV/hermes-wordpress/releases/download/v1.2.3/other.zip' ),
		array( 'name' => 'hermes-connector.zip', 'browser_download_url' => $asset_url ),
	),
);
$parsed_release = $connector::parse_release( 200, json_encode( $release ) );
expect_same( '1.2.3', $parsed_release['version'], 'release version must drop the v prefix' );
expect_same( $asset_url, $parsed_release['package'], 'release package must be the asset' );
expect_same( 'Notes', $parsed_release['changelog'], 'release notes must be kept' );

$foreign = $release;
$foreign['assets'][1]['browser_download_url'] = 'https://evil.example/hermes-connector.zip';
expect_same( null, $connector::parse_release( 200, json_encode( $foreign ) ), 'package of another host must be rejected' );
$other_repo = $release;
$other_repo['assets'][1]['browser_download_url'] = 'https://github.com/attacker/hermes-wordpress/releases/download/v1.2.3/hermes-connector.zip';
expect_same( null, $connector::parse_release( 200, json_encode( $other_repo ) ), 'package of another repository must be rejected' );
$other_tag = $release;
$other_tag['tag_name'] = 'v9.9.9';
expect_same( null, $connector::parse_release( 200, json_encode( $other_tag ) ), 'package of another tag must be rejected' );
expect_same( null, $connector::parse_release( 200, json_encode( array_merge( $release, array( 'prerelease' => true ) ) ) ), 'prerelease must be ignored' );
expect_same( null, $connector::parse_release( 200, json_encode( array_merge( $release, array( 'draft' => true ) ) ) ), 'draft must be ignored' );
expect_same( null, $connector::parse_release( 200, json_encode( array_merge( $release, array( 'tag_name' => 'latest' ) ) ) ), 'invalid tag must be ignored' );
expect_same( null, $connector::parse_release( 200, json_encode( array_merge( $release, array( 'assets' => array() ) ) ) ), 'release without the asset must be ignored' );
expect_same( null, $connector::parse_release( 404, '{}' ), 'missing release must return null' );
expect_same( null, $connector::parse_release( 200, 'not json' ), 'invalid JSON must return null' );

$update_data = $connector::update_data( $parsed_release );
expect_same( '1.2.3', $update_data['version'], 'update data must use the release version' );
expect_same( $asset_url, $update_data['package'], 'update data must use the release package' );
$no_release = $connector::update_data( null );
expect_same( $connector::VERSION, $no_release['version'], 'without a release the current version must be reported' );
expect_same( '', $no_release['package'], 'without a release there must be no package' );

expect_same( 1, preg_match( '/^ \\* Version:\s+' . preg_quote( $connector::VERSION, '/' ) . '$/m', file_get_contents( dirname( __DIR__ ) . '/hermes-connector.php' ) ), 'header version must equal the class version' );

echo "Hermes connector tests passed\n";
