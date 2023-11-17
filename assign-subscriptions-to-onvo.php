<?php
/*
Plugin Name: Assign Subscriptions to ONVO
Description: This plugin allows manually assigning an order of subscription type to ONVO, transforming it into automatic recurring billing.
Version: 1.0
Author: Gianko
Author URI: https://gianko.com/
*/

namespace ONVO;


function assign_to_onvo_and_charge_order(\WC_Order $order, string $customer_id, string $payment_method_id, string $schedule_next_payment)
{
	try {
		// Fetch payment method data
		$payment_gateway              	= \WC_Payment_Gateways::instance()->payment_gateways()['wc-onvo-payment-gateway'];
		$payment_method_gateway_title 	= $payment_gateway->title;
		$payment_method_gateway_id    	= $payment_gateway->id;
		$next_payment_date				=  date('Y-m-d H:i:s',strtotime($schedule_next_payment));
		$subscription = wcs_get_subscription($order->id);
		
		// Set customer ID for the subscription's customer
		\ONVO\set_customer_id_for_wp_user(
			$customer_id,
			$order->get_customer_id(),
			$payment_gateway->testmode
		);

		// Update the order meta
		$order->update_meta_data('_onvo_payment_method_id', $payment_method_id);
		$order->update_meta_data('_onvo_customer_id', $customer_id);
		$order->update_meta_data('_requires_manual_renewal', false);
		// $order->update_meta_data('_schedule_next_payment',$next_payment_date);

		// Update order payment method and title
		$order->set_payment_method($payment_method_gateway_id);
		$order->set_payment_method_title($payment_method_gateway_title);
		update_post_meta($order->id, '_schedule_next_payment', $next_payment_date);
		$order->save();




		// Trigger renewal payment hook for the order
		\WC_Subscriptions_Payment_Gateways::trigger_gateway_renewal_payment_hook($order);

		$order->add_order_note(
			sprintf(
				'Se asigno MANUALMENTE el ONVO customer_id <i>%s</i> y el ONVO payment_method_id <i>%s</i> a la orden.',
				$customer_id,
				$payment_method_id
			)
		);
	} catch (\Throwable $th) {
		//throw $th;
		$order->add_order_note(
			sprintf(
				$th->getMessage()
			)
		);
	}
}

function renew_subscriptions_page()
{
?>
	<div class="wrap">
		<h1>Renovar subscriptions con ONVO</h1>
		<p>Asignar una orden de tipo suscripción a ONVO. No crea una nueva suscripción, solo asocia una existente a ONVO y queda en renovación automática.</p>
		<hr>
		<form method="POST" action="">
			<p>
				<label for="order_id">Enter Suscription Order ID:</label>
				<input type="number" id="order_id" name="order_id" required /><br><br>
			</p>
			<p>
				<label for="customer_id">Enter Customer ID:</label>
				<input type="text" id="customer_id" name="customer_id" required /><br><br>
			</p>

			<p>
				<label for="payment_method_id">Enter Payment Method ID:</label>
				<input type="text" id="payment_method_id" name="payment_method_id" required /><br><br>
			</p>
			<p>
				<label for="payment_method_id">Enter Schedule Next Payment (Opcional):</label>
				<input  type="datetime-local" id="schedule_next_payment" name="schedule_next_payment"  /><br><br>
			</p>
			<p>
				<input type="submit" name="submit-assign-subscriptions-to-onvo" value="Submit" class="button button-primary" />
			</p>

			<input type="hidden" name="action" value="woocommerce_subscriptions_plugin_submit">
			<?php wp_nonce_field('assign-subscriptions-to-onvo' . get_current_user_id()); ?>
		</form>
	</div>
<?php

	if (isset($_POST['submit-assign-subscriptions-to-onvo'])) {
		on_renew_subsccription_page_submission();
	}
}

// Process the form submission
function on_renew_subsccription_page_submission()
{
	if (isset($_POST['submit-assign-subscriptions-to-onvo'])) {
		$order_id          		= absint($_POST['order_id']);
		$customer_id       		= sanitize_text_field($_POST['customer_id']);
		$payment_method_id 		= sanitize_text_field($_POST['payment_method_id']);
		$schedule_next_payment 	= sanitize_text_field($_POST['schedule_next_payment']);
		$post_type = get_post_type($order_id);
		if (!wp_verify_nonce(
			$_POST['_wpnonce'],
			'assign-subscriptions-to-onvo' . get_current_user_id()
		)) {
			echo '<div class="notice notice-error"><p>Invalid nonce. Please try again.</p></div>';
			return;
		}

		// Check if the order exists
		$order = wc_get_order($order_id);
		if (!$order) {
			echo '<div class="notice notice-error"><p>Invalid Order ID. Please try again.</p></div>';
			return;
		}

		if (empty($customer_id)) {
			echo '<div class="notice notice-error"><p>Enter Customer ID. Please try again.</p></div>';
			return;
		}

		if (empty($payment_method_id)) {
			echo '<div class="notice notice-error"><p>Enter Payment Method ID. Please try again.</p></div>';
			return;
		}

		if ($post_type == "shop_subscription") {
			assign_to_onvo_and_charge_order($order, $customer_id, $payment_method_id, $schedule_next_payment);
			echo '<div class="notice notice-success"><p>Data updated successfully. <a target="_blank" href="' . esc_url($order->get_edit_order_url()) . '">Order</a></p></div>';
		} else {
			echo '<div class="notice notice-error"><p>This order is not a suscription Order. <a target="_blank" href="' . esc_url($order->get_edit_order_url()) . '">Order</a></p></div>';
			return;
		}
	}
}

// Hook the functions into WordPress
add_action('admin_menu', __NAMESPACE__ . '\on_add_admin_menu');
add_action('admin_init', __NAMESPACE__ . '\on_admin_init');

function on_add_admin_menu()
{
	add_menu_page(
		'Renovar subscriptions con ONVO',
		'Renovar subscriptions con ONVO',
		'manage_options',
		'woocommerce_subscriptions_plugin',
		__NAMESPACE__ . '\renew_subscriptions_page'
	);
}

function on_admin_init()
{
	add_action(
		'admin_post_nopriv_woocommerce_subscriptions_plugin_submit',
		'on_renew_subsccription_page_submission'
	);
	add_action(
		'admin_post_woocommerce_subscriptions_plugin_submit',
		'on_renew_subsccription_page_submission'
	);
}
