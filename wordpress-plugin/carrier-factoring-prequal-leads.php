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

    const DB_VERSION = '1.2.0';
    const TABLE_NAME = 'carrier_prequal_submissions';

    public function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'plugins_loaded', array( $this, 'check_db_schema' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
        add_action( 'admin_menu', array( $this, 'register_admin_menus' ) );
        add_action( 'admin_post_export_prequal_leads_csv', array( $this, 'export_leads_csv' ) );
        add_action( 'wp_ajax_update_prequal_lead_status', array( $this, 'ajax_update_lead_status' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
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
            return new WP_REST_Response( array(
                'success'       => true,
                'submission_id' => (int) $existing_id,
                'status'        => 'existing_session'
            ), 200 );
        }

        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip_address = trim( $parts[0] );
        }
        $user_agent = sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) );

        $wpdb->insert(
            $table_name,
            array(
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
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'
            )
        );

        $submission_id = $wpdb->insert_id;

        return new WP_REST_Response( array(
            'success'       => true,
            'submission_id' => $submission_id,
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

        if ( empty( $email ) || empty( $mc_number ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'MC/DOT number and Email address are required.'
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

        if ( $submission_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE id = %d", $submission_id ) ) ) {
            $wpdb->update(
                $table_name,
                $lead_data,
                array( 'id' => $submission_id )
            );
        } else {
            $lead_data['submission_time'] = current_time( 'mysql' );
            $lead_data['notes'] = '';
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
            'Analytics Overview',
            'Analytics & Clicks',
            'manage_options',
            'carrier-prequal-analytics-guide',
            array( $this, 'render_analytics_guide_page' )
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

    public function render_analytics_guide_page() {
        ?>
        <div class="wrap" style="max-width:1100px; margin-top:20px;">
            <h1 style="font-size:26px; font-weight:700; color:#0f172a; margin-bottom:12px;">
                📊 Google Analytics & Click Tracking Setup
            </h1>
            <p style="color:#64748b; font-size:15px; margin-bottom:24px;">
                Learn how outbound link clicks, partner conversions, and page traffic are tracked directly in WordPress.
            </p>

            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px;">
                <h2 style="font-size:18px; font-weight:700; color:#0f172a; margin-top:0;">
                    1. MonsterInsights Pro Click & Traffic Reports
                </h2>
                <p style="color:#334155; line-height:1.6;">
                    Google Analytics Premium (MonsterInsights Pro) is installed and active on this website. It provides automated tracking of outbound button clicks, user sessions, and conversions.
                </p>
                <div style="display:flex; gap:12px; margin-top:16px;">
                    <a href="<?php echo admin_url('admin.php?page=monsterinsights_reports'); ?>" class="button button-primary" style="padding:6px 16px; height:auto; font-weight:600;">
                        Open MonsterInsights Reports &rarr;
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=monsterinsights_settings'); ?>" class="button button-secondary" style="padding:6px 16px; height:auto; font-weight:600;">
                        Open MonsterInsights Settings
                    </a>
                </div>
            </div>

            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px;">
                <h2 style="font-size:18px; font-weight:700; color:#0f172a; margin-top:0;">
                    2. Tracked Form & Outbound Clicks
                </h2>
                <ul style="color:#334155; line-height:1.8; list-style:disc; margin-left:20px;">
                    <li><strong>Form Submissions:</strong> Every completion of the prequalification form is logged into the local database table <code>wpda_carrier_prequal_submissions</code> and pushed as an event to Google Analytics (<code>prequal_form_submit</code>).</li>
                    <li><strong>Pre-filled Link Openings:</strong> When truckers click their personalized email link, the page records their visit immediately in WordPress under <code>Form Opened</code> so no click is ever lost.</li>
                    <li><strong>Elizabeth Valencia / Summar Clicks:</strong> Outbound clicks to Elizabeth's HubSpot calendar (<code>cwlqj04.na1.hs-sales-engage.com/Elizabeth-Valencia</code>) are tracked automatically as Outbound Link Click events.</li>
                    <li><strong>BasicBlock Activation Clicks:</strong> Outbound clicks to <code>na2.hubs.ly/y0y4mS0</code> are tracked automatically as Outbound Link Click events.</li>
                    <li><strong>Email Campaign Attribution:</strong> Traffic arriving from Kit with UTM tags (e.g. <code>?utm_source=kit&utm_medium=email</code>) is recorded with each lead and visible in the Leads table and CSV exports.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

new Empire_Carrier_Prequal_Manager();
