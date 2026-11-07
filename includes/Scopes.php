<?php
namespace CEWP;
if ( ! defined( 'ABSPATH' ) ) exit;
/** Add-ons register server-side validators with Scopes::register_validator($type, $fn).
 * The callable receives the decimal scope value and must return true for an existing identity.
 * Enabling a scope via the filter alone never authorizes writes.
 */
class Scopes {
    private static array $validators = [];
    public static function register_validator( string $type, callable $fn ): void {
        if ( in_array( $type, ['user','group'], true ) ) self::$validators[$type] = $fn;
        Pricing::invalidate();
    }
    public static function allowed(): array {
        if ( ! function_exists( 'apply_filters' ) ) return ['role'];
        $types = Pricing::filtered( 'cewp_allowed_scope_types', ['role'], [], static fn( $v ) => is_array( $v ) && count( array_filter( $v, static fn( $t ) => ! is_string( $t ) || ! in_array( $t, ['role','user','group'], true ) ) ) === 0 );
        return array_values( array_unique( $types ) );
    }
    public static function valid( string $type, string $value ): bool {
        if ( ! in_array( $type, self::allowed(), true ) ) return false;
        if ( $type === 'role' ) {
            return $value !== '';
        }
        if ( ! preg_match( '/^[1-9][0-9]*$/D', $value ) ) return false;
        try {
            if ( isset( self::$validators[$type] ) ) return self::$validators[$type]( $value ) === true;
            return $type === 'user' && (bool)get_user_by( 'id', (int)$value );
        } catch ( \Throwable $e ) { return false; }
    }
}
