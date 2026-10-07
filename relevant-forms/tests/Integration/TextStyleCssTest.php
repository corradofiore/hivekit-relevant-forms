<?php
/**
 * TextStyleCss tests: the value sanitizers, the per-group rule builder and
 * the scoped wrapper shared by the Divi, Gutenberg and Beaver Builder text
 * style controls.
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
	 * Each group gets its own rule, in a fixed declaration order, and a group
	 * with no usable value emits nothing.
	 */
	public function testEachGroupGetsItsOwnRule(): void {
		$css = TextStyleCss::css(
			'.rf-x',
			[
				FieldSelectors::LABELS   => [
					'color'  => '#112233',
					'size'   => '18',
					'weight' => '600',
				],
				FieldSelectors::CAPTIONS => [
					'color' => '#777777',
				],
				FieldSelectors::OPTIONS  => [],
			]
		);

		$this->assertSame(
			'.rf-x ' . FieldSelectors::LABELS . '{color:#112233;font-size:18px;font-weight:600;}'
			. '.rf-x ' . FieldSelectors::CAPTIONS . '{color:#777777;}',
			$css
		);

		$this->assertSame( '', TextStyleCss::css( '.rf-x', [] ) );
		$this->assertSame(
			'',
			TextStyleCss::css(
				'.rf-x',
				[
					FieldSelectors::LABELS => [
						'color'  => 'nope',
						'size'   => 'wide',
						'weight' => 'heavy',
					],
				]
			)
		);
	}

	/**
	 * Font sizes go through the shared length sanitizer: unitless numbers
	 * count as pixels, known units pass, anything else is dropped.
	 */
	public function testFontSizesReuseTheLengthSanitizer(): void {
		$this->assertSame(
			'.x ' . FieldSelectors::LABELS . '{font-size:18px;}',
			TextStyleCss::css( '.x', [ FieldSelectors::LABELS => [ 'size' => '18' ] ] )
		);
		$this->assertSame(
			'.x ' . FieldSelectors::LABELS . '{font-size:1.1rem;}',
			TextStyleCss::css( '.x', [ FieldSelectors::LABELS => [ 'size' => '1.1rem' ] ] )
		);
		$this->assertSame( '', TextStyleCss::css( '.x', [ FieldSelectors::LABELS => [ 'size' => 'wide' ] ] ) );
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
	 * through, styled forms get ONE scope class carrying every group's rule.
	 * Identical values — in any key order — always share one class.
	 */
	public function testScopedHtmlWrapsStyledMarkupAndPassesThroughOtherwise(): void {
		$html = '<form class="relevant-forms"></form>';

		$groups = [
			FieldSelectors::LABELS  => [
				'color' => '#112233',
				'size'  => '',
			],
			FieldSelectors::OPTIONS => [
				'weight' => '400',
			],
		];

		$this->assertSame( '', TextStyleCss::scopedHtml( '', $groups, 'rf-x-' ) );
		$this->assertSame( $html, TextStyleCss::scopedHtml( $html, [], 'rf-x-' ) );
		$this->assertSame(
			$html,
			TextStyleCss::scopedHtml( $html, [ FieldSelectors::LABELS => [ 'color' => 'not-a-color' ] ], 'rf-x-' )
		);

		$wrapped = TextStyleCss::scopedHtml( $html, $groups, 'rf-x-' );

		$this->assertSame( 1, substr_count( $wrapped, '<style>' ) );
		$this->assertSame( 1, substr_count( $wrapped, '<div class="rf-x-' ) );
		$this->assertMatchesRegularExpression(
			'/^<style>\.rf-x-[0-9a-f]{12} ' . preg_quote( FieldSelectors::LABELS, '/' ) . '\{color:#112233;\}'
			. '\.rf-x-[0-9a-f]{12} ' . preg_quote( FieldSelectors::OPTIONS, '/' ) . '\{font-weight:400;\}<\/style>'
			. '<div class="rf-x-[0-9a-f]{12}">/',
			$wrapped
		);
		$this->assertStringContainsString( $html, $wrapped );

		// The scope does not depend on the order the groups or their keys
		// were filled in.
		$this->assertSame(
			$wrapped,
			TextStyleCss::scopedHtml(
				$html,
				[
					FieldSelectors::OPTIONS => [
						'weight' => '400',
						'color'  => '',
					],
					FieldSelectors::LABELS  => [
						'size'  => '',
						'color' => '#112233',
					],
				],
				'rf-x-'
			)
		);
	}

	/**
	 * The three groups cover distinct elements: labels (with the display
	 * headings), captions (help text, step indicator, empty state) and option
	 * labels. Errors and authored content stay out of all three.
	 */
	public function testTheThreeGroupsCoverDistinctElements(): void {
		foreach ( [ '.relevant-forms-field > label', '.relevant-forms-section-title', '.relevant-forms-preview-label' ] as $expected ) {
			$this->assertStringContainsString( $expected, FieldSelectors::LABELS, $expected );
			$this->assertStringNotContainsString( $expected, FieldSelectors::CAPTIONS, $expected );
			$this->assertStringNotContainsString( $expected, FieldSelectors::OPTIONS, $expected );
		}

		foreach ( [ '.relevant-forms-help', '.relevant-forms-page-indicator' ] as $expected ) {
			$this->assertStringContainsString( $expected, FieldSelectors::CAPTIONS, $expected );
			$this->assertStringNotContainsString( $expected, FieldSelectors::LABELS, $expected );
		}

		$this->assertStringContainsString( '.relevant-forms-choice label', FieldSelectors::OPTIONS );
		$this->assertStringNotContainsString( 'choice', FieldSelectors::LABELS );
		$this->assertStringNotContainsString( 'choice', FieldSelectors::CAPTIONS );

		foreach ( [ FieldSelectors::LABELS, FieldSelectors::CAPTIONS, FieldSelectors::OPTIONS ] as $selector ) {
			$this->assertStringNotContainsString( '.relevant-forms-error', $selector );
			$this->assertStringNotContainsString( '.relevant-forms-content ', $selector );
			$this->assertStringStartsWith( '.relevant-forms ', $selector );

			// At most one `:is()` compound selector: the top level has no
			// comma, so the scope class and the builder APIs can prepend to
			// it as a single selector.
			$this->assertLessThanOrEqual( 1, substr_count( $selector, ':is(' ) );
		}
	}

	/**
	 * The groups can be created empty and filled from a flat source array.
	 */
	public function testGroupsCanBeBuiltFromAFlatSource(): void {
		$groups = TextStyleCss::emptyGroups();

		$this->assertSame(
			[ FieldSelectors::LABELS, FieldSelectors::CAPTIONS, FieldSelectors::OPTIONS ],
			array_keys( $groups )
		);

		foreach ( $groups as $values ) {
			$this->assertSame(
				[
					'color'  => '',
					'size'   => '',
					'weight' => '',
				],
				$values
			);
		}

		$this->assertSame(
			[
				'color'  => '#112233',
				'size'   => '18px',
				'weight' => '',
			],
			TextStyleCss::group(
				[
					'labelColor' => '#112233',
					'labelSize'  => '18px',
					'labelJunk'  => [ 'nope' ],
				],
				[
					'color'  => 'labelColor',
					'size'   => 'labelSize',
					'weight' => 'labelWeight',
				]
			)
		);
	}
}
