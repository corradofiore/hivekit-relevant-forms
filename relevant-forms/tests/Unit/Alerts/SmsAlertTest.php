<?php
/**
 * SmsAlert consent gating and payload unit tests (Phase 5, v2 §7-8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Ai\Classification;
use RelevantForms\Alerts\SmsAlert;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\Form;
use RelevantForms\Forms\FormSchema;

/**
 * Option store shared by CloudConfig/CloudConnection in tests.
 *
 * @var array<string, mixed>
 */
$GLOBALS['rf_sms_store'] = [];

beforeEach(
	function () {
		$GLOBALS['rf_sms_store'] = [
			'relevant_forms_settings' => [
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
				'sms_recipient' => '+3912345',
			],
		];

		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				return $GLOBALS['rf_sms_store'][ $key ] ?? $fallback;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ): bool {
				$GLOBALS['rf_sms_store'][ $key ] = $value;

				return true;
			}
		);
		Monkey\Functions\when( 'current_time' )->justReturn( '2026-09-15 12:00:00' );
		Monkey\Functions\when( 'do_action' )->justReturn( null );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'admin_url' )->alias(
			static fn ( string $path ): string => 'https://site.example/wp-admin/' . $path
		);
	}
);

/**
 * Build a form entity.
 */
function rf_sms_form(): Form {
	$schema = FormSchema::fromArray(
		[
			'name'      => 'Emergency Request',
			'ai_policy' => [ 'enabled' => true ],
			'fields'    => [],
		]
	);

	return new Form( 7, 'Emergency Request', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
}

/**
 * Validated classification with the given scores.
 *
 * @param float $spam      Spam probability.
 * @param float $relevance Relevance score.
 * @param float $urgency   Urgency score.
 * @throws RuntimeException When the fixture payload is invalid.
 */
function rf_sms_classification( float $spam, float $relevance, float $urgency ): Classification {
	$classification = Classification::fromArray(
		[
			'spam_probability' => $spam,
			'relevance'        => $relevance,
			'urgency'          => $urgency,
			'summary'          => 'Burst pipe in the basement.',
			'reason_code'      => 'relevant_enquiry',
		]
	);

	if ( $classification === null ) {
		throw new RuntimeException( 'Fixture classification is invalid.' );
	}

	return $classification;
}

/**
 * Enable the priority SMS capability on a connected site.
 */
function rf_sms_enable(): void {
	$connection = new CloudConnection();
	$connection->connect( 'site-1', 'https://a.example', 'Acme' );
	$connection->enableCapability( CloudConnection::CAP_SMS );
}

it(
	'sends nothing until the capability is explicitly enabled',
	function () {
		Monkey\Functions\expect( 'wp_remote_post' )->never();

		$alert = new SmsAlert();

		expect( $alert->isEnabled() )->toBeFalse();
		expect( $alert->shouldSend( rf_sms_classification( 0.02, 0.9, 0.9 ) ) )->toBeFalse();
	}
);

it(
	'requires a recipient and configured credentials',
	function () {
		rf_sms_enable();
		$GLOBALS['rf_sms_store']['relevant_forms_settings']['sms_recipient'] = '';

		expect( ( new SmsAlert() )->isEnabled() )->toBeFalse();

		$GLOBALS['rf_sms_store']['relevant_forms_settings']['sms_recipient'] = '+3912345';
		$GLOBALS['rf_sms_store']['relevant_forms_settings']                  = [];

		expect( ( new SmsAlert() )->isEnabled() )->toBeFalse();
	}
);

it(
	'only interrupts for classifications above the attention threshold',
	function () {
		rf_sms_enable();

		$alert = new SmsAlert();

		expect( $alert->shouldSend( rf_sms_classification( 0.02, 0.5, 0.3 ) ) )->toBeFalse();
		expect( $alert->shouldSend( rf_sms_classification( 0.02, 0.9, 0.9 ) ) )->toBeTrue();
	}
);

it(
	'posts the minimal alert payload with the secure entry link',
	function () {
		rf_sms_enable();

		$captured = null;

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 202 ],
					'body'     => 'stub',
				];
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 202 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) wp_json_encode(
				[
					'data' => [
						'delivery_id' => 41,
						'status'      => 'queued',
					],
				]
			)
		);

		$delivery = ( new SmsAlert() )->send( rf_sms_form(), 12, rf_sms_classification( 0.02, 0.9, 0.9 ) );

		expect( $delivery )->not->toBeNull();
		expect( $delivery['delivery_id'] )->toBe( 41 );
		expect( $captured['url'] )->toBe( 'https://cloud.example.com/api/v1/alerts/sms' );

		$payload = json_decode( (string) $captured['args']['body'], true );

		expect( $payload['recipient'] )->toBe( '+3912345' );
		expect( $payload['alert']['summary'] )->toBe( 'Burst pipe in the basement.' );
		expect( $payload['alert']['entry_url'] )->toBe( 'https://site.example/wp-admin/admin.php?page=relevant-entry&entry_id=12' );
		expect( $payload['alert']['attention_score'] )->toBeGreaterThanOrEqual( 85 );
	}
);

it(
	'returns null when the Cloud rejects the alert',
	function () {
		rf_sms_enable();

		Monkey\Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 502 ] ] );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 502 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );

		expect( ( new SmsAlert() )->send( rf_sms_form(), 12, rf_sms_classification( 0.02, 0.9, 0.9 ) ) )->toBeNull();
	}
);
