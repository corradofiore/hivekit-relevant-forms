<?php
/**
 * Accept payments admin page (Stripe Connect).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Admin\Pages;

use RelevantForms\Cloud\CloudConfig;
use RelevantForms\Cloud\CloudConnection;

/**
 * Stripe Connect: which account receives the money, the platform fee and
 * the connect/refresh/disconnect actions. Formerly the "Payments
 * configuration (Stripe)" tab of the settings screen.
 */
final class PaymentsPage {

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'relevant-forms' ), '', [ 'response' => 403 ] );
		}

		$connection        = new CloudConnection();
		$paymentsStatus    = $connection->payments();
		$paymentsConnected = ! empty( $paymentsStatus['connected'] );
		$paymentsClientOk  = CloudConfig::isConfigured() && CloudConnection::STATE_DISCONNECTED !== $connection->state();
		$feePercent        = (float) ( $paymentsStatus['fee_percent'] ?? 2.0 );
		$feeLabel          = rtrim( rtrim( number_format( $feePercent, 2, '.', '' ), '0' ), '.' );

		require RELEVANT_FORMS_PATH . 'templates/admin/payments.php';
	}
}
