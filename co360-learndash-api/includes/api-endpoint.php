<?php
/**
 * CO360 LearnDash API endpoint definitions.
 *
 * @package CO360_Learndash_API
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles REST API exposure for LearnDash enrollment queries.
 */
class CO360_Learndash_API {
    /**
     * REST namespace.
     */
    const REST_NAMESPACE = 'co360/v1';

    /**
     * Initialize hooks.
     */
    public static function init() : void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register REST routes.
     */
    public static function register_routes() : void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/learndash/enrollment',
            [
                'methods'             => [ 'POST', 'GET' ],
                'callback'            => [ __CLASS__, 'handle_request' ],
                'permission_callback' => [ __CLASS__, 'check_api_key' ],
                'args'                => [
                    'email'     => [
                        'required'          => false,
                        'sanitize_callback' => 'sanitize_email',
                    ],
                    'course_id' => [
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    /**
     * Validates the API key provided in headers.
     *
     * @param \WP_REST_Request $request REST request instance.
     *
     * @return bool|\WP_Error True when valid, WP_Error otherwise.
     */
    public static function check_api_key( \WP_REST_Request $request ) {
        $api_key = $request->get_header( 'x-api-key' );
        $api_key = is_string( $api_key ) ? sanitize_text_field( wp_unslash( $api_key ) ) : '';

        // For GET requests, allow api_key query parameter as a fallback for testing scenarios.
        if ( empty( $api_key ) && 'GET' === $request->get_method() ) {
            $api_key_param = $request->get_param( 'api_key' );
            if ( is_string( $api_key_param ) ) {
                $api_key = sanitize_text_field( wp_unslash( $api_key_param ) );
            }
        }

        if ( empty( $api_key ) || $api_key !== CO360_LD_API_KEY ) {
            return new \WP_Error(
                'co360_invalid_api_key',
                __( 'Invalid API key.', 'co360-learndash-api' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * Handles the enrollment check/list request.
     *
     * @param \WP_REST_Request $request REST request.
     *
     * @return \WP_REST_Response|\WP_Error Response data.
     */
    public static function handle_request( \WP_REST_Request $request ) {
        $params = self::extract_params( $request );

        $course_id = $params['course_id'];
        $email     = $params['email'];

        if ( empty( $course_id ) ) {
            return new \WP_Error(
                'co360_missing_course',
                __( 'The "course_id" parameter is required.', 'co360-learndash-api' ),
                [ 'status' => 400 ]
            );
        }

        $course = get_post( $course_id );
        if ( ! $course || 'sfwd-courses' !== $course->post_type ) {
            return new \WP_Error(
                'co360_course_not_found',
                __( 'Course not found.', 'co360-learndash-api' ),
                [ 'status' => 404 ]
            );
        }

        if ( ! empty( $email ) ) {
            return self::handle_single_student( $email, $course_id );
        }

        return self::handle_course_list( $course_id );
    }

    /**
     * Extract sanitized params from JSON body or query string.
     *
     * @param \WP_REST_Request $request REST request.
     *
     * @return array{course_id:int,email:string}
     */
    protected static function extract_params( \WP_REST_Request $request ) : array {
        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            $params = $request->get_params();
        }

        $course_id = isset( $params['course_id'] ) ? absint( $params['course_id'] ) : 0;
        $email     = isset( $params['email'] ) ? sanitize_email( wp_unslash( $params['email'] ) ) : '';

        return [
            'course_id' => $course_id,
            'email'     => $email,
        ];
    }

    /**
     * Handle mode A: single student enrollment lookup.
     *
     * @param string $email     Student email address.
     * @param int    $course_id Course ID.
     *
     * @return \WP_REST_Response Response data.
     */
    protected static function handle_single_student( string $email, int $course_id ) : \WP_REST_Response {
        if ( ! is_email( $email ) ) {
            return new \WP_REST_Response(
                [
                    'success'     => false,
                    'message'     => __( 'Invalid email format.', 'co360-learndash-api' ),
                    'user_exists' => false,
                    'enrolled'    => false,
                    'completed'   => false,
                ],
                400
            );
        }

        $user = get_user_by( 'email', $email );

        if ( ! $user || ! $user instanceof \WP_User ) {
            return new \WP_REST_Response(
                [
                    'success'     => true,
                    'user_exists' => false,
                    'enrolled'    => false,
                    'completed'   => false,
                ],
                200
            );
        }

        $completed   = (bool) learndash_course_completed( $user->ID, $course_id );
        $enrolled_ts = self::normalize_timestamp( ld_course_access_from( $course_id, $user->ID ) );

        $completed_ts = self::normalize_timestamp( learndash_user_get_course_completed_date( $user->ID, $course_id ) );
        $direct_user_ids = learndash_get_course_users_access_from_meta( $course_id );
        $direct_lookup   = [];

        if ( is_array( $direct_user_ids ) ) {
            $direct_lookup = array_fill_keys( array_map( 'intval', $direct_user_ids ), true );
        }

        $is_direct_access_user = isset( $direct_lookup[ (int) $user->ID ] );
        $enrolled              = self::calculate_enrolled_status( $course_id, $user->ID, $is_direct_access_user, $enrolled_ts, $completed );

        $data = [
            'success'     => true,
            'user_exists' => true,
            'enrolled'    => $enrolled,
            'completed'   => (bool) $completed,
            'enrolled_at_ts'  => $enrolled_ts,
            'enrolled_at'     => self::format_human_date( $enrolled_ts ),
            'completed_at_ts' => $completed_ts,
            'completed_at'    => self::format_human_date( $completed_ts ),
        ];

        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Handle mode B: list students enrolled in a course.
     *
     * @param int $course_id Course ID.
     *
     * @return \WP_REST_Response Response data.
     */
    protected static function handle_course_list( int $course_id ) : \WP_REST_Response {
        $user_ids = self::co360_ld_get_course_user_ids( $course_id );
        $direct_user_ids = learndash_get_course_users_access_from_meta( $course_id );
        $direct_lookup   = [];

        if ( is_array( $direct_user_ids ) ) {
            $direct_lookup = array_fill_keys( array_map( 'intval', $direct_user_ids ), true );
        }

        if ( empty( $user_ids ) || ! is_array( $user_ids ) ) {
            return new \WP_REST_Response(
                [
                    'success'   => true,
                    'course_id' => $course_id,
                    'total'     => 0,
                    'students'  => [],
                ],
                200
            );
        }

        $students = [];

        foreach ( $user_ids as $user_id ) {
            $user_id = absint( $user_id );
            if ( $user_id <= 0 ) {
                continue;
            }

            $user = get_user_by( 'id', $user_id );
            if ( ! $user || ! $user instanceof \WP_User ) {
                continue;
            }

            $enrolled_ts  = self::normalize_timestamp( ld_course_access_from( $course_id, $user->ID ) );
            $completed    = (bool) learndash_course_completed( $user->ID, $course_id );
            $completed_ts = self::normalize_timestamp( learndash_user_get_course_completed_date( $user->ID, $course_id ) );
            $is_direct_access_user = isset( $direct_lookup[ (int) $user->ID ] );
            $enrolled              = self::calculate_enrolled_status( $course_id, $user->ID, $is_direct_access_user, $enrolled_ts, $completed );

            $students[] = [
                'user_id'    => $user->ID,
                'email'      => $user->user_email,
                'first_name' => get_user_meta( $user->ID, 'first_name', true ),
                'last_name'  => get_user_meta( $user->ID, 'last_name', true ),
                'enrolled'   => $enrolled,
                'completed'  => $completed,
                'enrolled_at_ts'  => $enrolled_ts,
                'enrolled_at'     => self::format_human_date( $enrolled_ts ),
                'completed_at_ts' => $completed_ts,
                'completed_at'    => self::format_human_date( $completed_ts ),
            ];
        }

        $data = [
            'success'   => true,
            'course_id' => $course_id,
            'total'     => count( $students ),
            'students'  => $students,
        ];

        return new \WP_REST_Response( $data, 200 );
    }

    /**
     * Build a complete user list for a course roster by combining multiple LearnDash sources.
     *
     * @param int $course_id Course ID.
     *
     * @return int[] Unique user IDs.
     */
    protected static function co360_ld_get_course_user_ids( int $course_id ) : array {
        $user_ids = [];

        // Source 1: users with direct course access metadata.
        $direct_user_ids = learndash_get_course_users_access_from_meta( $course_id );
        if ( is_array( $direct_user_ids ) ) {
            $user_ids = array_merge( $user_ids, $direct_user_ids );
        }

        // Source 2: users with completed activity records in LearnDash activity table.
        global $wpdb;
        $activity_table = $wpdb->prefix . 'learndash_user_activity';
        $table_exists   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity_table ) );

        if ( $table_exists === $activity_table ) {
            $completed_user_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT user_id
                    FROM {$activity_table}
                    WHERE course_id = %d
                        AND activity_type = %s
                        AND activity_completed > 0",
                    $course_id,
                    'course'
                )
            );

            if ( is_array( $completed_user_ids ) ) {
                $user_ids = array_merge( $user_ids, $completed_user_ids );
            }
        }

        // Source 3: users with potential access via LearnDash groups related to this course.
        if ( function_exists( 'learndash_get_course_groups' ) && function_exists( 'learndash_get_groups_user_ids' ) ) {
            $group_ids = learndash_get_course_groups( $course_id, true );
            if ( is_array( $group_ids ) ) {
                foreach ( $group_ids as $group_id ) {
                    $group_id = absint( $group_id );
                    if ( $group_id <= 0 ) {
                        continue;
                    }

                    $group_user_ids = learndash_get_groups_user_ids( $group_id );
                    if ( is_array( $group_user_ids ) ) {
                        $user_ids = array_merge( $user_ids, $group_user_ids );
                    }
                }
            }
        }

        return array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
    }

    /**
     * Determine enrolled status using direct access, dates, LearnDash access and completion fallback.
     *
     * @param int      $course_id             Course ID.
     * @param int      $user_id               User ID.
     * @param bool     $is_direct_access_user Whether user belongs to direct access list.
     * @param int|null $enrolled_ts           Enrollment timestamp.
     * @param bool     $completed             Course completed flag.
     *
     * @return bool
     */
    protected static function calculate_enrolled_status( int $course_id, int $user_id, bool $is_direct_access_user, ?int $enrolled_ts, bool $completed ) : bool {
        return (bool) (
            $is_direct_access_user
            || ! empty( $enrolled_ts )
            || sfwd_lms_has_access( $course_id, $user_id )
            || $completed
        );
    }

    /**
     * Normalize a LearnDash timestamp return value.
     *
     * @param mixed $timestamp Timestamp from LearnDash helpers.
     *
     * @return int|null Normalized timestamp or null when missing.
     */
    protected static function normalize_timestamp( $timestamp ) : ?int {
        if ( empty( $timestamp ) || ! is_numeric( $timestamp ) ) {
            return null;
        }

        $timestamp = (int) $timestamp;
        if ( $timestamp <= 0 ) {
            return null;
        }

        return $timestamp;
    }

    /**
     * Format a human-readable date from a timestamp.
     *
     * @param int|null $timestamp Unix timestamp.
     *
     * @return string|null Date in YYYY-MM-DD or null when missing.
     */
    protected static function format_human_date( ?int $timestamp ) : ?string {
        if ( null === $timestamp ) {
            return null;
        }

        // Use ISO-like date format for API consumers while keeping timestamps unchanged.
        return date( 'Y-m-d', $timestamp );
    }
}
