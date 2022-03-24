<?php

defined('ABSPATH') || exit;

/**
 * The file that defines the core payment class
 *
 * A class definition that includes attributes and functions used across the
 * payments side of the site and the admin area.
 *
 * @link       https://pesepay.com
 * @since      1.0.0
 *
 * @package    Pesepay
 * @subpackage Pesepay/includes
 */

/**
 * The core payment class.
 *
 * This is used to define payment hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this payment gateway as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Pesepay
 * @subpackage Pesepay/includes/classes
 * @author     Pesepay <digital@pesepay.com>
 */
class WC_Pesepay_Gateway extends WC_Payment_Gateway
{

    /**
     * Initialise the payment gateway
     * 
     * We only get one chance to do it correctly ;)
     * 
     * @since 1.0.0
     * @version 1.0.0
     * @return void
     */
    public function __construct()
    {
        $this->id = 'wc_gateway_pesepay';
        $this->icon = plugin_dir_url(dirname(__DIR__)) . 'public/img/logo.svg';
        $this->has_fields = true;
        $this->method_title = __('Pesepay', PESEPAY_SLUG);
        $this->method_description = __('Pay with Pesepay', PESEPAY_SLUG);

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    /**
     * Prepare the admin fields for setting up the plugin
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return void
     */
    public function init_form_fields()
    {

        /**
         * Currencies supported by this gateway
         */
        $currencies = PesePay_Helper::get_supported_currencies();
        $currencies =  array_combine(array_column($currencies, "code"), array_column($currencies, "name"));

        /**
         * Quick link tom pesepay
         */
        $anchor = '<a href="https://pesepay.com">Pesepay</a>';

        /**
         * Status to set after an order has been completed
         */
        $statuses = wc_get_is_paid_statuses();
        $statuses = array_combine($statuses, array_map("wc_get_order_status_name", $statuses));

        $statuses = array_merge(array("" =>  __("Select Status", PESEPAY_SLUG)), $statuses);

        /**
         * The form fields
         */
        $this->form_fields = apply_filters(PESEPAY_SLUG . '_form_fields', array(

            'enabled' => array(
                'title'   => __('Enable/Disable', PESEPAY_SLUG),
                'type'    => 'checkbox',
                'label'   => __('Enable Pesepay Payment', PESEPAY_SLUG),
                'default' => 'no'
            ),
            'encryption_key' => array(
                'title'       => __('Encryption Key', PESEPAY_SLUG),
                "custom_attributes" => array("minlength" => PesePay_Helper::encryption_key_length(), "maxlength" => PesePay_Helper::encryption_key_length()),
                'type'        => 'password',
                'description' => sprintf(__('Encryption key, obtained from %s', PESEPAY_SLUG), $anchor),
            ),
            'integration_key' => array(
                'title'       => __('Integration Key', PESEPAY_SLUG),
                'type'        => 'password',
                'description' => sprintf(__('Integration key, obtained from %s', PESEPAY_SLUG), $anchor),
            ),
            'title' => array(
                'title'       => __('Title', PESEPAY_SLUG),
                'type'        => 'text',
                'description' => __('This controls the title for the payment method the customer sees during checkout.', PESEPAY_SLUG),
                'default'     => __('Pesepay Payment', PESEPAY_SLUG),
                'desc_tip'    => true,
            ),

            'description' => array(
                'title'       => __('Description', PESEPAY_SLUG),
                'type'        => 'text',
                'description' => __('Payment method description that the customer will see on your checkout.', PESEPAY_SLUG),
                'default'     => __('Pay with Pesepay.', PESEPAY_SLUG),
                'desc_tip'    => true,
            ),

            'status' => array(
                'title'       => __('Order Status', PESEPAY_SLUG),
                'type'        => 'select',
                'description' => __('Order status after a customer has completed payment.', PESEPAY_SLUG),
                'default'     => current($statuses),
                'options' => $statuses,
                'desc_tip'    => true,
                "class" => "wc-enhanced-select",
            ),

            'currencies' => array(
                'title'       => __('Currencies', PESEPAY_SLUG),
                'type'        => 'multiselect',
                'description' => __('Currencies this payment gateway should handle.', PESEPAY_SLUG),
                'default'     => current(PesePay_Helper::get_supported_currency_codes()),
                'desc_tip'    => true,
                "class" => "wc-enhanced-select",
                'options' => $currencies,
                "select_buttons" => true
            ),

            'instructions' => array(
                'title'       => __('Instructions', PESEPAY_SLUG),
                'type'        => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.(Optional)', PESEPAY_SLUG),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'debug'                 => array(
                'title'       => __('Debug log', PESEPAY_SLUG),
                'type'        => 'checkbox',
                'label'       => __('Enable logging', PESEPAY_SLUG),
                'default'     => 'no',
            )
        ));
    }

    /**
     * Show the pesepay badge
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return void
     */
    public function payment_fields()
    {
        parent::payment_fields();

        include plugin_dir_path(dirname(__DIR__)) . "public/partials/pesepay-public-display.php";
    }

    /**
     * Initiate payment on pesepay and redirect
     *
     * @since 1.0.0
     * @version 1.0.0
     * @param int $order_id
     * @return array
     */
    function process_payment($order_id): array
    {

        $order = wc_get_order($order_id);

        $total = $this->get_order_total();
        $currency = get_woocommerce_currency();
        $ref = sprintf(__("Order #: %s", PESEPAY_SLUG), $order->get_id());

        $url = add_query_arg(
            array(
                'wc-api' => PESEPAY_SLUG,
                "order" => $order->get_id()
            ),
            site_url()
        );

        $links = array(
            "returnUrl" => $url,
            "resultUrl" => $url
        );

        $response = PesePay_Helper::remote_init_transaction($total, $currency, $ref, $links);

        if ($response) {

            if ($response["success"]) {

                # Save the reference number and/or poll url (used to check the status of a transaction)
                $order->update_meta_data(PesePay_Helper::meta_key_prefix("-reference-number"), $response["data"]["referenceNumber"]);
                $order->save_meta_data();

                WC()->cart->empty_cart();

                return array(
                    'result'     => 'success',
                    'redirect'    => $response["data"]["redirectUrl"]
                );
            } else {
                # Get error message
                wc_add_notice($response["data"]["transactionStatusDescription"], "error");

                PesePay_Helper::log($response["data"]["transactionStatusDescription"] . "Order #: " . $order->get_id());
            }
        } else {
            # Get generic error message
            $message = __('Failed to initiate the transaction on pesepay, make sure your credentials are correct', PESEPAY_SLUG);

            wc_add_notice($message, "error");

            PesePay_Helper::log($message . "Order #: " . $order->get_id());
        }

        return parent::process_payment($order_id);
    }

    /**
     * Check if the gateway needs setup
     *
     * @since 1.0.0
     * @version 1.0.0
     * @return bool
     */
    public function needs_setup()
    {
        $keys = array("encryption_key", "integration_key");

        $enabled = true;
        foreach ($keys as $key) {
            $enabled &= strlen($this->get_option($key, "")) > 0;
        }

        return !$enabled;
    }

    /**
     * Check if the gateway is available for use.
     *
     * @version 1.0.0
     * @since 1.0.0
     * @return bool
     */
    public function is_available()
    {

        if (parent::is_available() && !$this->needs_setup()) {

            $currencies = $this->get_option("currencies", array());

            return in_array(get_woocommerce_currency(), wp_parse_list($currencies));
        }

        return false;
    }
} // end \WC_Pesepay 