<?php
/**
 * Plugin Name: Nsano Woocommerce Payments.
 * Plugin URI: https://github.com/diversetech/woo-nsano.git
 * Description: This plugin enables you to accept online payments for cards and mobile money payments using Nsano payment checkout.
 * Version: 1.0.0
 * Author: Gideon Ofori
 * Author URI: http://github.com/kgoofori
 * Author Email: kgoofori@gmail.com
 * License: GPLv2 or later
 * Requires at least: 4.4
 * Tested up to: 5.2.3
 * 
 * 
 * @package Nsano Payments Gateway
 * @category Plugin
 * @author Gideon Ofori
 */



if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))){
//     echo "<div class='error notice'><p>Woocommerce has to be installed and active to use the the Nsano Payments Gateway</b> plugin</p></div>";
//     return;
// }

function woo_nsano_init()
{
	function add_woo_nsano_payment_gateway( $methods ) 
	{
		$methods[] = 'WC_WooNsano_Payment_Gateway'; 
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_woo_nsano_payment_gateway' );

	if(class_exists('WC_Payment_Gateway'))
	{
		class WC_WooNsano_Payment_Gateway extends WC_Payment_Gateway 
		{

			public function __construct()
			{

				$this->id               = 'woo-nsano-payments';
				$this->icon             = plugins_url( 'images/nsano-logo.png' , __FILE__ ) ;
				$this->has_fields       = true;
				$this->method_title     = 'WooNsano Payments'; 
				$this->description       = $this->get_option( 'woo_nsano_description');            
				$this->init_form_fields();
				$this->init_settings();

				$this->title                    = $this->get_option( 'woo_nsano_title' );
				$this->woo_nsano_description       = $this->get_option( 'woo_nsano_description');
				$this->woo_nsano_merchant_id  	    = $this->get_option( 'woo_nsano_merchant_id' );
				$this->woo_nsano_merchant_key  	    = $this->get_option( 'woo_nsano_merchant_key' );

				
				if (is_admin()) 
				{

					if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
						add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
					} else {
						add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
					}	
				}
				
				//register webhook listener action
				add_action( 'woocommerce_api_wc_woo_nsano_payment_callback', array( $this, 'check_woo_nsano_payment_webhook' ) );

			}

			public function init_form_fields()
			{

				$this->form_fields = array(
					'enabled' => array(
						'title' =>  'Enable/Disable',
						'type' => 'checkbox',
						'label' =>  'Enable Nsano Payments',
						'default' => 'yes'
						),

					'woo_nsano_title' => array(
						'title' =>  'Title',
						'type' => 'text',
						'description' =>  'This displays the title which the user sees during checkout options.',
						'default' =>  'Pay With Nsano',
						'desc_tip'      => true,
						),

					'woo_nsano_description' => array(
						'title' =>  'Description',
						'type' => 'textarea',
						'description' =>  'This is the description which the user sees during checkout.',
						'default' =>  'Safe and secure payments with Ghana issued cards and mobile money from all networks.',
						'desc_tip'      => true,
						),

					'woo_nsano_merchant_id' => array(
						'title' =>  'Merchant ID',
						'type' => 'text',
						'description' =>  'This is your Merchant ID which you can find in your Dashboard.',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Merchant ID'
						),

					'woo_nsano_merchant_key' => array(
						'title' =>  'Merchant Key',
						'type' => 'text',
						'description' =>  'This is your Merchant Key which you can find in your Dashboard.',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Merchant Key'
						),
					);

			}

			/**
			 * handle webhook 
			 */
			public function check_woo_nsano_payment_webhook()
			{
				if ($_SERVER['REQUEST_METHOD'] === 'GET') {
					$order = new WC_Order();

					header('Location: '.$order->get_checkout_order_received_url());
					exit;
				}

				$base_url = 'https://manilla.nsano.com/checkout/verify';
				$response = wp_remote_post($base_url, array(
					'method' => 'POST',
					'timeout' => 1000,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept' => 'application/json',
						'Authorization' => 'Bearer '.$this->woo_nsano_merchant_id
						
					),
					'body' => json_encode([
						"order_id" => $_POST['order_id'],
						"merchant_apiKey" => $this->woo_nsano_merchant_key,
						"merchant_id" => $this->woo_nsano_merchant_id
					])
					)
				);

				
				//retrieve response body and extract the 
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body($response);

				$response_body_args = json_decode($response_body, true);
				$order_ref = $_POST['order_id'];

				//retrieve order id from the client reference
				$order_ref_items = explode('-', $order_ref);
				$order_id = end($order_ref_items);

				$order = new WC_Order( $order_id );
				//process the order with returned data from Nsano callback

				if($response_code == 200 && $response_body_args['code'] == '00')
				{
					
					$order->add_order_note('Nsano payment completed');				
					
					//Update the order status
					$order->update_status('payment processed', 'Payment Successful with Nsano');
					$order->payment_complete();

					//reduce the stock level of items ordered
					wc_reduce_stock_levels($order_id);

				}else{
					//add notice to order to inform merchant of 
					$order->add_order_note('Payment failed at Nsano.');
				}
				
			}

			/**
			 * process payments
			 */
			public function process_payment($order_id)
			{
				global $woocommerce;

				$order = new WC_Order( $order_id );

				// Get an instance of the WC_Order object
				$order = wc_get_order( $order_id );

				// $order_data = $order->get_items();

				//build order items for the hubel request body
				$woo_nsano_items = [];
				$items_counter = 0;
                $total_cost = 0;

				//Add shipping and VAT as a stand alone item
				//so that it appears in the customers bill.
				// $order_shipping_total = $order->get_total_shipping();
				$order_tax_total = $order->get_total_tax();

				//Nsano payment request body args
				$woo_nsano_request_args = [
					"order_id" => date('YmdHis-').$order_id ,
					"description" => $this->get_option('woo_nsano_description'),
					"amount" =>  $order->get_total() + $order_tax_total,
					'cust_firstname' => 'NA',
					'cust_lastname' => 'NA',
					"currency" => get_woocommerce_currency(),
					"return_url" => home_url('/wc-api/wc_woo_nsano_payment_callback'), //return to this page
					"cancel_url" => get_home_url(), //checkout url

					// was double charging shipping
					// "amount" =>  $order->get_total() + $order_tax_total +  $order_shipping_total,
					//   "callbackUrl" => WC()->api_request_url( 'WC_WooNsano_Payment_Gateway'), //register callback
				];
				
				
				//initiate request to Nsano payments API
				$base_url = 'https://manilla.nsano.com/checkout/payment';
				$response = wp_remote_post($base_url, array(
					'method' => 'POST',
					'timeout' => 1000,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept' => 'application/json',
						'Authorization' =>'Bearer '. $this->woo_nsano_merchant_id
						
					),
					'body' => json_encode($woo_nsano_request_args)
					)
				);

				
				//retrieve response body and extract the 
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body($response);

				$response_body_args = json_decode($response_body, true);

				switch ($response_code) {
					case 200:
							
						if($response_body_args['code'] == '00'){

							$woocommerce->cart->empty_cart();

							return array(
								'result'   => 'success',
								'redirect' => $response_body_args['data']['links']['checkout_url']
							);
						}

						wc_add_notice("HTTP STATUS: {$response_body_args['code']} - Something went wrong, please try again", "error" );
							
						break;

					case 400:
                        wc_add_notice("HTTP STATUS: $response_code - Payment Request Error: A required field is invalid or empty. Check payment plugin setup.", "error");

						break;

					case 500:
							wc_add_notice("HTTP STATUS: $response_code - Payment System Error: Contact Nsano for assistance", "error" );
                            
						break;

					case 401:
							wc_add_notice("HTTP STATUS: $response_code - Authentication Error: Request failed due to invalid Nsano credentials. Setup API Key & Secret on your Nsano dashboard", "error" );

						break;

					default:
							wc_add_notice("HTTP STATUS: $response_code Payment Error: Could not reach Nsano Payment Gateway. Please try again", "error" );

						break;
				}
			}

        }  // end of class WC_WooNsano_Payment_Gateway

} // end of if class exist WC_Gateway

}

/*Activation hook*/
add_action( 'plugins_loaded', 'woo_nsano_init' );



