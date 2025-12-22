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

        $enrolled  = sfwd_lms_has_access( $course_id, $user->ID );
        $completed = learndash_course_completed( $user->ID, $course_id );

        $data = [
            'success'     => true,
            'user_exists' => true,
            'enrolled'    => (bool) $enrolled,
            'completed'   => (bool) $completed,
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
        $user_ids = learndash_get_course_users_access_from_meta( $course_id );

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

            $enrolled = sfwd_lms_has_access( $course_id, $user->ID );

            $students[] = [
                'user_id'    => $user->ID,
                'email'      => $user->user_email,
                'first_name' => get_user_meta( $user->ID, 'first_name', true ),
                'last_name'  => get_user_meta( $user->ID, 'last_name', true ),
                'enrolled'   => (bool) $enrolled,
                'completed'  => (bool) learndash_course_completed( $user->ID, $course_id ),
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
}
