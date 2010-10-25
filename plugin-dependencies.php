<?php
/*
Plugin Name: Plugin Dependencies
Version: 1.0.1-alpha
Description: Prevent activating plugins that don't have all their dependencies satisfied
Author: scribu
Author URI: http://scribu.net/
Plugin URI: http://scribu.net/wordpress/plugin-dependencies
Text Domain: plugin-dependencies
Domain Path: /lang


Copyright (C) 2010 Cristi Burcă (scribu@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 3 of the License, or
( at your option ) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

class Plugin_Dependencies {

	function init() {
		add_action( 'load-plugins.php', array( __CLASS__, '_init' ) );
		add_action( 'extra_plugin_headers', array( __CLASS__, 'extra_plugin_headers' ) );
		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );
	}

	function _init() {
		load_plugin_textdomain( 'plugin-dependencies', '', dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		if ( isset( $_REQUEST['action'] ) && 'deactivate' == $_REQUEST['action'] )
			self::deactivate_cascade( (array) $_REQUEST['plugin'] );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_print_styles', array( __CLASS__, 'admin_print_styles' ) );
		add_action( 'admin_footer', array( __CLASS__, 'admin_footer' ) );
	}

	private static $active_plugins;
	private static $deactivate_cascade;

	private function deactivate_cascade( $to_deactivate ) {
		$hierarchy = self::get_dependency_hierarchy();

		self::$active_plugins = self::get_dependency_parents();
		self::$deactivate_cascade = array();

		self::_cascade( $to_deactivate );

		deactivate_plugins( self::$deactivate_cascade );

		set_transient( 'pd_deactivate_cascade', self::$deactivate_cascade );
	}

	private function _cascade( $to_deactivate ) {
		$found = array();
		foreach ( self::$active_plugins as $dep => $deps ) {
			if ( empty( $deps ) )
				continue;

			if ( count( array_intersect( $to_deactivate, $deps ) ) )
				$found[] = $dep;
		}

		$found = array_diff( $found, self::$deactivate_cascade ); // prevent endless loop
		if ( empty( $found ) )
			return;

		self::$deactivate_cascade = array_merge( self::$deactivate_cascade, $found );

		self::_cascade( $found );
	}

	// parent => children
	private function get_dependency_hierarchy() {
		$all_plugins = get_plugins();

		$r = array();
		foreach ( $all_plugins as $dep => $plugin_data ) {
			foreach ( self::get_dependencies( $plugin_data ) as $parent )
				$r[ $parent ][] = $dep;
		}

		return $r;
	}

	// child => parents
	private function get_dependency_parents( $active = true ) {
		if ( $active )
			$plugins = self::get_active_plugins();
		else
			$plugins = get_plugins();

		$r = array();
		foreach ( $plugins as $dep => $plugin_data ) {
			$r[ $dep ] = self::get_dependencies( $plugin_data );
		}

		return $r;
	}

	private function get_active_plugins() {
		$all_plugins = get_plugins();

		$active = array();
		foreach ( $all_plugins as $dep => $plugin_data ) {
			if ( !is_plugin_active( $dep ) && !is_plugin_active_for_network( $dep ) )
				continue;

			$active[ $dep ] = $plugin_data;
		}

		return $active;
	}

	function admin_notices() {
		if ( !isset( $_REQUEST['deactivate'] ) )
			return;

		$deactivate_cascade = get_transient('pd_deactivate_cascade');

		if ( empty( $deactivate_cascade ) )
			return;

		echo 
		html( 'div', array( 'class' => 'updated' ), html( 'p',
			__( 'The following plugins have also been deactivated:', 'plugin-dependencies' ),
			self::generate_dep_list( $deactivate_cascade )
		) );

		delete_transient( 'pd_deactivate_cascade' );
	}


	function admin_print_styles() {
?>
<style type="text/css">
.dep-list li { list-style: disc inside none }
span.deps li.unsatisfied { color: red }
span.deps li.unsatisfied_network { color: orange }
span.deps li.satisfied { color: green }
</style>
<?php
	}

	# http://core.trac.wordpress.org/changeset/15944
	function admin_footer() {
		$all_plugins = get_plugins();

		$hash = array();
		foreach ( $all_plugins as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			$hash[ $name ] = sanitize_title( $name );
		}

?>
<script type="text/javascript">
jQuery(document).ready(function($) {
	var hash = <?php echo json_encode( $hash ); ?>

	$('table.widefat tbody tr').not('.second').each(function() {
		var $self = $(this),
			title = $self.find('.plugin-title').text();

		$self.attr('id', hash[title]);
	});
});
</script>
<?php
	}

	function extra_plugin_headers( $headers ) {
		$headers['Dependencies'] = 'Dependencies';

		return $headers;
	}

	function plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		$deps = self::get_dependencies( $plugin_data );

		if ( empty( $deps ) )
			return $actions;

		$unsatisfied = $unsatisfied_network = array();
		foreach ( $deps as $dep ) {
			if ( !is_plugin_active( $dep ) )
				$unsatisfied[] = $dep;

			if ( !is_plugin_active_for_network( $dep ) )
				$unsatisfied_network[] = $dep;
		}

		if ( !empty( $unsatisfied ) ) {
			unset( $actions['activate'] );
		}

		if ( !empty( $unsatisfied_network ) ) {
			unset( $actions['network_activate'] );
		}

		$actions['deps'] = __( 'Required plugins:', 'plugin-dependencies') . '<br>' . self::generate_dep_list( $deps, $unsatisfied, $unsatisfied_network );

		return $actions;
	}

	private function generate_dep_list( $deps, $unsatisfied = array(), $unsatisfied_network = array() ) {
		$all_plugins = get_plugins();

		$dep_list = '';
		foreach ( $deps as $dep ) {
			$class = 'satisfied';

			if ( in_array( $dep, $unsatisfied_network ) )
				$class = 'unsatisfied_network';

			if ( in_array( $dep, $unsatisfied ) )
				$class = 'unsatisfied';

			if ( isset( $all_plugins[$dep] ) && isset( $all_plugins[$dep]['Name'] ) )
				$name = html( 'a', array( 'href' => '#' . sanitize_title( $all_plugins[$dep]['Name'] ) ), $all_plugins[$dep]['Name'] );
			else
				$name = html( 'span', esc_html( $dep ) );

			$dep_list .= html( 'li', compact( 'class' ), $name );
		}

		return html( 'ul', array( 'class' => 'dep-list' ), $dep_list );
	}

	private function get_dependencies( $plugin_data ) {
		if ( empty( $plugin_data['Dependencies'] ) )
			return array();

		# http://core.trac.wordpress.org/attachment/ticket/15193/
		if ( FALSE === strpos( $plugin_data['Dependencies'], '.php' ) )
			return array();

		return array_filter( preg_split( '/\s+/', $plugin_data['Dependencies'] ) );
	}
}

Plugin_Dependencies::init();

if ( ! function_exists( 'html' ) ):
function html( $tag ) {
	$args = func_get_args();

	$tag = array_shift( $args );

	if ( is_array( $args[0] ) ) {
		$closing = $tag;
		$attributes = array_shift( $args );
		foreach ( $attributes as $key => $value ) {
			$tag .= ' ' . $key . '="' . htmlspecialchars( $value, ENT_QUOTES ) . '"';
		}
	} else {
		list( $closing ) = explode(' ', $tag, 2);
	}

	$content = implode('', $args);

	return "<{$tag}>{$content}</{$closing}>";
}
endif;

