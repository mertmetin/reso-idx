<?php
/**
 * Plugin Name:       RESO IDX
 * Plugin URI:        https://github.com/mertmetin/reso-idx
 * Description:       Sync MLS listings from any RESO Web API (OData) feed into WordPress and show them with a search block, map and listing pages. Includes IDX display rules and a demo feed.
 * Version:           1.0.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Mert Metin
 * Author URI:        https://github.com/mertmetin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reso-idx
 *
 * @package ResoIdx
 */

defined( 'ABSPATH' ) || exit;

define( 'RESO_IDX_VERSION', '1.0.1' );
define( 'RESO_IDX_FILE', __FILE__ );
define( 'RESO_IDX_DIR', plugin_dir_path( __FILE__ ) );
define( 'RESO_IDX_URL', plugin_dir_url( __FILE__ ) );

require_once RESO_IDX_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \ResoIdx\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ResoIdx\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \ResoIdx\Plugin::class, 'boot' ) );
