<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Schedules;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;

final class ServicesPage {
	public static function render(): void {
		$id = isset( $_GET['service'] ) ? sanitize_text_field( wp_unslash( $_GET['service'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		if ( null !== $id ) {
			self::edit( 'new' === $id ? null : (int) $id );
			return;
		}
		Ui::header( 'Servicios', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-services', array( 'service' => 'new' ) ) ) . '">Agregar servicio</a>' );
		$services = Services::all();
		if ( ! $services ) {
			echo '<div class="mo-empty"><p>Todavía no hay servicios. Crea el primero para empezar a recibir reservas.</p><p><a class="button button-primary" href="' . esc_url( Ui::url( 'mo-booking-services', array( 'service' => 'new' ) ) ) . '">Agregar servicio</a></p></div>';
			Ui::footer();
			return;
		}
		$statusLabels = array( 'draft' => 'Borrador', 'published' => 'Publicado', 'inactive' => 'Desactivado' );
		echo '<table class="widefat striped mo-table"><thead><tr><th>Servicio</th><th>Estado</th><th>Precio base</th><th>Duración</th><th>Shortcode</th><th></th></tr></thead><tbody>';
		foreach ( $services as $s ) {
			echo '<tr><td data-label="Servicio"><strong>' . esc_html( $s['title'] ) . '</strong>' . ( $s['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) . '<br><span class="description">' . esc_html( $s['brand'] ) . '</span></td>';
			echo '<td data-label="Estado">' . esc_html( $statusLabels[ $s['status'] ] ?? $s['status'] ) . '</td>';
			echo '<td data-label="Precio base">' . Ui::money( (int) $s['price_cents'] ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td data-label="Duración">' . (int) $s['duration'] . ' min</td>';
			echo '<td data-label="Shortcode"><code>[mo_booking service="' . esc_html( $s['slug'] ) . '"]</code></td>';
			echo '<td><a class="button" href="' . esc_url( Ui::url( 'mo-booking-services', array( 'service' => $s['id'] ) ) ) . '">Editar</a> <a class="button" href="' . esc_url( Ui::url( 'mo-booking-rates', array( 'service' => $s['id'] ) ) ) . '">Tarifas</a> <a class="button" href="' . esc_url( Ui::url( 'mo-booking-policies', array( 'service' => $s['id'] ) ) ) . '">Políticas</a></td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function edit( ?int $id ): void {
		$service = $id ? Services::find( $id ) : null;
		if ( $id && ! $service ) {
			wp_die( 'El servicio no existe.' );
		}
		$old = Ui::oldInput();
		$s   = $service ?: array_merge(
			Services::defaultConfig(),
			array(
				'id'          => 0,
				'title'       => '',
				'slug'        => '',
				'brand'       => '',
				'status'      => 'draft',
				'resource_id' => Schedules::defaultResourceId(),
				'category_id' => null,
			)
		);
		$v = static function ( string $key, $default = '' ) use ( $old, $s ) {
			return array_key_exists( $key, $old ) ? $old[ $key ] : ( $s[ $key ] ?? $default );
		};

		Ui::header( $service ? 'Editar servicio: ' . $service['title'] : 'Nuevo servicio', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-services' ) ) . '">Volver</a>' );
		echo Ui::formOpen( 'save_service', array( 'service_id' => (int) $s['id'] ), 'mo-service-form' ); // phpcs:ignore WordPress.Security.EscapeOutput

		$categories = array( '' => 'Sin categoría' );
		foreach ( Services::categories() as $c ) {
			$categories[ $c['id'] ] = $c['name'];
		}
		$resources = array();
		foreach ( Schedules::resources() as $r ) {
			$resources[ $r['id'] ] = $r['name'];
		}

		$general  = Ui::row( 'Nombre del servicio', Ui::input( 'title', $v( 'title' ), 'text', array( 'class' => 'regular-text', 'required' => 'required' ) ), '', 'title' );
		$general .= Ui::row( 'Identificador (slug)', Ui::input( 'slug', $v( 'slug' ), 'text', array( 'class' => 'regular-text', 'pattern' => '[a-z0-9-]*' ) ), 'Se usa en el shortcode. Si lo dejas vacío se genera del nombre.', 'slug' );
		$general .= Ui::row( 'Nombre comercial visible', Ui::input( 'brand', $v( 'brand' ), 'text', array( 'class' => 'regular-text', 'placeholder' => 'Por ejemplo: Clics con Amor Fotografía' ) ), 'Si lo dejas vacío se usa el nombre del negocio de Ajustes.', 'brand' );
		$general .= Ui::row( 'Estado', Ui::select( 'status', array( 'draft' => 'Borrador (no visible)', 'published' => 'Publicado', 'inactive' => 'Desactivado (conserva historial)' ), $v( 'status' ) ), 'Solo los servicios publicados aceptan reservas.', 'status' );
		$general .= Ui::row( 'Categoría para reportes', Ui::select( 'category_id', $categories, (string) $v( 'category_id' ) ) . ' ' . Ui::input( 'new_category', '', 'text', array( 'placeholder' => 'o escribe una nueva', 'class' => 'mo-inline' ) ), 'Por ejemplo: minis, estudio, bodas, eventos.', 'category_id' );
		if ( count( $resources ) > 1 ) {
			$general .= Ui::row( 'Agenda (recurso)', Ui::select( 'resource_id', $resources, (string) $v( 'resource_id' ) ), 'Los servicios que comparten agenda no pueden vender el mismo tiempo.', 'resource_id' );
		} else {
			$general .= '<input type="hidden" name="resource_id" value="' . esc_attr( (string) $v( 'resource_id' ) ) . '">';
		}
		$general .= Ui::row( 'Descripción', Ui::textarea( 'description', $v( 'description' ), 4 ), 'Qué incluye, cuántas fotos, cómo es la sesión.', 'description' );
		$imageId  = (int) $v( 'image_id', 0 );
		$imageUrl = $imageId ? wp_get_attachment_image_url( $imageId, 'thumbnail' ) : '';
		$general .= Ui::row( 'Foto del servicio', '<div class="mo-media" data-mo-media><img src="' . esc_url( (string) $imageUrl ) . '" alt="" class="mo-media__preview"' . ( $imageUrl ? '' : ' hidden' ) . '>' . Ui::input( 'image_id', $imageId ?: '', 'hidden' ) . '<button type="button" class="button" data-mo-media-pick>Elegir imagen</button> <button type="button" class="button-link" data-mo-media-clear>Quitar</button></div>', 'Opcional. Es una foto pública del servicio, no de clientes.' );
		$general .= Ui::row( 'Ubicación o modalidad', Ui::input( 'location', $v( 'location' ), 'text', array( 'class' => 'regular-text' ) ), '', 'location' );
		$general .= Ui::row( 'Indicaciones para la sesión', Ui::textarea( 'instructions', $v( 'instructions' ), 3 ), 'Se muestran en la confirmación y en el portal.', 'instructions' );
		$general .= Ui::row( 'Entrega', Ui::input( 'delivery_text', $v( 'delivery_text' ), 'text', array( 'class' => 'large-text', 'placeholder' => 'Entrega de 5 a 7 días hábiles' ) ), '', 'delivery_text' );
		echo Ui::card( 'Datos generales', $general ); // phpcs:ignore WordPress.Security.EscapeOutput

		$time  = Ui::row( 'Duración (minutos)', Ui::input( 'duration', $v( 'duration' ), 'number', array( 'min' => 5, 'step' => 5, 'required' => 'required' ) ), '', 'duration' );
		$time .= Ui::row( 'Margen antes (minutos)', Ui::input( 'buffer_before', $v( 'buffer_before' ), 'number', array( 'min' => 0, 'step' => 5 ) ), 'Tiempo de preparación que también se bloquea.', 'buffer_before' );
		$time .= Ui::row( 'Margen después (minutos)', Ui::input( 'buffer_after', $v( 'buffer_after' ), 'number', array( 'min' => 0, 'step' => 5 ) ), 'Tiempo libre después de cada cita.', 'buffer_after' );
		$time .= Ui::row( 'Intervalo entre inicios (minutos)', Ui::input( 'interval', $v( 'interval' ) ?: '', 'number', array( 'min' => 5, 'step' => 5, 'placeholder' => 'automático' ) ), 'Vacío = duración + márgenes. Ejemplo minis: 20 min + 10 de margen = cada 30 min.', 'interval' );
		$time .= Ui::row( 'Anticipación mínima (horas)', Ui::input( 'min_notice_hours', $v( 'min_notice_hours' ), 'number', array( 'min' => 0, 'step' => '0.5' ) ), 'Cuánto antes de la cita se puede reservar como mínimo.', 'min_notice_hours' );
		$time .= Ui::row( 'Horizonte máximo (días)', Ui::input( 'horizon_days', $v( 'horizon_days' ), 'number', array( 'min' => 0 ) ), '0 = sin límite.', 'horizon_days' );
		$time .= Ui::row( 'Máximo de citas por día', Ui::input( 'max_per_day', $v( 'max_per_day' ), 'number', array( 'min' => 0 ) ), '0 = sin límite.', 'max_per_day' );
		$time .= Ui::row( 'Venta desde', Ui::input( 'sale_from', Ui::sqlToLocalInput( (string) $v( 'sale_from' ) ), 'datetime-local' ), 'Opcional. Antes de esta fecha no se puede reservar.', 'sale_from' );
		$time .= Ui::row( 'Venta hasta', Ui::input( 'sale_until', Ui::sqlToLocalInput( (string) $v( 'sale_until' ) ), 'datetime-local' ), 'Opcional.', 'sale_until' );
		echo Ui::card( 'Tiempo y agenda', $time ); // phpcs:ignore WordPress.Security.EscapeOutput

		$people  = Ui::row( 'Personas incluidas', Ui::input( 'participants_included', $v( 'participants_included' ), 'number', array( 'min' => 1 ) ), '', 'participants_included' );
		$people .= Ui::row( 'Máximo de personas', Ui::input( 'participants_max', $v( 'participants_max' ), 'number', array( 'min' => 1 ) ), '', 'participants_max' );
		$people .= Ui::row( 'Costo por persona adicional ($)', Ui::input( 'extra_participant', Money::toInput( (int) $v( 'extra_participant_cents', 0 ) ), 'text', array( 'inputmode' => 'decimal' ) ), '', 'extra_participant' );
		$people .= Ui::row( 'Mascotas', Ui::checkbox( 'pets_allowed', (bool) $v( 'pets_allowed' ), 'Se admiten mascotas' ) . '<br>' . Ui::checkbox( 'pets_count_as_participants', (bool) $v( 'pets_count_as_participants' ), 'Las mascotas cuentan como personas' ) );
		$people .= Ui::row( 'Máximo de mascotas', Ui::input( 'pets_max', $v( 'pets_max' ), 'number', array( 'min' => 0 ) ), '0 = sin límite.', 'pets_max' );
		$people .= Ui::row( 'Costo por mascota ($)', Ui::input( 'pet_fee', Money::toInput( (int) $v( 'pet_fee_cents', 0 ) ), 'text', array( 'inputmode' => 'decimal' ) ), '', 'pet_fee' );
		echo Ui::card( 'Personas y mascotas', $people ); // phpcs:ignore WordPress.Security.EscapeOutput

		$depositValue = 'percent' === $v( 'deposit_type' ) ? (string) $v( 'deposit_value' ) : Money::toInput( (int) $v( 'deposit_value', 0 ) );
		$pay          = Ui::row( 'Precio base ($ MXN)', Ui::input( 'price', Money::toInput( (int) $v( 'price_cents', 0 ) ), 'text', array( 'inputmode' => 'decimal', 'required' => 'required' ) ), 'Se usa cuando ninguna tarifa especial aplica. Las tarifas por fecha, preventa o cliente frecuente se configuran en "Tarifas y descuentos".', 'price' );
		$pay         .= Ui::row( 'Anticipo', Ui::select( 'deposit_type', array( 'fixed' => 'Importe fijo ($)', 'percent' => 'Porcentaje del total (%)', 'full' => 'Pago total', 'none' => 'Sin anticipo' ), $v( 'deposit_type' ) ) . ' ' . Ui::input( 'deposit_value', $depositValue, 'text', array( 'inputmode' => 'decimal', 'class' => 'small-text' ) ), 'El anticipo nunca supera el total.', 'deposit_type' );
		$pay         .= Ui::row( 'Horas para pagar el anticipo', Ui::input( 'hold_hours', (float) $v( 'hold_hours' ) ?: '', 'number', array( 'min' => 0, 'step' => '0.5', 'placeholder' => (string) \MOBookingStudio\Infrastructure\Settings::get( 'hold_hours' ) ) ), 'Vacío = valor general de Ajustes. Durante este tiempo el horario queda apartado.', 'hold_hours' );
		$pay         .= Ui::row( 'Confirmación', Ui::select( 'confirmation_mode', array( 'manual' => 'Manual: confirmo yo al revisar el pago', 'auto' => 'Automática al aprobar el pago' ), $v( 'confirmation_mode' ) ), '', 'confirmation_mode' );
		echo Ui::card( 'Precio y pago', $pay ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Extras.
		$extras = (array) $v( 'extras', array() );
		$rows   = '';
		foreach ( array_merge( $extras, array( array(), array() ) ) as $i => $e ) {
			$rows .= '<tr><td>' . Ui::input( "extras[$i][label]", $e['label'] ?? '', 'text', array( 'placeholder' => 'Nombre del extra', 'aria-label' => 'Nombre del extra' ) ) . Ui::input( "extras[$i][key]", $e['key'] ?? '', 'hidden' ) . '</td>' .
				'<td>' . Ui::input( "extras[$i][price]", isset( $e['price_cents'] ) ? Money::toInput( (int) $e['price_cents'] ) : ( $e['price'] ?? '' ), 'text', array( 'class' => 'small-text', 'aria-label' => 'Precio', 'inputmode' => 'decimal' ) ) . '</td>' .
				'<td>' . Ui::input( "extras[$i][max_qty]", $e['max_qty'] ?? 1, 'number', array( 'class' => 'small-text', 'min' => 1, 'aria-label' => 'Cantidad máxima' ) ) . '</td>' .
				'<td>' . Ui::input( "extras[$i][description]", $e['description'] ?? '', 'text', array( 'aria-label' => 'Descripción' ) ) . '</td></tr>';
		}
		echo Ui::card( 'Extras', '<p class="description">Productos o servicios opcionales con precio. Deja el nombre vacío para quitar uno. No se les aplican descuentos.</p><table class="widefat mo-repeat"><thead><tr><th>Nombre</th><th>Precio ($)</th><th>Máx.</th><th>Descripción</th></tr></thead><tbody>' . $rows . '</tbody></table><p><button type="button" class="button" data-mo-add-row>Agregar fila</button></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Preguntas.
		$questions = (array) $v( 'questions', array() );
		$rows      = '';
		$types     = array( 'text' => 'Texto corto', 'textarea' => 'Texto largo', 'select' => 'Selección', 'number' => 'Número', 'yesno' => 'Sí / No' );
		foreach ( array_merge( $questions, array( array(), array() ) ) as $i => $q ) {
			$options = isset( $q['options'] ) ? ( is_array( $q['options'] ) ? implode( "\n", $q['options'] ) : (string) $q['options'] ) : '';
			$rows   .= '<tr><td>' . Ui::input( "questions[$i][label]", $q['label'] ?? '', 'text', array( 'placeholder' => 'Pregunta', 'aria-label' => 'Pregunta' ) ) . Ui::input( "questions[$i][key]", $q['key'] ?? '', 'hidden' ) . '</td>' .
				'<td>' . Ui::select( "questions[$i][type]", $types, $q['type'] ?? 'text', array( 'aria-label' => 'Tipo' ) ) . '</td>' .
				'<td>' . Ui::checkbox( "questions[$i][required]", ! empty( $q['required'] ), 'Obligatoria' ) . '</td>' .
				'<td>' . Ui::textarea( "questions[$i][options]", $options, 2, array( 'placeholder' => 'Opciones, una por línea (solo selección)', 'aria-label' => 'Opciones' ) ) . '</td></tr>';
		}
		echo Ui::card( 'Preguntas adicionales', '<p class="description">Las respuestas se guardan como texto; no pueden ejecutar código.</p><table class="widefat mo-repeat"><thead><tr><th>Pregunta</th><th>Tipo</th><th></th><th>Opciones</th></tr></thead><tbody>' . $rows . '</tbody></table><p><button type="button" class="button" data-mo-add-row>Agregar fila</button></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Avisos por fecha.
		$notices = (array) $v( 'date_notices', array() );
		$rows    = '';
		foreach ( array_merge( $notices, array( array() ) ) as $i => $n ) {
			$rows .= '<tr><td>' . Ui::input( "date_notices[$i][from]", $n['from'] ?? '', 'date', array( 'aria-label' => 'Desde' ) ) . '</td><td>' . Ui::input( "date_notices[$i][to]", $n['to'] ?? '', 'date', array( 'aria-label' => 'Hasta' ) ) . '</td><td>' . Ui::input( "date_notices[$i][text]", $n['text'] ?? '', 'text', array( 'class' => 'large-text', 'aria-label' => 'Aviso' ) ) . '</td></tr>';
		}
		echo Ui::card( 'Avisos por fecha', '<p class="description">Se muestran al elegir esas fechas. Ejemplo: "Las sesiones del 19 y 20 de diciembre se entregan después de Navidad".</p><table class="widefat mo-repeat"><thead><tr><th>Desde</th><th>Hasta</th><th>Aviso</th></tr></thead><tbody>' . $rows . '</tbody></table><p><button type="button" class="button" data-mo-add-row>Agregar fila</button></p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo Ui::card( 'Condiciones del servicio', Ui::textarea( 'terms_text', $v( 'terms_text' ), 4 ) . '<p class="description">La persona debe aceptarlas al reservar. Las políticas de cambios y cancelación se editan en "Políticas".</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<p class="mo-sticky-actions">' . Ui::button( 'Guardar servicio' ) . '</p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::footer();
	}

	public static function save( array $p ): int {
		$id   = (int) ( $p['service_id'] ?? 0 ) ?: null;
		$cats = ! empty( $p['new_category'] ) ? Services::saveCategory( (string) $p['new_category'] ) : ( (int) ( $p['category_id'] ?? 0 ) ?: null );
		$money = static function ( $value, string $label ): int {
			if ( '' === trim( (string) $value ) ) {
				return 0;
			}
			$c = Money::parse( (string) $value );
			if ( null === $c || $c < 0 ) {
				throw new DomainException( 'invalid_amount', sprintf( 'El importe de "%s" no es válido.', $label ) );
			}
			return $c;
		};
		$extras = array();
		foreach ( (array) ( $p['extras'] ?? array() ) as $e ) {
			if ( '' === trim( (string) ( $e['label'] ?? '' ) ) ) {
				continue;
			}
			$extras[] = array(
				'key'         => sanitize_key( (string) ( $e['key'] ?? '' ) ),
				'label'       => sanitize_text_field( (string) $e['label'] ),
				'price_cents' => $money( $e['price'] ?? '', (string) $e['label'] ),
				'max_qty'     => (int) ( $e['max_qty'] ?? 1 ),
				'description' => sanitize_text_field( (string) ( $e['description'] ?? '' ) ),
			);
		}
		$questions = array();
		foreach ( (array) ( $p['questions'] ?? array() ) as $q ) {
			if ( '' === trim( (string) ( $q['label'] ?? '' ) ) ) {
				continue;
			}
			$questions[] = array(
				'key'      => sanitize_key( (string) ( $q['key'] ?? '' ) ),
				'label'    => sanitize_text_field( (string) $q['label'] ),
				'type'     => sanitize_key( (string) ( $q['type'] ?? 'text' ) ),
				'required' => ! empty( $q['required'] ),
				'options'  => array_map( 'sanitize_text_field', explode( "\n", (string) ( $q['options'] ?? '' ) ) ),
			);
		}
		$notices = array();
		foreach ( (array) ( $p['date_notices'] ?? array() ) as $n ) {
			if ( '' === trim( (string) ( $n['text'] ?? '' ) ) ) {
				continue;
			}
			$notices[] = array(
				'from' => sanitize_text_field( (string) ( $n['from'] ?? '' ) ),
				'to'   => sanitize_text_field( (string) ( $n['to'] ?? '' ) ),
				'text' => sanitize_text_field( (string) $n['text'] ),
			);
		}
		$depositType  = sanitize_key( (string) ( $p['deposit_type'] ?? 'none' ) );
		$depositValue = 'percent' === $depositType ? (float) str_replace( ',', '.', (string) ( $p['deposit_value'] ?? 0 ) ) : $money( $p['deposit_value'] ?? '', 'Anticipo' );
		if ( 'fixed' === $depositType && $depositValue <= 0 ) {
			throw new DomainException( 'invalid_amount', 'Indica el importe del anticipo o elige "Sin anticipo".' );
		}

		return Services::save(
			array(
				'title'                      => sanitize_text_field( (string) ( $p['title'] ?? '' ) ),
				'slug'                       => sanitize_title( (string) ( $p['slug'] ?? '' ) ),
				'brand'                      => sanitize_text_field( (string) ( $p['brand'] ?? '' ) ),
				'status'                     => sanitize_key( (string) ( $p['status'] ?? 'draft' ) ),
				'category_id'                => $cats,
				'resource_id'                => (int) ( $p['resource_id'] ?? 0 ),
				'description'                => sanitize_textarea_field( (string) ( $p['description'] ?? '' ) ),
				'image_id'                   => (int) ( $p['image_id'] ?? 0 ),
				'location'                   => sanitize_text_field( (string) ( $p['location'] ?? '' ) ),
				'instructions'               => sanitize_textarea_field( (string) ( $p['instructions'] ?? '' ) ),
				'delivery_text'              => sanitize_text_field( (string) ( $p['delivery_text'] ?? '' ) ),
				'duration'                   => (int) ( $p['duration'] ?? 0 ),
				'buffer_before'              => (int) ( $p['buffer_before'] ?? 0 ),
				'buffer_after'               => (int) ( $p['buffer_after'] ?? 0 ),
				'interval'                   => (int) ( $p['interval'] ?? 0 ),
				'min_notice_hours'           => (float) ( $p['min_notice_hours'] ?? 0 ),
				'horizon_days'               => (int) ( $p['horizon_days'] ?? 0 ),
				'max_per_day'                => (int) ( $p['max_per_day'] ?? 0 ),
				'sale_from'                  => Ui::localInputToSql( (string) ( $p['sale_from'] ?? '' ) ),
				'sale_until'                 => Ui::localInputToSql( (string) ( $p['sale_until'] ?? '' ) ),
				'participants_included'      => (int) ( $p['participants_included'] ?? 1 ),
				'participants_max'           => (int) ( $p['participants_max'] ?? 1 ),
				'extra_participant_cents'    => $money( $p['extra_participant'] ?? '', 'Persona adicional' ),
				'pets_allowed'               => ! empty( $p['pets_allowed'] ),
				'pets_count_as_participants' => ! empty( $p['pets_count_as_participants'] ),
				'pets_max'                   => (int) ( $p['pets_max'] ?? 0 ),
				'pet_fee_cents'              => $money( $p['pet_fee'] ?? '', 'Mascota' ),
				'price_cents'                => $money( $p['price'] ?? '', 'Precio base' ),
				'deposit_type'               => $depositType,
				'deposit_value'              => $depositValue,
				'hold_hours'                 => (float) ( $p['hold_hours'] ?? 0 ),
				'confirmation_mode'          => sanitize_key( (string) ( $p['confirmation_mode'] ?? 'manual' ) ),
				'extras'                     => $extras,
				'questions'                  => $questions,
				'date_notices'               => $notices,
				'terms_text'                 => sanitize_textarea_field( (string) ( $p['terms_text'] ?? '' ) ),
			),
			$id
		);
	}
}
