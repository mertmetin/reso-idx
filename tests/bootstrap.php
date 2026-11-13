<?php
/**
 * Unit test bootstrap.
 *
 * The sync, mapping, query and search classes don't depend on WordPress, so
 * they are tested without loading it. The few helpers they call are stubbed.
 *
 * @package ResoIdx
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES );
	}
}
