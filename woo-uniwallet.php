<?php
/**
 * Plugin Name: Uniwallet Payments for WooCommerce.
 * Plugin URI: https://wordpress.org/plugins/woo-uniwallet-payments-gateway/
 * Description: This plugin enables you to accept online payments for mobile money payments using Uniwallet payments API..
 * Version: 1.0.0
 * Author: Sadart Abukari
 * Author URI: https://www.linkedin.com/in/sadart-abukari-43a78a26/
 * Author Email: sadartabukari@gmail.com
 * License: GPLv2 or later
 * Requires at least: 4.4
 * Tested up to: 5.2.3
 * 
 * 
 * @package Uniwallet Payments Gateway
 * @category Plugin
 * @author Sadart Abukari
 */



if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))){
    echo "<div class='error notice'><p>Woocommerce has to be installed and active to use the the Uniwallet Payments Gateway</b> plugin</p></div>";
    return;
}

function uniwallet_init()
{
	function add_uniwallet_gateway_class( $methods ) 
	{
		$methods[] = 'WC_Uniwallet_Payment_Gateway'; 
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_uniwallet_gateway_class' );

	if(class_exists('WC_Payment_Gateway'))
	{
		class WC_Uniwallet_Payment_Gateway extends WC_Payment_Gateway 
		{

			public function __construct()
			{

				$this->id               = 'uniwallet-payments';
				// $this->icon             = plugins_url( 'images/uniwallet-0.png' , __FILE__ ) ;
				$this->has_fields       = true;
				$this->method_title     = 'Uniwallet Payments'; 
				$this->description       = $this->get_option( 'uniwallet_description');            
				$this->init_form_fields();
				$this->init_settings();

				$this->title                    = $this->get_option( 'uniwallet_title' );
				$this->uniwallet_description       = $this->get_option( 'uniwallet_description');
				$this->uniwallet_api_key      = $this->get_option( 'uniwallet_api_key' );
				$this->uniwallet_merchant_id   = $this->get_option( 'uniwallet_merchant_id' );
				$this->uniwallet_product_id   = $this->get_option( 'uniwallet_product_id' );
				$this->uniwallet_ip  = $this->get_option( 'uniwallet_ip' );
				$this->uniwallet_port  = $this->get_option( 'uniwallet_port' );

				
				if (is_admin()) 
				{

					if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
						add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
					} else {
						add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
					}				}
				
				//register webhook listener action
				add_action( 'woocommerce_api_wc_uniwallet_payment_gateway', array( $this, 'check_uniwallet_payment_webhook' ) );

			}

			public function init_form_fields()
			{

				$this->form_fields = array(
					'enabled' => array(
						'title' =>  'Enable/Disable',
						'type' => 'checkbox',
						'label' =>  'Enable Uniwallet Payments',
						'default' => 'yes'
						),

					'uniwallet_title' => array(
						'title' =>  'Title',
						'type' => 'text',
						'description' =>  'Pay With Uniwallet.',
						'default' =>  'Pay With Uniwallet',
						'desc_tip'      => true,
						),

					'uniwallet_description' => array(
						'title' =>  'Description',
						'type' => 'textarea',
						'description' =>  'Mobile Money Payment from all networks.',
						'default' =>  'Safe and secure payments with Ghana mobile money from all networks.',
						'desc_tip'      => true,
						),

					'uniwallet_api_key' => array(
						'title' =>  'API Key',
						'type' => 'text',
						'description' =>  'This is your Uniwallet API Key .',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Uniwallet API Key'
						),

				'uniwallet_merchant_id' => array(
						'title' =>  'Uniwallet Merchant ID',
						'type' => 'text',
						'description' =>  'This is your Uniwallet Merchant ID',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Uniwallet Merchant ID'
						),
				'uniwallet_product_id' => array(
						'title' =>  'Uniwallet Product ID',
						'type' => 'text',
						'description' =>  'This is your Uniwallet Product ID',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Uniwallet Uniwallet Product ID'
						),
				'uniwallet_ip' => array(
						'title' =>  'Uniwallet IP',
						'type' => 'text',
						'description' =>  'This is your Uniwallet IP',
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Uniwallet Uniwallet IP'
						),
						'uniwallet_port' => array(
							'title' =>  'Uniwallet PORT',
							'type' => 'text',
							'description' =>  'This is your Uniwallet PORT',
							'default' => '',
							'desc_tip'      => true,
							'placeholder' => 'Uniwallet Uniwallet PORT'
							),
										
					);

			}

			/**
			 * handle webhook 
			 */
			public function check_uniwallet_payment_webhook()
			{
				$decode_webhook = json_decode(@file_get_contents("php://input"));

				 global $woocommerce;
				 $order_ref = $decode_webhook->refNo;

				 //retrieve order id from the client reference
				 $order_ref_items = explode('-', $order_ref);
				 $order_id = $order_ref_items[1];

				 $order = new WC_Order( $order_id );
				 //process the order with returned data from Uniwallet callback
				if($decode_webhook->responseCode == '01')
				{
					
					$order->add_order_note('Uniwallet payment completed');				
					
					//Update the order status
					$order->update_status('payment processed', 'Payment Successful with Uniwallet');
					$order->payment_complete();

					//reduce the stock level of items ordered
					wc_reduce_stock_levels($order_id);
				}else{
					//add notice to order to inform merchant of 
					$order->add_order_note('Payment failed at Uniwallet. Send an email to support@uniwallet.com for assistance using this checkout ID:'.$decode_webhook->refNo);
				}
				
			}

			/**
			 * process payments
			 */
			public function process_payment($order_id)
			{

				// Phone number is required
				if(empty($_POST['uniwallet_phone'])){
					wc_add_notice("Please enter your Mobile Money Number.", "error");
					return FALSE;
				}

				// Phone numbers are supposed to be minimum 9 numbers
				if(empty($_POST['uniwallet_phone']) || strlen(trim($_POST['uniwallet_phone'])) < 9 
				|| !is_numeric($_POST['uniwallet_phone'])
				){
					wc_add_notice("Please enter a valid Mobile Money Number.", "error");
					return FALSE;
				}

				// Network is required
				if(empty($_POST['uniwallet_network'])){
					wc_add_notice("Please select network.", "error");
					return FALSE;
				}

				// Validate selected network
				$valid_network = ['MTN', 'ARTLTIGO', 'VODAFONE'];

				// Don't assume the correct value will be SENT due to option selection on payment form
				if(!in_array($_POST['uniwallet_network'], $valid_network)){
					wc_add_notice("Please select a valid network.", "error");
					return FALSE;
				}

				//MAKE SURE PHONE NUMBER HAS THE RIGHT FORMAT
				$_POST['uniwallet_phone'] = trim($_POST['uniwallet_phone']);
				if(!preg_match("#^233#" , $_POST['uniwallet_phone'])){
					$_POST['uniwallet_phone'] = preg_replace("#([0])([0-9]+)#" , "$2" ,$_POST['uniwallet_phone']);
					$_POST['uniwallet_phone'] = "233".trim($_POST['uniwallet_phone']);
				}
				
				global $woocommerce;

				$order = new WC_Order( $order_id );

				// Get an instance of the WC_Order object
				$order = wc_get_order( $order_id );

				$order_data = $order->get_items();

				//build order items for the uniwallet request body
				$uniwallet_items = [];
				$items_counter = 0;
                $total_cost = 0;

				//Add shipping and VAT as a stand alone item
				//so that it appears in the customers bill.
				$order_shipping_total = $order->get_total_shipping();
				$order_tax_total = $order->get_total_tax();
				$uniwallet_items[$items_counter] = [
							"name" => "VAT",
							"quantity" => 1, // VAT is always 1. Lol
							"unitPrice" => $order_tax_total
					];
					$items_counter = $items_counter+1;
				$uniwallet_items[$items_counter] = [
							"name" => "Shipping",
							"quantity" => 1, // Always 1
							"unitPrice" => $order_shipping_total
					];
					
					$items_counter = $items_counter+1;


				foreach ($order_data as $order_key => $order_value):
					$uniwallet_items[$items_counter] = [
							"name" => $order_value->get_name(),
							"quantity" => $order_value->get_quantity(), // Get the item quantity
							"unitPrice" => $order_value->get_total()/$order_value->get_quantity()
					];
					
						$total_cost += $order_value->get_total();
						$items_counter++;
				endforeach;


				//uniwallet payment request body args
				$uniwallet_request_args = [
					  "productId" => $this->uniwallet_product_id, 
					  "merchantId" => $this->uniwallet_merchant_id,
					  "refNo" => round(microtime(true) * 1000).'-'.$order_id, //generate a unique id the client reference
					  "msisdn" => $_POST['uniwallet_phone'],
					 // "amount" =>$total_cost, //get total cost of order items // WC()->cart->get_cart_subtotal();                      
					  "amount" =>$order_shipping_total + $total_cost + $order_tax_total, //get total cost of order items // WC()->cart->get_cart_subtotal();
					  "network" => $_POST['uniwallet_network'],					  
                      "narration" => $this->get_option('uniwallet_description'),
					  "apiKey" => $this->uniwallet_api_key, 
				];

				// var_dump($uniwallet_request_args); die;

				//initiate request to Uniwallet payments API
				$base_url = 'http://'.$this->uniwallet_ip .':'.$this->uniwallet_port.'/uniwallet/debit/customer';

				$response = wp_remote_post($base_url, array(
					'method' => 'POST',
					'timeout' => 45,
					'headers' => array(
						'Content-Type' => 'application/json'
					),
					'body' => json_encode($uniwallet_request_args)
					)
				);

		
				//retrieve response body and extract the 
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body($response);

	
				$response_body_args = json_decode($response_body, true);

				switch ($response_code) {
					case 200:

							if($response_body_args['responseCode'] == '03'){
								$order->update_meta_data( '_uniwallet_transaction_id' , $response_body_args['uniwalletTransactionId']) ;
								$order->update_meta_data( '_response' , $response_body) ;
								$order->update_meta_data( '_response_message' , $response_body_args['responseMessage']) ;
								$order->update_meta_data( '_response_code' , $response_body_args['responseCode']) ;
								wc_add_notice("Enter your PIN on the prompt on your phone to complete payment", "success");
								$order->update_status('on-hold: awaiting payment', 'Awaiting payment');

								// return FALSE;
								// Remove cart
								$woocommerce->cart->empty_cart();
								return array(
									'result'   => 'success',
									'redirect' =>  $this->get_return_url( $order ),//$response_body_args['data']['checkoutDirectUrl']
								);								
							}
							
							

							return array(
								'result'   => 'success',
								// 'redirect' => $response_body_args['data']['checkoutDirectUrl']
							);
						break;

					case 400:
                        wc_add_notice("HTTP STATUS: $response_code - Payment Request Error: A required field is invalid or empty. Check payment plugin setup.", "error");

						break;

					case 500:
							wc_add_notice("HTTP STATUS: $response_code - Payment System Error: Contact Uniwallet for assistance", "error" );
                            
						break;

					case 401:
							wc_add_notice("HTTP STATUS: $response_code - Authentication Error: Request failed due to invalid Uniwallet credentials. Setup with valid Merchant number, API Key & Secret", "error" );

						break;

					default:
							wc_add_notice("HTTP STATUS: $response_code Payment Error: Could not reach Uniwallet Payment Gateway. Please try again", "error" );

						break;
				}
			}

        }  // end of class WC_Uniwallet_Payment_Gateway
// UNIWALLET payment gateway description: Append custom phone number and network select field
add_filter( 'woocommerce_gateway_description', 'gateway_uniwallet_custom_fields', 20, 2 );
function gateway_uniwallet_custom_fields( $description, $payment_id ){
		//  uniwallet-payments
	// as in constructor ... 				
	//$this->id               = 'uniwallet-payments';
    if( 'uniwallet-payments' === $payment_id ){
        ob_start(); // Start buffering

        echo '<div  class="uniwallet-fields" style="padding:10px 0;">';

        woocommerce_form_field( 'uniwallet_phone', array(
            'type'          => 'text',
            'label'         => __("Enter Mobile Money Number", "woocommerce"),
            'class'         => array('form-row-wide'),
			'required'      => true,
			'description' =>  'Mobile Money Number',
			'default' => '',
			'desc_tip'      => true,
			'placeholder' => '0201234567'			
        ), '');
        woocommerce_form_field( 'uniwallet_network', array(
            'type'          => 'select',
            'label'         => __("Select Network", "woocommerce"),
            'class'         => array('form-row-wide'),
            'required'      => true,
            'options'       => array(
                ''          => __("Select Network", "woocommerce"),
                'MTN'  => __("MTN", "woocommerce"),
                'ARTLTIGO'  => __("AIRTELTIGO", "woocommerce"),
                'VODAFONE'  => __("VODAFONE", "woocommerce"),
            ),
        ), '');

        echo '<div>';

        $description .= ob_get_clean(); // Append buffered content
    }
    return $description;
}

// Checkout custom field validation
add_action('woocommerce_checkout_process', 'uniwallet_option_validation' );
function uniwallet_option_validation() {
    // if ( isset($_POST['payment_method']) && $_POST['payment_method'] === 'uniwallet-payments'
    // && isset($_POST['uniwallet_phone']) && empty($_POST['uniwallet_phone']) ) {
    //     wc_add_notice( __( 'Please Select an option for "Please enter a phone number.' ), 'error' );
    // }
}


// Checkout custom field save to order meta
add_action('woocommerce_checkout_create_order', 'save_uniwallet_option_order_meta', 10, 2 );
function save_uniwallet_option_order_meta( $order, $data ) {
    if ( isset($_POST['uniwallet_phone']) && ! empty($_POST['uniwallet_phone']) ) {
        $order->update_meta_data( '_uniwallet_phone' , esc_attr($_POST['uniwallet_phone']) );
    }
    if ( isset($_POST['uniwallet_network']) && ! empty($_POST['uniwallet_network']) ) {
        $order->update_meta_data( '_uniwallet_network' , esc_attr($_POST['uniwallet_network']) );
    }
}


// Display custom field on order totals lines everywhere
add_action('woocommerce_get_order_item_totals', 'display_uniwallet_option_on_order_totals', 10, 3 );
function display_uniwallet_option_on_order_totals( $total_rows, $order, $tax_display ) {
    if ( $order->get_payment_method() === 'uniwallet-payments' && $uniwallet_phone = $order->get_meta('_uniwallet_phone') ) {
        $sorted_total_rows = [];

        foreach ( $total_rows as $key_row => $total_row ) {
            $sorted_total_rows[$key_row] = $total_row;
            if( $key_row === 'payment_method' ) {
                $sorted_total_rows['_uniwallet_phone'] = [
                    'label' => __( "Mobile Money Number", "woocommerce"),
                    'value' => esc_html( $uniwallet_phone ),
                ];
            }
        }
        $total_rows = $sorted_total_rows;
    }
    if ( $order->get_payment_method() === 'uniwallet-payments' && $uniwallet_network = $order->get_meta('_uniwallet_network') ) {
        $sorted_total_rows = [];

        foreach ( $total_rows as $key_row => $total_row ) {
            $sorted_total_rows[$key_row] = $total_row;
            if( $key_row === 'payment_method' ) {
                $sorted_total_rows['_uniwallet_network'] = [
                    'label' => __( "Network", "woocommerce"),
                    'value' => esc_html( $uniwallet_network ),
                ];
            }
        }
        $total_rows = $sorted_total_rows;
	}

    if ( $order->get_payment_method() === 'uniwallet-payments' && $uniwallet_transaction_id = $order->get_meta('_uniwallet_transaction_id') ) {
        $sorted_total_rows = [];

        foreach ( $total_rows as $key_row => $total_row ) {
            $sorted_total_rows[$key_row] = $total_row;
            if( $key_row === 'payment_method' ) {
                $sorted_total_rows['_uniwallet_transaction_id'] = [
                    'label' => __( "Uniwallet Transaction Id", "woocommerce"),
                    'value' => esc_html( $uniwallet_transaction_id ),
                ];
            }
        }
        $total_rows = $sorted_total_rows;
	}

    if ( $order->get_payment_method() === 'uniwallet-payments' && $response = $order->get_meta('_response') ) {
        $sorted_total_rows = [];

        foreach ( $total_rows as $key_row => $total_row ) {
            $sorted_total_rows[$key_row] = $total_row;
            if( $key_row === 'payment_method' ) {
                $sorted_total_rows['_response'] = [
                    'label' => __( "Response", "woocommerce"),
                    'value' => esc_html( $response),
                ];
            }
        }
        $total_rows = $sorted_total_rows;
	}
		

    return $total_rows;
}

// Display custom field in Admin orders, below billing address block
add_action( 'woocommerce_admin_order_data_after_billing_address', 'display_uniwallet_option_near_admin_order_billing_address', 10, 1 );
function display_uniwallet_option_near_admin_order_billing_address( $order ){
    if( $uniwallet_phone = $order->get_meta('_uniwallet_phone') ) {
        echo '<div class="uniwallet-option">
        <p><strong>'.__('Mobile Money Number').':</strong> ' . $uniwallet_phone . '</p>
        </div>';
    }
    if( $uniwallet_network = $order->get_meta('_uniwallet_network') ) {
        echo '<div class="uniwallet-option">
        <p><strong>'.__('Network').':</strong> ' . $uniwallet_network . '</p>
        </div>';
    }
    if( $uniwallet_transaction_id = $order->get_meta('_uniwallet_transaction_id') ) {
        echo '<div class="uniwallet-option">
        <p><strong>'.__('Uniwallet Transaction Id').':</strong> ' . $uniwallet_transaction_id . '</p>
        </div>';
    }
    if( $response = $order->get_meta('_response') ) {
        echo '<div class="uniwallet-option">
        <p><strong>'.__('Response').':</strong> ' . $response . '</p>
        </div>';
    }
}
} // end of if class exist WC_Gateway

}

/*Activation hook*/
add_action( 'plugins_loaded', 'uniwallet_init' );
