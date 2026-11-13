<?php
/**
 * Page wrapper for a listing: uses the theme's header and footer.
 *
 * Block themes have no header.php, so their header and footer template
 * parts are rendered instead.
 *
 * @package ResoIdx
 */

use ResoIdx\Frontend\ListingPage;
use ResoIdx\Frontend\View;

defined( 'ABSPATH' ) || exit;

$reso_idx_body = View::render( 'listing', array( 'listing' => ListingPage::current() ) );

if ( wp_is_block_theme() ) :
	$reso_idx_header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
	$reso_idx_footer = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $reso_idx_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
	<main class="reso-idx-main"><?php echo $reso_idx_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template. ?></main>
	<?php echo $reso_idx_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks. ?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
else :
	get_header();
	echo '<main class="reso-idx-main">' . $reso_idx_body . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered template.
	get_footer();
endif;
