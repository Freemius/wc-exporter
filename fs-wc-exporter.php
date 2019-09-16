<?php
    /**
     * Plugin Name: WooCommerce Export to CSV by Freemius
     * Plugin URI:  http://freemius.com/
     * Description: Export WooCommerce data to a CSV for a migration to Freemius.
     * Version:     1.0.0
     * Author:      Freemius
     * Author URI:  http://freemius.com
     * License: GPL2
     *
     * @requires
     *  1. WooCommerce 3.0 or higher, assuming payments currency is USD and have no fees.
     *  2. PHP 5.3 or higher [using spl_autoload_register()]
     */

    if ( ! defined( 'ABSPATH' ) ) {
        exit;
    }

    class FS_WC_Export {
        const GUID_TRANSIENT_NAME = 'fs_csv_export_guid';
        const LICENSES_PER_EXECUTION = 500;
        const MAX_LICENSES_PER_EXECUTION = 500;
        const OLD_API_KEYS_END_DATE = '2019-02-24';

        const DEBUG = false;

        #region Singleton

        private static $INSTANCE;

        public static function instance() {
            if ( ! isset( self::$INSTANCE ) ) {
                self::$INSTANCE = new self();
            }

            return self::$INSTANCE;
        }

        #endregion

        private function __construct() {
            require_once 'class-fs-csv-order-license.php';
        }

        /**
         * Initiate a non-blocking licenses export.
         *
         * @author Vova Feldman
         * @since  1.0.0
         */
        public function init() {
            $upload = wp_upload_dir();

            $csv_export_path = $upload['basedir'] . '/wc-export.csv';

            require_once( ABSPATH . 'wp-admin/includes/file.php' );

            if ( 'direct' !== get_filesystem_method( array(), $upload['basedir'] ) ) {

                add_action( 'admin_notices', 'insufficient_file_permissions_notice' );

                return;
            }

            if ( self::DEBUG ) {
                // Delete the file before starting the export in debug mode.
                unlink( $csv_export_path );

                $this->do_export( $csv_export_path, 0, 20 );

                exit;
            }

            if ( empty( $_GET[ self::GUID_TRANSIENT_NAME ] ) ) {
                if ( file_exists( $csv_export_path ) ) {
                    // Clean up transient.
                    delete_transient( self::GUID_TRANSIENT_NAME );
                } else {
                    // Generate unique ID.
                    $guid = md5( rand() . microtime() );

                    set_transient( self::GUID_TRANSIENT_NAME, $guid );

                    // Start non-blocking data export.
                    $this->spawn_export( $guid, 0, self::LICENSES_PER_EXECUTION );
                }
            } else {
                $guid = get_transient( self::GUID_TRANSIENT_NAME );

                if ( empty( $guid ) || $guid !== $_GET[ self::GUID_TRANSIENT_NAME ] ) {
                    // Guide does not match.
                    return;
                }

                $offset = ( ! empty( $_GET['offset'] ) && is_numeric( $_GET['offset'] ) ) ?
                    max( $_GET['offset'], 0 ) :
                    0;

                $limit = ( ! empty( $_GET['limit'] ) && is_numeric( $_GET['limit'] ) ) ?
                    min( $_GET['limit'], self::MAX_LICENSES_PER_EXECUTION ) :
                    self::LICENSES_PER_EXECUTION;

                $exported_count = $this->do_export( $csv_export_path, $offset, $limit );

                if ( $exported_count == $limit ) {
                    // Continue with non-blocking data export.
                    $this->spawn_export( $guid, $offset + $limit, $limit );
                }
            }
        }

        /**
         * @param string $guid
         * @param int    $offset
         * @param int    $limit
         */
        private function spawn_export( $guid, $offset = 0, $limit = self::LICENSES_PER_EXECUTION ) {
            $export_url = add_query_arg( array(
                self::GUID_TRANSIENT_NAME => $guid,
                'offset'                  => $offset,
                'limit'                   => $limit,
                'XDEBUG_SESSION_START'    => rand( 0, 9999999 ),
                'XDEBUG_SESSION'          => 'PHPSTORM',
            ), $this->get_current_url() );

            // Add cookies to trigger request with same user access permissions.
            $cookies = array();
            foreach ( $_COOKIE as $name => $value ) {
                if ( 0 === strpos( $name, 'tk_' ) ||
                     0 === strpos( $name, 'mp_' )
                ) {
                    continue;
                }

                $cookies[] = new WP_Http_Cookie( array(
                    'name'  => $name,
                    'value' => $value
                ) );
            }

            if ( empty( $_COOKIE['XDEBUG_SESSION'] ) ) {
                $cookies[] = new WP_Http_Cookie( array(
                    'name'  => 'XDEBUG_SESSION',
                    'value' => 'PHPSTORM',
                ) );
            }

            wp_remote_get(
                $export_url,
                array(
                    'timeout'   => 0.01,
                    'blocking'  => false,
                    'sslverify' => false,
                    'cookies'   => $cookies,
                )
            );
        }

        /**
         * Execute the export.
         *
         * @param string $csv_export_path
         * @param int    $offset
         * @param int    $limit
         *
         * @return int Number of exported licenses.
         */
        private function do_export(
            $csv_export_path,
            $offset = 0,
            $limit = self::LICENSES_PER_EXECUTION
        ) {
            // Remove execution time limit.
            ini_set( 'max_execution_time', 0 );

            $fp = fopen( $csv_export_path, 'a' );

            if ( 0 == $offset ) {
                $record = new FS_CSV_Order_License();

                fputcsv( $fp, array_keys( $record->to_array() ) );
            }

            $orders = wc_get_orders( array(
                'limit'  => $limit,
                'offset' => $offset,
                'status' => 'completed',
                'order'  => 'DESC',
            ) );

            try {
                $i = 0;

                /**
                 * + guest purchase (no user ID) - 1569528, 1327631
                 *
                 * + One time purchase of a product - 1569528, 1327631
                 * + one time purchase of a bundle - 1828455
                 * + initial payment of a subscription of a product - 1828463
                 * + initial payment of a subscription of a bundle - 1277098
                 * + subscription renewal of a product - 1826995
                 * + subscription renewal of a bundle - 1333825
                 * + One time purchase of a product with more than 1 licenses
                 * + initial payment of a subscription of a product with more than 1 licenses - 1377889
                 */
//                $orders = array( wc_get_order( 1828455 ) );

                /**
                 * @var WC_Order $order
                 */
                foreach ( $orders as $order ) {
                    if ($order instanceof WC_Order_Refund) {
                        // Ignore refunds.
                        continue;
                    }

                    if ( WCAM()->wc_subs_exist ) {
                        if ( wcs_order_contains_renewal( $order ) ) {
                            // Skip subscription orders that are not the parent order.
                            continue;
                        }
                    }

                    $record = new FS_CSV_Order_License();

                    $record->order_id = $order->get_id();

                    $customer_id = $order->get_customer_id();

                    if ( ! WC_AM_USER()->has_api_access( $customer_id ) ) {
                        continue;
                    }

                    // Set user info.
                    $record->set_user_info_by_order( $order );

                    // Set billing info.
                    $record->set_billing_by_order( $order );

                    // Set license created at.
                    $record->license_created = $order->get_date_created()->date( 'Y-m-d H:i:s' );

                    /**
                     * @key integer product ID
                     * @val integer bundle ID
                     */
                    $bundled_product_ids_hash = array();
                    $bundle_licenses_info     = array();

                    /**
                     * Find all bundles.
                     *
                     * @var WC_Order_Item_Product[] $product_items
                     */
                    $product_items = array_values( $order->get_items() );
                    foreach ( $product_items as $product_item ) {
                        $product_id = $product_item->get_product_id();

                        if ( isset( $bundled_product_ids_hash[ $product_id ] ) ) {
                            // The product is part of a bundle already.
                            continue;
                        }

                        if ( wc_pb_is_bundle_container_order_item( $product_item ) ) {
                            $bundle_licenses_info[ $product_id ] = array(
                                'product_item' => $product_item,
                                'licenses'     => false,
                            );

                            $bundle_product_items = wc_pb_get_bundled_order_items( $product_item, $order );

                            foreach ( $bundle_product_items as $bundle_product_item ) {
                                $bundled_product_ids_hash[ $bundle_product_item->get_product_id() ] = $product_id;
                            }
                        }
                    }

                    $resources = $this->get_order_api_resources( $order->get_id() );

                    /**
                     * If order don't have API resources, create them artificially for the rest of the logic.
                     */
                    if ( empty( $resources ) ) {
                        $resources = array();

                        $expiration_by_subscription_id = array();
                        $subscription_id_by_product_id = array();

                        if ( WCAM()->wc_subs_exist ) {
                            /**
                             * @var WC_Subscription[] $subscriptions
                             */
                            $subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );

                            foreach ( $subscriptions as $s ) {
                                if ( ! empty( $s->get_parent_id() ) &&
                                     $order->get_id() != $s->get_parent_id()
                                ) {
                                    // Skip non parent subscriptions.
                                    return null;
                                }

                                $subscription_id = $s->get_id();

                                $expiration_by_subscription_id[ $subscription_id ] = ( $s->get_total() > 0 ?
                                    $s->get_time( 'next_payment' ) :
                                    null
                                );

                                $subscription_items = $s->get_items();

                                foreach ( $subscription_items as $product_item ) {
                                    $subscription_id_by_product_id[ $product_item->get_product_id() ] = $subscription_id;
                                }
                            }
                        }

                        $handled_products = array();
                        foreach ( $product_items as $product_item ) {
                            $product_id = $product_item->get_product_id();

                            if ( isset( $handled_products[ $product_id ] ) ) {
                                continue;
                            }

                            $handled_products[ $product_id ] = true;

                            if ( isset( $bundle_licenses_info[ $product_id ] ) ) {
                                // Don't add resources for bundles.
                                continue;
                            }


                            $resource             = new stdClass();
                            $resource->product_id = $product_item->get_variation_id();
                            $resource->parent_id  = $product_item->get_product_id();
                            // No subscription.
                            $resource->sub_id = null;
                            // License activations.
                            $resource->activations_purchased_total = get_post_meta( $resource->product_id, '_api_activations', true );
                            $resource->product_title               = $product_item->get_name();

                            // Default to Lifetime.
                            $expiration = 0;

                            // If product is part of a subscription, use the subscription next payment date.
                            if ( isset( $subscription_id_by_product_id[ $resource->parent_id ] ) ) {
                                $subscription_id = $subscription_id_by_product_id[ $resource->parent_id ];

                                if ( isset( $expiration_by_subscription_id[ $subscription_id ] ) ) {
                                    $expiration = $expiration_by_subscription_id[ $subscription_id ];
                                }
                            }

                            $resource->access_expires = $expiration;

                            $resources[] = $resource;
                        }
                    }

                    $master_api_key = WC_AM_USER()->get_master_api_key( $customer_id );

                    foreach ( $resources as $resource ) {
                        $licenses = $this->get_order_resource_licenses(
                            $order,
                            $resource,
                            $master_api_key,
                            $bundled_product_ids_hash,
                            $bundle_licenses_info
                        );

                        if ( is_null( $licenses ) ) {
                            continue;
                        }

                        // @todo IMPLEMENT: Get site URL.
                        if ( false ) {
                            $activation_resources = WC_AM_API_ACTIVATION_DATA_STORE()->get_activation_resources_by_user_id( $customer_id );
                            if ( ! empty( $activation_resources ) ) {
                            }
                        }

                        $record->local_product_id    = $resource->parent_id;
                        $record->local_product_title = $resource->product_title;

                        $license_keys_hash = array();
                        foreach ( $licenses as $license ) {
                            if ( isset( $license_keys_hash[ $license['key'] ] ) ) {
                                continue;
                            }

                            $license_keys_hash[ $license['key'] ] = true;

                            $record->set_license( $license );

                            $this->output_record( $record, $fp, $offset + $i );

                            $i ++;
                        }
                    }

                    foreach ( $bundle_licenses_info as $bundle_id => $bundle_data ) {
                        $record->local_product_id    = $bundle_id;
                        $record->local_product_title = $bundle_data['product_item']->get_name();

                        if ( ! empty( $bundle_data['licenses'] ) ) {
                            foreach ( $bundle_data['licenses'] as $license ) {
                                $record->set_license( $license );

                                $this->output_record( $record, $fp, $offset + $i );

                                $i ++;
                            }
                        }
                    }
                }
            } catch ( Exception $e ) {
                fputcsv( $fp, var_export( $e, true ) );
            }

            fclose( $fp );

            return count( $orders );
        }

        function insufficient_file_permissions_notice() {
            ?>
            <div class="notice">
                <p><?php _e( 'The WooCommerce data export plugin do not have sufficient permissions to write to the uploads folder.', 'freemius' ); ?></p>
            </div>
            <?php
        }

        #region Helper Methods

        /**
         * Logic taken from WC_AM_Order_Admin::api_resource_meta_box().
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param int $order_id
         *
         * @return object[]
         */
        private function get_order_api_resources( $order_id ) {
            /**
             * Subscription resources should be displayed on the Subscription parent order only.
             */
            if ( WCAM()->wc_subs_exist ) {
                $sub_parent_id = WC_AM_SUBSCRIPTION()->get_parent_id( $order_id );

                if ( (int) $sub_parent_id == (int) $order_id ) {
                    // Use $sub_parent_id, since $post_id would get results only for the current post, not the parent.
                    $sub_resources = WC_AM_API_RESOURCE_DATA_STORE()->get_all_api_resources_for_sub_parent_id( $sub_parent_id );
                }
            }

            if ( ! empty( $sub_resources ) ) {
                $non_sub_resources = WC_AM_API_RESOURCE_DATA_STORE()->get_all_api_non_wc_subscription_resources_for_order_id( $order_id );
                $resources         = array_merge( $non_sub_resources, $sub_resources );
            } else {
                // If WC Subs exist, but WC Subs is deactivated, the Expires field will display required.
                $resources = WC_AM_API_RESOURCE_DATA_STORE()->get_all_api_resources_for_order_id( $order_id );
            }

            return $resources;
        }

        /**
         * Pull order's resource licenses.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param \WC_Order $order
         * @param object    $resource
         * @param string    $master_api_key
         * @param array     $bundled_product_ids_hash
         * @param array     $bundle_licenses_info
         * @return array|null
         */
        private function get_order_resource_licenses(
            WC_Order $order,
            $resource,
            $master_api_key,
            $bundled_product_ids_hash,
            &$bundle_licenses_info
        ) {

            $order_id             = $order->get_id();
            $product_variation_id = $resource->product_id;

            if ( ! empty( $resource->sub_id ) && WCAM()->wc_subs_exist ) {
                $subscription = WC_AM_Subscription::instance()->get_subscription_object( $resource->sub_id );

                if ( ! empty( $subscription->get_parent_id() ) &&
                     $order_id != $subscription->get_parent_id()
                ) {
                    // Skip non parent subscriptions.
                    return null;
                }

                $expiration = $this->get_license_expiration( $subscription );
            } else {
                $expiration = $resource->access_expires == 0 ?
                    null :
                    gmdate( 'Y-m-d H:i:s', $resource->access_expires );
            }

            $activations = $resource->activations_purchased_total;
            if ( ! is_numeric( $activations ) ||
                 999 < $activations
            ) {
                $activations = null;
            }


            $order_licenses = array();
//            $master_api_key_resources    = WC_AM_API_RESOURCE_DATA_STORE()->get_active_api_resources( $master_api_key, $product_variation_id );
//            $total_activations_purchased = WC_AM_API_RESOURCE_DATA_STORE()->get_total_activations_purchased( $master_api_key_resources );

            // Add master API key.
            $master_licenses[] = array(
                'key'        => $master_api_key,
                'quota'      => $activations,
                'expiration' => $expiration,
            );


            if ( ! empty( self::OLD_API_KEYS_END_DATE ) &&
                 $order->get_date_created()->getTimestamp() < strtotime( self::OLD_API_KEYS_END_DATE )
            ) {
                // Add order key (legacy licenses).
                $master_licenses[] = array(
                    'key'        => $order->get_order_key(),
                    'quota'      => $activations,
                    'expiration' => $expiration,
                );
            }

            $is_bundled_product = isset( $bundled_product_ids_hash[ $resource->parent_id ] );

            if ( $is_bundled_product ) {
                $bundle_id = $bundled_product_ids_hash[ $resource->parent_id ];

                // Do not include the master keys for bundled products, those will be added separately.
                if ( false === $bundle_licenses_info[ $bundle_id ]['licenses'] ) {
                    $bundle_licenses_info[ $bundle_id ]['licenses'] = $master_licenses;
                }
            } else {
                // Add master API key.
                $order_licenses = $master_licenses;
            }

            $product_order_api_key = WC_AM_API_RESOURCE_DATA_STORE()->get_api_resource_product_order_api_key( $order_id, $product_variation_id );

            if ( ! empty( $product_order_api_key ) ) {
                // Add product key.
                $order_licenses[] = array(
                    'key'        => $product_order_api_key,
                    'quota'      => $activations,
                    'expiration' => $expiration,
                );
            }

            return $order_licenses;
        }

        /**
         * Get license expiration in UTC datetime.
         *
         * @author   Vova Feldman
         * @since    1.0.0
         *
         * @param \WC_Subscription $subscription
         *
         * @return null|string
         *
         */
        private function get_license_expiration( WC_Subscription $subscription ) {
            return ( $subscription->get_total() > 0 ?
                gmdate( 'Y-m-d H:i:s', $subscription->get_time( 'next_payment' ) ) :
                null
            );
        }

        /**
         * @author   Vova Feldman
         * @since    1.0.0
         *
         * @param \FS_CSV_Order_License $record
         * @param resource              $csv_file_pointer
         * @param int                   $index
         */
        private function output_record( FS_CSV_Order_License $record, $csv_file_pointer, $index ) {
            fputcsv( $csv_file_pointer, array_values( $record->to_array( $index ) ) );

            if ( self::DEBUG ) {
                // Debugging.
                echo json_encode( $record->to_array( $index ), JSON_PRETTY_PRINT );

                echo "<br><br>";
            }
        }

        /**
         * Get current request full URL.
         *
         * @author   Vova Feldman (@svovaf)
         * @since    1.0.3
         *
         * @return string
         */
        private function get_current_url() {
            $host = $_SERVER['HTTP_HOST'];
            $uri  = $_SERVER['REQUEST_URI'];
            $port = $_SERVER['SERVER_PORT'];

            $is_https = ( 443 == $port || ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) );

            return ( $is_https ? 'https' : 'http' ) . "://{$host}{$uri}";
        }

        #endregion
    }

    function fs_wc_export_init() {
        if ( ! is_admin() ) {
            // Ignore non WP Admin requests.
            return;
        }

        if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ||
             ( defined( 'DOING_CRON' ) && DOING_CRON )
        ) {
            // Ignore AJAX & WP-Cron requests.
            return;
        }

        $csv_exporter = FS_WC_Export::instance();
        $csv_exporter->init();
    }

    // Get Freemius WooCommerce Migration running.
    add_action( 'admin_init', 'fs_wc_export_init' );