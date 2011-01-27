<?php
/*
Plugin Name: Plugin Dependencies
Version: 1.1-alpha
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


add_action( 'load-plugins.php', array( 'Plugin_Dependencies_UI', 'init' ) );

class Plugin_Dependencies_UI {

	function init() {
		Plugin_Dependencies::init();

		load_plugin_textdomain( 'plugin-dependencies', '', dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );

		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'admin_print_styles', array( __CLASS__, 'admin_print_styles' ) );
		add_action( 'admin_footer', array( __CLASS__, 'admin_footer' ) );

		if ( isset( $_REQUEST['action'] ) && 'deactivate' == $_REQUEST['action'] ) {
			$deactivate_cascade = Plugin_Dependencies::deactivate_cascade( (array) $_REQUEST['plugin'] );
			set_transient( 'pd_deactivate_cascade', $deactivate_cascade );
		}
	}

	function admin_notices() {
		if ( !isset( $_REQUEST['deactivate'] ) )
			return;

		$deactivate_cascade = get_transient( 'pd_deactivate_cascade' );

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

	function plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		$deps = Plugin_Dependencies::get_dependencies( $plugin_file );

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$network_active_plugins = (array) get_site_option( 'active_sitewide_plugins' );

		if ( empty( $deps ) )
			return $actions;

		$unsatisfied = $unsatisfied_network = array();
		foreach ( $deps as $dep ) {
			$plugin_ids = Plugin_Dependencies::get_real_plugin_ids( $dep );

			if ( !count( array_intersect( $active_plugins, $plugin_ids ) ) )
				$unsatisfied[] = $dep;

			if ( is_multisite() && !count( array_intersect( $network_active_plugins, $plugin_ids ) ) )
				$unsatisfied_network[] = $dep;
		}

		if ( !empty( $unsatisfied ) ) {
			unset( $actions['activate'] );
		}

		if ( !empty( $unsatisfied_network ) ) {
			unset( $actions['network_activate'] );
		}

		$actions['deps'] = __( 'Required plugins:', 'plugin-dependencies' ) . '<br>' . self::generate_dep_list( $deps, $unsatisfied, $unsatisfied_network );

		return $actions;
	}

	private function generate_dep_list( $deps, $unsatisfied = array(), $unsatisfied_network = array() ) {
		$all_plugins = get_plugins();

		$dep_list = '';
		foreach ( $deps as $dep ) {
			$plugin_ids = Plugin_Dependencies::get_real_plugin_ids( $dep );

			if ( in_array( $dep, $unsatisfied ) )
				$class = 'unsatisfied';
			elseif ( in_array( $dep, $unsatisfied_network ) )
				$class = 'unsatisfied_network';
			else
				$class = 'satisfied';

			if ( empty( $plugin_ids ) ) {
				$name = html( 'span', esc_html( $dep ) );
			} else {
				$list = array();
				foreach ( $plugin_ids as $plugin_id ) {
					$name = isset( $all_plugins[ $plugin_id ]['Name'] ) ? $all_plugins[ $plugin_id ]['Name'] : $plugin_id;
					$list[] = html( 'a', array( 'href' => '#' . sanitize_title( $name ) ), $name );
				}
				$name = implode( ' or ', $list );
			}

			$dep_list .= html( 'li', compact( 'class' ), $name );
		}

		return html( 'ul', array( 'class' => 'dep-list' ), $dep_list );
	}
}


add_action( 'extra_plugin_headers', array( 'Plugin_Dependencies', 'extra_plugin_headers' ) );

class Plugin_Dependencies {

	private static $dependencies = array();
	private static $provides = array();

	private static $active_plugins;
	private static $deactivate_cascade;

	function extra_plugin_headers( $headers ) {
		$headers['Dependencies'] = 'Dependencies';
		$headers['Provides'] = 'Provides';

		return $headers;
	}

	function init() {
		$all_plugins = get_plugins();

		foreach ( get_plugins() as $plugin => $plugin_data ) {
			# http://core.trac.wordpress.org/attachment/ticket/15193/
			self::$dependencies[ $plugin ] = array_filter( preg_split( '/\s+/', $plugin_data['Dependencies'] ) );

			self::$provides[ $plugin ] = array_filter( preg_split( '/\s+/', $plugin_data['Provides'] ) );
			self::$provides[ $plugin ][] = $plugin;
		}
	}

	/**
	 * Get a list of real or virtual dependencies for a plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public function get_dependencies( $plugin_id ) {
		return self::$dependencies[ $plugin_id ];
	}

	/**
	 * Get a list of dependencies provided by a certain plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public function get_provides( $plugin_id ) {
		return self::$provides[ $plugin_id ];
	}

	/**
	 * Get a list of plugins that provide a certain dependency
	 *
	 * @param string $dep Real or virtual dependency
	 * @return array List of plugin ids
	 */
	public function get_real_plugin_ids( $dep ) {
		$plugin_ids = array();

		if ( isset( self::$provides[ $dep ] ) ) {
			$plugin_ids = array( $dep );
		} else {
			// virtual dependency
			foreach ( self::$provides as $plugin => $provides ) {
				if ( in_array( $dep, $provides ) ) {
					$plugin_ids[] = $plugin;
				}
			}
		}

		return $plugin_ids;
	}

	/**
	 * Deactivate plugins that would have unmet dependencies
	 *
	 * @param array $plugin_ids A list of plugin basenames
	 * @return array List of deactivated plugins
	 */
	public function deactivate_cascade( $to_deactivate ) {
		self::$active_plugins = self::get_active_plugins();
		self::$deactivate_cascade = array();

		self::_cascade( $to_deactivate );

		return self::$deactivate_cascade;
	}

	private function _cascade( $to_deactivate ) {
		$to_deactivate_deps = array();
		foreach ( $to_deactivate as $plugin_id )
			$to_deactivate_deps = array_merge( $to_deactivate_deps, self::get_provides( $plugin_id ) );

		$found = array();
		foreach ( self::$active_plugins as $dep ) {
			$deps = self::$dependencies[ $dep ];

			if ( empty( $deps ) )
				continue;

			if ( count( array_intersect( $to_deactivate_deps, $deps ) ) )
				$found[] = $dep;
		}

		$found = array_diff( $found, self::$deactivate_cascade ); // prevent endless loop
		if ( empty( $found ) )
			return;

		self::$deactivate_cascade = array_merge( self::$deactivate_cascade, $found );

		self::_cascade( $found );

		deactivate_plugins( $found );
	}

	private function get_active_plugins() {
		$active = get_option( 'active_plugins', array() );

		if ( is_multisite() )
			$active = array_merge( $active, get_site_option( 'active_sitewide_plugins', array() ) );

		return $active;
	}
}


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

