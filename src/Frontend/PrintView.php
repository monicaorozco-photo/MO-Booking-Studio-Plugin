<?php
namespace MOBookingStudio\Frontend;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Contracts;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Infrastructure\Tokens;

/**
 * "Descargar mi contrato y datos de la sesión" (solo para la persona que reservó).
 *
 * Página lista para imprimir o "Guardar como PDF" desde el navegador. Se abre desde el
 * enlace privado y usa su misma sesión (cookie): sin enlace privado no se ve nada. Muestra
 * el contrato exacto que aceptó (versión, fecha y hash) y los datos de su sesión; nunca
 * notas internas, historial ni datos de otras personas.
 */
final class PrintView {
	public const QUERY = 'mo_print';

	public static function url(): string {
		return add_query_arg( self::QUERY, '1', home_url( '/' ) );
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybeRender' ), 0 );
	}

	public function maybeRender(): void {
		if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Frame-Options: DENY' );
		header( 'Content-Type: text/html; charset=utf-8' );
		try {
			$booking = Bookings::get( Tokens::sessionBooking( Portal::cookie(), null, false ) );
			status_header( 200 );
			echo self::document( $booking ); // phpcs:ignore WordPress.Security.EscapeOutput -- Cada dato se escapa en document().
		} catch ( DomainException $e ) {
			status_header( 403 );
			echo self::page( 'Mi reserva', '<p>Para descargar tu contrato abre primero el enlace privado que te enviamos por correo y usa el botón "Descargar mi contrato y datos de la sesión".</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}

	public static function document( array $b ): string {
		$service  = Services::find( $b['service_id'] );
		$local    = $b['start']->setTimezone( Settings::timezone() );
		$summary  = Bookings::summary( $b );
		$business = (string) Settings::get( 'business_name' );
		$brand    = $service && $service['brand'] ? $service['brand'] : $business;
		$contact  = (array) $b['contact'];

		$rows = array(
			'Número de reserva' => $b['public_ref'],
			'Estado'            => BookingStatus::label( $b['status'] ),
			'Servicio'          => $service ? $service['title'] : (string) ( $b['quote']['service_title'] ?? '' ),
			'Fecha'             => Catalog::dateText( $local ),
			'Hora'              => $local->format( 'H:i' ) . ' h (' . $b['timezone'] . ')',
		);
		$confirmed = in_array( $b['status'], array( BookingStatus::CONFIRMED, BookingStatus::COMPLETED ), true );
		if ( $service && $service['location'] ) {
			// beta.4: la ubicación se comparte cuando el anticipo está confirmado.
			$rows['Lugar'] = $confirmed ? $service['location'] : 'Se comparte al confirmar tu anticipo';
		}
		if ( null !== $b['children'] || null !== $b['adults'] ) {
			$rows['Asistentes'] = (int) $b['participants'] . ' (niños ' . (int) $b['children'] . ', adultos ' . (int) $b['adults'] . ')';
		} else {
			$rows['Personas'] = (string) (int) $b['participants'];
		}
		if ( (int) $b['pets'] ) {
			$rows['Mascotas'] = (string) (int) $b['pets'];
		}
		$rows['Nombre']   = (string) ( $contact['name'] ?? '' );
		$rows['Correo']   = (string) ( $contact['email'] ?? '' );
		$rows['Teléfono'] = (string) ( $contact['phone'] ?? '' );

		$html  = '<header><p class="brand">' . esc_html( $brand ) . '</p><h1>Contrato y datos de tu sesión</h1>';
		$html .= '<p class="actions no-print"><button type="button" onclick="window.print()">Imprimir o guardar como PDF</button> <span>En el cuadro de impresión elige "Guardar como PDF".</span></p></header>';
		$html .= '<section><h2>Tu sesión</h2><table>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</table>';
		if ( $confirmed && $service && '' !== (string) $service['instructions'] ) {
			$html .= '<h3>Indicaciones</h3><p>' . nl2br( esc_html( (string) $service['instructions'] ) ) . '</p>';
		}
		if ( '' !== $b['important_info'] ) {
			$html .= '<h3>Información importante que nos compartiste</h3><p>' . nl2br( esc_html( $b['important_info'] ) ) . '</p>';
		}
		foreach ( (array) $b['answers'] as $a ) {
			if ( isset( $a['label'], $a['value'] ) && '' !== (string) $a['value'] ) {
				$html .= '<p><strong>' . esc_html( $a['label'] ) . ':</strong> ' . nl2br( esc_html( (string) $a['value'] ) ) . '</p>';
			}
		}
		$html .= '</section>';

		$view  = Portal::view( $b );
		$html .= '<section><h2>Precio y pagos</h2><table>';
		foreach ( $view['price_rows'] as $l ) {
			$html .= '<tr class="' . esc_attr( $l['kind'] ) . '"><th scope="row">' . esc_html( $l['label'] ) . '</th><td>' . esc_html( $l['amount_txt'] ) . '</td></tr>';
		}
		$html .= '<tr class="total"><th scope="row">Total</th><td>' . esc_html( Money::format( $b['total_cents'] ) ) . '</td></tr>';
		$html .= '<tr><th scope="row">Anticipo requerido</th><td>' . esc_html( Money::format( $b['deposit_cents'] ) ) . '</td></tr>';
		$html .= '<tr><th scope="row">Pagado</th><td>' . esc_html( Money::format( max( 0, $summary['applied_cents'] ) ) ) . '</td></tr>';
		$html .= '<tr><th scope="row">Saldo</th><td>' . esc_html( Money::format( $summary['balance_cents'] ) ) . '</td></tr>';
		$html .= '</table>';
		$payments = $view['payments'];
		if ( $payments ) {
			$html .= '<h3>Pagos recibidos</h3><table>';
			foreach ( $payments as $p ) {
				$html .= '<tr><th scope="row">' . esc_html( $p['date'] ) . '</th><td>' . esc_html( $p['amount_txt'] . ' · ' . $p['method'] ) . '</td></tr>';
			}
			$html .= '</table>';
		}
		$html .= '</section>';

		$html .= '<section class="contract"><h2>Contrato</h2>';
		if ( $b['contract_version'] ) {
			$html .= '<p class="meta"><strong>' . esc_html( $b['contract_title'] ) . '</strong> · versión ' . (int) $b['contract_version'] .
				( $b['contract_accepted_at'] ? ' · aceptado el ' . esc_html( Catalog::dateTimeText( new \DateTimeImmutable( $b['contract_accepted_at'], new \DateTimeZone( 'UTC' ) ) ) ) : ' · registrado por el estudio' ) . '</p>';
			$html .= '<div class="contract-body">' . Contracts::sanitize( (string) $b['contract_body'] ) . '</div>';
			$html .= '<p class="hash">Huella del texto aceptado (SHA-256): ' . esc_html( $b['contract_hash'] ) . '</p>';
		} else {
			$html .= '<p>Esta reserva no tiene una copia de contrato guardada.</p>';
		}
		$html .= '</section>';
		$html .= '<footer><p>' . esc_html( $business ) . ' · Documento generado el ' . esc_html( Catalog::dateTimeText( \MOBookingStudio\Plugin::clock()->now() ) ) . '</p></footer>';
		return self::page( 'Contrato ' . $b['public_ref'], $html );
	}

	private static function page( string $title, string $body ): string {
		$css = 'body{font-family:Montserrat,"Helvetica Neue",Arial,sans-serif;color:#2E2018;max-width:780px;margin:0 auto;padding:24px 16px;line-height:1.5;background:#fff}'
			. 'h1,h2{font-family:"Cormorant Garamond",Georgia,serif;font-weight:500}h1{font-size:34px;margin:4px 0 12px}h2{font-size:24px;border-bottom:1px solid #EADCCF;padding-bottom:4px;margin-top:28px}h3{font-size:15px}'
			. '.brand{text-transform:uppercase;letter-spacing:.2em;font-size:11px;color:#75655A;margin:0}'
			. 'table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #F1E8DE;vertical-align:top}th{width:38%;font-weight:600}'
			. '.total th,.total td{font-weight:700}.reference td{text-decoration:line-through;color:#646970}.discounted th,.discounted td{font-weight:600}.meta{color:#3c434a}.hash{font-size:11px;color:#646970;word-break:break-all}'
			. 'button{font:inherit;padding:10px 16px;border-radius:6px;border:0;background:#654528;color:#FAF7F2;cursor:pointer}.actions span{color:#50575e;font-size:14px}'
			. 'footer{margin-top:32px;font-size:12px;color:#646970}'
			. '@media print{.no-print{display:none}body{padding:0}h2{break-after:avoid}.contract{break-before:page}}';
		return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>' . esc_html( $title ) . '</title><style>' . $css . '</style></head><body>' . $body . '</body></html>';
	}
}
