<?php
/**
 * Plugin Name: Empire Dispatch - Carrier Prequalification Engine & Leads Manager
 * Plugin URI:  https://www.dispatch.businessautomationempire.com/
 * Description: Stores all carrier factoring prequal submissions in a dedicated database table, captures prefilled link clicks, relays leads to n8n, and provides a full analytics & lead evaluation dashboard in WP Admin with 1-click CSV export.
 * Version:     1.2.0
 * Author:      Empire Dispatch
 * Author URI:  https://www.dispatch.businessautomationempire.com/
 * License:     GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Empire_Carrier_Prequal_Manager {

    const DB_VERSION = '1.2.1';
    const TABLE_NAME = 'carrier_prequal_submissions';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'plugins_loaded', array( $this, 'check_db_schema' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_post_export_prequal_leads_csv', array( $this, 'export_leads_csv' ) );
        add_action( 'wp_ajax_update_prequal_lead_status', array( $this, 'ajax_update_lead_status' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'request', array( $this, 'prevent_wp_reserved_var_collision' ), 1 );
        add_filter( 'the_content', array( $this, 'preserve_inline_scripts' ), 999 );
        add_action( 'wp_head', array( $this, 'inject_ga4_tracking_code' ), 1 );
    }

    /**
     * Prevent WordPress convert_chars / wptexturize from corrupting inline scripts inside post content.
     */
    public function preserve_inline_scripts( $content ) {
        if ( false === strpos( $content, '<script' ) ) {
            return $content;
        }
        return preg_replace_callback( '/<script\b[^>]*>(.*?)<\/script>/is', function( $matches ) {
            return str_replace( array( '&#038;&#038;', '&#038;', '&amp;&amp;', '&amp;' ), array( '&&', '&', '&&', '&' ), $matches[0] );
        }, $content );
    }

    /**
     * Prevent WordPress from treating ?name=... in campaign prefill links as a post slug query (which causes 404s).
     */
    public function prevent_wp_reserved_var_collision( $query_vars ) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( strpos( $uri, 'carrier-factoring-prequalification-engine' ) !== false ) {
            if ( isset( $query_vars['name'] ) ) {
                unset( $query_vars['name'] );
                $query_vars['pagename'] = 'carrier-factoring-prequalification-engine';
            }
        }
        return $query_vars;
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public function check_db_schema() {
        $installed_ver = get_option( 'empire_prequal_db_version' );
        if ( $installed_ver !== self::DB_VERSION ) {
            $this->create_db_table();
            update_option( 'empire_prequal_db_version', self::DB_VERSION );
        }
    }

    public function activate() {
        $this->create_db_table();
        update_option( 'empire_prequal_db_version', self::DB_VERSION );
    }

    public function create_db_table() {
        global $wpdb;
        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prequal_token varchar(64) NOT NULL DEFAULT '',
            submission_time datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mc_number varchar(100) NOT NULL DEFAULT '',
            company_name varchar(150) NOT NULL DEFAULT '',
            authority_age varchar(100) NOT NULL DEFAULT '',
            equipment_type varchar(150) NOT NULL DEFAULT '',
            monthly_gross varchar(100) NOT NULL DEFAULT '',
            factoring_status varchar(150) NOT NULL DEFAULT '',
            first_name varchar(100) NOT NULL DEFAULT '',
            phone varchar(50) NOT NULL DEFAULT '',
            email varchar(150) NOT NULL DEFAULT '',
            matched_partner varchar(50) NOT NULL DEFAULT '',
            partner_name varchar(100) NOT NULL DEFAULT '',
            lead_status varchar(50) NOT NULL DEFAULT 'New',
            partner_url text DEFAULT NULL,
            notes text DEFAULT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent text DEFAULT NULL,
            utm_source varchar(100) DEFAULT '',
            utm_medium varchar(100) DEFAULT '',
            utm_campaign varchar(100) DEFAULT '',
            n8n_sync_status varchar(100) NOT NULL DEFAULT 'Pending',
            PRIMARY KEY  (id),
            KEY submission_time (submission_time),
            KEY prequal_token (prequal_token),
            KEY matched_partner (matched_partner),
            KEY lead_status (lead_status),
            KEY email (email),
            KEY mc_number (mc_number)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }

    public function register_rest_endpoints() {
        register_rest_route( 'empire-dispatch/v1', '/prequal-submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_prequal_submit' ),
            'permission_callback' => '__return_true',
        ) );
        register_rest_route( 'empire/v1', '/prequal-submit', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_prequal_submit' ),
            'permission_callback' => '__return_true',
        ) );

        // Dedicated endpoint for initial link-click tracking
        register_rest_route( 'empire-dispatch/v1', '/prequal-ping', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_prequal_ping' ),
            'permission_callback' => '__return_true',
        ) );

        // Realtime stats endpoint for admin dashboard
        register_rest_route( 'empire-dispatch/v1', '/realtime-stats', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_realtime_stats' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );

        // Realtime test ping simulator
        register_rest_route( 'empire-dispatch/v1', '/realtime-test-ping', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_realtime_test_ping' ),
            'permission_callback' => function() { return current_user_can( 'manage_options' ); },
        ) );
    }

    /**
     * Handle initial click / visit ping when trucker opens personalized link
     */
    public function handle_prequal_ping( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }

        $mc_number    = sanitize_text_field( $params['mc_dot'] ?? $params['mc_number'] ?? $params['mc'] ?? '' );
        $company_name = sanitize_text_field( $params['company_name'] ?? $params['company'] ?? '' );
        $first_name   = sanitize_text_field( $params['owner_name'] ?? $params['first_name'] ?? $params['name'] ?? '' );
        $phone        = sanitize_text_field( $params['phone'] ?? '' );
        $email        = sanitize_email( $params['email'] ?? '' );
        $utm_source   = sanitize_text_field( $params['utm_source'] ?? 'kit_email' );
        $utm_medium   = sanitize_text_field( $params['utm_medium'] ?? 'email' );
        $utm_campaign = sanitize_text_field( $params['utm_campaign'] ?? '' );

        // Only record if we have an identifying piece of information
        if ( empty( $email ) && empty( $mc_number ) && empty( $phone ) ) {
            return new WP_REST_Response( array( 'success' => false, 'message' => 'Anonymous visit' ), 200 );
        }

        $table_name = self::get_table_name();

        // Check if lead was already logged in the last 24h
        $existing_id = null;
        if ( ! empty( $email ) ) {
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table_name WHERE email = %s AND submission_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1",
                $email
            ) );
        } elseif ( ! empty( $mc_number ) ) {
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table_name WHERE mc_number = %s AND submission_time >= DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1",
                $mc_number
            ) );
        }

        if ( $existing_id ) {
            $existing_token = $wpdb->get_var( $wpdb->prepare(
                "SELECT prequal_token FROM $table_name WHERE id = %d",
                $existing_id
            ) );
            if ( empty( $existing_token ) ) {
                $existing_token = wp_generate_password( 32, false );
                $wpdb->update( $table_name, array( 'prequal_token' => $existing_token ), array( 'id' => $existing_id ) );
            }
            return new WP_REST_Response( array(
                'success'       => true,
                'submission_id' => (int) $existing_id,
                'prequal_token' => $existing_token,
                'status'        => 'existing_session'
            ), 200 );
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip_address = trim( $parts[0] );
        }
        $user_agent = sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) );

        $prequal_token = wp_generate_password( 32, false );

        $wpdb->insert(
            $table_name,
            array(
                'prequal_token'    => $prequal_token,
                'submission_time'  => current_time( 'mysql' ),
                'mc_number'        => $mc_number,
                'company_name'     => $company_name,
                'authority_age'    => '',
                'equipment_type'   => '',
                'monthly_gross'    => '',
                'factoring_status' => '',
                'first_name'       => $first_name,
                'phone'            => $phone,
                'email'            => $email,
                'matched_partner'  => '',
                'partner_name'     => '',
                'lead_status'      => 'Form Opened',
                'partner_url'      => null,
                'notes'            => 'Clicked link from email. Form loaded with prefilled carrier info. Awaiting answers to 2 questions.',
                'ip_address'       => $ip_address,
                'user_agent'       => $user_agent,
                'utm_source'       => $utm_source,
                'utm_medium'       => $utm_medium,
                'utm_campaign'     => $utm_campaign,
                'n8n_sync_status'  => 'Form Opened'
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );

        $submission_id = $wpdb->insert_id;

        return new WP_REST_Response( array(
            'success'       => true,
            'submission_id' => $submission_id,
            'prequal_token' => $prequal_token,
            'status'        => 'lead_captured_on_open'
        ), 200 );
    }

    /**
     * Handle Form Submission (full completion)
     */
    public function handle_prequal_submit( WP_REST_Request $request ) {
        global $wpdb;
        $params = $request->get_json_params();
        if ( empty( $params ) ) {
            $params = $request->get_body_params();
        }

        $submission_id    = intval( $params['submission_id'] ?? 0 );
        $prequal_token    = sanitize_text_field( $params['prequal_token'] ?? '' );
        $mc_number        = sanitize_text_field( $params['mc_dot'] ?? $params['mc_number'] ?? $params['mc'] ?? '' );
        $company_name     = sanitize_text_field( $params['company_name'] ?? $params['company'] ?? '' );
        $first_name       = sanitize_text_field( $params['owner_name'] ?? $params['first_name'] ?? $params['name'] ?? '' );
        $authority_age    = sanitize_text_field( $params['authority_age'] ?? '' );
        $equipment        = sanitize_text_field( $params['equipment'] ?? 'Dry Van (53ft)' );
        $monthly_gross    = sanitize_text_field( $params['monthly_gross'] ?? '' );
        $factoring_status = sanitize_text_field( $params['factoring_status'] ?? 'not_factoring' );
        $phone            = sanitize_text_field( $params['phone'] ?? '' );
        $email            = sanitize_email( $params['email'] ?? '' );
        $utm_source       = sanitize_text_field( $params['utm_source'] ?? '' );
        $utm_medium       = sanitize_text_field( $params['utm_medium'] ?? '' );
        $utm_campaign     = sanitize_text_field( $params['utm_campaign'] ?? '' );

        if ( empty( $email ) || ! is_email( $email ) || empty( $mc_number ) || empty( $company_name ) || empty( $first_name ) || empty( $phone ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'All contact fields (MC/DOT, Company, Owner Name, Phone, and valid Email) are required.'
            ), 400 );
        }

        // Strict allowed values check
        $allowed_authority = array( 'under_90_days', 'over_90_days' );
        if ( ! in_array( $authority_age, $allowed_authority, true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid authority age selection.'
            ), 400 );
        }

        $allowed_revenue = array( 'under_10k', 'under_15k', '10k_25k', '15k_35k', '25k_50k_plus', '35k_75k', '75k_plus' );
        if ( ! in_array( $monthly_gross, $allowed_revenue, true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid monthly revenue selection.'
            ), 400 );
        }

        $allowed_equipment = array(
            'Dry Van (53ft)', 'Dry Van (53\')',
            'Reefer (Temperature-Controlled)', 'Reefer (53\')',
            'Flatbed / Step Deck', 'Flatbed / Step',
            '26ft Box Truck',
            'Hotshot / Power Only', 'Hotshot / Power',
            'Multi-Truck Fleet', 'Fleet (2+ Units)'
        );
        if ( ! in_array( $equipment, $allowed_equipment, true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid equipment selection.'
            ), 400 );
        }

        $allowed_factoring = array( 'not_factoring', 'rate_too_high', 'locked_contract', 'contract_locked', 'exploring', 'clean_never' );
        if ( ! in_array( $factoring_status, $allowed_factoring, true ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Invalid factoring status selection.'
            ), 400 );
        }

        // Prequal Engine Partner Match Logic
        $is_under_age   = ($authority_age === 'under_90_days');
        $is_low_revenue = ($monthly_gross === 'under_10k' || $monthly_gross === 'under_15k');
        $is_locked      = ($factoring_status === 'locked_contract' || $factoring_status === 'contract_locked');

        $is_summar = ( ! $is_under_age && ! $is_low_revenue && ! $is_locked );

        $matched_partner = $is_summar ? 'SUMMAR' : 'BASICBLOCK';
        $partner_name    = $is_summar ? 'Summar Financial' : 'BasicBlock';
        $partner_url     = $is_summar 
            ? 'https://cwlqj04.na1.hs-sales-engage.com/Elizabeth-Valencia' 
            : 'https://na2.hubs.ly/y0y4mS0';

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip_address = trim( $parts[0] );
        }
        $user_agent = sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) );

        $table_name = self::get_table_name();

        $lead_data = array(
            'mc_number'        => $mc_number,
            'company_name'     => $company_name,
            'authority_age'    => $authority_age,
            'equipment_type'   => $equipment,
            'monthly_gross'    => $monthly_gross,
            'factoring_status' => $factoring_status,
            'first_name'       => $first_name,
            'phone'            => $phone,
            'email'            => $email,
            'matched_partner'  => $matched_partner,
            'partner_name'     => $partner_name,
            'lead_status'      => 'New',
            'partner_url'      => $partner_url,
            'ip_address'       => $ip_address,
            'user_agent'       => $user_agent,
            'utm_source'       => $utm_source,
            'utm_medium'       => $utm_medium,
            'utm_campaign'     => $utm_campaign,
            'n8n_sync_status'  => 'Pending'
        );

        $verified_record_id = 0;
        if ( $submission_id && ! empty( $prequal_token ) ) {
            $verified_record_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM $table_name WHERE id = %d AND prequal_token = %s",
                $submission_id,
                $prequal_token
            ) );
        }

        if ( $verified_record_id ) {
            $wpdb->update(
                $table_name,
                $lead_data,
                array( 'id' => $verified_record_id )
            );
            $submission_id = $verified_record_id;
        } else {
            $prequal_token = wp_generate_password( 32, false );
            $lead_data['prequal_token']   = $prequal_token;
            $lead_data['submission_time'] = current_time( 'mysql' );
            $lead_data['notes']           = '';
            $wpdb->insert( $table_name, $lead_data );
            $submission_id = $wpdb->insert_id;
        }

        // Relay to n8n webhook
        $n8n_webhook_url = 'https://n8n.businessautomationempire.online/webhook/trucker-factoring-prequal';
        $n8n_payload = array(
            'mc_dot'           => $mc_number,
            'mc_number'        => $mc_number,
            'company_name'     => $company_name,
            'owner_name'       => $first_name,
            'first_name'       => $first_name,
            'authority_age'    => $authority_age,
            'equipment'        => $equipment,
            'monthly_gross'    => $monthly_gross,
            'factoring_status' => $factoring_status,
            'phone'            => $phone,
            'email'            => $email,
            'submission_id'    => $submission_id,
            'source'           => 'dispatch_wp_engine',
            'utm_source'       => $utm_source,
            'utm_medium'       => $utm_medium,
            'utm_campaign'     => $utm_campaign
        );

        $email_sent = false;
        $n8n_status = 'Pending';

        $n8n_response = wp_remote_post( $n8n_webhook_url, array(
            'method'      => 'POST',
            'timeout'     => 10,
            'redirection' => 5,
            'headers'     => array( 'Content-Type' => 'application/json' ),
            'body'        => wp_json_encode( $n8n_payload ),
        ) );

        if ( ! is_wp_error( $n8n_response ) ) {
            $code = wp_remote_retrieve_response_code( $n8n_response );
            $body = wp_remote_retrieve_body( $n8n_response );
            $data = json_decode( $body, true );
            if ( $code >= 200 && $code < 300 ) {
                $n8n_status = 'Success (HTTP ' . $code . ')';
                if ( isset( $data['email_sent'] ) && $data['email_sent'] === true ) {
                    $email_sent = true;
                }
            } else {
                $n8n_status = 'Failed (HTTP ' . $code . ')';
            }
        } else {
            $n8n_status = 'Error: ' . $n8n_response->get_error_message();
        }

        if ( $submission_id ) {
            $wpdb->update(
                $table_name,
                array( 'n8n_sync_status' => $n8n_status ),
                array( 'id' => $submission_id ),
                array( '%s' ),
                array( '%d' )
            );
        }

        return new WP_REST_Response( array(
            'success'         => true,
            'tier'            => $matched_partner,
            'matched_partner' => $matched_partner,
            'partner_name'    => $partner_name,
            'booking_url'     => $is_summar ? $partner_url : null,
            'activation_url'  => ! $is_summar ? $partner_url : null,
            'email_sent'      => $email_sent,
            'submission_id'   => $submission_id,
            'prequal_token'   => $prequal_token,
            'message'         => 'Carrier prequalification record saved and processed successfully.'
        ), 200 );
    }

    public function register_admin_menus() {
        add_menu_page(
            'Carrier Prequal Leads',
            'Prequal Leads',
            'manage_options',
            'carrier-prequal-leads',
            array( $this, 'render_admin_dashboard' ),
            'dashicons-chart-area',
            21
        );

        add_submenu_page(
            'carrier-prequal-leads',
            'All Submissions',
            'All Submissions',
            'manage_options',
            'carrier-prequal-leads',
            array( $this, 'render_admin_dashboard' )
        );

        add_submenu_page(
            'carrier-prequal-leads',
            'Realtime Dashboard',
            '⚡ Realtime Dashboard',
            'manage_options',
            'carrier-prequal-analytics-guide',
            array( $this, 'render_realtime_dashboard_page' )
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'carrier-prequal' ) === false ) {
            return;
        }
        wp_enqueue_script( 'jquery' );
    }

    public function ajax_update_lead_status() {
        check_ajax_referer( 'empire_prequal_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied' );
        }

        global $wpdb;
        $id     = intval( $_POST['id'] ?? 0 );
        $status = sanitize_text_field( $_POST['status'] ?? 'New' );
        $notes  = sanitize_textarea_field( $_POST['notes'] ?? '' );

        if ( ! $id ) {
            wp_send_json_error( 'Invalid lead ID' );
        }

        $table_name = self::get_table_name();
        $wpdb->update(
            $table_name,
            array(
                'lead_status' => $status,
                'notes'       => $notes
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'message' => 'Status updated successfully' ) );
    }

    public function export_leads_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized user.' );
        }

        global $wpdb;
        $table_name = self::get_table_name();
        $results = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC", ARRAY_A );

        $filename = 'carrier_prequal_leads_' . date( 'Y-m-d_H-i-s' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );
        fputs( $output, "\xEF\xBB\xBF" );

        fputcsv( $output, array(
            'Lead ID',
            'Date & Time (UTC)',
            'Carrier / Driver Name',
            'Company Legal Name',
            'Phone',
            'Email',
            'MC/DOT #',
            'Authority Age',
            'Equipment Type',
            'Monthly Gross Revenue',
            'Current Factoring Status',
            'Matched Partner',
            'Partner Name',
            'Lead Status',
            'n8n Sync Status',
            'Internal Notes',
            'UTM Source',
            'UTM Medium',
            'UTM Campaign',
            'IP Address'
        ) );

        if ( ! empty( $results ) ) {
            foreach ( $results as $row ) {
                fputcsv( $output, array(
                    $row['id'],
                    $row['submission_time'],
                    $row['first_name'],
                    $row['company_name'],
                    $row['phone'],
                    $row['email'],
                    $row['mc_number'],
                    $row['authority_age'],
                    $row['equipment_type'],
                    $row['monthly_gross'],
                    $row['factoring_status'],
                    $row['matched_partner'],
                    $row['partner_name'],
                    $row['lead_status'],
                    $row['n8n_sync_status'],
                    $row['notes'],
                    $row['utm_source'],
                    $row['utm_medium'],
                    $row['utm_campaign'],
                    $row['ip_address']
                ) );
            }
        }

        fclose( $output );
        exit;
    }

    public function render_admin_dashboard() {
        global $wpdb;
        $table_name = self::get_table_name();

        $search   = sanitize_text_field( $_GET['s'] ?? '' );
        $partner  = sanitize_text_field( $_GET['partner'] ?? '' );
        $status   = sanitize_text_field( $_GET['status'] ?? '' );
        $paged    = max( 1, intval( $_GET['paged'] ?? 1 ) );
        $per_page = 25;
        $offset   = ( $paged - 1 ) * $per_page;

        $where = array( '1=1' );
        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = $wpdb->prepare( "(first_name LIKE %s OR company_name LIKE %s OR email LIKE %s OR phone LIKE %s OR mc_number LIKE %s)", $like, $like, $like, $like, $like );
        }
        if ( ! empty( $partner ) ) {
            $where[] = $wpdb->prepare( "matched_partner = %s", $partner );
        }
        if ( ! empty( $status ) ) {
            $where[] = $wpdb->prepare( "lead_status = %s", $status );
        }
        $where_sql = implode( ' AND ', $where );

        $total_leads      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        $completed_leads  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE matched_partner != ''" );
        $opened_leads     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE lead_status = 'Form Opened'" );
        $summar_leads     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'SUMMAR'" );
        $basicblock_leads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'BASICBLOCK'" );
        $today_leads      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE DATE(submission_time) = CURDATE()" );
        $funded_leads     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE lead_status = 'Funded'" );

        $total_filtered   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE $where_sql" );
        $total_pages      = ceil( $total_filtered / $per_page );
        $leads            = $wpdb->get_results( "SELECT * FROM $table_name WHERE $where_sql ORDER BY id DESC LIMIT $per_page OFFSET $offset", ARRAY_A );

        $nonce = wp_create_nonce( 'empire_prequal_nonce' );
        $export_url = admin_url( 'admin-post.php?action=export_prequal_leads_csv' );
        ?>
        <div class="wrap" style="max-width:1300px; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom:20px;">
                <div>
                    <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin:0 0 4px 0;">
                        🚛 Carrier Factoring Prequalification Leads
                    </h1>
                    <p style="margin:0; color:#64748b; font-size:14px;">
                        Live database of truckers prequalified via <a href="<?php echo home_url('/carrier-factoring-prequalification-engine/'); ?>" target="_blank" style="color:#0284c7; text-decoration:none; font-weight:600;">The 45-Second Prequalification Engine &rarr;</a>
                    </p>
                </div>
                <div style="display:flex; gap:10px; margin-top:10px;">
                    <a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" style="background:#059669; border-color:#059669; padding:6px 16px; height:auto; font-weight:600;">
                        📥 Export All Leads (CSV)
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=monsterinsights_reports'); ?>" class="button button-secondary" style="padding:6px 14px; height:auto; font-weight:600;">
                        📊 MonsterInsights Traffic & Clicks
                    </a>
                </div>
            </div>

            <!-- KPI Metric Cards -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:16px; margin-bottom:24px;">
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.5px;">Total Captured</div>
                    <div style="font-size:32px; font-weight:800; color:#0f172a; margin-top:6px;"><?php echo number_format($total_leads); ?></div>
                    <div style="font-size:12px; color:#10b981; margin-top:4px; font-weight:600;">In Database</div>
                </div>

                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-left:4px solid #10b981;">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#059669; letter-spacing:0.5px;">Summar Matches</div>
                    <div style="font-size:32px; font-weight:800; color:#059669; margin-top:6px;"><?php echo number_format($summar_leads); ?></div>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">
                        <?php echo $completed_leads > 0 ? round(($summar_leads / $completed_leads) * 100, 1) : 0; ?>% of submissions (To Elizabeth)
                    </div>
                </div>

                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-left:4px solid #0284c7;">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#0284c7; letter-spacing:0.5px;">BasicBlock Matches</div>
                    <div style="font-size:32px; font-weight:800; color:#0284c7; margin-top:6px;"><?php echo number_format($basicblock_leads); ?></div>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">
                        <?php echo $completed_leads > 0 ? round(($basicblock_leads / $completed_leads) * 100, 1) : 0; ?>% (&lt;90d / Flex)
                    </div>
                </div>

                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05); border-left:4px solid #f59e0b;">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#d97706; letter-spacing:0.5px;">Clicked Link / Open</div>
                    <div style="font-size:32px; font-weight:800; color:#d97706; margin-top:6px;"><?php echo number_format($opened_leads); ?></div>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Visited via email (Follow-up)</div>
                </div>

                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                    <div style="font-size:12px; font-weight:700; text-transform:uppercase; color:#64748b; letter-spacing:0.5px;">Today's Inflow</div>
                    <div style="font-size:32px; font-weight:800; color:#6366f1; margin-top:6px;"><?php echo number_format($today_leads); ?></div>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Active today</div>
                </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 20px; margin-bottom:20px;">
                <form method="GET" action="" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <input type="hidden" name="page" value="carrier-prequal-leads">
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search Name, Company, Email, MC#, Phone..." style="min-width:260px; padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">

                    <select name="partner" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                        <option value="">All Partners</option>
                        <option value="SUMMAR" <?php selected($partner, 'SUMMAR'); ?>>Summar Financial</option>
                        <option value="BASICBLOCK" <?php selected($partner, 'BASICBLOCK'); ?>>BasicBlock</option>
                    </select>

                    <select name="status" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px;">
                        <option value="">All Statuses</option>
                        <option value="New" <?php selected($status, 'New'); ?>>New (Submitted)</option>
                        <option value="Form Opened" <?php selected($status, 'Form Opened'); ?>>Form Opened (Incomplete)</option>
                        <option value="Contacted" <?php selected($status, 'Contacted'); ?>>Contacted</option>
                        <option value="In Review" <?php selected($status, 'In Review'); ?>>In Review</option>
                        <option value="Funded" <?php selected($status, 'Funded'); ?>>Funded</option>
                        <option value="Disqualified" <?php selected($status, 'Disqualified'); ?>>Disqualified</option>
                    </select>

                    <button type="submit" class="button" style="padding:6px 14px; font-weight:600;">Filter</button>
                    <?php if ( ! empty($search) || ! empty($partner) || ! empty($status) ) : ?>
                        <a href="<?php echo admin_url('admin.php?page=carrier-prequal-leads'); ?>" class="button-link" style="color:#ef4444; font-size:13px; text-decoration:none;">Reset</a>
                    <?php endif; ?>

                    <span style="margin-left:auto; color:#64748b; font-size:13px;">
                        Showing <?php echo count($leads); ?> of <?php echo $total_filtered; ?> leads
                    </span>
                </form>
            </div>

            <!-- Leads Table -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow-x:auto; box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <table class="wp-list-table widefat fixed striped table-view-list" style="border:0; min-width:1150px;">
                    <thead>
                        <tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0;">
                            <th style="width:45px; font-weight:700; color:#475569;">ID</th>
                            <th style="width:130px; font-weight:700; color:#475569;">Date & Time</th>
                            <th style="width:190px; font-weight:700; color:#475569;">Carrier Contact</th>
                            <th style="width:95px; font-weight:700; color:#475569;">MC/DOT</th>
                            <th style="width:105px; font-weight:700; color:#475569;">Authority</th>
                            <th style="width:130px; font-weight:700; color:#475569;">Equipment</th>
                            <th style="width:110px; font-weight:700; color:#475569;">Gross Rev.</th>
                            <th style="width:140px; font-weight:700; color:#475569;">Factoring Status</th>
                            <th style="width:130px; font-weight:700; color:#475569;">Partner Match</th>
                            <th style="width:125px; font-weight:700; color:#475569;">Lead Status</th>
                            <th style="width:85px; font-weight:700; color:#475569;">n8n Sync</th>
                            <th style="width:75px; font-weight:700; color:#475569;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $leads ) ) : ?>
                            <tr>
                                <td colspan="12" style="text-align:center; padding:32px; color:#64748b; font-size:15px;">
                                    No prequalification records found. Submissions from <a href="<?php echo home_url('/carrier-factoring-prequalification-engine/'); ?>" target="_blank">the form</a> will appear here instantly!
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $leads as $lead ) : 
                                $is_summar = ($lead['matched_partner'] === 'SUMMAR');
                                $is_bb = ($lead['matched_partner'] === 'BASICBLOCK');
                                $badge_bg = $is_summar ? '#ecfdf5' : ($is_bb ? '#f0f9ff' : '#fef3c7');
                                $badge_color = $is_summar ? '#065f46' : ($is_bb ? '#0369a1' : '#92400e');
                                $badge_border = $is_summar ? '#a7f3d0' : ($is_bb ? '#bae6fd' : '#fde68a');
                            ?>
                                <tr id="lead-row-<?php echo $lead['id']; ?>">
                                    <td style="font-weight:700; color:#64748b;">#<?php echo $lead['id']; ?></td>
                                    <td style="font-size:12px; color:#475569;">
                                        <strong><?php echo date('M j, Y', strtotime($lead['submission_time'])); ?></strong><br>
                                        <span style="color:#94a3b8;"><?php echo date('g:i A', strtotime($lead['submission_time'])); ?></span>
                                    </td>
                                    <td>
                                        <strong style="color:#0f172a; font-size:14px;"><?php echo esc_html($lead['first_name'] ?: 'Driver'); ?></strong>
                                        <?php if ( ! empty($lead['company_name']) ) : ?>
                                            <div style="font-size:12px; color:#475569; font-weight:600;"><?php echo esc_html($lead['company_name']); ?></div>
                                        <?php endif; ?>
                                        <a href="mailto:<?php echo esc_attr($lead['email']); ?>" style="color:#0284c7; text-decoration:none; font-size:12px;"><?php echo esc_html($lead['email']); ?></a><br>
                                        <a href="tel:<?php echo esc_attr($lead['phone']); ?>" style="color:#64748b; text-decoration:none; font-size:12px;"><?php echo esc_html($lead['phone']); ?></a>
                                    </td>
                                    <td>
                                        <span style="display:inline-block; background:#f1f5f9; padding:2px 8px; border-radius:4px; font-weight:600; font-size:12px; font-family:monospace;">
                                            <?php echo esc_html($lead['mc_number']); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php 
                                            if ( empty($lead['authority_age']) ) {
                                                echo '<span style="color:#94a3b8;">—</span>';
                                            } elseif ($lead['authority_age'] === 'over_90_days' || strpos($lead['authority_age'], '90+') !== false) {
                                                echo '✅ 90+ Days';
                                            } else {
                                                echo '⚡ &lt;90 Days';
                                            }
                                        ?>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php 
                                            $eq_map = array(
                                                'dry_van' => 'Dry Van (53\')',
                                                'reefer' => 'Reefer (53\')',
                                                'flatbed' => 'Flatbed/Step',
                                                'box_hotshot_power' => 'Box/Hotshot/Power'
                                            );
                                            echo $lead['equipment_type'] ? esc_html( $eq_map[$lead['equipment_type']] ?? $lead['equipment_type'] ) : '<span style="color:#94a3b8;">—</span>';
                                        ?>
                                    </td>
                                    <td style="font-size:12px; font-weight:600;">
                                        <?php 
                                            $rev_map = array(
                                                'under_10k' => '&lt; $10k/mo',
                                                'under_15k' => '&lt; $15k/mo',
                                                '10k_25k' => '$10k - $25k',
                                                '15k_35k' => '$15k - $35k',
                                                '25k_50k_plus' => '$25k - $50k+',
                                                '35k_75k' => '$35k - $75k',
                                                'over_75k' => '$75k+/mo'
                                            );
                                            echo $lead['monthly_gross'] ? ($rev_map[$lead['monthly_gross']] ?? esc_html($lead['monthly_gross'])) : '<span style="color:#94a3b8;">—</span>';
                                        ?>
                                    </td>
                                    <td style="font-size:12px;">
                                        <?php 
                                            $fact_map = array(
                                                'clean' => '🟢 Clean / Never',
                                                'not_factoring' => '🟢 Waiting on Brokers',
                                                'switching' => '🟡 Looking to Switch',
                                                'rate_too_high' => '🟡 Rate Too High',
                                                'locked_contract' => '🔴 Contract Locked',
                                                'contract_locked' => '🔴 Contract Locked',
                                                'exploring' => '🔵 Exploring Non-Recourse'
                                            );
                                            echo $lead['factoring_status'] ? ($fact_map[$lead['factoring_status']] ?? esc_html($lead['factoring_status'])) : '<span style="color:#94a3b8;">—</span>';
                                        ?>
                                    </td>
                                    <td>
                                        <?php if ( ! empty($lead['matched_partner']) ) : ?>
                                            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:700; background:<?php echo $badge_bg; ?>; color:<?php echo $badge_color; ?>; border:1px solid <?php echo $badge_border; ?>;">
                                                <?php echo esc_html($lead['partner_name'] ?: $lead['matched_partner']); ?>
                                            </span>
                                        <?php else : ?>
                                            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#f1f5f9; color:#64748b;">
                                                Pending Answers
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <select class="lead-status-dropdown" data-id="<?php echo $lead['id']; ?>" style="font-size:12px; padding:3px 6px; border-radius:4px; border:1px solid #cbd5e1;">
                                            <option value="New" <?php selected($lead['lead_status'], 'New'); ?>>New</option>
                                            <option value="Form Opened" <?php selected($lead['lead_status'], 'Form Opened'); ?>>Form Opened</option>
                                            <option value="Contacted" <?php selected($lead['lead_status'], 'Contacted'); ?>>Contacted</option>
                                            <option value="In Review" <?php selected($lead['lead_status'], 'In Review'); ?>>In Review</option>
                                            <option value="Funded" <?php selected($lead['lead_status'], 'Funded'); ?>>Funded</option>
                                            <option value="Disqualified" <?php selected($lead['lead_status'], 'Disqualified'); ?>>Disqualified</option>
                                        </select>
                                    </td>
                                    <td style="font-size:11px;">
                                        <?php if ( strpos($lead['n8n_sync_status'], 'Success') !== false ) : ?>
                                            <span style="color:#059669; font-weight:600;">✓ Synced</span>
                                        <?php elseif ( strpos($lead['n8n_sync_status'], 'Error') !== false || strpos($lead['n8n_sync_status'], 'Failed') !== false ) : ?>
                                            <span style="color:#ef4444; font-weight:600;" title="<?php echo esc_attr($lead['n8n_sync_status']); ?>">⚠ Failed</span>
                                        <?php else : ?>
                                            <span style="color:#64748b;"><?php echo esc_html($lead['n8n_sync_status']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small open-notes-modal" data-id="<?php echo $lead['id']; ?>" data-name="<?php echo esc_attr($lead['first_name'] ?: $lead['company_name']); ?>" data-notes="<?php echo esc_attr($lead['notes']); ?>" style="font-size:11px;">
                                            Notes
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div style="display:flex; justify-content:center; gap:8px; margin-top:20px;">
                    <?php for ( $i = 1; $i <= $total_pages; $i++ ) : 
                        $url = add_query_arg( array(
                            'page'   => 'carrier-prequal-leads',
                            'paged'  => $i,
                            's'      => $search,
                            'partner'=> $partner,
                            'status' => $status
                        ), admin_url('admin.php') );
                    ?>
                        <a href="<?php echo esc_url($url); ?>" class="button <?php echo $paged == $i ? 'button-primary' : ''; ?>" style="min-width:32px; text-align:center;">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <!-- Notes Modal -->
            <div id="notes-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:10px; width:480px; max-width:90%; padding:24px; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <h3 id="notes-modal-title" style="margin-top:0; font-size:18px; color:#0f172a;">Lead Evaluation Notes</h3>
                    <input type="hidden" id="notes-lead-id" value="">
                    <textarea id="notes-lead-textarea" rows="5" style="width:100%; margin:12px 0; border:1px solid #cbd5e1; border-radius:6px; padding:10px; font-family:inherit;" placeholder="Add internal follow-up notes, call logs, Elizabeth's feedback, factoring contract details..."></textarea>
                    <div style="display:flex; justify-content:flex-end; gap:8px;">
                        <button type="button" id="close-notes-modal" class="button">Cancel</button>
                        <button type="button" id="save-notes-btn" class="button button-primary">Save Notes</button>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                var nonce = '<?php echo $nonce; ?>';

                $('.lead-status-dropdown').on('change', function() {
                    var $select = $(this);
                    var leadId = $select.data('id');
                    var newStatus = $select.val();
                    $select.prop('disabled', true);

                    $.post(ajaxurl, {
                        action: 'update_prequal_lead_status',
                        security: nonce,
                        id: leadId,
                        status: newStatus
                    }, function(res) {
                        $select.prop('disabled', false);
                        if (res.success) {
                            $select.css('border-color', '#10b981');
                            setTimeout(function() { $select.css('border-color', '#cbd5e1'); }, 1500);
                        } else {
                            alert('Failed to update status.');
                        }
                    });
                });

                $('.open-notes-modal').on('click', function() {
                    var leadId = $(this).data('id');
                    var name = $(this).data('name') || 'Lead #' + leadId;
                    var notes = $(this).data('notes') || '';
                    $('#notes-modal-title').text('Evaluation Notes: ' + name);
                    $('#notes-lead-id').val(leadId);
                    $('#notes-lead-textarea').val(notes);
                    $('#notes-modal').css('display', 'flex');
                });

                $('#close-notes-modal').on('click', function() {
                    $('#notes-modal').hide();
                });

                $('#save-notes-btn').on('click', function() {
                    var leadId = $('#notes-lead-id').val();
                    var notes = $('#notes-lead-textarea').val();
                    var currentStatus = $('#lead-row-' + leadId + ' .lead-status-dropdown').val();

                    $.post(ajaxurl, {
                        action: 'update_prequal_lead_status',
                        security: nonce,
                        id: leadId,
                        status: currentStatus,
                        notes: notes
                    }, function(res) {
                        if (res.success) {
                            $('#lead-row-' + leadId + ' .open-notes-modal').data('notes', notes);
                            $('#notes-modal').hide();
                        } else {
                            alert('Failed to save notes.');
                        }
                    });
                });
            });
            </script>
        </div>
        <?php
    }

    /**
     * Inject Google tag (gtag.js) GA4 for Empire Dispatch
     */
    public function inject_ga4_tracking_code() {
        if ( is_admin() ) {
            return;
        }
        $ga4_id = 'G-8YQR90ZFWQ';
        ?>
<!-- Google tag (gtag.js) GA4 for Empire Dispatch -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $ga4_id ); ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', '<?php echo esc_attr( $ga4_id ); ?>', {
    'send_page_view': true,
    'cookie_flags': 'SameSite=None;Secure'
  });
</script>
        <?php
    }

    /**
     * REST endpoint: Get Realtime Telemetry Stats
     */
    public function get_realtime_stats() {
        global $wpdb;
        $table_name = self::get_table_name();

        $thirty_mins_ago = gmdate( 'Y-m-d H:i:s', time() - 1800 );
        $active_30m = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE submission_time >= %s",
            $thirty_mins_ago
        ) );

        $today_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE DATE(submission_time) = CURDATE()"
        );

        $total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

        $summar_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'SUMMAR'"
        );

        $basicblock_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'BASICBLOCK'"
        );

        $kit_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE utm_source = 'kit' OR utm_campaign LIKE '%hormozi%'"
        );

        $recent_items = $wpdb->get_results(
            "SELECT id, submission_time, first_name, company_name, mc_number, matched_partner, lead_status, utm_source, utm_campaign 
             FROM $table_name 
             ORDER BY id DESC LIMIT 15",
            ARRAY_A
        );

        return new WP_REST_Response( array(
            'success'            => true,
            'active_30m'         => $active_30m,
            'today_count'        => $today_count,
            'total_count'        => $total_count,
            'summar_count'       => $summar_count,
            'basicblock_count'   => $basicblock_count,
            'kit_count'          => $kit_count,
            'recent_items'       => $recent_items,
            'ga4_measurement_id' => 'G-8YQR90ZFWQ',
            'ga4_property_id'    => '563804447',
            'ga4_account_id'     => '415895070',
            'ga4_realtime_url'   => 'https://analytics.google.com/analytics/web/?pli=1&authuser=1#/a415895070p563804447/realtime/overview',
            'server_time'        => current_time( 'mysql' )
        ), 200 );
    }

    /**
     * REST endpoint: Handle Realtime Test Ping Simulation
     */
    public function handle_realtime_test_ping( WP_REST_Request $request ) {
        global $wpdb;
        $table_name = self::get_table_name();

        $token = wp_generate_password( 32, false );
        $sim_num = rand( 1000, 9999 );
        $wpdb->insert(
            $table_name,
            array(
                'prequal_token'    => $token,
                'submission_time'  => current_time( 'mysql' ),
                'mc_number'        => 'MC-TEST-' . $sim_num,
                'company_name'     => 'Realtime Test Logistics ' . $sim_num,
                'authority_age'    => 'over_90_days',
                'equipment_type'   => 'Dry Van (53ft)',
                'monthly_gross'    => '25k_50k_plus',
                'factoring_status' => 'not_factoring',
                'first_name'       => 'Test Operator ' . $sim_num,
                'phone'            => '(555) 019-' . rand( 1000, 9999 ),
                'email'            => 'test.trucker.' . $sim_num . '@example.com',
                'matched_partner'  => 'SUMMAR',
                'partner_name'     => 'Summar Financial (Elizabeth Valencia)',
                'lead_status'      => 'Verified Test',
                'partner_url'      => 'https://cwlqj04.na1.hs-sales-engage.com/Elizabeth-Valencia',
                'notes'            => 'Simulated realtime test event fired from WordPress Realtime Analytics Dashboard.',
                'ip_address'       => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1' ),
                'user_agent'       => 'Empire Realtime Dashboard Simulator',
                'utm_source'       => 'dashboard_simulator',
                'utm_medium'       => 'realtime_test',
                'utm_campaign'     => 'liveness_check',
                'n8n_sync_status'  => 'Test Ignored (Live DB Verified)'
            )
        );

        return new WP_REST_Response( array(
            'success' => true,
            'message' => 'Realtime test lead successfully generated and recorded in database.',
            'id'      => $wpdb->insert_id
        ), 200 );
    }

    public function render_analytics_guide_page() {
        $this->render_realtime_dashboard_page();
    }

    /**
     * Render the Live Realtime Analytics Dashboard in WordPress Admin
     */
    public function render_realtime_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        global $wpdb;
        $table_name = self::get_table_name();

        $thirty_mins_ago = gmdate( 'Y-m-d H:i:s', time() - 1800 );
        $active_30m = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE submission_time >= %s",
            $thirty_mins_ago
        ) );

        $today_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE DATE(submission_time) = CURDATE()"
        );

        $total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

        $summar_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'SUMMAR'"
        );

        $basicblock_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE matched_partner = 'BASICBLOCK'"
        );

        $kit_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE utm_source = 'kit' OR utm_campaign LIKE '%hormozi%'"
        );

        $recent_items = $wpdb->get_results(
            "SELECT id, submission_time, first_name, company_name, mc_number, matched_partner, lead_status, utm_source, utm_campaign 
             FROM $table_name 
             ORDER BY id DESC LIMIT 15",
            ARRAY_A
        );

        $rest_nonce       = wp_create_nonce( 'wp_rest' );
        $ga4_realtime_url = 'https://analytics.google.com/analytics/web/?pli=1&authuser=1#/a415895070p563804447/realtime/overview';
        ?>
        <div class="wrap" style="max-width:1300px; margin-top:20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
            
            <!-- Top Header & Actions -->
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px; padding-bottom:16px; border-bottom:1px solid #e2e8f0;">
                <div>
                    <div style="display:inline-flex; align-items:center; gap:8px; background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; font-size:12px; font-weight:700; padding:4px 10px; border-radius:9999px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.05em;">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#10b981; box-shadow:0 0 0 2px rgba(16,185,129,0.3); animation:pulse 2s infinite;"></span>
                        Live Telemetry & GA4 Realtime Stream
                    </div>
                    <h1 style="font-size:28px; font-weight:800; color:#0f172a; margin:0 0 6px 0; letter-spacing:-0.02em;">
                        ⚡ Empire Dispatch Realtime Analytics Dashboard
                    </h1>
                    <p style="margin:0; color:#64748b; font-size:14px;">
                        Live stream of carrier link opens, prequalification submissions, partner routing, and Google Analytics 4 telemetry.
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <button type="button" id="btn-realtime-test" class="button" style="background:#f1f5f9; border:1px solid #cbd5e1; color:#334155; font-weight:600; padding:8px 14px; border-radius:6px; cursor:pointer;">
                        🧪 Send Test Ping
                    </button>
                    <button type="button" id="btn-toggle-refresh" class="button" style="background:#f8fafc; border:1px solid #cbd5e1; color:#0f172a; font-weight:600; padding:8px 14px; border-radius:6px; cursor:pointer;">
                        🔄 Auto-Refresh: ON (10s)
                    </button>
                    <a href="<?php echo esc_url( $ga4_realtime_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-primary" style="background:#0284c7; border-color:#0284c7; padding:8px 18px; height:auto; font-weight:700; font-size:14px; border-radius:6px; display:inline-flex; align-items:center; gap:6px; text-decoration:none; box-shadow:0 1px 3px rgba(2,132,199,0.3);">
                        <span>Launch GA4 Realtime Stream</span>
                        <span style="font-size:16px;">&nearr;</span>
                    </a>
                </div>
            </div>

            <!-- KPI Metric Cards Grid -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:24px;">
                
                <!-- Card 1: Active In Last 30 Mins -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04); position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; right:0; height:4px; background:#10b981;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Active Last 30 Mins</div>
                            <div id="stat-active-30m" style="font-size:36px; font-weight:800; color:#0f172a; margin-top:6px; line-height:1;"><?php echo $active_30m; ?></div>
                        </div>
                        <div style="width:40px; height:40px; border-radius:10px; background:#ecfdf5; display:flex; align-items:center; justify-content:center; color:#059669; font-size:20px;">
                            👥
                        </div>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#10b981; font-weight:600; display:flex; align-items:center; gap:4px;">
                        <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981;"></span>
                        Live Carrier Inflow
                    </div>
                </div>

                <!-- Card 2: Today's Inflow -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04); position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; right:0; height:4px; background:#0284c7;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Leads & Opens Today</div>
                            <div id="stat-today-count" style="font-size:36px; font-weight:800; color:#0f172a; margin-top:6px; line-height:1;"><?php echo $today_count; ?></div>
                        </div>
                        <div style="width:40px; height:40px; border-radius:10px; background:#f0f9ff; display:flex; align-items:center; justify-content:center; color:#0284c7; font-size:20px;">
                            📋
                        </div>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#64748b;">
                        Total all-time: <strong id="stat-total-count" style="color:#0f172a;"><?php echo $total_count; ?></strong> leads
                    </div>
                </div>

                <!-- Card 3: Summar / Elizabeth Valencia -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04); position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; right:0; height:4px; background:#059669;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Summar (Elizabeth)</div>
                            <div id="stat-summar-count" style="font-size:36px; font-weight:800; color:#0f172a; margin-top:6px; line-height:1;"><?php echo $summar_count; ?></div>
                        </div>
                        <div style="width:40px; height:40px; border-radius:10px; background:#ecfdf5; display:flex; align-items:center; justify-content:center; color:#059669; font-size:20px;">
                            🤝
                        </div>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#64748b;">
                        Authority &gt; 90 Days ($25k-$50k+)
                    </div>
                </div>

                <!-- Card 4: BasicBlock / Sydney Lock -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04); position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; right:0; height:4px; background:#6366f1;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">BasicBlock (Sydney)</div>
                            <div id="stat-basicblock-count" style="font-size:36px; font-weight:800; color:#0f172a; margin-top:6px; line-height:1;"><?php echo $basicblock_count; ?></div>
                        </div>
                        <div style="width:40px; height:40px; border-radius:10px; background:#eef2ff; display:flex; align-items:center; justify-content:center; color:#6366f1; font-size:20px;">
                            🚀
                        </div>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#64748b;">
                        New Authority &lt; 90 Days (Fast Track)
                    </div>
                </div>

                <!-- Card 5: Kit Email Attribution -->
                <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,0.04); position:relative; overflow:hidden;">
                    <div style="position:absolute; top:0; left:0; right:0; height:4px; background:#f59e0b;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div style="color:#64748b; font-size:13px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em;">Kit Email Traffic</div>
                            <div id="stat-kit-count" style="font-size:36px; font-weight:800; color:#0f172a; margin-top:6px; line-height:1;"><?php echo $kit_count; ?></div>
                        </div>
                        <div style="width:40px; height:40px; border-radius:10px; background:#fef3c7; display:flex; align-items:center; justify-content:center; color:#d97706; font-size:20px;">
                            📧
                        </div>
                    </div>
                    <div style="margin-top:12px; font-size:12px; color:#64748b;">
                        UTM Source: <code>kit</code> / Hormozi Batch
                    </div>
                </div>

            </div>

            <!-- GA4 Stream & Telemetry Health Card -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04); margin-bottom:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:18px; font-weight:800; color:#0f172a; margin:0 0 4px 0;">
                            📡 Google Analytics 4 Connection Status
                        </h2>
                        <p style="margin:0; color:#64748b; font-size:13px;">
                            Verified stream configuration for Empire Dispatch web property.
                        </p>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <a href="<?php echo admin_url('admin.php?page=monsterinsights_settings'); ?>" class="button" style="background:#f8fafc; border:1px solid #cbd5e1; font-weight:600; color:#334155; border-radius:6px;">
                            MonsterInsights Settings &rarr;
                        </a>
                        <a href="<?php echo esc_url( $ga4_realtime_url ); ?>" target="_blank" rel="noopener noreferrer" class="button" style="background:#0284c7; color:#fff; border-color:#0284c7; font-weight:600; border-radius:6px;">
                            Open GA4 Realtime Report &rarr;
                        </a>
                    </div>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">GA4 Account</div>
                        <div style="font-weight:700; color:#0f172a; font-size:14px; margin-top:2px;">Empire Dispatch <span style="font-size:12px; color:#64748b;">(415895070)</span></div>
                    </div>
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">GA4 Property</div>
                        <div style="font-weight:700; color:#0f172a; font-size:14px; margin-top:2px;">Empire Dispatch <span style="font-size:12px; color:#64748b;">(563804447)</span></div>
                    </div>
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">Measurement ID</div>
                        <div style="font-weight:700; color:#0284c7; font-size:14px; margin-top:2px; font-family:monospace;">G-8YQR90ZFWQ</div>
                    </div>
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">Web Stream</div>
                        <div style="font-weight:700; color:#0f172a; font-size:14px; margin-top:2px;">Empire Dispatch Web <span style="font-size:12px; color:#64748b;">(16320151931)</span></div>
                    </div>
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">Tag Injection</div>
                        <div style="font-weight:700; color:#059669; font-size:14px; margin-top:2px;">Active in <code>wp_head</code></div>
                    </div>
                    <div>
                        <div style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase;">MonsterInsights Sync</div>
                        <div style="font-weight:700; color:#059669; font-size:14px; margin-top:2px;">Saved &amp; Validated</div>
                    </div>
                </div>
            </div>

            <!-- Live Realtime Activity Stream -->
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.04);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
                    <div>
                        <h2 style="font-size:18px; font-weight:800; color:#0f172a; margin:0 0 4px 0;">
                            🔴 Live Telemetry Stream
                        </h2>
                        <p style="margin:0; color:#64748b; font-size:13px;">
                            Real-time inflow of carrier opens, submissions, and partner clicks (auto-refreshes every 10s).
                        </p>
                    </div>
                    <div style="font-size:12px; color:#64748b;">
                        Last updated: <span id="last-updated-time" style="font-weight:600; color:#0f172a;"><?php echo current_time( 'g:i:s A' ); ?></span>
                    </div>
                </div>

                <div style="overflow-x:auto;">
                    <table class="wp-list-table widefat fixed striped" style="border:1px solid #e2e8f0; border-radius:8px; overflow:hidden;">
                        <thead>
                            <tr style="background:#f8fafc;">
                                <th style="width:70px; font-weight:700; color:#475569;">ID</th>
                                <th style="width:160px; font-weight:700; color:#475569;">Timestamp (UTC)</th>
                                <th style="width:160px; font-weight:700; color:#475569;">Carrier / Driver</th>
                                <th style="width:180px; font-weight:700; color:#475569;">Company Legal Name</th>
                                <th style="width:130px; font-weight:700; color:#475569;">MC / DOT #</th>
                                <th style="width:130px; font-weight:700; color:#475569;">Status</th>
                                <th style="width:150px; font-weight:700; color:#475569;">Matched Partner</th>
                                <th style="font-weight:700; color:#475569;">Campaign / Source</th>
                            </tr>
                        </thead>
                        <tbody id="realtime-activity-body">
                            <?php if ( ! empty( $recent_items ) ) : ?>
                                <?php foreach ( $recent_items as $item ) : ?>
                                    <?php
                                        $partner_badge = '<span style="color:#94a3b8; font-size:12px;">—</span>';
                                        if ( $item['matched_partner'] === 'SUMMAR' ) {
                                            $partner_badge = '<span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">SUMMAR</span>';
                                        } elseif ( $item['matched_partner'] === 'BASICBLOCK' ) {
                                            $partner_badge = '<span style="background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">BASICBLOCK</span>';
                                        }

                                        $status_badge = '<span style="background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">' . esc_html( $item['lead_status'] ) . '</span>';
                                        if ( $item['lead_status'] === 'Form Opened' ) {
                                            $status_badge = '<span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Form Opened</span>';
                                        } elseif ( $item['lead_status'] === 'Verified Test' ) {
                                            $status_badge = '<span style="background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Verified Test</span>';
                                        } elseif ( $item['lead_status'] === 'New' || $item['lead_status'] === 'Prequalified' ) {
                                            $status_badge = '<span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Prequalified</span>';
                                        }
                                    ?>
                                    <tr>
                                        <td style="font-weight:600; color:#64748b;">#<?php echo esc_html( $item['id'] ); ?></td>
                                        <td style="font-size:12px; color:#475569;"><?php echo esc_html( $item['submission_time'] ); ?></td>
                                        <td style="font-weight:600; color:#0f172a;"><?php echo esc_html( $item['first_name'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $item['company_name'] ?: '—' ); ?></td>
                                        <td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;"><?php echo esc_html( $item['mc_number'] ?: '—' ); ?></code></td>
                                        <td><?php echo $status_badge; ?></td>
                                        <td><?php echo $partner_badge; ?></td>
                                        <td style="font-size:12px; color:#64748b;">
                                            <?php if ( ! empty( $item['utm_source'] ) || ! empty( $item['utm_campaign'] ) ) : ?>
                                                <code><?php echo esc_html( ($item['utm_source'] ?: 'direct') . ' / ' . ($item['utm_campaign'] ?: 'none') ); ?></code>
                                            <?php else : ?>
                                                <span style="color:#94a3b8;">direct / none</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="8" style="text-align:center; padding:30px; color:#64748b;">No recent stream activity recorded yet.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top:16px; text-align:right;">
                    <a href="<?php echo admin_url('admin.php?page=carrier-prequal-leads'); ?>" style="color:#0284c7; text-decoration:none; font-weight:600; font-size:13px;">
                        View Full Submissions Archive &amp; Export CSV &rarr;
                    </a>
                </div>
            </div>

        </div>

        <style>
            @keyframes pulse {
                0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.5); }
                70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
                100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
            }
        </style>

        <script>
        (function() {
            var restNonce = '<?php echo esc_js( $rest_nonce ); ?>';
            var statsUrl  = '<?php echo esc_url_raw( rest_url( "empire-dispatch/v1/realtime-stats" ) ); ?>';
            var pingUrl   = '<?php echo esc_url_raw( rest_url( "empire-dispatch/v1/realtime-test-ping" ) ); ?>';
            var autoRefresh = true;
            var pollInterval = null;

            function formatPartner(p) {
                if (p === 'SUMMAR') return '<span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">SUMMAR</span>';
                if (p === 'BASICBLOCK') return '<span style="background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:700;">BASICBLOCK</span>';
                return '<span style="color:#94a3b8; font-size:12px;">—</span>';
            }

            function formatStatus(s) {
                if (s === 'Form Opened') return '<span style="background:#fef3c7; color:#92400e; border:1px solid #fde68a; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Form Opened</span>';
                if (s === 'Verified Test') return '<span style="background:#f3e8ff; color:#6b21a8; border:1px solid #e9d5ff; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Verified Test</span>';
                if (s === 'New' || s === 'Prequalified') return '<span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">Prequalified</span>';
                return '<span style="background:#f1f5f9; color:#475569; padding:2px 8px; border-radius:4px; font-size:11px; font-weight:600;">' + (s || 'New') + '</span>';
            }

            function fetchRealtimeStats() {
                fetch(statsUrl, {
                    headers: { 'X-WP-Nonce': restNonce }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (!data || !data.success) return;
                    document.getElementById('stat-active-30m').innerText = data.active_30m;
                    document.getElementById('stat-today-count').innerText = data.today_count;
                    document.getElementById('stat-total-count').innerText = data.total_count;
                    document.getElementById('stat-summar-count').innerText = data.summar_count;
                    document.getElementById('stat-basicblock-count').innerText = data.basicblock_count;
                    document.getElementById('stat-kit-count').innerText = data.kit_count;
                    
                    var d = new Date();
                    document.getElementById('last-updated-time').innerText = d.toLocaleTimeString();

                    if (data.recent_items && data.recent_items.length) {
                        var tbody = document.getElementById('realtime-activity-body');
                        var rowsHtml = '';
                        data.recent_items.forEach(function(item) {
                            var campaignStr = (item.utm_source || 'direct') + ' / ' + (item.utm_campaign || 'none');
                            rowsHtml += '<tr>' +
                                '<td style="font-weight:600; color:#64748b;">#' + item.id + '</td>' +
                                '<td style="font-size:12px; color:#475569;">' + (item.submission_time || '') + '</td>' +
                                '<td style="font-weight:600; color:#0f172a;">' + (item.first_name || '—') + '</td>' +
                                '<td>' + (item.company_name || '—') + '</td>' +
                                '<td><code style="background:#f1f5f9; padding:2px 6px; border-radius:4px;">' + (item.mc_number || '—') + '</code></td>' +
                                '<td>' + formatStatus(item.lead_status) + '</td>' +
                                '<td>' + formatPartner(item.matched_partner) + '</td>' +
                                '<td style="font-size:12px; color:#64748b;"><code>' + campaignStr + '</code></td>' +
                            '</tr>';
                        });
                        tbody.innerHTML = rowsHtml;
                    }
                })
                .catch(function(err) {
                    console.warn('[Realtime Dashboard] Fetch error:', err);
                });
            }

            // Test Ping button
            var testBtn = document.getElementById('btn-realtime-test');
            if (testBtn) {
                testBtn.addEventListener('click', function() {
                    testBtn.disabled = true;
                    testBtn.innerText = 'Sending...';
                    fetch(pingUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': restNonce
                        }
                    })
                    .then(function(res) { return res.json(); })
                    .then(function(resp) {
                        testBtn.disabled = false;
                        testBtn.innerText = '🧪 Send Test Ping';
                        if (resp && resp.success) {
                            fetchRealtimeStats();
                        }
                    })
                    .catch(function() {
                        testBtn.disabled = false;
                        testBtn.innerText = '🧪 Send Test Ping';
                    });
                });
            }

            // Auto-refresh toggle
            var toggleBtn = document.getElementById('btn-toggle-refresh');
            if (toggleBtn) {
                toggleBtn.addEventListener('click', function() {
                    autoRefresh = !autoRefresh;
                    if (autoRefresh) {
                        toggleBtn.innerText = '🔄 Auto-Refresh: ON (10s)';
                        toggleBtn.style.background = '#f8fafc';
                        startPolling();
                    } else {
                        toggleBtn.innerText = '⏸️ Auto-Refresh: PAUSED';
                        toggleBtn.style.background = '#fef2f2';
                        stopPolling();
                    }
                });
            }

            function startPolling() {
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(fetchRealtimeStats, 10000);
            }
            function stopPolling() {
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = null;
            }

            startPolling();
        })();
        </script>
        <?php
    }

}

new Empire_Carrier_Prequal_Manager();
