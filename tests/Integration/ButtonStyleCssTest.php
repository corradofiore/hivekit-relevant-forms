<?php
/**
 * ButtonStyleCss tests: the sanitizers, the rule builder and the scoped
 * wrapper shared by the Divi, Gutenberg and Beaver Builder button controls.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Tests\Integration;

use RelevantForms\Integrations\Support\ButtonStyleCss;

/**
 * {@inheritdoc}
 *
 * @group integration
 */
final class ButtonStyleCssTest extends TestCase {

	/**
	 * The rule builder emits one declaration per usable value in a fixed
	 * order, skips empty ones, and returns nothing when no value is set —
	 * unstyled buttons keep the theme's styling.
	 */
	public function testTheRuleBuilderEmitsOnlyTheValuesThatAreSet(): void {
		$css = ButtonStyleCss::css(
			'.rf-x .relevant-forms .wp-element-button',
			[
				'background' => '#112233',
				'text'       => '',
				'radius'     => '8px',
				'padding'    => '12px 24px',
			]
		);

		$this->assertSame(
			'.rf-x .relevant-forms .wp-element-button{background-color:#112233;border-radius:8px;padding:12px 24px;}',
			$css
		);

		$this->assertSame(
			'',
			ButtonStyleCss::css(
				'.x',
				[
					'background' => '',
					'text'       => '',
				]
			)
		);
		$this->assertSame( '', ButtonStyleCss::css( '.x', [] ) );
	}

	/**
	 * Colors: hex shades, the rgb/hsl families, transparent and
	 * currentColor pass; anything that could break out of the declaration
	 * is dropped.
	 */
	public function testColorsAreValidated(): void {
		foreach ( [ '#abc', '#AABBCC', '#aabbccdd', 'rgb(1, 2, 3)', 'rgba(1,2,3,0.5)', 'hsl(1, 2%, 3%)', 'transparent', 'currentColor' ] as $valid ) {
			$this->assertNotSame( '', ButtonStyleCss::color( $valid ), $valid );
		}

		foreach ( [ 'red', 'red;} .evil{color:blue', 'url(javascript:x)', '#12345', '' ] as $invalid ) {
			$this->assertSame( '', ButtonStyleCss::color( $invalid ), $invalid );
		}
	}

	/**
	 * Lengths: numbers (unitless counts as pixels), the allowed units and a
	 * bare zero pass; malformed values are dropped.
	 */
	public function testLengthsRequireANumberAndOptionalUnit(): void {
		$this->assertSame( '8px', ButtonStyleCss::length( '8' ) );
		$this->assertSame( '8px', ButtonStyleCss::length( ' 8PX ' ) );
		$this->assertSame( '2.5rem', ButtonStyleCss::length( '2.5rem' ) );
		$this->assertSame( '50%', ButtonStyleCss::length( '50%' ) );
		$this->assertSame( '0', ButtonStyleCss::length( '0' ) );
		$this->assertSame( '', ButtonStyleCss::length( '' ) );

		foreach ( [ '8foo', 'calc(100% - 2px)', 'url(x)', 'wide', '8px;' ] as $invalid ) {
			$this->assertSame( '', ButtonStyleCss::length( $invalid ), $invalid );
		}
	}

	/**
	 * Padding accepts one to four length values; more values or any invalid
	 * token drop the whole shorthand (CSS would discard a broken value
	 * anyway, but the output stays predictable).
	 */
	public function testPaddingAcceptsOneToFourLengthValues(): void {
		$this->assertSame( '12px', ButtonStyleCss::padding( '12' ) );
		$this->assertSame( '12px 24px', ButtonStyleCss::padding( '12px 24px' ) );
		$this->assertSame( '0 1em 0 1em', ButtonStyleCss::padding( '0 1em 0 1em' ) );
		$this->assertSame( '', ButtonStyleCss::padding( '1px 2px 3px 4px 5px' ) );
		$this->assertSame( '', ButtonStyleCss::padding( '10px nope' ) );
		$this->assertSame( '', ButtonStyleCss::padding( '' ) );
	}

	/**
	 * The scoped wrapper: empty markup passes through, unstyled forms pass
	 * through, styled forms get a deterministic scope class with the rule.
	 * Identical values — in any key order — always share one class.
	 */
	public function testScopedHtmlWrapsStyledMarkupAndPassesThroughOtherwise(): void {
		$html = '<form class="relevant-forms"></form>';

		$this->assertSame( '', ButtonStyleCss::scopedHtml( '', [ 'background' => '#000000' ], 'rf-x-' ) );
		$this->assertSame( $html, ButtonStyleCss::scopedHtml( $html, [], 'rf-x-' ) );

		$wrapped = ButtonStyleCss::scopedHtml( $html, [ 'background' => '#112233' ], 'rf-x-' );

		$this->assertMatchesRegularExpression(
			'/^<style>\.rf-x-[0-9a-f]{12} \.relevant-forms \.wp-element-button\{background-color:#112233;\}<\/style><div class="rf-x-[0-9a-f]{12}">/',
			$wrapped
		);
		$this->assertStringContainsString( $html, $wrapped );

		$this->assertSame( $wrapped, ButtonStyleCss::scopedHtml( $html, [ 'background' => '#112233' ], 'rf-x-' ) );

		$canonical = ButtonStyleCss::scopedHtml(
			$html,
			[
				'background' => '#112233',
				'text'       => '#ffffff',
				'radius'     => '8px',
				'padding'    => '',
			],
			'rf-x-'
		);
		$reordered = ButtonStyleCss::scopedHtml(
			$html,
			[
				'padding'    => '',
				'radius'     => '8px',
				'text'       => '#ffffff',
				'background' => '#112233',
			],
			'rf-x-'
		);

		$this->assertSame( $canonical, $reordered );

		$other = ButtonStyleCss::scopedHtml( $html, [ 'background' => '#223344' ], 'rf-x-' );

		$this->assertNotSame( $wrapped, $other );
	}
}
