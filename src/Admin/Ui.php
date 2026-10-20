<?php
namespace MOBookingStudio\Admin;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Utilidades de interfaz del panel. Todo texto que viene de datos se escapa al mostrarse.
 */
final class Ui {
	public static function url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/** Abre un formulario que envía a admin-post.php con nonce propio de la acción. */
	public static function formOpen( string $do, array $hidden = array(), string $class = '', bool $multipart = false ): string {
		$html = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mo-form ' . esc_attr( $class ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '>';
		$html .= '<input type="hidden" name="action" value="mo_booking">';
		$html .= '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		$html .= wp_nonce_field( 'mo_booking_' . $do, '_mo_nonce', true, false );
		$html .= '<input type="hidden" name="form_key" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		foreach ( $hidden as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		return $html;
	}

	public static function button( string $label, string $class = 'button-primary', string $confirm = '' ): string {
		return '<button type="submit" class="button ' . esc_attr( $class ) . '"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
	}

	public static function flash( string $type, string $message ): void {
		$key   = 'mo_booking_flash_' . get_current_user_id();
		$list  = get_transient( $key ) ?: array();
		$list[] = array( $type, $message );
		set_transient( $key, $list, 120 );
	}

	public static function renderFlash(): void {
		$key  = 'mo_booking_flash_' . get_current_user_id();
		$list = get_transient( $key ) ?: array();
		delete_transient( $key );
		foreach ( $list as $item ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $item[0] ? 'error' : ( 'warning' === $item[0] ? 'warning' : 'success' ) ), esc_html( $item[1] ) );
		}
	}

	/** Valores del último envío fallido, para no perder lo escrito. */
	public static function rememberInput( array $input ): void {
		set_transient( 'mo_booking_old_' . get_current_user_id(), $input, 120 );
	}

	public static function oldInput(): array {
		$key = 'mo_booking_old_' . get_current_user_id();
		$old = get_transient( $key );
		delete_transient( $key );
		return is_array( $old ) ? $old : array();
	}

	public static function money( int $cents ): string {
		return esc_html( Money::format( $cents ) );
	}

	public static function pesos( $cents ): string {
		return esc_attr( Money::toInput( (int) $cents ) );
	}

	public static function statusPill( string $status ): string {
		return '<span class="mo-pill mo-pill--' . esc_attr( $status ) . '">' . esc_html( BookingStatus::label( $status ) ) . '</span>';
	}

	public static function financialPill( string $status ): string {
		return '<span class="mo-pill mo-pill--fin-' . esc_attr( $status ) . '">' . esc_html( Ledger::LABELS[ $status ] ?? $status ) . '</span>';
	}

	public static function localDateTime( ?string $utc, string $format = 'Y-m-d H:i' ): string {
		if ( ! $utc ) {
			return '';
		}
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( Settings::timezone() )->format( $format );
	}

	public static function localToday(): string {
		return \MOBookingStudio\Plugin::clock()->now()->setTimezone( Settings::timezone() )->format( 'Y-m-d' );
	}

	public static function age( string $utc ): string {
		$seconds = \MOBookingStudio\Plugin::clock()->now()->getTimestamp() - ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		if ( $seconds < 3600 ) {
			return sprintf( 'hace %d min', max( 1, (int) floor( $seconds / 60 ) ) );
		}
		if ( $seconds < 86400 ) {
			return sprintf( 'hace %d h', (int) floor( $seconds / 3600 ) );
		}
		return sprintf( 'hace %d días', (int) floor( $seconds / 86400 ) );
	}

	public static function input( string $name, $value, string $type = 'text', array $attrs = array() ): string {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" id="' . esc_attr( self::id( $name ) ) . '" value="' . esc_attr( (string) $value ) . '"' . $extra . '>';
	}

	public static function textarea( string $name, $value, int $rows = 4, array $attrs = array() ): string {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		return '<textarea name="' . esc_attr( $name ) . '" id="' . esc_attr( self::id( $name ) ) . '" rows="' . $rows . '" class="large-text"' . $extra . '>' . esc_textarea( (string) $value ) . '</textarea>';
	}

	public static function select( string $name, array $options, $selected, array $attrs = array() ): string {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		$html = '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( self::id( $name ) ) . '"' . $extra . '>';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $selected, (string) $value, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return $html . '</select>';
	}

	public static function checkbox( string $name, bool $checked, string $label ): string {
		return '<label class="mo-check"><input type="checkbox" name="' . esc_attr( $name ) . '" value="1"' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/** Fila de formulario con etiqueta y ayuda. */
	public static function row( string $label, string $control, string $help = '', string $for = '' ): string {
		return '<div class="mo-row"><label class="mo-row__label"' . ( $for ? ' for="' . esc_attr( self::id( $for ) ) . '"' : '' ) . '>' . esc_html( $label ) . '</label><div class="mo-row__control">' . $control . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</div></div>';
	}

	public static function id( string $name ): string {
		return 'mo-' . trim( preg_replace( '/[^a-z0-9]+/i', '-', $name ), '-' );
	}

	public static function header( string $title, string $actions = '' ): void {
		echo '<div class="wrap mo-admin"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput
		self::renderFlash();
	}

	public static function footer(): void {
		echo '</div>';
	}

	public static function card( string $title, string $body, string $class = '' ): string {
		return '<section class="mo-card ' . esc_attr( $class ) . '"><h2 class="mo-card__title">' . esc_html( $title ) . '</h2>' . $body . '</section>';
	}

	public static function localInputToSql( string $value ): string {
		// datetime-local: 2026-11-15T13:00 -> 2026-11-15 13:00
		$value = trim( str_replace( 'T', ' ', $value ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ? $value : '';
	}

	public static function sqlToLocalInput( string $value ): string {
		return $value ? str_replace( ' ', 'T', substr( $value, 0, 16 ) ) : '';
	}

	public static function weekdays(): array {
		return array( 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo' );
	}
}
