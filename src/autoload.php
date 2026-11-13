<?php
/**
 * PSR-4 autoloader for the ResoIdx namespace.
 *
 * The plugin has no runtime Composer dependencies, so it ships its own small
 * autoloader. That keeps the plugin installable straight from a GitHub zip
 * (and in WordPress Playground) without running `composer install`.
 *
 * @package ResoIdx
 */

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ResoIdx\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
