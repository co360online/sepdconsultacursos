<?php
/**
 * Plugin Name: CO360 LearnDash API
 * Description: REST API endpoint to check LearnDash enrollments and list course students for corporate integrations.
 * Version: 1.0.0
 * Author: CO360
 * License: GPL-2.0-or-later
 * Requires PHP: 7.4.33
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define API key constant. In future versions, move this to a settings page.
if ( ! defined( 'CO360_LD_API_KEY' ) ) {
    define( 'CO360_LD_API_KEY', 'CAMBIAR_POR_CLAVE_REAL' );
}

// Plugin path constants.
if ( ! defined( 'CO360_LD_API_PATH' ) ) {
    define( 'CO360_LD_API_PATH', plugin_dir_path( __FILE__ ) );
}

require_once CO360_LD_API_PATH . 'includes/api-endpoint.php';

add_action( 'plugins_loaded', [ 'CO360_Learndash_API', 'init' ] );
