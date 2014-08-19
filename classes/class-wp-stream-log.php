<?php

class WP_Stream_Log {

	/**
	 * Log buffer key/identifier
	 */
	const LOG_BUFFER_OPTION_KEY = 'wp_stream_log_buffer';

	/**
	 * Buffer schedule hook name
	 */
	const LOG_CLEAN_BUFFER_CRON_HOOK = 'wp_stream_clean_buffer_cron';

	/**
	 * Insert record schedule hook name
	 */
	const LOG_INSERT_RECORD_CRON_HOOK = 'wp_stream_insert_record_cron';

	/**
	 * Log handler
	 *
	 * @var \WP_Stream_Log
	 */
	public static $instance = null;

	/**
	 * Total number of records to log per API call
	 *
	 * @var int
	 */
	public static $limit = 500;

	/**
	 * Load log handler class, filterable by extensions
	 *
	 * @return void
	 */
	public static function load() {
		/**
		 * Filter allows developers to change log handler class
		 *
		 * @param  array   Current Class
		 * @return string  New Class for log handling
		 */
		$log_handler = apply_filters( 'wp_stream_log_handler', __CLASS__ );

		add_action( self::LOG_CLEAN_BUFFER_CRON_HOOK, array( __CLASS__, 'clean_buffer' ) );
		add_action( self::LOG_INSERT_RECORD_CRON_HOOK, array( __CLASS__, 'insert_record' ) );

		self::$instance = new $log_handler;
	}

	/**
	 * Return active instance of this class
	 *
	 * @return WP_Stream_Log
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			$class = __CLASS__;
			self::$instance = new $class;
		}

		return self::$instance;
	}

	/**
	 * Retrieve records waiting to be logged from the local database
	 *
	 * @return array
	 */
	public static function get_buffer() {
		global $wpdb;

		$buffer = array();

		$buffer_parts = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name LIKE %s", self::LOG_BUFFER_OPTION_KEY . '_%' ) );
		$buffer_parts = array_map( 'maybe_unserialize', $buffer_parts );

		foreach ( $buffer_parts as $buffer_part ) {
			$buffer = array_merge( $buffer, $buffer_part );
		}

		return $buffer;
	}

	/**
	 * Save any records waiting to be logged in the local database
	 *
	 * @return void
	 */
	public static function save_buffer( $buffer ) {
		global $wpdb;

		$current_buffer_parts = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(option_id) FROM $wpdb->options WHERE option_name LIKE %s", self::LOG_BUFFER_OPTION_KEY . '_%' ) );

		for ( $i = count( $buffer ); $i <= $current_buffer_parts; $i++ ) {
			delete_option( self::LOG_BUFFER_OPTION_KEY . '_' . $i );
		}

		if ( empty( $buffer ) ) {
			return;
		}

		$grouped_records = array();

		for ( $i = 0; $i < count( $buffer ); $i += self::$limit ) {
			$grouped_records[] = array_slice( $buffer, $i, self::$limit );
		}

		foreach ( $grouped_records as $key => $grouped_record ) {
			update_option( self::LOG_BUFFER_OPTION_KEY . '_' . $key, $grouped_record );
		}
	}

	/**
	 * Gets records stored in the buffer and attempts to bulk send them to the API
	 *
	 * @return void
	 */
	public static function clean_buffer() {
		$buffer = self::get_buffer();

		$request = WP_Stream::$db->store( $buffer[0] );

		// Clear buffer on success
		if ( $request ) {
			unset( $buffer[0] );
			save_buffer( $buffer );
		}

		if ( empty( $buffer ) ) {
			wp_clear_scheduled_hook( self::LOG_CLEAN_BUFFER_CRON_HOOK );
		}
	}

	/**
	 * Sends an API call to log records
	 *
	 * Used to schedule records for creation in a non-blocking way via WP_Cron
	 * Stores failed requests in a local database buffer and schedules a retry
	 *
	 * @param  array Record to be added
	 * @return mixed Request data on success|false on failure
	 */
	public static function insert_record( $record ) {
		$request = WP_Stream::$db->store( array( $record ) );

		if ( ! $request || is_wp_error( $request ) ) {
			$buffer   = self::get_buffer();
			$buffer[] = $record;

			WP_Stream_Log::save_buffer( $buffer );

			// Schedule buffer clearance
			if ( ! wp_next_scheduled( self::LOG_CLEAN_BUFFER_CRON_HOOK ) ) {
				$buffer_schedule = apply_filters( 'wp_stream_buffer_schedule', 'hourly', $buffer );
				wp_schedule_event( time() + HOUR_IN_SECONDS, $buffer_schedule, self::LOG_CLEAN_BUFFER_CRON_HOOK );
			}
		}

		return $request;
	}

	/**
	 * Log handler
	 *
	 * @param         $connector
	 * @param  string $message   sprintf-ready error message string
	 * @param  array  $args      sprintf (and extra) arguments to use
	 * @param  int    $object_id Target object id
	 * @param  string $context   Context of the event
	 * @param  string $action    Action of the event
	 * @param  int    $user_id   User responsible for the event
	 *
	 * @return void
	 */
	public function log( $connector, $message, $args, $object_id, $context, $action, $user_id = null ) {
		global $wpdb;

		if ( is_null( $user_id ) ) {
			$user_id = get_current_user_id();
		}

		if ( is_null( $object_id ) ) {
			$object_id = 0;
		}

		$user  = new WP_User( $user_id );
		$roles = get_option( $wpdb->get_blog_prefix() . 'user_roles' );

		$visibility = 'publish';
		if ( self::is_record_excluded( $connector, $context, $action, $user ) ) {
			$visibility = 'private';
		}

		if ( ! isset( $args['author_meta'] ) ) {
			$args['author_meta'] = array(
				'user_email'      => $user->user_email,
				'display_name'    => ( defined( 'WP_CLI' ) && empty( $user->display_name ) ) ? 'WP-CLI' : $user->display_name,
				'user_login'      => $user->user_login,
				'user_role_label' => ! empty( $user->roles ) ? $roles[ $user->roles[0] ]['name'] : null,
				'agent'           => WP_Stream_Author::get_current_agent(),
			);

			if ( ( defined( 'WP_CLI' ) ) && function_exists( 'posix_getuid' ) ) {
				$uid       = posix_getuid();
				$user_info = posix_getpwuid( $uid );

				$args['author_meta']['system_user_id']   = $uid;
				$args['author_meta']['system_user_name'] = $user_info['name'];
			}
		}

		// Remove meta with null values from being logged
		$meta = array_filter(
			$args,
			function ( $var ) {
				return ! is_null( $var );
			}
		);

		// Get current time with milliseconds
		$iso_8601_extended_date = wp_stream_get_iso_8601_extended_date();

		$recordarr = array(
			'object_id'   => $object_id,
			'site_id'     => is_multisite() ? get_current_site()->id : 1,
			'blog_id'     => apply_filters( 'blog_id_logged', is_network_admin() ? 0 : get_current_blog_id() ),
			'author'      => $user_id,
			'author_role' => ! empty( $user->roles ) ? $user->roles[0] : null,
			'created'     => $iso_8601_extended_date,
			'visibility'  => $visibility,
			'type'        => 'stream',
			'summary'     => vsprintf( $message, $args ),
			'connector'   => $connector,
			'context'     => $context,
			'action'      => $action,
			'stream_meta' => $meta,
			'ip'          => wp_stream_filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP ),
		);

		// Schedule the record to be added immediately. This allows sending a new record to the API without effecting load time.
		wp_schedule_single_event( time(), self::LOG_INSERT_RECORD_CRON_HOOK, array( $recordarr ) );
	}

	/**
	 * This function is use to check whether or not a record should be excluded from the log
	 *
	 * @param $connector string name of the connector being logged
	 * @param $context   string name of the context being logged
	 * @param $action    string name of the action being logged
	 * @param $user_id   int    id of the user being logged
	 * @param $ip        string ip address being logged
	 * @return bool
	 */
	public function is_record_excluded( $connector, $context, $action, $user = null, $ip = null ) {
		if ( is_null( $user ) ) {
			$user = wp_get_current_user();
		}

		if ( is_null( $ip ) ) {
			$ip = wp_stream_filter_input( INPUT_SERVER, 'REMOTE_ADDR', FILTER_VALIDATE_IP );
		} else {
			$ip = wp_stream_filter_var( $ip, FILTER_VALIDATE_IP );
		}

		$user_role = isset( $user->roles[0] ) ? $user->roles[0] : null;

		$record = array(
			'connector'  => $connector,
			'context'    => $context,
			'action'     => $action,
			'author'     => $user->ID,
			'role'       => $user_role,
			'ip_address' => $ip,
		);

		$exclude_settings = isset( WP_Stream_Settings::$options['exclude_rules'] ) ? WP_Stream_Settings::$options['exclude_rules'] : array();

		if ( isset( $exclude_settings['exclude_row'] ) && ! empty( $exclude_settings['exclude_row'] ) ) {
			foreach ( $exclude_settings['exclude_row'] as $key => $value ) {
				// Prepare values
				$author_or_role = isset( $exclude_settings['author_or_role'][ $key ] ) ? $exclude_settings['author_or_role'][ $key ] : '';
				$connector      = isset( $exclude_settings['connector'][ $key ] ) ? $exclude_settings['connector'][ $key ] : '';
				$context        = isset( $exclude_settings['context'][ $key ] ) ? $exclude_settings['context'][ $key ] : '';
				$action         = isset( $exclude_settings['action'][ $key ] ) ? $exclude_settings['action'][ $key ] : '';
				$ip_address     = isset( $exclude_settings['ip_address'][ $key ] ) ? $exclude_settings['ip_address'][ $key ] : '';

				$exclude = array(
					'connector'  => ! empty( $connector ) ? $connector : null,
					'context'    => ! empty( $context ) ? $context : null,
					'action'     => ! empty( $action ) ? $action : null,
					'ip_address' => ! empty( $ip_address ) ? $ip_address : null,
					'author'     => is_numeric( $author_or_role ) ? $author_or_role : null,
					'role'       => ( ! empty( $author_or_role ) && ! is_numeric( $author_or_role ) ) ? $author_or_role : null,
				);

				$exclude_rules = array_filter( $exclude, 'strlen' );

				if ( ! empty( $exclude_rules ) ) {
					$excluded = true;

					foreach ( $exclude_rules as $exclude_key => $exclude_value ) {
						if ( $record[ $exclude_key ] !== $exclude_value ) {
							$excluded = false;
							break;
						}
					}

					if ( $excluded ) {
						return true;
					}
				}
			}
		}

		return false;
	}

}
