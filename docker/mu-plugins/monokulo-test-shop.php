<?php
/**
 * Plugin Name: Monokulo test shop
 * Description: For the Docker test shop only (docker/wp-setup copies it into
 *              mu-plugins): the plugin's Connect button goes to the Monokulo
 *              on this machine, http://localhost:8081, where scripts/smoke
 *              starts one.
 */
add_filter(
	'monokulo_control_plane_base_url',
	static function () {
		return 'http://localhost:8081';
	}
);
