<?php
/**
 * AiClassifier consent gating and payload unit tests (v2 §4, §7-8).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

use Brain\Monkey;
use RelevantForms\Ai\AiClassifier;
use RelevantForms\Cloud\CloudConnection;
use RelevantForms\Forms\FormSchema;
use RelevantForms\Forms\Form;

/**
 * Option store shared by CloudConfig/CloudConnection in tests.
 *
 * @var array<string, mixed>
 */
$GLOBALS['rf_ai_store'] = [];

beforeEach(
	function () {
		$GLOBALS['rf_ai_store'] = [
			'relevant_forms_settings' => [
				'cloud_url'     => 'https://cloud.example.com',
				'cloud_api_key' => 'site-key',
			],
		];

		Monkey\Functions\when( 'get_option' )->alias(
			static function ( string $key, mixed $fallback = [] ): mixed {
				return $GLOBALS['rf_ai_store'][ $key ] ?? $fallback;
			}
		);
		Monkey\Functions\when( 'update_option' )->alias(
			static function ( string $key, mixed $value ): bool {
				$GLOBALS['rf_ai_store'][ $key ] = $value;

				return true;
			}
		);
		Monkey\Functions\when( 'current_time' )->justReturn( '2026-09-15 12:00:00' );
		Monkey\Functions\when( 'do_action' )->justReturn( null );
		Monkey\Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Monkey\Functions\when( 'apply_filters' )->returnArg( 2 );
		Monkey\Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}
);

/**
 * Build a form entity with an explicit AI policy.
 *
 * @param array<string, mixed> $aiPolicy AI policy.
 */
function rf_ai_form( array $aiPolicy ): Form {
	$schema = FormSchema::fromArray(
		[
			'name'      => 'Contact',
			'ai_policy' => $aiPolicy,
			'fields'    => [
				[
					'id'    => 'message',
					'type'  => 'textarea',
					'label' => 'Message',
				],
			],
		]
	);

	return new Form( 7, 'Contact', 'published', $schema, '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
}

/**
 * Local protection payload as produced by SpamProtection::evaluate().
 *
 * @return array<string, mixed>
 */
function rf_ai_protection(): array {
	return [
		'status'  => 'new',
		'score'   => 0.1,
		'signals' => [
			'spam_score'         => 0.1,
			'links_detected'     => 0,
			'duplicate_count'    => 0,
			'submission_time_ms' => 9000,
			'recent_submissions' => 1,
			'rate_limit'         => 5,
		],
		'hash'    => 'abc123',
	];
}

it(
	'sends nothing until the capability is explicitly enabled',
	function () {
		Monkey\Functions\expect( 'wp_remote_post' )->never();

		$classifier = new AiClassifier();

		expect( $classifier->isEnabled( rf_ai_form( [] ) ) )->toBeFalse();
		expect( $classifier->classify( rf_ai_form( [] ), [ 'message' => 'hello' ], rf_ai_protection() ) )->toBeNull();
	}
);

it(
	'requires the API credentials to be configured',
	function () {
		$GLOBALS['rf_ai_store']['relevant_forms_settings'] = [];

		$connection = new CloudConnection();
		$connection->connect( 'site-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		Monkey\Functions\expect( 'wp_remote_post' )->never();

		expect( ( new AiClassifier() )->isEnabled( rf_ai_form( [] ) ) )->toBeFalse();
	}
);

it(
	'honours the per-form opt-out flag',
	function () {
		$connection = new CloudConnection();
		$connection->connect( 'site-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		Monkey\Functions\expect( 'wp_remote_post' )->never();

		$classifier = new AiClassifier();

		expect( $classifier->isEnabled( rf_ai_form( [ 'enabled' => false ] ) ) )->toBeFalse();
		expect( $classifier->isEnabled( rf_ai_form( [] ) ) )->toBeTrue();
	}
);

it(
	'posts the redacted context and validates the response',
	function () {
		$connection = new CloudConnection();
		$connection->connect( 'site-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		$captured = null;

		Monkey\Functions\when( 'wp_remote_post' )->alias(
			static function ( string $url, array $args ) use ( &$captured ): array {
				$captured = [
					'url'  => $url,
					'args' => $args,
				];

				return [
					'response' => [ 'code' => 200 ],
					'body'     => 'stub',
				];
			}
		);
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn(
			(string) wp_json_encode(
				[
					'data' => [
						'classification' => [
							'spam_probability' => 0.02,
							'relevance'        => 0.9,
							'urgency'          => 0.8,
							'reason_code'      => 'relevant_enquiry',
						],
					],
				]
			)
		);

		$classification = ( new AiClassifier() )->classify(
			rf_ai_form( [] ),
			[ 'message' => 'Reach me at jane@example.com' ],
			rf_ai_protection()
		);

		expect( $classification )->not->toBeNull();
		expect( $classification->reasonCode )->toBe( 'relevant_enquiry' );
		expect( $captured['url'] )->toBe( 'https://cloud.example.com/api/v1/classifications' );

		$body = (string) $captured['args']['body'];
		expect( $body )->toContain( '[EMAIL]' );
		expect( $body )->not->toContain( 'jane@example.com' );
	}
);

it(
	'returns null when the Cloud rejects the request',
	function () {
		$connection = new CloudConnection();
		$connection->connect( 'site-1', 'https://a.example', 'Acme' );
		$connection->enableCapability( CloudConnection::CAP_AI );

		Monkey\Functions\when( 'wp_remote_post' )->justReturn( [ 'response' => [ 'code' => 502 ] ] );
		Monkey\Functions\when( 'is_wp_error' )->justReturn( false );
		Monkey\Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 502 );
		Monkey\Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"success":false}' );

		expect(
			( new AiClassifier() )->classify( rf_ai_form( [] ), [ 'message' => 'hi' ], rf_ai_protection() )
		)->toBeNull();
	}
);
