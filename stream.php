<?php
/**
 * Plugin Name: Stream
 * Plugin URI: https://wp-stream.com/
 * Description: Stream tracks logged-in user activity so you can monitor every change made on your WordPress site in beautifully organized detail. All activity is organized by context, action and IP address for easy filtering. Developers can extend Stream with custom connectors to log any kind of action.
 * Version: 2.0.0-beta
 * Author: Stream
 * Author URI: https://wp-stream.com/
 * License: GPLv2+
 * Text Domain: stream
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2014 WP Stream Pty Ltd (https://wp-stream.com/)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */

class WP_Stream {

	/**
	 * Plugin version number
	 *
	 * @const string
	 */
	const VERSION = '2.0.0-beta';

	/**
	 * Hold Stream instance
	 *
	 * @var string
	 */
	public static $instance;

	/**
	 * @var WP_Stream_DB_Base
	 */
	public static $db;

	/**
	 * @var WP_Stream_API
	 */
	public static $api;

	/**
	 * Admin notices, collected and displayed on proper action
	 *
	 * @var array
	 */
	public static $notices = array();

	/**
	 * Class constructor
	 */
	private function __construct() {
		define( 'WP_STREAM_PLUGIN', plugin_basename( __FILE__ ) );
		define( 'WP_STREAM_DIR', plugin_dir_path( __FILE__ ) );
		define( 'WP_STREAM_URL', plugin_dir_url( __FILE__ ) );
		define( 'WP_STREAM_INC_DIR', WP_STREAM_DIR . 'includes/' );
		define( 'WP_STREAM_CLASS_DIR', WP_STREAM_DIR . 'classes/' );
		define( 'WP_STREAM_EXTENSIONS_DIR', WP_STREAM_DIR . 'extensions/' );

		spl_autoload_register( array( $this, 'autoload' ) );

		// Load helper functions
		require_once WP_STREAM_INC_DIR . 'functions.php';

		// Load DB helper interface/class
		$driver = 'WP_Stream_DB';
		if ( class_exists( $driver ) ) {
			self::$db = new $driver;
		}

		if ( ! self::$db ) {
			wp_die( __( 'Stream: Could not load chosen DB driver.', 'stream' ), 'Stream DB Error' );
		}

		// Load API helper interface/class
		self::$api = new WP_Stream_API;

		// Install the plugin
		add_action( 'wp_stream_before_db_notices', array( __CLASS__, 'install' ) );

		// Load languages
		add_action( 'plugins_loaded', array( __CLASS__, 'i18n' ) );

		// Load settings, enabling extensions to hook in
		add_action( 'init', array( 'WP_Stream_Settings', 'load' ), 9 );

		// Load network class
		if ( is_multisite() ) {
			WP_Stream_Network::get_instance();
		}

		// Load logger class
		add_action( 'plugins_loaded', array( 'WP_Stream_Log', 'load' ) );

		// Load connectors after widgets_init, but before the default of 10
		add_action( 'init', array( 'WP_Stream_Connectors', 'load' ), 9 );

		// Load extensions
		foreach ( glob( WP_STREAM_EXTENSIONS_DIR . '*' ) as $extension ) {
			require_once sprintf( '%s/class-wp-stream-%s.php', $extension, basename( $extension ) );
		}

		// Load support for feeds
		add_action( 'init', array( 'WP_Stream_Feeds', 'load' ) );

		// Add frontend indicator
		add_action( 'wp_head', array( $this, 'frontend_indicator' ) );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'WP_Stream_Admin', 'load' ) );

			add_action( 'plugins_loaded', array( 'WP_Stream_Dashboard_Widget', 'load' ) );

			add_action( 'plugins_loaded', array( 'WP_Stream_Live_Update', 'load' ) );

			add_action( 'plugins_loaded', array( 'WP_Stream_Pointers', 'load' ) );

			add_action( 'plugins_loaded', array( 'WP_Stream_Migrate', 'load' ) );
		}
	}

	/**
	 * Invoked when the PHP version check fails. Load up the translations and
	 * add the error message to the admin notices
	 */
	static function fail_php_version() {
		add_action( 'plugins_loaded', array( __CLASS__, 'i18n' ) );
		self::notice( __( 'Stream requires PHP version 5.3+, plugin is currently NOT ACTIVE.', 'stream' ) );
	}

	/**
	* Autoloader for classes
	*
	* @param  string $class
	* @return void
	*/
	function autoload( $class ) {
		$class      = strtolower( str_replace( '_', '-', $class ) );
		$class_file = sprintf( '%sclass-%s.php', WP_STREAM_CLASS_DIR, $class );

		if ( is_readable( $class_file ) ) {
			require_once $class_file;
		}
	}

	/**
	 * Loads the translation files.
	 *
	 * @access public
	 * @action plugins_loaded
	 * @return void
	 */
	public static function i18n() {
		load_plugin_textdomain( 'stream', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}

	/**
	 * Whether the current PHP version meets the minimum requirements
	 *
	 * @return bool
	 */
	public static function is_valid_php_version() {
		return version_compare( PHP_VERSION, '5.3', '>=' );
	}

	/**
	 * Is Stream connected?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		return ( self::$api->api_key && self::$api->site_uuid );
	}

	/**
	 * Is Stream in development mode?
	 *
	 * @return bool
	 */
	public static function is_development_mode() {
		$development_mode = false;

		if ( defined( 'WP_STREAM_DEV_DEBUG' ) ) {
			$development_mode = WP_STREAM_DEV_DEBUG;
		} else if ( site_url() && false === strpos( site_url(), '.' ) ) {
			$development_mode = true;
		}

		return apply_filters( 'wp_stream_development_mode', $development_mode );
	}

	/**
	 * Handle notice messages according to the appropriate context (WP-CLI or the WP Admin)
	 *
	 * @param string $message
	 * @param bool $is_error
	 * @return void
	 */
	public static function notice( $message, $is_error = true ) {
		if ( defined( 'WP_CLI' ) ) {
			$message = strip_tags( $message );
			if ( $is_error ) {
				WP_CLI::warning( $message );
			} else {
				WP_CLI::success( $message );
			}
		} else {
			// Trigger admin notices
			add_action( 'all_admin_notices', array( __CLASS__, 'admin_notices' ) );

			self::$notices[] = compact( 'message', 'is_error' );
		}
	}

	/**
	 * Show an error or other message in the WP Admin
	 *
	 * @action all_admin_notices
	 * @return void
	 */
	public static function admin_notices() {
		foreach ( self::$notices as $notice ) {
			$class_name   = empty( $notice['is_error'] ) ? 'updated' : 'error';
			$html_message = sprintf( '<div class="%s">%s</div>', esc_attr( $class_name ), wpautop( $notice['message'] ) );

			echo wp_kses_post( $html_message );
		}
	}

	/**
	 * Displays an HTML comment in the frontend head to indicate that Stream is activated,
	 * and which version of Stream is currently in use.
	 *
	 * @since 1.4.5
	 *
	 * @action wp_head
	 * @return string|void An HTML comment, or nothing if the value is filtered out.
	 */
	public function frontend_indicator() {
		$comment = sprintf( 'Stream WordPress user activity plugin v%s', esc_html( self::VERSION ) ); // Localization not needed

		/**
		 * Filter allows the HTML output of the frontend indicator comment
		 * to be altered or removed, if desired.
		 *
		 * @return string $comment The content of the HTML comment
		 */
		$comment = apply_filters( 'wp_stream_frontend_indicator', $comment );

		if ( ! empty( $comment ) ) {
			echo sprintf( "<!-- %s -->\n", esc_html( $comment ) ); // xss ok
		}
	}

	/**
	 * Return active instance of WP_Stream, create one if it doesn't exist
	 *
	 * @return WP_Stream
	 */
	public static function get_instance() {
		if ( empty( self::$instance ) ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}

		return self::$instance;
	}

}

if ( WP_Stream::is_valid_php_version() ) {
	$GLOBALS['wp_stream'] = WP_Stream::get_instance();
} else {
	WP_Stream::fail_php_version();
}

register_deactivation_hook( __FILE__, array( 'WP_Stream_Admin', 'remove_api_authentication' ) );
