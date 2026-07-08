<?php
defined( 'ABSPATH' ) || exit;

/**
 * MCP Server for AI booking agents.
 *
 * Exposes a Model Context Protocol (MCP) server over Streamable HTTP
 * so AI agents can query services/availability and manage bookings.
 *
 * @since 1.2.0
 * @package GX_Zoho_Bookings
 */
final class GX_ZB_MCP_Server {

	/**
	 * Singleton instance.
	 *
	 * @since 1.2.0
	 * @var GX_ZB_MCP_Server|null
	 */
	private static $instance = null;

	/**
	 * Returns the singleton instance.
	 *
	 * @since 1.2.0
	 * @return GX_ZB_MCP_Server
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 *
	 * @since 1.2.0
	 */
	private function __construct() {}

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the MCP REST route.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			'gx-zb/v1',
			'/mcp',
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission callback for the MCP route.
	 *
	 * Checks mcp_enabled setting and API key from Authorization or X-GX-ZB-Key header.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request The incoming request.
	 * @return true|WP_Error True if authorized, WP_Error otherwise.
	 */
	public function check_permission( $request ) {
		$settings   = GX_ZB_Settings::instance();
		$mcp_enabled = $settings->get( 'mcp_enabled', false );
		$api_key    = $settings->get( 'mcp_api_key', '' );

		if ( ! $mcp_enabled || empty( $api_key ) ) {
			return new WP_Error(
				'gx_zb_mcp_disabled',
				__( 'MCP server is not enabled.', 'gx-zoho-bookings' ),
				array( 'status' => 403 )
			);
		}

		// Check Authorization: Bearer {key}.
		$auth_header  = $request->get_header( 'Authorization' );
		$provided_key = '';
		if ( ! empty( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			$provided_key = trim( substr( $auth_header, 7 ) );
		}

		// Fallback: X-GX-ZB-Key header.
		if ( empty( $provided_key ) ) {
			$provided_key = trim( (string) $request->get_header( 'X-GX-ZB-Key' ) );
		}

		if ( empty( $provided_key ) || ! hash_equals( $api_key, $provided_key ) ) {
			return new WP_Error(
				'gx_zb_mcp_unauthorized',
				__( 'Invalid or missing API key.', 'gx-zoho-bookings' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Main request handler.
	 *
	 * Dispatches JSON-RPC 2.0 requests. GET/DELETE return 405.
	 * Notifications (no id) return 202. Batch arrays return -32600.
	 *
	 * @since 1.2.0
	 * @param WP_REST_Request $request The incoming request.
	 * @return WP_REST_Response
	 */
	public function handle( $request ) {
		$http_method = $request->get_method();

		// GET and DELETE are not allowed.
		if ( 'POST' !== $http_method ) {
			return $this->rpc_error( null, -32601, 'Method Not Allowed', 405 );
		}

		$body = json_decode( $request->get_body(), true );

		// Malformed JSON.
		if ( null === $body && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->rpc_error( null, -32700, 'Parse error', 400 );
		}

		// Batch requests (JSON arrays) are not supported by this stateless server.
		if ( is_array( $body ) && isset( $body[0] ) ) {
			return $this->rpc_error( null, -32600, 'Batch requests not supported', 200 );
		}

		// Validate basic JSON-RPC structure.
		if ( ! is_array( $body ) || ! isset( $body['jsonrpc'] ) || '2.0' !== $body['jsonrpc'] || ! isset( $body['method'] ) ) {
			return $this->rpc_error( null, -32600, 'Invalid Request', 200 );
		}

		$rpc_method = $body['method'];
		$rpc_id     = array_key_exists( 'id', $body ) ? $body['id'] : null;
		$rpc_params = isset( $body['params'] ) ? $body['params'] : array();

		// Notifications: no id present.
		if ( null === $rpc_id ) {
			return new WP_REST_Response( '', 202 );
		}

		// Dispatch known methods.
		switch ( $rpc_method ) {
			case 'initialize':
				return $this->rpc_initialize( $rpc_params, $rpc_id );
			case 'ping':
				return $this->rpc_ping( $rpc_id );
			case 'tools/list':
				return $this->rpc_tools_list( $rpc_id );
			case 'tools/call':
				return $this->rpc_tools_call( $rpc_params, $rpc_id );
			default:
				return $this->rpc_error( $rpc_id, -32601, 'Method not found: ' . esc_html( $rpc_method ), 200 );
		}
	}

	/**
	 * Handles the 'initialize' JSON-RPC method.
	 *
	 * @since 1.2.0
	 * @param array $params Request params.
	 * @param mixed $id     Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_initialize( $params, $id ) {
		$client_version = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] ) && ! empty( $params['protocolVersion'] )
			? $params['protocolVersion']
			: '2025-06-18';

		$result = array(
			'protocolVersion' => $client_version,
			'capabilities'    => array(
				'tools' => array(
					'listChanged' => false,
				),
			),
			'serverInfo'      => array(
				'name'    => 'gx-zoho-bookings',
				'version' => GX_ZB_VERSION,
			),
		);

		return $this->rpc_result( $result, $id );
	}

	/**
	 * Handles the 'ping' JSON-RPC method.
	 *
	 * @since 1.2.0
	 * @param mixed $id Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_ping( $id ) {
		return $this->rpc_result( new stdClass(), $id );
	}

	/**
	 * Handles the 'tools/list' JSON-RPC method.
	 *
	 * @since 1.2.0
	 * @param mixed $id Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_tools_list( $id ) {
		$defs  = $this->tool_defs();
		$tools = array();

		foreach ( $defs as $name => $info ) {
			$tools[] = array(
				'name'        => $name,
				'description' => $info[0],
				'inputSchema' => $info[1],
			);
		}

		return $this->rpc_result( array( 'tools' => $tools ), $id );
	}

	/**
	 * Handles the 'tools/call' JSON-RPC method.
	 *
	 * @since 1.2.0
	 * @param array $params Request params containing 'name' and 'arguments'.
	 * @param mixed $id     Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_tools_call( $params, $id ) {
		if ( ! isset( $params['name'] ) || ! is_string( $params['name'] ) ) {
			return $this->rpc_error( $id, -32602, 'Invalid params: missing or invalid tool name', 200 );
		}

		$tool_name = $params['name'];
		$defs      = $this->tool_defs();

		if ( ! isset( $defs[ $tool_name ] ) ) {
			return $this->rpc_error( $id, -32602, 'Unknown tool: ' . esc_html( $tool_name ), 200 );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$schema    = $defs[ $tool_name ][1];

		// Validate arguments.
		$validation = $this->validate_tool_args( $arguments, $schema, $tool_name );
		if ( is_wp_error( $validation ) ) {
			return $this->rpc_tool_error( $validation->get_error_message(), $id );
		}

		// Check API connection for all tools except get_connection_status.
		if ( 'get_connection_status' !== $tool_name ) {
			$connected = $this->check_connected();
			if ( is_wp_error( $connected ) ) {
				return $this->rpc_tool_error( $connected->get_error_message(), $id );
			}
		}

		// Call the tool.
		$callback = $defs[ $tool_name ][2];
		$result   = call_user_func( array( $this, $callback ), $validation );

		if ( is_wp_error( $result ) ) {
			// Deliberate: forward the WP_Error message. These are our own
			// hand-written strings or Zoho's API errormessage — never tokens —
			// and the agent needs actionable errors (e.g. "reconnect") to
			// recover. The key holder already has full booking access.
			return $this->rpc_tool_error( $result->get_error_message(), $id );
		}

		// Wrap result in MCP content format.
		$content = array(
			array(
				'type' => 'text',
				'text' => wp_json_encode( $result ),
			),
		);

		return $this->rpc_result(
			array(
				'content' => $content,
				'isError' => false,
			),
			$id
		);
	}

	/**
	 * Checks if API mode is enabled and OAuth is connected.
	 *
	 * @since 1.2.0
	 * @return true|WP_Error True if connected, WP_Error otherwise.
	 */
	private function check_connected() {
		$settings = GX_ZB_Settings::instance();
		$mode     = $settings->get( 'mode', 'embed' );

		if ( 'api' !== $mode ) {
			return new WP_Error(
				'gx_zb_not_connected',
				'Zoho Bookings is not connected — complete OAuth in WordPress admin'
			);
		}

		$oauth = GX_ZB_OAuth::instance();
		if ( ! $oauth->is_connected() ) {
			return new WP_Error(
				'gx_zb_not_connected',
				'Zoho Bookings is not connected — complete OAuth in WordPress admin'
			);
		}

		return true;
	}

	/**
	 * Validates tool arguments against the input schema.
	 *
	 * @since 1.2.0
	 * @param array  $arguments The provided arguments.
	 * @param array  $schema    The JSON Schema for the tool.
	 * @param string $tool_name The tool name for error messages.
	 * @return array|WP_Error Sanitized arguments or WP_Error on validation failure.
	 */
	private function validate_tool_args( $arguments, $schema, $tool_name ) {
		$properties = isset( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) ? $schema['required'] : array();
		$sanitized  = array();

		// Check required fields.
		foreach ( $required as $field ) {
			if ( ! isset( $arguments[ $field ] ) || ( is_string( $arguments[ $field ] ) && '' === trim( $arguments[ $field ] ) ) ) {
				return new WP_Error(
					'gx_zb_validation_error',
					sprintf(
						/* translators: 1: tool name, 2: field name */
						'Validation error in tool "%1$s": required field "%2$s" is missing or empty.',
						$tool_name,
						$field
					)
				);
			}
		}

		// Validate each provided argument.
		foreach ( $arguments as $key => $value ) {
			if ( ! isset( $properties[ $key ] ) ) {
				// Unknown field — skip silently (could also error, but skipping is more forgiving).
				continue;
			}

			$prop = $properties[ $key ];
			$type = isset( $prop['type'] ) ? $prop['type'] : 'string';

			// Type validation.
			switch ( $type ) {
				case 'string':
					if ( ! is_string( $value ) ) {
						return new WP_Error(
							'gx_zb_validation_error',
							sprintf(
								/* translators: 1: tool name, 2: field name, 3: expected type */
								'Validation error in tool "%1$s": field "%2$s" must be a string.',
								$tool_name,
								$key
							)
						);
					}
					$sanitized[ $key ] = sanitize_text_field( $value );
					break;

				case 'integer':
				case 'number':
					if ( ! is_numeric( $value ) ) {
						return new WP_Error(
							'gx_zb_validation_error',
							sprintf(
								/* translators: 1: tool name, 2: field name */
								'Validation error in tool "%1$s": field "%2$s" must be a number.',
								$tool_name,
								$key
							)
						);
					}
					$sanitized[ $key ] = ( 'integer' === $type ) ? (int) $value : (float) $value;
					break;

				case 'array':
					if ( ! is_array( $value ) ) {
						return new WP_Error(
							'gx_zb_validation_error',
							sprintf(
								/* translators: 1: tool name, 2: field name */
								'Validation error in tool "%1$s": field "%2$s" must be an array.',
								$tool_name,
								$key
							)
						);
					}
					$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
					break;

				default:
					$sanitized[ $key ] = $value;
					break;
			}

			// Email format validation.
			if ( isset( $prop['format'] ) && 'email' === $prop['format'] && ! empty( $sanitized[ $key ] ) ) {
				if ( ! is_email( $sanitized[ $key ] ) ) {
					return new WP_Error(
						'gx_zb_validation_error',
						sprintf(
							/* translators: 1: tool name, 2: field name */
							'Validation error in tool "%1$s": field "%2$s" must be a valid email address.',
							$tool_name,
							$key
						)
					);
				}
			}

			// Enum validation.
			if ( isset( $prop['enum'] ) && ! empty( $sanitized[ $key ] ) ) {
				if ( ! in_array( $sanitized[ $key ], $prop['enum'], true ) ) {
					return new WP_Error(
						'gx_zb_validation_error',
						sprintf(
							/* translators: 1: tool name, 2: field name, 3: allowed values */
							'Validation error in tool "%1$s": field "%2$s" must be one of: %3$s.',
							$tool_name,
							$key,
							implode( ', ', $prop['enum'] )
						)
					);
				}
			}

			// Date format validation (YYYY-MM-DD).
			if ( isset( $prop['description'] ) && false !== strpos( $prop['description'], 'YYYY-MM-DD' ) && ! empty( $sanitized[ $key ] ) ) {
				$dt = DateTime::createFromFormat( 'Y-m-d', $sanitized[ $key ] );
				if ( ! $dt || $dt->format( 'Y-m-d' ) !== $sanitized[ $key ] ) {
					return new WP_Error(
						'gx_zb_validation_error',
						sprintf(
							/* translators: 1: tool name, 2: field name */
							'Validation error in tool "%1$s": field "%2$s" must be a valid date in YYYY-MM-DD format.',
							$tool_name,
							$key
						)
					);
				}
			}
		}

		// Also check required fields after sanitization for empty strings.
		foreach ( $required as $field ) {
			if ( ! isset( $sanitized[ $field ] ) || '' === $sanitized[ $field ] ) {
				return new WP_Error(
					'gx_zb_validation_error',
					sprintf(
						/* translators: 1: tool name, 2: field name */
						'Validation error in tool "%1$s": required field "%2$s" is missing or empty.',
						$tool_name,
						$field
					)
				);
			}
		}

		return $sanitized;
	}

	/**
	 * Converts a date string from YYYY-MM-DD to Zoho's dd-MMM-yyyy format.
	 *
	 * @since 1.2.0
	 * @param string $date Date in YYYY-MM-DD format.
	 * @return string Date in dd-MMM-yyyy format (e.g., 15-Mar-2025).
	 */
	private function date_to_zoho( $date ) {
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		return $dt->format( 'd-M-Y' );
	}

	/**
	 * Parses a time slot string and returns hours, minutes, seconds.
	 *
	 * Supports formats like "10:00 AM", "14:30", "2:00 PM", "10:00AM", etc.
	 *
	 * @since 1.2.0
	 * @param string $time The time slot string.
	 * @return array|WP_Error Array with 'hours', 'minutes', 'seconds' keys, or WP_Error.
	 */
	private function parse_time( $time ) {
		$formats = array( 'h:i A', 'H:i', 'h:iA', 'g:i A', 'g:iA', 'H:i:s', 'h:i:s A' );

		foreach ( $formats as $fmt ) {
			$dt = DateTime::createFromFormat( $fmt, trim( $time ) );
			if ( $dt ) {
				return array(
					'hours'   => (int) $dt->format( 'H' ),
					'minutes' => (int) $dt->format( 'i' ),
					'seconds' => (int) $dt->format( 's' ),
				);
			}
		}

		return new WP_Error(
			'gx_zb_validation_error',
			sprintf(
				/* translators: %s: time string */
				'Unable to parse time "%s". Please use a format like "10:00 AM" or "14:30".',
				esc_html( $time )
			)
		);
	}

	/**
	 * Combines a YYYY-MM-DD date and a time slot into Zoho's datetime format.
	 *
	 * @since 1.2.0
	 * @param string $date YYYY-MM-DD date.
	 * @param string $time Time slot string.
	 * @return string|WP_Error Datetime in 'dd-MMM-yyyy HH:mm:ss' format, or WP_Error.
	 */
	private function combine_date_time( $date, $time ) {
		$date_dt = DateTime::createFromFormat( 'Y-m-d', $date );
		if ( ! $date_dt ) {
			return new WP_Error(
				'gx_zb_validation_error',
				'Invalid date format. Expected YYYY-MM-DD.'
			);
		}

		$parsed_time = $this->parse_time( $time );
		if ( is_wp_error( $parsed_time ) ) {
			return $parsed_time;
		}

		$date_dt->setTime( $parsed_time['hours'], $parsed_time['minutes'], $parsed_time['seconds'] );
		return $date_dt->format( 'd-M-Y H:i:s' );
	}

	// =========================================================================
	// Tool implementations
	// =========================================================================

	/**
	 * Tool: get_connection_status
	 *
	 * Always works even when disconnected.
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (none expected).
	 * @return array
	 */
	private function tool_get_connection_status( $args ) {
		$settings = GX_ZB_Settings::instance();
		$oauth    = GX_ZB_OAuth::instance();

		return array(
			'mode'         => $settings->get( 'mode', 'embed' ),
			'region'       => $settings->get( 'region', 'us' ),
			'is_connected' => $oauth->is_connected(),
			'version'      => GX_ZB_VERSION,
		);
	}

	/**
	 * Tool: list_workspaces
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_list_workspaces( $args ) {
		$client = GX_ZB_API_Client::instance();
		return $client->get_workspaces();
	}

	/**
	 * Tool: list_services
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (may contain workspace_id).
	 * @return array|WP_Error
	 */
	private function tool_list_services( $args ) {
		$workspace_id = isset( $args['workspace_id'] ) ? $args['workspace_id'] : '';
		$client       = GX_ZB_API_Client::instance();
		return $client->get_services( $workspace_id );
	}

	/**
	 * Tool: list_staff
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (must contain service_id).
	 * @return array|WP_Error
	 */
	private function tool_list_staff( $args ) {
		$client = GX_ZB_API_Client::instance();
		return $client->get_staff( $args['service_id'] );
	}

	/**
	 * Tool: get_available_slots
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (service_id, staff_id, date).
	 * @return array|WP_Error
	 */
	private function tool_get_available_slots( $args ) {
		$client     = GX_ZB_API_Client::instance();
		$zoho_date  = $this->date_to_zoho( $args['date'] );
		return $client->get_available_slots( $args['service_id'], $args['staff_id'], $zoho_date );
	}

	/**
	 * Tool: list_appointments
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (status, from_date, to_date).
	 * @return array|WP_Error
	 */
	private function tool_list_appointments( $args ) {
		$client  = GX_ZB_API_Client::instance();
		$filters = array();

		if ( ! empty( $args['status'] ) ) {
			$filters['status'] = $args['status'];
		}
		if ( ! empty( $args['from_date'] ) ) {
			$filters['from_time'] = $this->date_to_zoho( $args['from_date'] );
		}
		if ( ! empty( $args['to_date'] ) ) {
			$filters['to_time'] = $this->date_to_zoho( $args['to_date'] );
		}

		return $client->get_appointments( $filters );
	}

	/**
	 * Tool: get_appointment
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (booking_id).
	 * @return array|WP_Error
	 */
	private function tool_get_appointment( $args ) {
		$client = GX_ZB_API_Client::instance();
		return $client->get_appointment( $args['booking_id'] );
	}

	/**
	 * Tool: create_booking
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_create_booking( $args ) {
		$from_time = $this->combine_date_time( $args['date'], $args['time'] );
		if ( is_wp_error( $from_time ) ) {
			return $from_time;
		}

		$customer_details = array(
			'name'         => $args['customer_name'],
			'email'        => $args['customer_email'],
			'phone_number' => isset( $args['customer_phone'] ) ? $args['customer_phone'] : '',
		);

		$create_args = array(
			'service_id'       => $args['service_id'],
			'staff_id'         => $args['staff_id'],
			'from_time'        => $from_time,
			'timezone'         => wp_timezone_string(),
			// Pass the array — GX_ZB_API_Client::create_appointment() JSON-encodes
			// it into the form field itself; encoding here would double-encode.
			'customer_details' => $customer_details,
		);

		if ( ! empty( $args['notes'] ) ) {
			$create_args['notes'] = $args['notes'];
		}

		$client = GX_ZB_API_Client::instance();
		$result = $client->create_appointment( $create_args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Wrap in ok/data envelope for consistency.
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: reschedule_booking
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_reschedule_booking( $args ) {
		$start_time = $this->combine_date_time( $args['date'], $args['time'] );
		if ( is_wp_error( $start_time ) ) {
			return $start_time;
		}

		$staff_id = isset( $args['staff_id'] ) ? $args['staff_id'] : '';
		$client   = GX_ZB_API_Client::instance();
		$result   = $client->reschedule_appointment( $args['booking_id'], $start_time, $staff_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: update_booking_status
	 *
	 * @since 1.2.0
	 * @param array $args Validated arguments (booking_id, action).
	 * @return array|WP_Error
	 */
	private function tool_update_booking_status( $args ) {
		$client = GX_ZB_API_Client::instance();
		$result = $client->update_appointment_status( $args['booking_id'], $args['action'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: create_workspace
	 *
	 * @since 1.3.0
	 * @param array $args Validated arguments (name).
	 * @return array|WP_Error
	 */
	private function tool_create_workspace( $args ) {
		$result = GX_ZB_API_Client::instance()->create_workspace( $args['name'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: create_service
	 *
	 * @since 1.3.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_create_service( $args ) {
		if ( isset( $args['assigned_staff_ids'] ) ) {
			$args['assigned_staffs'] = $args['assigned_staff_ids'];
			unset( $args['assigned_staff_ids'] );
		}
		$result = GX_ZB_API_Client::instance()->create_service( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: update_service
	 *
	 * @since 1.3.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_update_service( $args ) {
		$args['id'] = $args['service_id'];
		unset( $args['service_id'] );
		if ( isset( $args['assigned_staff_ids'] ) ) {
			$args['assigned_staffs'] = $args['assigned_staff_ids'];
			unset( $args['assigned_staff_ids'] );
		}
		$result = GX_ZB_API_Client::instance()->update_service( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: delete_service
	 *
	 * @since 1.3.0
	 * @param array $args Validated arguments (service_id).
	 * @return array|WP_Error
	 */
	private function tool_delete_service( $args ) {
		$result = GX_ZB_API_Client::instance()->delete_service( $args['service_id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: add_staff
	 *
	 * @since 1.3.0
	 * @param array $args Validated arguments.
	 * @return array|WP_Error
	 */
	private function tool_add_staff( $args ) {
		if ( isset( $args['assigned_service_ids'] ) ) {
			$args['assigned_services'] = $args['assigned_service_ids'];
			unset( $args['assigned_service_ids'] );
		}
		$result = GX_ZB_API_Client::instance()->add_staff( $args );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array(
			'ok'   => true,
			'data' => $result,
		);
	}

	/**
	 * Tool: create_payment_link
	 *
	 * @since 1.4.0
	 * @param array $args Validated arguments (service_id, customer_email?).
	 * @return array|WP_Error
	 */
	private function tool_create_payment_link( $args ) {
		$stripe = GX_ZB_Stripe::instance();
		if ( ! $stripe->is_enabled() ) {
			return new WP_Error( 'gx_zb_stripe_not_configured', __( 'Payments are not enabled or Stripe is not configured.', 'gx-zoho-bookings' ) );
		}

		$services = GX_ZB_API_Client::instance()->get_services();
		if ( is_wp_error( $services ) ) {
			return $services;
		}

		$cost = 0.0;
		$name = '';
		foreach ( (array) $services as $service ) {
			$sid = isset( $service['id'] ) ? (string) $service['id'] : ( isset( $service['service_id'] ) ? (string) $service['service_id'] : '' );
			if ( $sid === $args['service_id'] ) {
				$cost = isset( $service['cost'] ) ? floatval( $service['cost'] ) : 0.0;
				$name = isset( $service['name'] ) ? (string) $service['name'] : '';
				break;
			}
		}

		if ( '' === $name ) {
			return new WP_Error( 'gx_zb_api_error', __( 'Service not found.', 'gx-zoho-bookings' ) );
		}
		if ( $cost <= 0 ) {
			return new WP_Error( 'gx_zb_validation_error', __( 'This service is free — no payment link is needed.', 'gx-zoho-bookings' ) );
		}

		$session = $stripe->create_checkout_session(
			array(
				'amount_cents'   => (int) round( $cost * 100 ),
				'currency'       => $stripe->currency(),
				'product_name'   => $name,
				'customer_email' => isset( $args['customer_email'] ) ? $args['customer_email'] : '',
				'success_url'    => home_url( '/?gx_zb_pay=return&session_id={CHECKOUT_SESSION_ID}' ),
				'cancel_url'     => home_url( '/?gx_zb_pay=cancel' ),
				'metadata'       => array( 'source' => 'mcp' ),
			)
		);
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return array(
			'ok'       => true,
			'url'      => $session['url'],
			'amount'   => $cost,
			'currency' => $stripe->currency(),
		);
	}

	// =========================================================================
	// Tool definitions + JSON-RPC envelopes
	// =========================================================================

	/**
	 * Tool registry: name => array( description, inputSchema, callback method ).
	 *
	 * Descriptions are written for AI agents: what the tool does, when to use
	 * it, and argument format hints.
	 *
	 * @since 1.2.0
	 * @return array[]
	 */
	private function tool_defs() {
		$no_args = array(
			'type'       => 'object',
			'properties' => new stdClass(),
		);

		return array(
			'get_connection_status'  => array(
				'Check whether this WordPress site is connected to Zoho Bookings and which mode/region it uses. Call this first if any other tool reports a connection problem. Always available.',
				$no_args,
				'tool_get_connection_status',
			),
			'list_workspaces'        => array(
				'List Zoho Bookings workspaces for this account. Most accounts have exactly one; use its id as workspace_id in list_services if needed.',
				$no_args,
				'tool_list_workspaces',
			),
			'list_services'          => array(
				'List bookable services (name, id, duration). Start here when booking: you need a service_id for staff, slots and bookings.',
				array(
					'type'       => 'object',
					'properties' => array(
						'workspace_id' => array(
							'type'        => 'string',
							'description' => 'Optional Zoho workspace id; omit to use the default workspace.',
						),
					),
				),
				'tool_list_services',
			),
			'list_staff'             => array(
				'List staff members who can deliver a given service. You need a staff_id for get_available_slots and create_booking.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id' => array(
							'type'        => 'string',
							'description' => 'Service id from list_services.',
						),
					),
					'required'   => array( 'service_id' ),
				),
				'tool_list_staff',
			),
			'get_available_slots'    => array(
				'Get open time slots for a service + staff member on a date. Always call this before create_booking and pass one of the returned slot strings as the time argument.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id' => array(
							'type'        => 'string',
							'description' => 'Service id from list_services.',
						),
						'staff_id'   => array(
							'type'        => 'string',
							'description' => 'Staff id from list_staff.',
						),
						'date'       => array(
							'type'        => 'string',
							'description' => 'Date in YYYY-MM-DD format.',
						),
					),
					'required'   => array( 'service_id', 'staff_id', 'date' ),
				),
				'tool_get_available_slots',
			),
			'list_appointments'      => array(
				'List appointments, optionally filtered by status and/or date range. Use to review the schedule or find a booking_id.',
				array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'upcoming', 'completed', 'cancel', 'noshow' ),
							'description' => 'Optional status filter.',
						),
						'from_date' => array(
							'type'        => 'string',
							'description' => 'Optional range start in YYYY-MM-DD format.',
						),
						'to_date'   => array(
							'type'        => 'string',
							'description' => 'Optional range end in YYYY-MM-DD format.',
						),
					),
				),
				'tool_list_appointments',
			),
			'get_appointment'        => array(
				'Fetch full details of one appointment by its booking_id (from list_appointments or a create_booking response).',
				array(
					'type'       => 'object',
					'properties' => array(
						'booking_id' => array(
							'type'        => 'string',
							'description' => 'Zoho booking id.',
						),
					),
					'required'   => array( 'booking_id' ),
				),
				'tool_get_appointment',
			),
			'create_booking'         => array(
				'Create an appointment for a customer. Workflow: list_services -> list_staff -> get_available_slots -> create_booking with one of the returned slots as time. Returns the created booking details.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id'     => array(
							'type'        => 'string',
							'description' => 'Service id from list_services.',
						),
						'staff_id'       => array(
							'type'        => 'string',
							'description' => 'Staff id from list_staff.',
						),
						'date'           => array(
							'type'        => 'string',
							'description' => 'Date in YYYY-MM-DD format.',
						),
						'time'           => array(
							'type'        => 'string',
							'description' => 'A slot string returned by get_available_slots, e.g. "10:00 AM" or "14:30".',
						),
						'customer_name'  => array(
							'type'        => 'string',
							'description' => 'Customer full name.',
						),
						'customer_email' => array(
							'type'        => 'string',
							'format'      => 'email',
							'description' => 'Customer email address.',
						),
						'customer_phone' => array(
							'type'        => 'string',
							'description' => 'Optional customer phone number.',
						),
						'notes'          => array(
							'type'        => 'string',
							'description' => 'Optional notes for the appointment.',
						),
					),
					'required'   => array( 'service_id', 'staff_id', 'date', 'time', 'customer_name', 'customer_email' ),
				),
				'tool_create_booking',
			),
			'reschedule_booking'     => array(
				'Move an existing appointment to a new date/time (and optionally different staff). Check get_available_slots for the new time first.',
				array(
					'type'       => 'object',
					'properties' => array(
						'booking_id' => array(
							'type'        => 'string',
							'description' => 'Zoho booking id to reschedule.',
						),
						'date'       => array(
							'type'        => 'string',
							'description' => 'New date in YYYY-MM-DD format.',
						),
						'time'       => array(
							'type'        => 'string',
							'description' => 'New time slot string, e.g. "10:00 AM" or "14:30".',
						),
						'staff_id'   => array(
							'type'        => 'string',
							'description' => 'Optional new staff id.',
						),
					),
					'required'   => array( 'booking_id', 'date', 'time' ),
				),
				'tool_reschedule_booking',
			),
			'update_booking_status'  => array(
				'Mark an appointment completed, cancel it, or record a no-show. This changes real customer bookings — confirm intent before cancelling.',
				array(
					'type'       => 'object',
					'properties' => array(
						'booking_id' => array(
							'type'        => 'string',
							'description' => 'Zoho booking id.',
						),
						'action'     => array(
							'type'        => 'string',
							'enum'        => array( 'completed', 'cancel', 'noshow' ),
							'description' => 'New status action.',
						),
					),
					'required'   => array( 'booking_id', 'action' ),
				),
				'tool_update_booking_status',
			),
			'create_workspace'       => array(
				'Create a Zoho Bookings workspace. Name must be 2-50 characters and cannot contain any of | / \\ , ? { } < > : ; " \' `. Most accounts need only one workspace; the free plan allows one.',
				array(
					'type'       => 'object',
					'properties' => array(
						'name' => array(
							'type'        => 'string',
							'description' => 'Workspace name, 2-50 chars, no | / \\ , ? { } < > : ; " \' ` characters.',
						),
					),
					'required'   => array( 'name' ),
				),
				'tool_create_workspace',
			),
			'create_service'         => array(
				'Create a one-on-one service in a workspace (only one-on-one is supported by the Zoho API). Get workspace_id from list_workspaces. Returns a success message.',
				array(
					'type'       => 'object',
					'properties' => array(
						'name'               => array( 'type' => 'string', 'description' => 'Service name.' ),
						'workspace_id'       => array( 'type' => 'string', 'description' => 'Workspace id from list_workspaces.' ),
						'duration'           => array( 'type' => 'integer', 'description' => 'Duration in minutes (default 30).' ),
						'cost'               => array( 'type' => 'number', 'description' => 'Service cost.' ),
						'description'        => array( 'type' => 'string', 'description' => 'Service description.' ),
						'pre_buffer'         => array( 'type' => 'integer', 'description' => 'Buffer minutes before the appointment.' ),
						'post_buffer'        => array( 'type' => 'integer', 'description' => 'Buffer minutes after the appointment.' ),
						'meeting_mode'       => array( 'type' => 'string', 'enum' => array( 'online', 'offline' ), 'description' => 'online or offline (default offline).' ),
						'meeting_type'       => array( 'type' => 'string', 'enum' => array( 'zohomeeting', 'zoom', 'teams', 'gmeet', '' ), 'description' => 'Required when meeting_mode is online.' ),
						'assigned_staff_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Staff ids to assign (from list_staff).' ),
					),
					'required'   => array( 'name', 'workspace_id' ),
				),
				'tool_create_service',
			),
			'update_service'         => array(
				'Update an existing service. Supply only the fields you want to change. Use status to activate/deactivate. Get service_id from list_services.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id'         => array( 'type' => 'string', 'description' => 'Service id to update.' ),
						'name'               => array( 'type' => 'string', 'description' => 'New service name.' ),
						'duration'           => array( 'type' => 'integer', 'description' => 'Duration in minutes.' ),
						'cost'               => array( 'type' => 'number', 'description' => 'Service cost.' ),
						'description'        => array( 'type' => 'string', 'description' => 'Service description.' ),
						'pre_buffer'         => array( 'type' => 'integer', 'description' => 'Buffer minutes before.' ),
						'post_buffer'        => array( 'type' => 'integer', 'description' => 'Buffer minutes after.' ),
						'status'             => array( 'type' => 'string', 'enum' => array( 'active', 'in_active' ), 'description' => 'Service status.' ),
						'meeting_mode'       => array( 'type' => 'string', 'enum' => array( 'online', 'offline' ), 'description' => 'online or offline.' ),
						'meeting_type'       => array( 'type' => 'string', 'enum' => array( 'zohomeeting', 'zoom', 'teams', 'gmeet', '' ), 'description' => 'Required when meeting_mode is online.' ),
						'assigned_staff_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Staff ids to assign.' ),
					),
					'required'   => array( 'service_id' ),
				),
				'tool_update_service',
			),
			'delete_service'         => array(
				'DESTRUCTIVE and IRREVERSIBLE: permanently delete a service. Always confirm with the user before calling. Only works for one-on-one services.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id' => array( 'type' => 'string', 'description' => 'Service id to permanently delete.' ),
					),
					'required'   => array( 'service_id' ),
				),
				'tool_delete_service',
			),
			'add_staff'              => array(
				'Add a staff member. Provide name and email (required). Note: the Zoho API cannot edit or remove staff afterward — that is done in the Zoho Bookings admin UI. The free plan typically allows one staff member.',
				array(
					'type'       => 'object',
					'properties' => array(
						'name'                 => array( 'type' => 'string', 'description' => 'Staff full name.' ),
						'email'                => array( 'type' => 'string', 'format' => 'email', 'description' => 'Staff email address.' ),
						'phone'                => array( 'type' => 'string', 'description' => 'Phone number.' ),
						'designation'          => array( 'type' => 'string', 'description' => 'Job title.' ),
						'role'                 => array( 'type' => 'string', 'enum' => array( 'Staff', 'Manager', 'Admin' ), 'description' => 'Role (default Staff).' ),
						'assigned_service_ids' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Service ids to assign the staff to.' ),
					),
					'required'   => array( 'name', 'email' ),
				),
				'tool_add_staff',
			),
			'create_payment_link'    => array(
				'Create a Stripe Checkout payment link for a paid service, to send to a customer. Returns a URL where they pay securely on Stripe. Errors if the service is free or payments are not enabled. Payment is handled entirely by Stripe.',
				array(
					'type'       => 'object',
					'properties' => array(
						'service_id'     => array( 'type' => 'string', 'description' => 'Service id from list_services (must have a price).' ),
						'customer_email' => array( 'type' => 'string', 'format' => 'email', 'description' => 'Optional customer email to prefill at checkout.' ),
					),
					'required'   => array( 'service_id' ),
				),
				'tool_create_payment_link',
			),
			// FUTURE (paid plan): payment capture webhooks + multi-resource booking tools.
		);
	}

	/**
	 * Build a JSON-RPC success response.
	 *
	 * @since 1.2.0
	 * @param mixed $result Result payload.
	 * @param mixed $id     Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_result( $result, $id ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Build a JSON-RPC protocol error response.
	 *
	 * @since 1.2.0
	 * @param mixed  $id      Request id (null when unknown).
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	private function rpc_error( $id, $code, $message, $status = 200 ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Build a tool-level error (MCP isError content), NOT a protocol error.
	 *
	 * @since 1.2.0
	 * @param string $message Human-readable error for the agent.
	 * @param mixed  $id      Request id.
	 * @return WP_REST_Response
	 */
	private function rpc_tool_error( $message, $id ) {
		return $this->rpc_result(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
				),
				'isError' => true,
			),
			$id
		);
	}
}
