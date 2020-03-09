<?php
/**
 * Plugin Name: WidepayCash Woocommerce Payments.
 * Plugin URI: https://wordpress.org/plugins/woo-widepaycash-payments-gateway/
 * Description: This plugin enables you to accept online payments for cards and mobile money payments using WidepayCash payment checkout.
 * Version: 1.0.0
 * Author: Gideon Ofori
 * Author URI: http://facebook.com/goofori
 * Author Email: kgoofori@gmail.com
 * License: GPLv2 or later
 * Requires at least: 4.4
 * Tested up to: 5.2.3
 * 
 * 
 * @package WidepayCash Payments Gateway
 * @category Plugin
 * @author Gideon Ofori
 */



if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
// if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))){
//     echo "<div class='error notice'><p>Woocommerce has to be installed and active to use the the WidepayCash Payments Gateway</b> plugin</p></div>";
//     return;
// }

function widepaycash_init()
{
	function add_widepaycash_payment_gateway( $methods ) 
	{
		$methods[] = 'WC_Widepaycash_Payment_Gateway'; 
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_widepaycash_payment_gateway' );

	if(class_exists('WC_Payment_Gateway'))
	{
		class WC_Widepaycash_Payment_Gateway extends WC_Payment_Gateway 
		{

			public function __construct()
			{

				$this->id               = 'widepaycash-payments';
				$this->icon             = plugins_url( 'images/widepaycash-logo.png' , __FILE__ ) ;
				$this->has_fields       = true;
				$this->method_title     = 'WidepayCash Payments'; 
				$this->description       = $this->get_option( 'widepaycash_description');            
				$this->init_form_fields();
				$this->init_settings();

				$this->title                    = $this->get_option( 'widepaycash_title' );
				$this->widepaycash_description       = $this->get_option( 'widepaycash_description');
				$this->widepaycash_clientid  	    = $this->get_option( 'widepaycash_clientid' );
				$this->widepaycash_clientsecret      = $this->get_option( 'widepaycash_clientsecret' );

				
				if (is_admin()) 
				{

					if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
						add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
					} else {
						add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
					}	
				}
				
				//register webhook listener action
				add_action( 'woocommerce_api_wc_widepaycash_payment_callback', array( $this, 'check_widepaycash_payment_webhook' ) );

			}

			public function init_form_fields()
			{

				$this->form_fields = array(
					'enabled' => array(
						'title' =>  'Enable/Disable',
						'type' => 'checkbox',
						'label' =>  'Enable WidepayCash Payments',
						'default' => 'yes'
						),

					'widepaycash_title' => array(
						'title' =>  'Title',
						'type' => 'text',
						'description' =>  'This displays the title which the user sees during checkout options.',
						'default' =>  'Pay With WidepayCash',
						'desc_tip'      => true,
						),

					'widepaycash_description' => array(
						'title' =>  'Description',
						'type' => 'textarea',
						'description' =>  'This is the description which the user sees during checkout.',
						'default' =>  'Safe and secure payments with Ghana issued cards and mobile money from all networks.',
						'desc_tip'      => true,
						),

					'widepaycash_clientid' => array(
						'title' =>  'API Key',
						'type' => 'text',
						'description' =>  'This is your WidepayCash API ID which you can find in your Dashboard.',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'WidepayCash API Key'
						),

					'widepaycash_clientsecret' => array(
						'title' =>  'API Secret',
						'type' => 'text',
						'description' =>  'This is your WidepayCash API Secret which you can find in your Dashboard.',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'WidepayCash API Secret'
						),
					);

			}

			/**
			 * handle webhook 
			 */
			public function check_widepaycash_payment_webhook()
			{
				$decode_webhook = json_decode(@file_get_contents("php://input"));

				 global $woocommerce;
				 $order_ref = $decode_webhook->Data->refNo;

				 //retrieve order id from the client reference
				 $order_ref_items = explode('-', $order_ref);
				 $order_id = end($order_ref_items);

				 $order = new WC_Order( $order_id );
				 //process the order with returned data from WidepayCash callback
				if($decode_webhook->ResponseCode == '0000' && $decode_webhook->transactionStatus == 'SUCCESSFUL')
				{
					
					$order->add_order_note('WidepayCash payment completed');				
					
					//Update the order status
					$order->update_status('payment processed', 'Payment Successful with WidepayCash');
					$order->payment_complete();

					//reduce the stock level of items ordered
					wc_reduce_stock_levels($order_id);
				}else{
					//add notice to order to inform merchant of 
					$order->add_order_note('Payment failed at WidepayCash.');
				}

				echo '12344';
				
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
				$widepaycash_items = [];
				$items_counter = 0;
                $total_cost = 0;

				//Add shipping and VAT as a stand alone item
				//so that it appears in the customers bill.
				$order_shipping_total = $order->get_total_shipping();
				$order_tax_total = $order->get_total_tax();

				// $widepaycash_items[$items_counter] = [
				// 			"name" => "VAT",
				// 			"quantity" => 1, // VAT is always 1. Lol
				// 			"unitPrice" => $order_tax_total
				// 	];
				// 	$items_counter = $items_counter+1;
				// $widepaycash_items[$items_counter] = [
				// 			"name" => "Shipping",
				// 			"quantity" => 1, // Always 1
				// 			"unitPrice" => $order_shipping_total
				// 	];
					
				// 	$items_counter = $items_counter+1;


				// foreach ($order_data as $order_key => $order_value):
				// 	$widepaycash_items[$items_counter] = [
				// 			"name" => $order_value->get_name(),
				// 			"quantity" => $order_value->get_quantity(), // Get the item quantity
				// 			"unitPrice" => $order_value->get_total()/$order_value->get_quantity()
				// 	];
					
				// 		$total_cost += $order_value->get_total();
				// 		$items_counter++;
				// endforeach;


				//widepaycash payment request body args
				$widepaycash_request_args = [
					//   "items" => $widepaycash_items,
					  "refNo" => date('YmdHis-').$order_id ,
					  "amount" => WC()->cart->get_cart_subtotal() + $order_tax_total +  $order_shipping_total,
                      
                    //   "totalAmount" =>$order_shipping_total + $total_cost + $order_tax_total, //get total cost of order items // WC()->cart->get_cart_subtotal();
                      "description" => $this->get_option('widepaycash_description'),
					  "callbackUrl" => WC()->api_request_url( 'WC_Widepaycash_Payment_Gateway'), //register callback
					  
					  "successUrl" => $order->get_checkout_order_received_url(), //return to this page
					  "failedUrl" => get_home_url(), //checkout url
					  "apiKey" => $this->widepaycash_clientid,
					  "apiSecret" => $this->widepaycash_clientsecret
				];
				
				
				//initiate request to WidepayCash payments API
				$base_url = 'https:api.widepaycash.com/v1.1/checkout/initialize';
				$response = wp_remote_post($base_url, array(
					'method' => 'POST',
					'timeout' => 45,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept' => 'application/json'
					),
					'body' => json_encode($widepaycash_request_args)
					)
				);

				
				//retrieve response body and extract the 
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body($response);

				$response_body_args = json_decode($response_body, true);

				switch ($response_code) {
					case 200:
							
							// $order->update_status('on-hold: awaiting payment', 'Awaiting payment');
							
							// Remove cart
							$woocommerce->cart->empty_cart();

							return array(
								'result'   => 'success',
								'redirect' => $response_body_args['data']['redirectUrl']
							);
						break;

					case 400:
                        wc_add_notice("HTTP STATUS: $response_code - Payment Request Error: A required field is invalid or empty. Check payment plugin setup.", "error");

						break;

					case 500:
							wc_add_notice("HTTP STATUS: $response_code - Payment System Error: Contact WidepayCash for assistance", "error" );
                            
						break;

					case 401:
							wc_add_notice("HTTP STATUS: $response_code - Authentication Error: Request failed due to invalid WidepayCash credentials. Setup API Key & Secret on your WidepayCash dashboard", "error" );

						break;

					default:
							wc_add_notice("HTTP STATUS: $response_code Payment Error: Could not reach WidepayCash Payment Gateway. Please try again", "error" );

						break;
				}
			}

        }  // end of class WC_Widepaycash_Payment_Gateway

} // end of if class exist WC_Gateway

}

/*Activation hook*/
add_action( 'plugins_loaded', 'widepaycash_init' );



