<?php
/**
 * Field type registry.
 *
 * @package RelevantForms
 */

declare(strict_types=1);

namespace RelevantForms\Forms\Fields;

/**
 * Registry of available field types, bootstrapped lazily.
 */
final class FieldTypeRegistry {

	/**
	 * Registered types keyed by machine name.
	 *
	 * @var array<string, FieldType>|null
	 */
	private static ?array $types = null;

	/**
	 * Register a type (used by extensions).
	 */
	public static function register( FieldType $type ): void {
		self::all();
		self::$types[ $type->type() ] = $type;
	}

	/**
	 * Whether a type is known.
	 */
	public static function has( string $type ): bool {
		return isset( self::all()[ $type ] );
	}

	/**
	 * Get a type or null.
	 */
	public static function get( string $type ): ?FieldType {
		return self::all()[ $type ] ?? null;
	}

	/**
	 * All registered types keyed by machine name.
	 *
	 * @return array<string, FieldType>
	 */
	public static function all(): array {
		if ( null !== self::$types ) {
			return self::$types;
		}

		self::$types = [];
		foreach ( self::builtIn() as $type ) {
			self::$types[ $type->type() ] = $type;
		}

		return self::$types;
	}

	/**
	 * Built-in field types.
	 *
	 * @return array<int, FieldType>
	 */
	private static function builtIn(): array {
		return [
			new TextField(),
			new TextareaField(),
			new EmailField(),
			new TelField(),
			new NumberField(),
			new UrlField(),
			new SelectField(),
			new RadioField(),
			new CheckboxField(),
			new ConsentField(),
			new SectionField(),
			new PagebreakField(),
			new HiddenField(),
			new DateField(),
			new FileField(),
			new PaymentSingleField(),
			new PaymentSelectField(),
			new PaymentMultipleField(),
			new PaymentCheckboxField(),
			new PaymentTotalField(),
		];
	}
}
