<?php
/*
Plugin Name: Plugin Dependencies
Version: 1.2.1
Description: Prevent activating plugins that don't have all their dependencies satisfied
Author: scribu
Author URI: http://scribu.net/
Plugin URI: http://scribu.net/wordpress/plugin-dependencies
Text Domain: plugin-dependencies
Domain Path: /lang
*/

if ( ! is_admin() ) {
	return;
}


/**
 * Main plugin class
 */
class Plugin_Dependencies_Loader {

	/**
	 * Initialize plugin
	 *
	 * @access public
	 * @action plugins_loaded
	 * @return void
	 */
	public static function init() {
		add_filter( 'extra_plugin_headers', array( __CLASS__, 'extra_plugin_headers' ) );
		add_action( 'load-plugins.php', array( 'Plugin_Dependencies_UI', 'init' ) );

		add_action( 'init', array( 'Plugin_Dependencies_UI', 'load_textdomain' ) );
		add_action( 'admin_notices', array( 'Plugin_Dependencies_UI', 'admin_notices' ) );
		add_action( 'network_admin_notices', array( 'Plugin_Dependencies_UI', 'admin_notices' ) );

		add_action( 'activate_plugin', array( 'Plugin_Dependencies', 'check_conflicting' ), 10, 2 );
		add_action( 'deactivate_plugin', array( 'Plugin_Dependencies', 'check_cascade' ), 10, 2 );
		add_action( 'activate_plugin', array( 'Plugin_Dependencies', 'check_activation' ), 1, 2 );
	}


	/**
	 * Add extra plugin headers
	 *
	 * @param array $headers Plugin headers
	 * @return array
	 */
	public static function extra_plugin_headers( $headers ) {
		$headers['Provides'] = 'Provides';
		$headers['Depends']  = 'Depends';

		return $headers;
	}
}
add_action( 'plugins_loaded', array( 'Plugin_Dependencies_Loader', 'init' ) );


class Plugin_Dependencies {

	private static $dependencies;

	private static $provides;

	private static $active_plugins;
	private static $active_network_plugins;

	private static $blocked_activations; // will normally only be filled on bulk activations
	private static $deactivate_cascade;
	private static $deactivate_conflicting;
	private static $deactivated_on_sites;

	public static function init( $force = false ) {
		if ( ( isset( self::$dependencies ) && isset( self::$provides ) ) && $force === false ) {
			return;
		}

		self::$dependencies = array();
		self::$provides     = array();

		$all_plugins = array_merge( get_plugins(), get_mu_plugins() );
		$all_plugins = apply_filters( 'plugin_dependencies_all_plugins', $all_plugins );

		$plugins_by_name = array();
		foreach ( $all_plugins as $plugin => $plugin_data ) {
			$plugins_by_name[ $plugin_data['Name'] ] = $plugin;
		}

		foreach ( $all_plugins as $plugin => $plugin_data ) {
			self::$provides[ $plugin ] = '';
			if ( ! empty( $plugin_data['Provides'] ) ) {
				// @todo [JRF => whomever] The array subkey is being overwritten straight away. What gives ?
				self::$provides[ $plugin ]   = self::parse_field( $plugin_data['Provides'] );
				self::$provides[ $plugin ][] = $plugin;
			}

			$deps = array();

			if ( ! empty( $plugin_data['Depends'] ) ) {
				foreach ( self::parse_field( $plugin_data['Depends'] ) as $dep ) {
					if ( isset( $plugins_by_name[ $dep ] ) ) {
						$dep = $plugins_by_name[ $dep ];
					}
	
					$deps[] = $dep;
				}
			}

			self::$dependencies[ $plugin ] = $deps;
		}
	}

	private static function parse_field( $str ) {
		return array_filter( preg_split( '/,\s*/', $str ) );
	}

	/**
	 * Get a list of real or virtual dependencies for a plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public static function get_dependencies( $plugin_id ) {
		if ( ! isset( self::$dependencies ) ) {
			self::init();
		}

		if ( ! isset( self::$dependencies[ $plugin_id ] ) ) {
			return array();
		} else {
			return self::$dependencies[ $plugin_id ];
		}
	}

	/**
	 * Get a list of dependencies provided by a certain plugin
	 *
	 * @param string $plugin_id A plugin basename
	 * @return array List of dependencies
	 */
	public static function get_provided( $plugin_id ) {
		if ( ! isset( self::$provides ) ) {
			self::init();
		}

		if ( ! isset( self::$provides[ $plugin_id ] ) ) {
			return array();
		} else {
			return self::$provides[ $plugin_id ];
		}
	}

	/**
	 * Get a list of plugins that provide a certain dependency
	 *
	 * @param string $dep Real or virtual dependency
	 * @return array List of plugins
	 */
	public static function get_providers( $dep ) {
		if ( ! isset( self::$provides ) ) {
			self::init();
		}

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
	 * Check if the dependencies for activating a particular plugin are met
	 *
	 * @param string $plugin       Name of the plugin currently being activated
	 * @param bool   $network_wide Whether this is a network or single site activation
	 */
	public static function check_activation( $plugin, $network_wide = false ) {
		if ( $network_wide === true ) {
			self::$active_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		}
		else {
			self::$active_plugins = get_option( 'active_plugins', array() );

			if ( is_multisite() ) {
				self::$active_plugins = array_merge( self::$active_plugins, array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
			}
		}

		// Allow for plugins which are being activated in the same bulk activation action
		$bulk = array();
		if ( ( isset( $_POST['action'] ) && $_POST['action'] === 'activate-selected' ) && ( isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) ) {
			$bulk = $_POST['checked'];
		}

		self::$active_plugins = array_merge( self::$active_plugins, array_keys( (array) get_mu_plugins() ), $bulk );

		$deps = self::get_dependencies( $plugin );
		if ( count( $deps ) === count( array_intersect( self::$active_plugins, $deps ) ) ) {
			// Ok, all dependencies have been met
			return;
		}
		else {
			self::$blocked_activations[] = $plugin;
			self::set_transient( 'activate', self::$blocked_activations, $network_wide );

			/* Prevent other activation hooks from running as the plugin will not be activated.
			   Unfortunately we can't easily do this for the rest of the 'activate_plugins' hook nor
			   for the 'activated_plugin' hook as these are not plugin specific and those actions
			   should still run for non-blocked activations in a bulk activation run.
			   So hooking into the last one to undo any additional activation actions run by running
			   their corresponding deactivation hooks.
			   */
			remove_all_actions( 'activate_' . $plugin );
			add_action( 'activated_plugin', array( __CLASS__, 'undo_activation_actions' ), 9999, 2 );

			if ( $network_wide ) {
				add_filter( 'pre_update_site_option_active_sitewide_plugins', array( __CLASS__, 'prevent_activation' ), 10, 2 );
			} else {
				add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'prevent_activation' ), 10, 2 );
			}

			if ( ! empty( self::$blocked_activations ) && has_filter( 'pre_update_option_recently_activated', array( __CLASS__, 'override_recently_activated' ) ) === false ) {
				add_filter( 'pre_update_option_recently_activated', array( __CLASS__, 'override_recently_activated' ) );
			}
		}
	}


	/**
	 * Check for conflicting provider plugins which may need to be cascade deactivated.
	 *
	 * @param string $plugin       Name of the plugin currently being activated
	 * @param bool   $network_wide Whether this is a network or single site activation
	 */
	public static function check_conflicting( $plugin, $network_wide = false ) {
		if ( ! isset( self::$blocked_activations ) || ! in_array( $plugin, self::$blocked_activations, true ) ) {
			self::execute_check( 'conflicting', $plugin, $network_wide );
		}
	}


	/**
	 * Check for depencing plugins which need to be cascade deactivated.
	 *
	 * @param string $plugin       Name of the plugin currently being deactivated
	 * @param bool   $network_wide Whether this is a network or single site deactivation
	 */
	public static function check_cascade( $plugin, $network_wide = false ) {
		self::execute_check( 'cascade', $plugin, $network_wide );
	}


	/**
	 * Execute a dependency check
	 *
	 * @param string $type          Either 'conflicting' or 'cascade'
	 * @param string $plugin        Name of the plugin currently being (de-)activated
	 * @param bool   $network_wide  Whether the current call to the method is for a network-wide (de)activation
	 */
	protected static function execute_check( $type, $plugin, $network_wide = false ) {
		remove_action( 'deactivate_plugin', array( 'Plugin_Dependencies', 'check_cascade' ) );
		Plugin_Dependencies::init();
		self::$active_network_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		self::check_dependencies( $type, $plugin, $network_wide, $network_wide );
		add_action( 'deactivate_plugin', array( 'Plugin_Dependencies', 'check_cascade' ), 10, 2 );
	}


	/**
	 * Execute the actual dependency checks.
	 *
	 * @param string $type                  Either 'conflicting' or 'cascade'
	 * @param string $plugin                Name of the plugin currently being (de-)activated
	 * @param bool   $network_wide          Whether the current call to the method is for a network-wide (de)activation
	 * @param bool   $original_network_wide Whether the originating call to this method was for a network-wide (de)activation
	 */
	private static function check_dependencies( $type, $plugin, $network_wide = false, $original_network_wide = false ) {

		if ( ! is_multisite() || $network_wide === false ) {
			self::$active_plugins = get_option( 'active_plugins', array() );

			/* No need to execute check when a plugin is network deactivated, but still activated for
			   the individual site */
			if ( self::$active_plugins !== array() && ( $original_network_wide === false || ! in_array( $plugin, self::$active_plugins, true ) ) ) {

				if ( is_multisite() ) {
					self::$active_plugins = array_merge( self::$active_plugins, self::$active_network_plugins );
				}

				$deactivated = call_user_func( array( __CLASS__, "deactivate_$type" ), (array) $plugin, false );
				if ( $deactivated !== array() ) {
					self::set_transient( $type, $deactivated, false );
					self::add_to_recently_deactivated( $deactivated );
					self::$deactivated_on_sites[] = get_current_blog_id();

					if ( $original_network_wide === false ) {
						add_filter( 'pre_update_option_active_plugins', array( __CLASS__, 'prevent_option_override' ) );
					}
				}
			}
		}
		else {
			/* Multi-site network (de-)activation - check plugin dependencies for each blog */
			self::check_dependencies_for_blogs( $type, $plugin );

			/* And for the network */
			self::$active_plugins = self::$active_network_plugins;
			$deactivated          = call_user_func( array( __CLASS__, "deactivate_$type" ), (array) $plugin, $network_wide );
			if ( $deactivated !== array() ) {
				self::set_transient( $type, $deactivated, true );
				add_filter( 'pre_update_site_option_active_sitewide_plugins', array( __CLASS__, 'prevent_option_override_sitewide' ) );
			}

			if ( isset( self::$deactivated_on_sites ) && self::$deactivated_on_sites !== array() ) {
				self::set_transient( 'network', self::$deactivated_on_sites, true );
			}
		}
		self::$active_plugins = null;
	}


	/**
	 * Walk through all blogs and execute the requested check for each.
	 *
	 * @param string $type    Either 'conflicting' or 'cascade'
	 * @param string $plugin  Name of the plugin currently being (de-)activated
	 */
	public static function check_dependencies_for_blogs( $type, $plugin ) {
		global $wpdb;

		$original_blog_id = get_current_blog_id(); // alternatively use: $wpdb->blogid
		$all_blogs        = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

		if ( is_array( $all_blogs ) && $all_blogs !== array() ) {
			foreach ( $all_blogs as $blog_id ) {
				switch_to_blog( $blog_id );
				self::check_dependencies( $type, $plugin, false, true );
			}
			// Restore back to original blog
			switch_to_blog( $original_blog_id );
		}
	}


	/**
	 * Deactivate plugins that would provide the same dependencies as the ones in the list
	 *
	 * @param array $plugin_ids A list of plugin basenames
	 * @param  bool  $network_deactivate Whether to network or single site deactivate any plugins which need deactivating
	 * @return array List of deactivated plugins
	 */
	protected static function deactivate_conflicting( $to_activate, $network_deactivate = false ) {
		$deps = array();
		foreach ( $to_activate as $plugin_id ) {
			$deps = array_merge( $deps, self::get_provided( $plugin_id ) );
		}

		self::$deactivate_conflicting = array();

		$to_check = array_diff( get_option( 'active_plugins', array() ), $to_activate );	// precaution

		foreach ( $to_check as $active_plugin ) {
			$common = array_intersect( $deps, self::get_provided( $active_plugin ) );

			if ( ! empty( $common ) ) {
				self::$deactivate_conflicting[] = $active_plugin;
			}
		}

		// TODO: don't deactivate plugins that would still have all dependencies satisfied
		$deactivated = self::deactivate_cascade( self::$deactivate_conflicting, $network_deactivate );

		deactivate_plugins( self::$deactivate_conflicting, false, $network_deactivate );

		return array_merge( self::$deactivate_conflicting, $deactivated );
	}


	/**
	 * Deactivate plugins that would have unmet dependencies
	 *
	 * @param array $plugin_ids         A list of plugin basenames
	 * @param  bool $network_deactivate Whether to network or single site deactivate any plugins which need deactivating
	 * @return array List of deactivated plugins
	 */
	protected static function deactivate_cascade( $to_deactivate, $network_deactivate = false ) {
		if ( empty( $to_deactivate ) ) {
			return array();
		}

		self::$deactivate_cascade = array();

		self::_cascade( $to_deactivate, $network_deactivate );

		// Do not notify about plugins which are requested to be deactivated anyway within the same bulk deactivation request
		$bulk = array();
		if ( ( isset( $_POST['action2'] ) && $_POST['action2'] === 'deactivate-selected' ) && ( isset( $_POST['checked'] ) && is_array( $_POST['checked'] ) ) ) {
			$bulk = $_POST['checked'];
		}
		self::$deactivate_cascade = array_diff( self::$deactivate_cascade, $bulk );

		return self::$deactivate_cascade;
	}

	private static function _cascade( $to_deactivate, $network_deactivate = false ) {
		$to_deactivate_deps = array();
		foreach ( $to_deactivate as $plugin_id ) {
			$to_deactivate_deps = array_merge( $to_deactivate_deps, self::get_provided( $plugin_id ) );
		}

		$found = array();
		foreach ( self::$active_plugins as $dep ) {
			$matching_deps = array_intersect( $to_deactivate_deps, self::get_dependencies( $dep ) );
			if ( ! empty( $matching_deps ) ) {
				$found[] = $dep;
			}
		}

		$found = array_diff( $found, self::$deactivate_cascade ); // prevent endless loop
		if ( empty( $found ) ) {
			return;
		}

		self::$deactivate_cascade = array_merge( self::$deactivate_cascade, $found );

		self::_cascade( $found, $network_deactivate );

		deactivate_plugins( $found, false, $network_deactivate );
	}


	/**
	 * Set a 'plugins deactivated' transient.
	 *
	 * @param string $type         Either 'conflicting' or 'cascade'
	 * @param array  $deactivated  Deactivated plugins
	 * @param bool   $network      Whether to set the transient for the network or for an individual site
	 */
	protected static function set_transient( $type, $deactivated, $network = false ) {
		if ( $network !== true ) {
			$value = get_transient( "pd_deactivate_$type" );
			if ( is_array( $value ) ) {
				$deactivated = array_merge( $value, $deactivated );
			}
			set_transient( "pd_deactivate_$type", array_unique( $deactivated ) );
		}
		else {
			$value = get_site_transient( "pd_deactivate_$type" );
			if ( is_array( $value ) ) {
				$deactivated = array_merge( $value, $deactivated );
			}
			set_site_transient( "pd_deactivate_$type", array_unique( $deactivated ) );
		}
	}


	/**
	 * Add deactivated plugins to the 'recently active' plugins list.
	 *
	 * @param array $deactivated Array of deactivated plugins
	 */
	protected static function add_to_recently_deactivated( $deactivated ) {
		$recent = array();
		foreach ( $deactivated as $plugin ) {
			$recent[ $plugin ] = time();
		}
		update_option( 'recently_activated', $recent + (array) get_option( 'recently_activated' ) );
	}


	/**
	 * Add blocked (bulk) plugin activations to the 'recently active' plugins list.
	 */
	public static function override_recently_activated( $new_value ) {
		remove_filter( current_filter(), array( __CLASS__, __FUNCTION__ ) );

		$recent = array();
		foreach ( self::$blocked_activations as $plugin ) {
			$recent[ $plugin ] = time();
		}
		return array_merge( $new_value, $recent );
	}


	/**
	 * Prevent a plugin from being activated by overruling the new option value with the old.
	 * Used for blocking plugin activations.
	 */
	public static function prevent_activation( $new_value, $old_value ) {
		remove_filter( current_filter(), array( __CLASS__, __FUNCTION__ ) );
		return $old_value;
	}


	/**
	 * Prevent the active plugins option being overwritten by the original (de)activate_plugins call as
	 * that would undo the changes made by this plugin.
	 */
	public static function prevent_option_override( $new_value ) {
		remove_filter( current_filter(), array( __CLASS__, __FUNCTION__ ) );
		return array_diff( $new_value, (array) self::$deactivate_cascade, (array) self::$deactivate_conflicting );
	}


	/**
	 * Prevent the sitewide active plugins option being overwritten by the original (de)activate_plugins call as
	 * that would undo the changes made by this plugin.
	 */
	public static function prevent_option_override_sitewide( $new_value ) {
		remove_filter( current_filter(), array( __CLASS__, __FUNCTION__ ) );

		$unset = array_merge( (array) self::$deactivate_cascade, (array) self::$deactivate_conflicting );
		foreach ( $unset as $plugin ) {
			unset( $new_value[ $plugin ] );
		}

		return $new_value;
	}


	/**
	 * Undo any activation actions which may have run for blocked activation plugins
	 *
	 * @param string $plugin        Name of the plugin which was blocked
	 * @param bool   $network_wide  Whether the intended activation was for the network or single site
	 */
	public static function undo_activation_actions( $plugin, $network_wide ) {
		remove_action( current_filter(), array( __CLASS__, __FUNCTION__ ) );
		do_action( 'deactivate_plugin', $plugin, $network_wide );
		do_action( 'deactivate_' . $plugin, $network_wide );
		do_action( 'deactivated_plugin', $plugin, $network_wide );
	}
}


class Plugin_Dependencies_UI {

	private static $msg;

	/**
	 * @var array $unsatisfied  Checkbox ids for 'unsatisfied' plugins
	 */
	protected static $unsatisfied = array();

	public static function init() {
		add_action( 'admin_print_styles', array( __CLASS__, 'admin_print_styles' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'footer_script' ), 20 );

		add_filter( 'plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );
		add_filter( 'network_admin_plugin_action_links', array( __CLASS__, 'plugin_action_links' ), 10, 4 );

		Plugin_Dependencies::init();
	}


	/**
	 * Load text domain and set the message texts
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'plugin-dependencies', false, dirname( plugin_basename( __FILE__ ) ) . '/lang' );

		self::$msg = array(
			array( 'cascade', __( 'The following plugins have (also) been deactivated as a plugin they depend on has been deactivated:', 'plugin-dependencies' ) ),
			array( 'conflicting', __( 'The following plugins have been deactivated due to dependency conflicts:', 'plugin-dependencies' ) ),
			array( 'activate', __( 'One or more plugins were not activated as their dependencies were not met at the time of activation. If the dependencies have been met in the mean time, please try and activate them again:', 'plugin-dependencies' ) ),
			array( 'network', __( 'The plugin(s) which was just deactivated provided a dependency for other plugins. Dependent plugin(s) on the following sites in your network have been deactivated:', 'plugin-dependencies' ) ),
		);
	}


	/**
	 * Show admin notices
	 */
	public static function admin_notices() {
		if ( current_user_can( 'publish_posts' ) ) {
			foreach ( self::$msg as $args ) {
				list( $type, $text ) = $args;

				if ( ! is_network_admin() ) {
					$deactivated = get_transient( "pd_deactivate_$type" );
					delete_transient( "pd_deactivate_$type" );
				}
				else {
					$deactivated = get_site_transient( "pd_deactivate_$type" );
					delete_site_transient( "pd_deactivate_$type" );
				}

				if ( empty( $deactivated ) ) {
					continue;
				}

				if ( $type !== 'network' ) {
					echo html(
						'div',
						array( 'class' => 'updated' ),
							html( 'p', $text, self::generate_dep_list( $deactivated, $deactivated ) )
					); // xss ok
				}
				else {
					$dep_list = '';
					$class    = 'unsatisfied';
					foreach ( $deactivated as $blog_id ) {
						$details   = get_blog_details( $blog_id, false );
						$dep_list .= html( 'li', compact( 'class' ), html( 'a', array( 'href' => get_admin_url( $blog_id, 'plugins.php?plugin_status=recently_activated' ) ), $details->blogname ) );
					}

					echo html(
						'div',
						array( 'class' => 'updated' ),
						html( 'p', $text, html( 'ul', array( 'class' => 'dep-list' ), $dep_list ) )
					); // xss ok
				}
			}
		}
	}

	public static function admin_print_styles() {
		?>
			<style type="text/css">
				span.dep-action { white-space: nowrap }
				.dep-list li { list-style: disc inside none }
				.dep-list li.unsatisfied { color: red }
				.dep-list li.unsatisfied_network { color: orange }
				.dep-list li.satisfied { color: lime }
			</style>
		<?php
	}


	/**
	 * Add javascript for the plugins page.
	 *
	 * - Turn plugin names into hash links for linking between dependent plugins/admin notice and plugins
	 * - Remove bulk action checkbox for unsatisfied plugins to avoid bulk activation (only on
	 *	 single site within multisite network as otherwise update/delete actions wouldn't be
	 *	 available anymore either)
	 */
	public static function footer_script() {
		$all_plugins = get_plugins();

		$hash = array();
		foreach ( $all_plugins as $file => $data ) {
			$name = isset( $data['Name'] ) ? $data['Name'] : $file;
			$hash[ $name ] = sanitize_title( $name );
		}

		$unsatisfied = '#' . implode( ', #', self::$unsatisfied );
		?>
			<script type="text/javascript">
				jQuery(function($) {
					var hash = <?php echo json_encode( $hash ); ?>

					$('table.widefat tbody tr').not('.second').each(function() {
						var $self = $(this), title = $self.find('.plugin-title').text();

						$self.attr('id', hash[title]);
					});
		<?php
		if ( is_multisite() && ! is_network_admin() ):
			?>
					$('<?php echo esc_attr( $unsatisfied ); ?>').remove();
			<?php
		endif;
		?>
				});
			</script>
		<?php
	}


	public static function plugin_action_links( $actions, $plugin_file, $plugin_data, $context ) {
		$deps = Plugin_Dependencies::get_dependencies( $plugin_file );

		if ( empty( $deps ) ) {
			return $actions;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		$network_active_plugins = array_keys( get_site_option( 'active_sitewide_plugins', array() ) );
		$mu_plugins = array_keys( (array) get_mu_plugins() );

		$unsatisfied = $unsatisfied_network = array();
		foreach ( $deps as $dep ) {
			$plugin_ids = Plugin_Dependencies::get_providers( $dep );

			if ( array_intersect( $mu_plugins, $plugin_ids ) !== array() ) {
				continue;
			}

			if ( ! is_network_admin() && array_intersect( $active_plugins, $plugin_ids ) === array() && array_intersect( $network_active_plugins, $plugin_ids ) === array() ) {
				$unsatisfied[] = $dep;
			}

			if ( is_network_admin() && array_intersect( $network_active_plugins, $plugin_ids ) === array() ) {
				$unsatisfied_network[] = $dep;
			}
		}

		if ( ! empty( $unsatisfied ) ) {
			unset( $actions['activate'] );

			self::$unsatisfied[] = 'checkbox_' . md5( $plugin_data['Name'] );
		}

		if ( ! empty( $unsatisfied_network ) ) {
			// Array key was changed in WP 3.4
			if ( isset( $actions['network_activate'] ) ) {
				unset( $actions['network_activate'] );
			}
			else {
				unset( $actions['activate'] );
			}
			self::$unsatisfied[] = 'checkbox_' . md5( $plugin_data['Name'] );
		}

		$actions['deps'] = html( 'span', array( 'class' => 'dep-action' ), __( 'Required plugins:', 'plugin-dependencies' ) ) . '<br>' . self::generate_dep_list( $deps, $unsatisfied, $unsatisfied_network );

		return $actions;
	}


	public static function generate_dep_list( $deps, $unsatisfied = array(), $unsatisfied_network = array() ) {
		$all_plugins = get_plugins();
		$mu_plugins  = get_mu_plugins();

		$dep_list = '';
		foreach ( $deps as $dep ) {
			$plugin_ids = Plugin_Dependencies::get_providers( $dep );
			if ( in_array( $dep, $unsatisfied ) ) {
				$class = 'unsatisfied';
				$title = __( 'Dependency: Unsatisfied', 'plugin-dependencies' );
			}
			elseif ( in_array( $dep, $unsatisfied_network ) ) {
				$class = 'unsatisfied_network';
				$title = __( 'Dependency: Network unsatisfied', 'plugin-dependencies' );
			}
			else {
				$class = 'satisfied';
				$title = __( 'Dependency: Satisfied', 'plugin-dependencies' );
			}

			if ( empty( $plugin_ids ) ) {
				$name = html( 'span', esc_html( $dep ) );
			} else {
				$list = array();
				foreach ( $plugin_ids as $plugin_id ) {
					if ( isset( $all_plugins[ $plugin_id ]['Name'] ) ) {
						if ( is_network_admin() || ! is_plugin_active_for_network( $plugin_id ) ) {
							$name = $all_plugins[ $plugin_id ]['Name'];
							$url  = self_admin_url( 'plugins.php' ) . '#' . sanitize_title( $name );
						}
						else {
							$name = sprintf(
								__( '%s (%s)', 'plugin-dependencies' ),
								$all_plugins[ $plugin_id ]['Name'],
								__( 'network', 'plugin-dependencies' )
							);
							if ( current_user_can( 'manage_network_plugins' ) ) {
								$url  = network_admin_url( 'plugins.php' ) . '#' . sanitize_title( $all_plugins[ $plugin_id ]['Name'] );
							}
							else {
								$url = false;
							}
						}
					} elseif ( isset( $mu_plugins[ $plugin_id ]['Name'] ) ) {
						$name = sprintf(
							__( '%s (%s)', 'plugin-dependencies' ),
							$mu_plugins[ $plugin_id ]['Name'],
							__( 'must-use', 'plugin-dependencies' )
						);
						$url  = add_query_arg( 'plugin_status', 'mustuse' ) . '#' . sanitize_title( $mu_plugins[ $plugin_id ]['Name'] );
					} else {
						$name = $plugin_id;
						$url  = '#' . sanitize_title( $name );
					}

					if ( $url !== false ) {
						$list[] = html( 'a', array( 'href' => $url, 'title' => $title ), $name );
					}
					else {
						$list[] = html( 'span', array( 'title' => $title ), $name );
					}
				}
				$name = implode( ' or ', $list );
			}

			$dep_list .= html( 'li', compact( 'class' ), $name );
		}

		return html( 'ul', array( 'class' => 'dep-list' ), $dep_list );
	}
}


if ( ! function_exists( 'html' ) ) :
	function html( $tag ) {
		$args = func_get_args();

		$tag = array_shift( $args );

		if ( is_array( $args[0] ) ) {
			$closing    = $tag;
			$attributes = array_shift( $args );
			foreach ( $attributes as $key => $value ) {
				if ( false === $value ) {
					continue;
				}

				if ( true === $value ) {
					$value = $key;
				}

				$tag .= ' ' . $key . '="' . esc_attr( $value ) . '"';
			}
		} else {
			list( $closing ) = explode( ' ', $tag, 2 );
		}

		if ( in_array( $closing, array( 'area', 'base', 'basefont', 'br', 'hr', 'input', 'img', 'link', 'meta' ) ) ) {
			return "<{$tag} />";
		}

		$content = implode( '', $args );

		return "<{$tag}>{$content}</{$closing}>";
	}
endif;
