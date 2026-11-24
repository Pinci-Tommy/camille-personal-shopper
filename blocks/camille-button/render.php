<?php
/**
 * Front-end output of the Camille button block.
 *
 * @package CamillePersonalShopper
 *
 * @var array $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$camille_ps_html = Camille_PS_Frontend::button_html(
	isset( $attributes['label'] ) ? (string) $attributes['label'] : '',
	isset( $attributes['mode'] ) ? (string) $attributes['mode'] : 'tryon'
);
if ( '' !== $camille_ps_html ) {
	echo '<div ' . get_block_wrapper_attributes() . '>' . $camille_ps_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts are escaped where they are built.
}
