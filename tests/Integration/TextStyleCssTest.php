<?php
/**
 * TextStyleCss tests: the value sanitizers, the rule builder and the scoped
 * wrapper shared by the Divi, Gutenberg and Beaver Builder "Text styles"
 * controls.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Integrations\Support\FieldSelectors;
use RelevantForms\Integrations\Support\TextStyleCss;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class TextStyleCssTest extends TestCase {

	/**
	 * The rule builder emits one declaration per usable value in a fixed
	 * order and returns nothing when no value is set — unstyled text keeps
	 * the theme's typography.
	 */
	public function testTheRuleBuilderEmitsOnlyTheValuesThatAreSet(): void {
		$css = TextStyleCss::css(
			'.rf-x ' . FieldSelectors::TEXT,
			[
				'color'  => '#112233',
				'size'   => '',
				'weight' => '600',
			]
		);

		$this->assertSame(
			'.rf-x ' . FieldSelectors::TEXT . '{color:#112233;font-weight:600;}',
			$css
		);

		$this->assertSame( '', TextStyleCss::css( '.x', [ 'color' => '' ] ) );
		$this->assertSame( '', TextStyleCss::css( '.x', [] ) );
	}

	/**
	 * Font sizes go through the shared length sanitizer: unitless numbers
	 * count as pixels, known units pass, anything else is dropped.
	 */
	public function testFontSizesReuseTheLengthSanitizer(): void {
		$this->assertSame(
			'.x{font-size:18px;}',
			TextStyleCss::css( '.x', [ 'size' => '18' ] )
		);
		$this->assertSame(
			'.x{font-size:1.1rem;}',
			TextStyleCss::css( '.x', [ 'size' => '1.1rem' ] )
		);
		$this->assertSame( '', TextStyleCss::css( '.x', [ 'size' => 'wide' ] ) );
	}

	/**
	 * Weights: the numeric scale and the two keywords themes use pass,
	 * everything else is dropped.
	 */
	public function testWeightsAreValidated(): void {
		foreach ( [ '100', '400', '700', '900', 'normal', 'BOLD' ] as $valid ) {
			$this->assertNotSame( '', TextStyleCss::weight( $valid ), $valid );
		}

		$this->assertSame( 'bold', TextStyleCss::weight( ' BOLD ' ) );

		foreach ( [ '50', '950', '999', 'heavy', '600px', '600;} .evil{color:red', '' ] as $invalid ) {
			$this->assertSame( '', TextStyleCss::weight( $invalid ), $invalid );
		}
	}

	/**
	 * The scoped wrapper: empty markup passes through, unstyled forms pass
	 * through, styled forms get a deterministic scope class with one rule
	 * for the text outside the controls. Identical values — in any key
	 * order — always share one class.
	 */
	public function testScopedHtmlWrapsStyledMarkupAndPassesThroughOtherwise(): void {
		$html = '<form class="relevant-forms"></form>';

		$this->assertSame( '', TextStyleCss::scopedHtml( '', [ 'color' => '#000000' ], 'rf-x-' ) );
		$this->assertSame( $html, TextStyleCss::scopedHtml( $html, [], 'rf-x-' ) );

		$wrapped = TextStyleCss::scopedHtml(
			$html,
			[
				'color'  => '#112233',
				'weight' => '600',
			],
			'rf-x-'
		);

		$this->assertMatchesRegularExpression(
			'/^<style>\.rf-x-[0-9a-f]{12} ' . preg_quote( FieldSelectors::TEXT, '/' ) . '\{color:#112233;font-weight:600;\}<\/style><div class="rf-x-[0-9a-f]{12}">/',
			$wrapped
		);
		$this->assertStringContainsString( $html, $wrapped );

		$this->assertSame(
			TextStyleCss::scopedHtml(
				$html,
				[
					'color' => '#112233',
					'size'  => '18px',
				],
				'rf-x-'
			),
			TextStyleCss::scopedHtml(
				$html,
				[
					'size'  => '18px',
					'color' => '#112233',
				],
				'rf-x-'
			)
		);
	}

	/**
	 * The shared selector covers the text outside the controls — field
	 * labels, option labels, help text and the display headings — and
	 * deliberately leaves error messages and authored content alone.
	 */
	public function testTheTextSelectorCoversTheNonControlText(): void {
		foreach (
			[
				'.relevant-forms-field > label',
				'.relevant-forms-choice label',
				'.relevant-forms-help',
				'.relevant-forms-section-title',
				'.relevant-forms-content-title',
				'.relevant-forms-page-title',
				'.relevant-forms-page-indicator',
				'.relevant-forms-preview-heading',
				'.relevant-forms-preview-label',
			] as $expected
		) {
			$this->assertStringContainsString( $expected, FieldSelectors::TEXT, $expected );
		}

		$this->assertStringNotContainsString( '.relevant-forms-error', FieldSelectors::TEXT );
		$this->assertStringNotContainsString( 'input', FieldSelectors::TEXT );

		// One `:is()` compound selector: the list sits inside the functional
		// pseudo-class, so the top level has no comma and the whole thing is
		// a single selector string — what the builder APIs take per control.
		$this->assertStringStartsWith( '.relevant-forms :is(', FieldSelectors::TEXT );
		$this->assertStringEndsWith( ')', FieldSelectors::TEXT );
		$this->assertSame( 1, substr_count( FieldSelectors::TEXT, ':is(' ) );
	}
}
