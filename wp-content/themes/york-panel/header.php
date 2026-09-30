<?php
/**
 * Minimal header.
 *
 * @package York_Panel
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<!-- Bugmark SDK -->
<script
  src="http://localhost:4100/sdk/bugmark.js"
  data-site-key="bm_demo_GhfTkYJCNnhv"
  data-api-url="http://localhost:4100"
  defer
></script>
<body <?php body_class( is_user_logged_in() ? 'yp-logged-in' : 'yp-logged-out' ); ?>>
<?php wp_body_open(); ?>
