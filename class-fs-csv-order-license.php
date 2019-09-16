<?php

    class FS_CSV_Order_License {
        public $order_id;

        // User.
        public $user_email;
        public $user_firstname;
        public $user_lastname;
        public $user_ip;
        public $is_email_verified = false;

        // License.
        public $license_created;
        public $license_key;
        public $license_quantity;
        public $license_expires_at;

        // Billing.
        public $billing_email;
        public $business;
        public $phone;
        public $site_url;
        public $tax_id;
        public $address_street_1;
        public $address_street_2;
        public $address_city;
        public $address_state;
        public $address_country;
        public $address_country_code;
        public $address_zip;

        // Product.
        public $local_product_id;
        public $local_product_title;

        /**
         * @param array $license
         */
        public function set_license( $license ) {
            $this->license_key        = $license['key'];
            $this->license_quantity   = $license['quota'];
            $this->license_expires_at = $license['expiration'];
        }

        public function to_array( $count = 0, $order_index = 0 ) {
            /*$utf8_encoded = array(
                'user_firstname'   => '',
                'user_lastname'    => '',
                'business'         => '',
                'address_street_1' => '',
                'address_street_2' => '',
                'address_city'     => '',
                'address_state'    => '',
                'address_country'  => '',
            );

            foreach ( $utf8_encoded as $prop => $value ) {
                if ( is_string( $this->{$prop} ) && ! empty( $this->{$prop} ) ) {
//                    $utf8_encoded[ $prop ] = utf8_decode( $this->{$prop} );
                    $utf8_encoded[ $prop ] = $this->{$prop};
                }
            }*/

            return array(
                'index'                => $count,
                'order_index'          => $order_index,
                'order_id'             => $this->order_id,

                // User.
                'user_email'           => $this->user_email,
//                'first_name'           => $utf8_encoded['user_firstname'],
//                'last_name'            => $utf8_encoded['user_lastname'],
                'first_name'           => $this->user_firstname,
                'last_name'            => $this->user_lastname,
                'ip'                   => $this->user_ip,
                'is_email_verified'    => $this->is_email_verified ? 'true' : 'false',

                // License.
                'license_quantity'     => $this->license_quantity,
                'license_created'      => $this->license_created,
                'license_expires_at'   => $this->license_expires_at,
                'license_key'          => $this->license_key,

                // Billing.
                'billing_email'        => $this->billing_email,
//                'business_name'        => $utf8_encoded['business'],
                'business_name'        => $this->business,
                'business_phone'       => $this->phone,
                'website_url'          => $this->site_url,
                'tax_id'               => $this->tax_id,
//                'address_street_1'     => $utf8_encoded['address_street_1'],
//                'address_street_2'     => $utf8_encoded['address_street_2'],
//                'address_city'         => $utf8_encoded['address_city'],
//                'address_state'        => $utf8_encoded['address_state'],
//                'address_country'      => $utf8_encoded['address_country'],
                'address_street_1'     => $this->address_street_1,
                'address_street_2'     => $this->address_street_2,
                'address_city'         => $this->address_city,
                'address_state'        => $this->address_state,
                'address_country'      => $this->address_country,
                'address_country_code' => $this->address_country_code,
                'address_zip'          => $this->address_zip,

                // Product.
                'local_product_id'     => $this->local_product_id,
                'local_product_title'  => $this->local_product_title,
            );
        }

        /**
         * Set the billing details by an order.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param \WC_Order $wc_order
         */
        public function set_billing_by_order( WC_Order $wc_order ) {
            $this->billing_email    = $wc_order->get_billing_email();
            $this->business         = $wc_order->get_billing_company();
            $this->phone            = $wc_order->get_billing_phone();
            $this->address_street_1 = $wc_order->get_billing_address_1();
            $this->address_street_2 = $wc_order->get_billing_address_2();
            $this->address_city     = $wc_order->get_billing_city();
            $this->address_state    = $wc_order->get_billing_state();
            $this->address_country  = $wc_order->get_billing_country();
            $this->address_zip      = $wc_order->get_billing_postcode();

            $this->tax_id = '';
        }

        /**
         * Set the user details by an order.
         *
         * @author Vova Feldman
         * @since  1.0.0
         *
         * @param \WC_Order $wc_order
         */
        public function set_user_info_by_order( WC_Order $wc_order ) {
            $customer = $wc_order->get_user();

            // User fields.
            if ( ! is_object( $customer ) ) {
                $this->user_email = $wc_order->get_billing_email();
            } else {
                $this->user_email     = $customer->user_email;
                $this->user_firstname = $customer->user_firstname;
                $this->user_lastname  = $customer->user_lastname;
            }

            if ( empty( $this->user_firstname ) ) {
                $this->user_firstname = $wc_order->get_billing_first_name();
            }
            if ( empty( $this->user_lastname ) ) {
                $this->user_lastname = $wc_order->get_billing_last_name();
            }

            $this->user_ip = $wc_order->get_customer_ip_address();

            $this->is_email_verified = false;
        }
    }