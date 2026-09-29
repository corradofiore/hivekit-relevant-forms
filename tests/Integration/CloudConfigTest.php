<?php
/**
 * Cloud service URL resolution (hardcoded endpoint + staging overrides).
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Cloud\CloudConfig;

/**
 * The service endpoint is code-owned; the settings screen asks only for
 * the site API key. Staging overrides and legacy values keep working.
 *
 * @group integration
 */
final class CloudConfigTest extends TestCase {

	/**
	 * Nothing configured: the built-in service URL applies.
	 */
	public function testUrlDefaultsToTheServiceHostWithTheVersionedApiBase(): void {
		update_option( 'relevant_forms_settings', [] );

		$this->assertSame( 'https://api.relevantcloud.com', CloudConfig::url() );
		$this->assertSame( 'https://api.relevantcloud.com/api/v1', CloudConfig::apiBase() );
	}

	/**
	 * A stored legacy URL (pre-1.0 installs) still wins over the default.
	 */
	public function testLegacyStoredUrlWinsOverTheDefault(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_url' => 'https://staging.example.com/' ] );

		$this->assertSame( 'https://staging.example.com', CloudConfig::url() );
		$this->assertSame( 'https://staging.example.com/api/v1', CloudConfig::apiBase() );
	}

	/**
	 * The filter overrides both the legacy setting and the default.
	 */
	public function testFilterOverrideWinsOverTheLegacySetting(): void {
		update_option( 'relevant_forms_settings', [ 'cloud_url' => 'https://staging.example.com' ] );

		add_filter( 'relevant_forms_cloud_url', static fn (): string => 'https://filter.example.com/' );

		try {
			$this->assertSame( 'https://filter.example.com', CloudConfig::url() );
			$this->assertSame( 'https://filter.example.com/api/v1', CloudConfig::apiBase() );
		} finally {
			remove_all_filters( 'relevant_forms_cloud_url' );
		}
	}

	/**
	 * The site key alone configures the Cloud connection.
	 */
	public function testTheSiteKeyAloneConfiguresTheCloud(): void {
		update_option( 'relevant_forms_settings', [] );
		$this->assertFalse( CloudConfig::isConfigured() );

		update_option( 'relevant_forms_settings', [ 'cloud_api_key' => 'site-key-1' ] );
		$this->assertTrue( CloudConfig::isConfigured() );
	}

	/**
	 * The mask preview shows only the key ends; short values reveal
	 * nothing at all.
	 */
	public function testMaskPreviewShowsOnlyTheKeyEnds(): void {
		$this->assertSame( 'sfk_••••••••cdef', CloudConfig::mask( 'sfk_1234567890abcdef' ) );
		$this->assertSame( '••••••••', CloudConfig::mask( 'short-key' ) );
	}
}
