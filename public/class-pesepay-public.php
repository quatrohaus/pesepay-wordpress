<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://pesepay.com
 * @since      1.0.0
 *
 * @package    Pesepay
 * @subpackage Pesepay/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Pesepay
 * @subpackage Pesepay/public
 * @author     Pesepay <digital@pesepay.com>
 */
class Pesepay_Public
{

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{

		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Handle response from pesepay
	 *
	 * @since 1.0.0
	 * @version 1.0.0
	 * @return array
	 */
	public function woocommerce_api_wc_gateway()
	{

		$order = filter_input(INPUT_GET, "order", FILTER_VALIDATE_INT);

		if ($order) {

			$order = wc_get_order($order);

			$reference = $order->get_meta(PesePay_Helper::meta_key_prefix("-reference-number"));

			$response = PesePay_Helper::remote_check_transaction($reference);

			if ($response) {
				#	die(json_encode($response));
				if ($response["success"]) {

					# Save the reference number and/or poll url (used to check the status of a transaction)
					$order->update_meta_data(PesePay_Helper::meta_key_prefix("-reference-number"), $response["data"]["referenceNumber"]);
					$order->save_meta_data();

					#
					switch (strtoupper($response["data"]["transactionStatus"])) {
						case "CANCELLED":
							$message  = __("Transaction cancelled on Pesepay", $this->plugin_name);

							wc_add_notice($message, "error");

							PesePay_Helper::log($message . " Order #: " . $order->get_id());

							break;
						case "SUCCESS":
							//payment confirmed
							$order->payment_complete();

							// Reduce stock levels
							wc_reduce_stock_levels($order->get_id());

							PesePay_Helper::log(__("Payment Completed", $this->plugin_name) . " Order #: " . $order->get_id());

							$gateway = PesePay_Helper::get_gateway_instance();

							if (is_object($gateway)) {
								wp_redirect($gateway->get_return_url($order));
							} else {
								wp_redirect($order->get_checkout_order_received_url());
							}
							return;
							break;
						case "FAILED":
						default:
							$message = __("Payment failed on Pesepay", $this->plugin_name);
							$order->set_status("failed", $message);

							wc_add_notice($message, "error");

							PesePay_Helper::log($message . " Order #: " . $order->get_id());
					}
				} else {
					# Get error message
					$message = $response["data"]["transactionStatusDescription"];
					$order->set_status("failed", $message);

					wc_add_notice($message, "error");

					PesePay_Helper::log($response["data"]["transactionStatusDescription"]);
				}
			} else {
				# Get generic error message
				$message = __("Error retriving transaction status", $this->plugin_name);
				$order->set_status("failed", $message);

				wc_add_notice($message, "error");

				PesePay_Helper::log($message . " Order #: " . $order->get_id());
			}

			$order->save();

			wp_redirect($order->get_checkout_payment_url());
			return;
		}

		wp_redirect(site_url());
	}

	/**
	 * Set order status as set in payment gateway options
	 *
	 * @param string $status
	 * @param string $order
	 * @return string
	 */
	public function woocommerce_payment_complete_order_status($status, $order)
	{

		$order = wc_get_order($order);

		if ($order) {

			if ($order->get_payment_method() == PesePay_Helper::get_gateway_id()) {

				$gateway = PesePay_Helper::get_gateway_instance();

				if (is_object($gateway)) {
					$_status = $gateway->get_option("status");

					#Only change if specifically set to be changed
					if ($_status) {
						$status = $_status;
					}
				}
			}
		}

		return $status;
	}
}