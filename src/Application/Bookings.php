<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\Policy;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Infrastructure\Tokens;
use MOBookingStudio\Plugin;

/**
 * Operaciones de reserva. Las reservas públicas y las que crea la administradora
 * pasan por las mismas validaciones.
 *
 * Control de cupos: dentro de una transacción se bloquea una fila por recurso y día UTC
 * (en orden estable) antes de buscar solapamientos e insertar la ocupación. Dos intervalos
 * que se solapan siempre comparten al menos un día UTC, así que dos solicitudes que
 * compiten por el mismo tiempo se serializan y la segunda ve la ocupación de la primera.
 */
final class Bookings {
	private const REF_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

	// ----- Lectura -----

	public static function find( int $id, bool $forUpdate = false ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d' . ( $forUpdate ? ' FOR UPDATE' : '' ), $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get( int $id, bool $forUpdate = false ): array {
		$b = self::find( $id, $forUpdate );
		if ( ! $b ) {
			throw new DomainException( 'not_found', 'La reserva no existe.', 404 );
		}
		return $b;
	}

	public static function findByRef( string $ref ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE public_ref = %s', strtoupper( trim( $ref ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function hydrate( array $row ): array {
		foreach ( array( 'quote', 'policy', 'answers', 'contact', 'consent' ) as $key ) {
			$row[ $key ] = (array) Db::decode( $row[ $key ] ?? '' );
		}
		foreach ( array( 'id', 'service_id', 'service_version', 'client_id', 'resource_id', 'participants', 'pets', 'total_cents', 'deposit_cents', 'version', 'frequent_claim', 'session_counted', 'is_test' ) as $key ) {
			$row[ $key ] = (int) $row[ $key ];
		}
		foreach ( array( 'children', 'adults', 'contract_id', 'contract_version' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) && null !== $row[ $key ] ? (int) $row[ $key ] : null;
		}
		$row['invite_override'] = (int) ( $row['invite_override'] ?? 0 );
		$row['important_info']  = (string) ( $row['important_info'] ?? '' );
		$row['archived']        = ! empty( $row['archived_at'] );
		$row['trashed']         = ! empty( $row['trashed_at'] );
		$row['start']   = Db::fromSql( $row['start_utc'] );
		$row['end']     = Db::fromSql( $row['end_utc'] );
		$row['expires'] = Db::fromSql( $row['expires_at'] );
		return $row;
	}

	/**
	 * @param array $filters status, from, to (fechas locales), service_id, client_id, search, limit, offset
	 */
	public static function list( array $filters = array() ): array {
		$wpdb               = Db::wpdb();
		list( $where, $params ) = self::listWhere( $filters );
		$order    = ( $filters['order'] ?? 'asc' ) === 'desc' ? 'DESC' : 'ASC';
		$columns  = array( 'start' => 'b.start_utc', 'created' => 'b.created_at', 'total' => 'b.total_cents', 'ref' => 'b.public_ref', 'client' => 'c.name' );
		$orderBy  = $columns[ $filters['order_by'] ?? 'start' ] ?? 'b.start_utc';
		$limit    = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$offset   = max( 0, (int) ( $filters['offset'] ?? 0 ) );
		$params[] = $limit;
		$params[] = $offset;
		$sql      = 'SELECT b.*, c.name AS client_name, c.email AS client_email, c.phone AS client_phone, s.title AS service_title FROM ' . Schema::table( 'bookings' ) . ' b LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id = b.client_id LEFT JOIN ' . Schema::table( 'services' ) . ' s ON s.id = b.service_id WHERE ' . $where . " ORDER BY $orderBy $order, b.id $order LIMIT %d OFFSET %d";
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'hydrate' ), $rows );
	}

	/** Total con los mismos filtros que list() (para paginar). */
	public static function count( array $filters = array() ): int {
		$wpdb               = Db::wpdb();
		list( $where, $params ) = self::listWhere( $filters );
		$sql = 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' b LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id = b.client_id WHERE ' . $where;
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array{0:string,1:array} */
	private static function listWhere( array $filters ): array {
		$wpdb   = Db::wpdb();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['status'] ) ) {
			$statuses = (array) $filters['status'];
			$where[]  = 'b.status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			$params   = array_merge( $params, $statuses );
		}
		$zone = Settings::timezone();
		if ( ! empty( $filters['from'] ) ) {
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters['from'], $zone );
			if ( $d ) {
				$where[]  = 'b.start_utc >= %s';
				$params[] = Db::toSql( $d );
			}
		}
		if ( ! empty( $filters['to'] ) ) {
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $filters['to'], $zone );
			if ( $d ) {
				$where[]  = 'b.start_utc < %s';
				$params[] = Db::toSql( $d->modify( '+1 day' ) );
			}
		}
		foreach ( array( 'service_id', 'client_id' ) as $key ) {
			if ( ! empty( $filters[ $key ] ) ) {
				$where[]  = "b.$key = %d";
				$params[] = (int) $filters[ $key ];
			}
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $filters['search'] ) ) . '%';
			$where[]  = '(b.public_ref LIKE %s OR c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s)';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		if ( isset( $filters['archived'] ) ) {
			$where[] = $filters['archived'] ? 'b.archived_at IS NOT NULL' : 'b.archived_at IS NULL';
		}
		// Papelera: fuera de las listas salvo que se pida.
		$where[] = ! empty( $filters['trash'] ) ? 'b.trashed_at IS NOT NULL' : 'b.trashed_at IS NULL';
		if ( ! empty( $filters['conflict'] ) ) {
			$where[] = "b.conflict_state = 'open'";
		}
		if ( ! empty( $filters['frequent_auto'] ) ) {
			$where[]  = '(b.frequent_match = %s OR b.frequent_match = %s)';
			$params[] = FrequentCheck::MATCH_AUTO;
			$params[] = FrequentCheck::MATCH_AUTO_REVIEW;
		}
		if ( isset( $filters['is_test'] ) ) {
			$where[]  = 'b.is_test = %d';
			$params[] = (int) $filters['is_test'];
		}
		return array( implode( ' AND ', $where ), $params );
	}

	public static function movements( int $bookingId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d ORDER BY id', $bookingId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function proofs( int $bookingId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'proofs' ) . ' WHERE booking_id = %d ORDER BY id', $bookingId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function summary( array $booking ): array {
		$pending = false;
		foreach ( self::proofs( $booking['id'] ) as $p ) {
			if ( 'pending' === $p['status'] ) {
				$pending = true;
			}
		}
		return Ledger::summarize( self::movements( $booking['id'] ), $booking['total_cents'], $booking['deposit_cents'], $pending );
	}

	/**
	 * SEG-03: el evento del cliente (invitación de Google) depende de una condición
	 * independiente del estado: anticipo aprobado, anticipo configurado en cero o la acción
	 * explícita "Invitar al cliente sin anticipo". Una cita ya invitada conserva su evento.
	 */
	public static function inviteAllowed( array $booking ): bool {
		if ( BookingStatus::CONFIRMED !== $booking['status'] ) {
			return false;
		}
		if ( 0 === (int) $booking['deposit_cents'] || ! empty( $booking['invite_override'] ) ) {
			return true;
		}
		return self::summary( $booking )['deposit_covered'];
	}

	/** SEG-03: acción explícita, con motivo, para invitar a una cita confirmada sin anticipo cubierto. */
	public static function inviteWithoutDeposit( int $bookingId, string $reason ): array {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			throw new DomainException( 'reason_required', 'Escribe el motivo para invitar sin anticipo.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $reason ) {
				$booking = self::get( $bookingId, true );
				if ( BookingStatus::CONFIRMED !== $booking['status'] ) {
					throw new DomainException( 'invalid_transition', 'Solo se puede invitar a una cita confirmada.', 409 );
				}
				if ( ! empty( $booking['invite_override'] ) ) {
					return;
				}
				self::update( $booking, array( 'invite_override' => 1 ) );
				Audit::log( 'booking', $bookingId, 'invite_without_deposit', array( 'reason' => $reason ) );
			}
		);
		return self::get( $bookingId );
	}

	// ----- Creación -----

	/**
	 * Crea una reserva a partir de una cotización aceptada.
	 *
	 * @param array $input {quote_id, idempotency_key, name, email, phone, phone_country, answers, accept_terms, whatsapp_consent, frequent_claim}
	 * @param array $options {source, is_test, requested_at, category, ip}
	 * @return array{booking:array,token:string,replayed:bool}
	 */
	public static function create( array $service, array $input, array $options = array() ): array {
		$now      = Plugin::clock()->now();
		$source   = $options['source'] ?? 'public';
		$isAdmin  = 'admin' === $source;
		$key      = (string) ( $input['idempotency_key'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9-]{16,64}$/', $key ) ) {
			throw new DomainException( 'invalid_request', 'Falta la clave de la solicitud. Recarga la página e intenta de nuevo.', 400 );
		}
		$contact = self::validateContact( $input );
		$answers = self::validateAnswers( $service, (array) ( $input['answers'] ?? array() ) );
		$info    = self::validateImportantInfo( $input['important_info'] ?? '' );
		// 6.5: copia inmutable del contrato vigente; en línea es obligatorio aceptarlo.
		$contract = $isAdmin
			? ( Contracts::forService( $service ) ? Contracts::snapshotFor( $service, null ) : array() )
			: Contracts::snapshotFor( $service, $input['contract_version'] ?? null );
		if ( ! $isAdmin && empty( $input['accept_terms'] ) ) {
			throw new DomainException( 'terms_required', 'Para reservar debes aceptar las condiciones y políticas.', 400, array( 'field' => 'accept_terms' ) );
		}
		// beta.4: los avisos por WhatsApp y correo son parte del servicio (configurable en Ajustes).
		if ( ! $isAdmin && Settings::get( 'whatsapp_consent_required' ) && empty( $input['whatsapp_consent'] ) ) {
			throw new DomainException( 'consent_required', 'Para continuar acepta recibir los avisos de tu reserva por WhatsApp y correo.', 400, array( 'field' => 'whatsapp_consent' ) );
		}
		$requestHash = hash( 'sha256', Db::json( array( $input['quote_id'] ?? ( $input['start'] ?? '' ), $contact, $answers, $info ) ) );

		// Reintento de la misma solicitud: devolver la reserva existente sin duplicar efectos.
		$existing = self::findByIdempotency( $key );
		if ( $existing ) {
			if ( $existing['request_hash'] !== $requestHash ) {
				throw new DomainException( 'idempotency_conflict', 'Esta solicitud ya se usó con otros datos. Recarga la página para empezar una nueva.', 409 );
			}
			return array(
				'booking'  => $existing,
				'token'    => Tokens::tokenFor( $existing['id'], $existing['token_nonce'] ),
				'replayed' => true,
			);
		}

		self::expireDue();

		$category    = Pricing::CATEGORY_GENERAL;
		$requestedAt = $now;
		if ( $isAdmin ) {
			$start       = Catalog::parseStart( (string) $input['start'] );
			$category    = Pricing::CATEGORY_FREQUENT === ( $options['category'] ?? '' ) ? Pricing::CATEGORY_FREQUENT : Pricing::CATEGORY_GENERAL;
			$requestedAt = $options['requested_at'] ?? $now;
			if ( $requestedAt > $now ) {
				throw new DomainException( 'invalid_request', 'La fecha de solicitud no puede estar en el futuro.' );
			}
			$pricingInput = $input;
		} else {
			$quote = Catalog::loadQuote( (string) ( $input['quote_id'] ?? '' ) );
			if ( (int) $quote['service_id'] !== (int) $service['id'] ) {
				throw new DomainException( 'invalid_quote', 'La cotización no corresponde a este servicio.', 400 );
			}
			if ( Db::fromSql( $quote['expires_at'] ) <= $now ) {
				throw new DomainException( 'quote_expired', 'Tu resumen venció. Revisa de nuevo el precio y el horario.', 410 );
			}
			$start        = Catalog::parseStart( $quote['payload']['input']['start'] );
			$pricingInput = $quote['payload']['input'];
			// Tarifa de cliente frecuente solo con una verificación vigente y el mismo contacto.
			$verification = FrequentCheck::get( isset( $input['frequent_token'] ) ? (string) $input['frequent_token'] : null );
			if ( $verification ) {
				FrequentCheck::assertContact( $verification, $contact );
				$category                       = Pricing::CATEGORY_FREQUENT;
				$pricingInput['frequent_token'] = (string) $input['frequent_token'];
			}
		}

		// El servidor recalcula; si el precio cambió desde la cotización, se pide revisar.
		$rules   = Services::rules( (int) $service['id'] );
		$context = Catalog::pricingContext( $pricingInput, $start, $requestedAt, $category );
		$result  = ( new Pricing( Settings::timezone() ) )->quote( Services::forPricing( $service ), $rules, $context );
		if ( ! $isAdmin ) {
			$accepted = $quote['payload']['result'];
			if ( $accepted['total_cents'] !== $result['total_cents'] || $accepted['deposit_cents'] !== $result['deposit_cents'] || $quote['pricing_hash'] !== Services::pricingHash( $service, $rules ) ) {
				$fresh = Catalog::quote( $service, $pricingInput );
				throw new DomainException( 'quote_changed', 'El precio cambió desde que viste el resumen. Revisa el nuevo resumen antes de confirmar.', 409, array( 'quote' => $fresh ) );
			}
		}

		$params   = Services::availabilityParams( $service );
		$occupy   = Availability::occupancyFor( $start, $params );
		$holdUntil = $result['deposit_cents'] > 0 ? $now->modify( sprintf( '+%d minutes', (int) round( Services::holdHours( $service ) * 60 ) ) ) : null;
		$status   = $result['deposit_cents'] > 0 ? BookingStatus::PENDING_PAYMENT : BookingStatus::CONFIRMED;
		// DEC-06: comprobación fresca en Google antes de tomar el bloqueo local. Si Google no
		// responde, la reserva en línea se detiene; la administradora puede forzarla con registro.
		$googleOverride = false;
		try {
			$externalBusy = Catalog::freshGoogleBusy( $service, $start );
		} catch ( DomainException $e ) {
			if ( ! $isAdmin || empty( $options['google_override'] ) ) {
				throw $e;
			}
			$externalBusy   = array();
			$googleOverride = true;
		}
		$isTest      = ! empty( $options['is_test'] ) || ! empty( $service['is_test'] );
		$verifiedId  = isset( $verification ) && $verification ? (int) $verification['client_id'] : 0;
		$verifiedReview = $verifiedId && ! empty( $verification['review'] );
		$extrasInput = $context['extras'];

		$created = Db::transaction(
			static function () use ( $service, $start, $occupy, $now, $result, $status, $holdUntil, $contact, $answers, $info, $contract, $isAdmin, $context, $input, $key, $requestHash, $requestedAt, $source, $isTest, $category, $extrasInput, $verifiedId, $verifiedReview, $externalBusy, $googleOverride ) {
				$resource = (int) $service['resource_id'];
				self::lockDays( $resource, $occupy->utcDays() );

				$slot = Catalog::findAvailableSlot( $service, $start, $now, null, 'admin' === $source, $externalBusy );
				if ( ! $slot ) {
					throw new DomainException( 'slot_unavailable', 'Lo sentimos, alguien acaba de apartar ese horario. Elige otro, por favor.', 409, array( 'field' => 'start' ) );
				}
				/**
				 * Solo para pruebas de concurrencia: permite retrasar la transacción entre la
				 * comprobación de disponibilidad y la inserción, donde ocurriría una carrera.
				 */
				do_action( 'mo_booking_before_insert', $service, $start );

				// Una segunda solicitud con la misma clave pudo terminar mientras esperábamos.
				$again = self::findByIdempotency( $key );
				if ( $again ) {
					return array( $again, false );
				}

				$client = $verifiedId
					? array( 'id' => $verifiedId, 'matched' => true, 'review' => false )
					: Clients::matchOrCreate( $contact['name'], $contact['email_norm'], $contact['phone_e164'], $contact['phone'], $contact['phone_country'], $isTest );
				$wpdb   = Db::wpdb();
				$nonce  = Tokens::newNonce();
				$row    = array(
					'public_ref'       => self::newRef(),
					'service_id'       => (int) $service['id'],
					'service_version'  => (int) $service['version'],
					'category_id'      => $service['category_id'],
					'client_id'        => $client['id'],
					'resource_id'      => $resource,
					'status'           => $status,
					'start_utc'        => Db::toSql( $start ),
					'end_utc'          => Db::toSql( $slot['end'] ),
					'timezone'         => Settings::timezoneInfo()['requested'],
					'participants'     => $result['participants'],
					'pets'             => $result['pets'],
					'children'         => $context['children'],
					'adults'           => $context['adults'],
					'important_info'   => $info,
					'contract_id'      => $contract['contract_id'] ?? null,
					'contract_version' => $contract['contract_version'] ?? null,
					'contract_title'   => $contract['contract_title'] ?? '',
					'contract_body'    => $contract['contract_body'] ?? null,
					'contract_hash'    => $contract['contract_hash'] ?? '',
					'contract_accepted_at' => ( $contract && ! $isAdmin ) ? Db::toSql( $now ) : null,
					'client_category'  => $category,
					'frequent_claim'   => ! empty( $input['frequent_claim'] ) || $verifiedId ? 1 : 0,
					'frequent_match'   => $verifiedId ? ( $verifiedReview ? FrequentCheck::MATCH_AUTO_REVIEW : FrequentCheck::MATCH_AUTO ) : '',
					'total_cents'      => $result['total_cents'],
					'deposit_cents'    => $result['deposit_cents'],
					'quote'            => Db::json(
						array_merge(
							$result,
							array(
								'requested_at'    => $requestedAt->format( 'c' ),
								'service_title'   => $service['title'],
								'service_version' => (int) $service['version'],
								'pricing_hash'    => Services::pricingHash( $service, Services::rules( (int) $service['id'] ) ),
								'extras_input'    => $extrasInput,
								'frequent_check'  => $verifiedId ? 'verified_by_contact' : null,
							)
						)
					),
					'policy'           => Db::json( Services::policySnapshot( (int) $service['id'] ) ),
					'answers'          => Db::json( $answers ),
					'contact'          => Db::json( $contact ),
					'consent'          => Db::json(
						array(
							'terms'          => ! empty( $input['accept_terms'] ) || 'admin' === $source,
							'whatsapp'       => ! empty( $input['whatsapp_consent'] ),
							'at'             => $now->format( 'c' ),
							'contract'       => $contract ? $contract['contract_title'] . ' v' . $contract['contract_version'] : '',
						)
					),
					'expires_at'       => Db::toSql( $holdUntil ),
					'deadline_at'      => Db::toSql( $holdUntil ),
					'idempotency_key'  => $key,
					'request_hash'     => $requestHash,
					'token_nonce'      => $nonce,
					'version'          => 1,
					'source'           => $source,
					'is_test'          => $isTest ? 1 : 0,
					'created_at'       => Db::toSql( $now ),
					'updated_at'       => Db::toSql( $now ),
				);
				Db::check( $wpdb->insert( Schema::table( 'bookings' ), $row ), 'booking_insert' );
				$id = (int) $wpdb->insert_id;
				Db::check(
					$wpdb->insert(
						Schema::table( 'occupancies' ),
						array(
							'resource_id' => $resource,
							'booking_id'  => $id,
							'start_utc'   => Db::toSql( $occupy->start ),
							'end_utc'     => Db::toSql( $occupy->end ),
							'kind'        => 'booking',
							'expires_at'  => Db::toSql( $holdUntil ),
							'created_at'  => Db::toSql( $now ),
						)
					),
					'occupancy_insert'
				);
				Tokens::register( $id, $nonce, self::tokenExpiry( $start ) );
				CalendarSync::enqueue( $id );
				if ( $googleOverride ) {
					Audit::log( 'booking', $id, 'google_override', array( 'reason' => 'Creada sin verificar Google (no respondió). Excepción manual de la administradora.' ) );
				}
				Audit::log(
					'booking',
					$id,
					'created',
					array(
						'status'       => $status,
						'source'       => $source,
						'total'        => $result['total_cents'],
						'client_match' => $client['matched'],
						'review'       => $client['review'],
					),
					'admin' === $source ? 'user' : 'client'
				);
				return array( self::get( $id ), true );
			}
		);

		list( $booking, $isNew ) = $created;
		if ( $isNew ) {
			Notifications::bookingEvent( 'booking_created', $booking );
			if ( BookingStatus::CONFIRMED === $booking['status'] ) {
				Notifications::scheduleReminder( $booking );
			}
		}
		return array(
			'booking'  => $booking,
			'token'    => Tokens::tokenFor( $booking['id'], $booking['token_nonce'] ),
			'replayed' => ! $isNew,
		);
	}

	public static function findByIdempotency( string $key ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'bookings' ) . ' WHERE idempotency_key = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function validateContact( array $input ): array {
		// 6.3 campo 7: sin espacios repetidos ni exteriores; ortografía y acentos intactos.
		$name = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ) );
		if ( mb_strlen( $name ) < 2 ) {
			throw new DomainException( 'invalid_name', 'Escribe tu nombre completo.', 400, array( 'field' => 'name' ) );
		}
		$email = Contact::normalizeEmail( (string) ( $input['email'] ?? '' ) );
		if ( ! $email ) {
			throw new DomainException( 'invalid_email', 'Escribe un correo válido.', 400, array( 'field' => 'email' ) );
		}
		$country = strtoupper( (string) ( $input['phone_country'] ?? 'MX' ) );
		if ( ! in_array( $country, array( 'MX', 'US' ), true ) ) {
			$country = 'MX';
		}
		$phoneRaw = trim( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) );
		$phone    = Contact::normalizePhone( $phoneRaw, $country );
		if ( ! $phone ) {
			throw new DomainException( 'invalid_phone', 'Revisa tu teléfono: escribe 10 dígitos o incluye el código de país con "+".', 400, array( 'field' => 'phone' ) );
		}
		// 6.3 campo 8: se busca por el correo normalizado, pero se conserva como lo escribió.
		$emailShown = trim( sanitize_email( (string) ( $input['email'] ?? '' ) ) );
		return array(
			'name'          => mb_substr( $name, 0, 190 ),
			'email'         => '' !== $emailShown ? $emailShown : $email,
			'email_norm'    => $email,
			'phone'         => mb_substr( $phoneRaw, 0, 40 ),
			'phone_country' => $country,
			'phone_e164'    => $phone,
		);
	}

	public const IMPORTANT_INFO_MAX = 2000;

	/** 6.3 campo 6: texto plano opcional, máximo 2,000 caracteres. */
	public static function validateImportantInfo( $value ): string {
		$text = trim( sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' ) );
		if ( mb_strlen( $text ) > self::IMPORTANT_INFO_MAX ) {
			throw new DomainException( 'info_too_long', 'La información importante puede tener hasta 2,000 caracteres.', 400, array( 'field' => 'important_info' ) );
		}
		return $text;
	}

	public static function validateAnswers( array $service, array $answers ): array {
		$out = array();
		foreach ( (array) $service['questions'] as $q ) {
			$value = $answers[ $q['key'] ] ?? '';
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value ) {
				if ( $q['required'] ) {
					throw new DomainException( 'answer_required', sprintf( 'Responde: %s', $q['label'] ), 400, array( 'field' => 'answers.' . $q['key'] ) );
				}
				continue;
			}
			switch ( $q['type'] ) {
				case 'number':
					if ( ! is_numeric( $value ) ) {
						throw new DomainException( 'invalid_answer', sprintf( '"%s" debe ser un número.', $q['label'] ), 400, array( 'field' => 'answers.' . $q['key'] ) );
					}
					break;
				case 'select':
					if ( ! in_array( $value, $q['options'], true ) ) {
						throw new DomainException( 'invalid_answer', sprintf( 'Elige una opción válida en "%s".', $q['label'] ), 400, array( 'field' => 'answers.' . $q['key'] ) );
					}
					break;
				case 'yesno':
					if ( ! in_array( $value, array( 'si', 'no' ), true ) ) {
						throw new DomainException( 'invalid_answer', sprintf( 'Responde sí o no en "%s".', $q['label'] ), 400, array( 'field' => 'answers.' . $q['key'] ) );
					}
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				default:
					$value = sanitize_text_field( $value );
			}
			// 6.4: instantánea de la pregunta; editarla o retirarla después no cambia esta respuesta.
			$out[ $q['key'] ] = array(
				'label'   => $q['label'],
				'type'    => $q['type'],
				'options' => (array) ( $q['options'] ?? array() ),
				'value'   => mb_substr( $value, 0, 2000 ),
			);
		}
		return $out;
	}

	// ----- Vencimientos -----

	/**
	 * Marca como vencidas las retenciones cuyo plazo terminó y libera su ocupación.
	 * A la hora exacta de vencimiento la reserva ya venció. Se llama al consultar,
	 * cotizar, reservar y desde la tarea programada; la disponibilidad no depende de que
	 * esto se ejecute, porque las ocupaciones vencidas ya no cuentan.
	 *
	 * @return int Reservas vencidas.
	 */
	public static function expireDue( int $limit = 50 ): int {
		$wpdb  = Db::wpdb();
		$now   = Plugin::clock()->now();
		$table = Schema::table( 'bookings' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, status, version FROM $table WHERE (status = %s AND expires_at IS NOT NULL AND expires_at <= %s) OR (status = %s AND correction_until IS NOT NULL AND correction_until <= %s) LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				BookingStatus::PENDING_PAYMENT,
				Db::toSql( $now ),
				BookingStatus::CORRECTION_REQUESTED,
				Db::toSql( $now ),
				$limit
			),
			ARRAY_A
		) ?: array();
		$count = 0;
		foreach ( $rows as $r ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status = %s, version = version + 1, updated_at = %s WHERE id = %d AND status = %s AND version = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					BookingStatus::EXPIRED,
					Db::toSql( $now ),
					(int) $r['id'],
					$r['status'],
					(int) $r['version']
				)
			);
			if ( $updated ) {
				self::releaseOccupancy( (int) $r['id'], $now );
				CalendarSync::enqueue( (int) $r['id'] );
				Audit::log( 'booking', (int) $r['id'], 'status:' . BookingStatus::EXPIRED, array( 'from' => $r['status'], 'reason' => 'Terminó el plazo de retención.' ), 'system', 0 );
				Notifications::cancelPending( (int) $r['id'] );
				Notifications::bookingEvent( 'booking_expired', self::get( (int) $r['id'] ) );
				++$count;
			}
		}
		return $count;
	}

	// ----- Pagos -----

	/**
	 * Registra un pago verificado por la administradora. Un comprobante no puede aprobarse dos veces.
	 *
	 * @param array $data {proof_id?, amount_cents, received_date (Y-m-d local), reference, method, confirm (bool), note, form_key}
	 */
	public static function approvePayment( int $bookingId, array $data ): array {
		$amount = (int) ( $data['amount_cents'] ?? 0 );
		if ( $amount <= 0 ) {
			throw new DomainException( 'invalid_amount', 'Indica el importe recibido.' );
		}
		$proofId = ! empty( $data['proof_id'] ) ? (int) $data['proof_id'] : null;
		$key     = $proofId ? 'proof:' . $proofId : 'manual:' . preg_replace( '/[^A-Za-z0-9-]/', '', (string) ( $data['form_key'] ?? '' ) );
		if ( ! $proofId && strlen( $key ) < 20 ) {
			throw new DomainException( 'invalid_request', 'Recarga la página e intenta de nuevo.' );
		}
		$receivedAt = self::localDateToUtc( (string) ( $data['received_date'] ?? '' ) );
		$now        = Plugin::clock()->now();

		$result = Db::transaction(
			static function () use ( $bookingId, $amount, $proofId, $key, $receivedAt, $data, $now ) {
				$booking = self::get( $bookingId, true );
				$wpdb    = Db::wpdb();
				if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'payments' ) . ' WHERE idempotency_key = %s', $key ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					throw new DomainException( 'already_recorded', 'Este pago ya se registró. No se duplicó.', 409 );
				}
				if ( $proofId ) {
					$proof = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'proofs' ) . ' WHERE id = %d AND booking_id = %d FOR UPDATE', $proofId, $bookingId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! $proof ) {
						throw new DomainException( 'not_found', 'El comprobante no pertenece a esta reserva.', 404 );
					}
					if ( 'approved' === $proof['status'] ) {
						throw new DomainException( 'already_recorded', 'Este comprobante ya se aprobó.', 409 );
					}
					$wpdb->update(
						Schema::table( 'proofs' ),
						array(
							'status'      => 'approved',
							'reviewed_by' => get_current_user_id(),
							'reviewed_at' => Db::toSql( $now ),
							'review_note' => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
						),
						array( 'id' => $proofId )
					);
				}
				Db::check(
					$wpdb->insert(
						Schema::table( 'payments' ),
						array(
							'booking_id'      => $bookingId,
							'proof_id'        => $proofId,
							'type'            => 'payment',
							'amount_cents'    => $amount,
							'method'          => in_array( $data['method'] ?? '', array( 'transfer', 'cash', 'other' ), true ) ? $data['method'] : 'transfer',
							'reference'       => sanitize_text_field( (string) ( $data['reference'] ?? '' ) ),
							'received_at'     => Db::toSql( $receivedAt ),
							'category_id'     => $booking['category_id'],
							'note'            => sanitize_text_field( (string) ( $data['note'] ?? '' ) ),
							'actor_id'        => get_current_user_id(),
							'idempotency_key' => $key,
							'created_at'      => Db::toSql( $now ),
						)
					),
					'payment_insert'
				);
				$paymentId = (int) $wpdb->insert_id;
				Audit::log( 'booking', $bookingId, 'payment_approved', array( 'payment_id' => $paymentId, 'amount' => $amount, 'proof_id' => $proofId ) );

				$summary  = self::summary( $booking );
				$event    = 'payment_approved';
				$waiting  = array( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW, BookingStatus::UNDER_REVIEW, BookingStatus::CORRECTION_REQUESTED, BookingStatus::PENDING_APPROVAL );
				$deadline = Db::fromSql( $booking['deadline_at'] ) ?: Db::fromSql( $booking['expires_at'] );
				if ( in_array( $booking['status'], $waiting, true ) ) {
					if ( $summary['deposit_covered'] ) {
						// Decisión 2: aprobar el anticipo completo confirma la cita.
						self::transition( $booking, BookingStatus::CONFIRMED, 'Anticipo verificado.', array( 'expires_at' => null, 'correction_until' => null ) );
						self::holdIndefinitely( $bookingId );
						$event = 'booking_confirmed';
					} elseif ( $deadline && $deadline > $now ) {
						// Decisión 4: pago parcial; sigue pendiente con la hora límite original.
						$fields = array( 'expires_at' => Db::toSql( $deadline ), 'correction_until' => null );
						if ( BookingStatus::PENDING_PAYMENT === $booking['status'] ) {
							self::update( $booking, $fields );
						} else {
							self::transition( $booking, BookingStatus::PENDING_PAYMENT, sprintf( 'Pago parcial de %s; faltan %s antes de la hora límite original.', Money::format( $amount ), Money::format( $summary['deposit_missing'] ) ), $fields );
						}
						self::setOccupancyExpiry( $bookingId, $deadline );
						$event = null; // La administradora avisa a la persona.
					} else {
						// P4: pago parcial revisado cuando la hora límite ya pasó.
						self::transition( $booking, BookingStatus::EXPIRED, 'Pago parcial y la hora límite original ya pasó.', array( 'expires_at' => null, 'correction_until' => null ) );
						self::releaseOccupancy( $bookingId, $now );
						Audit::log( 'booking', $bookingId, 'reconciliation_needed', array( 'reason' => 'Dinero recibido en una reserva vencida: decide devolución o abono a una nueva reserva.' ) );
						$event = 'booking_expired';
					}
				} elseif ( ! BookingStatus::isOpen( $booking['status'] ) ) {
					Audit::log( 'booking', $bookingId, 'reconciliation_needed', array( 'reason' => 'Pago registrado en una reserva que ya no está activa. El horario no se recupera.' ) );
					$event = null;
				}
				// El pago puede habilitar la invitación de una cita ya confirmada (SEG-03).
				CalendarSync::enqueue( $bookingId );
				return array( 'payment_id' => $paymentId, 'event' => $event );
			}
		);
		$booking = self::get( $bookingId );
		if ( $result['event'] ) {
			Notifications::bookingEvent( $result['event'], $booking, array( 'amount_cents' => $amount, 'payment_id' => $result['payment_id'] ) );
		}
		return $booking;
	}

	/**
	 * DEC-01: pide una sola sustitución del comprobante, con un motivo de la lista
	 * (lo ve la persona) y una nota interna opcional (no la ve). El horario sigue
	 * apartado durante el plazo de corrección (2 horas por defecto).
	 */
	public static function requestCorrection( int $bookingId, ?int $proofId, string $reasonKey, string $internalNote = '', string $otherReason = '', string $exceptionReason = '' ): array {
		// 7.3: motivo comprensible para la persona: uno de la lista o uno escrito ("Otro").
		$reason = 'otro' === $reasonKey ? trim( sanitize_text_field( $otherReason ) ) : Settings::correctionReason( $reasonKey );
		if ( null === $reason || '' === $reason ) {
			throw new DomainException( 'reason_required', 'otro' === $reasonKey ? 'Escribe el motivo que verá la persona.' : 'Elige el motivo de la corrección.' );
		}
		$exceptionReason = trim( sanitize_text_field( $exceptionReason ) );
		$now   = Plugin::clock()->now();
		$until = $now->modify( sprintf( '+%d minutes', (int) round( (float) Settings::get( 'correction_hours' ) * 60 ) ) );
		Db::transaction(
			static function () use ( $bookingId, $proofId, $reason, $internalNote, $now, $until, $exceptionReason ) {
				$booking = self::get( $bookingId, true );
				if ( (int) $booking['corrections_used'] >= 1 ) {
					if ( '' === $exceptionReason ) {
						throw new DomainException( 'correction_used', 'Esta reserva ya tuvo su corrección. Para dar otra oportunidad escribe el motivo de la excepción.', 409 );
					}
					Audit::log( 'booking', $bookingId, 'correction_exception', array( 'reason' => $exceptionReason, 'number' => (int) $booking['corrections_used'] + 1 ) );
				}
				if ( $proofId ) {
					self::reviewProof( $bookingId, $proofId, 'correction', $internalNote, $now, $reason );
				}
				self::transition(
					$booking,
					BookingStatus::CORRECTION_REQUESTED,
					$reason,
					array(
						'correction_until' => Db::toSql( $until ),
						'expires_at'       => null,
						'corrections_used' => (int) $booking['corrections_used'] + 1,
					)
				);
				self::setOccupancyExpiry( $bookingId, $until );
				if ( '' !== trim( $internalNote ) ) {
					Audit::log( 'booking', $bookingId, 'internal_note', array( 'note' => sanitize_textarea_field( $internalNote ) ) );
				}
			}
		);
		$booking = self::get( $bookingId );
		Notifications::bookingEvent( 'correction_requested', $booking, array( 'reason' => $reason ) );
		return $booking;
	}

	/** La administradora abrió o asumió la revisión del comprobante. */
	public static function startReview( int $bookingId ): void {
		$booking = self::find( $bookingId );
		if ( $booking && BookingStatus::PAYMENT_REVIEW === $booking['status'] ) {
			try {
				self::transition( $booking, BookingStatus::UNDER_REVIEW, 'Revisión iniciada.' );
			} catch ( DomainException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Otra pestaña ya la inició: no pasa nada.
			}
		}
	}

	private static function reviewProof( int $bookingId, int $proofId, string $status, string $note, \DateTimeImmutable $now, string $clientReason = '' ): void {
		$wpdb  = Db::wpdb();
		$proof = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'proofs' ) . ' WHERE id = %d AND booking_id = %d FOR UPDATE', $proofId, $bookingId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $proof ) {
			throw new DomainException( 'not_found', 'El comprobante no pertenece a esta reserva.', 404 );
		}
		if ( 'approved' === $proof['status'] ) {
			throw new DomainException( 'invalid_transition', 'Ese comprobante ya fue aprobado; para corregir el dinero registra un ajuste.', 409 );
		}
		$wpdb->update(
			Schema::table( 'proofs' ),
			array(
				'status'        => $status,
				'review_note'   => mb_substr( sanitize_text_field( $note ), 0, 255 ),
				'client_reason' => mb_substr( $clientReason, 0, 190 ),
				'reviewed_by' => get_current_user_id(),
				'reviewed_at' => Db::toSql( $now ),
			),
			array( 'id' => $proofId )
		);
		Audit::log( 'booking', $bookingId, 'proof_' . $status, array( 'proof_id' => $proofId, 'reason' => $clientReason ) );
	}

	/**
	 * Registra un movimiento manual: devolución realizada, devolución pendiente, crédito o reverso.
	 */
	public static function addMovement( int $bookingId, string $type, int $amount, string $note, string $formKey, ?int $reversesId = null, string $reference = '' ): void {
		if ( ! in_array( $type, array( 'refund_due', 'refund_done', 'credit', 'reversal', 'retention', 'penalty' ), true ) ) {
			throw new DomainException( 'invalid_movement', 'Tipo de movimiento no válido.' );
		}
		if ( $amount <= 0 ) {
			throw new DomainException( 'invalid_amount', 'El importe debe ser mayor que cero.' );
		}
		if ( '' === trim( $note ) ) {
			throw new DomainException( 'reason_required', 'Indica el motivo del movimiento.' );
		}
		$key = 'mov:' . preg_replace( '/[^A-Za-z0-9-]/', '', $formKey );
		if ( strlen( $key ) < 20 ) {
			throw new DomainException( 'invalid_request', 'Recarga la página e intenta de nuevo.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $type, $amount, $note, $key, $reversesId, $reference ) {
				$booking = self::get( $bookingId, true );
				if ( 'reversal' === $type ) {
					$target = null;
					foreach ( self::movements( $bookingId ) as $m ) {
						if ( (int) $m['id'] === (int) $reversesId ) {
							$target = $m;
						}
						if ( 'reversal' === $m['type'] && (int) $m['reverses_id'] === (int) $reversesId ) {
							throw new DomainException( 'already_recorded', 'Ese movimiento ya fue revertido.', 409 );
						}
					}
					if ( ! $target || 'reversal' === $target['type'] ) {
						throw new DomainException( 'not_found', 'El movimiento a revertir no existe.', 404 );
					}
					$amount = (int) $target['amount_cents'];
				}
				if ( 'refund_done' === $type ) {
					$summary = self::summary( $booking );
					if ( $amount > $summary['refund_due_cents'] ) {
						throw new DomainException( 'invalid_amount', 'Primero registra la devolución pendiente; el importe supera lo que se debe devolver.' );
					}
				}
				self::insertMovement( $booking, $type, $amount, $key, $note, $reversesId, $reference );
			}
		);
	}

	private static function insertMovement( array $booking, string $type, int $amount, string $key, string $note, ?int $reversesId = null, string $reference = '' ): void {
		$wpdb = Db::wpdb();
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'payments' ) . ' WHERE idempotency_key = %s', $key ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			throw new DomainException( 'already_recorded', 'Este movimiento ya se registró.', 409 );
		}
		$now = Plugin::clock()->now();
		Db::check(
			$wpdb->insert(
				Schema::table( 'payments' ),
				array(
					'booking_id'      => $booking['id'],
					'type'            => $type,
					'amount_cents'    => $amount,
					'method'          => 'transfer',
					'reference'       => sanitize_text_field( $reference ),
					'received_at'     => in_array( $type, array( 'refund_done' ), true ) ? Db::toSql( $now ) : null,
					'reverses_id'     => $reversesId,
					'category_id'     => $booking['category_id'],
					'note'            => mb_substr( sanitize_text_field( $note ), 0, 255 ),
					'actor_id'        => get_current_user_id(),
					'idempotency_key' => $key,
					'created_at'      => Db::toSql( $now ),
				)
			),
			'movement_insert'
		);
		Audit::log( 'booking', $booking['id'], 'movement:' . $type, array( 'amount' => $amount, 'note' => $note ) );
	}

	// ----- Transiciones administrativas -----

	public static function confirm( int $bookingId, string $override = '' ): array {
		Db::transaction(
			static function () use ( $bookingId, $override ) {
				$booking = self::get( $bookingId, true );
				$summary = self::summary( $booking );
				if ( ! $summary['deposit_covered'] && '' === trim( $override ) ) {
					throw new DomainException( 'deposit_missing', sprintf( 'Falta %s del anticipo. Para confirmar sin cubrirlo escribe el motivo de la excepción.', Money::format( $summary['deposit_missing'] ) ) );
				}
				self::transition( $booking, BookingStatus::CONFIRMED, '' !== trim( $override ) ? 'Excepción: ' . $override : 'Cita aprobada.', array( 'expires_at' => null, 'correction_until' => null ) );
				self::holdIndefinitely( $bookingId );
				if ( ! $summary['deposit_covered'] ) {
					// SEG-03: la excepción queda auditada y NO invita al cliente.
					Audit::log( 'booking', $bookingId, 'confirmed_by_exception', array( 'reason' => sanitize_textarea_field( $override ), 'deposit_missing' => $summary['deposit_missing'] ) );
				}
			}
		);
		$booking = self::get( $bookingId );
		Notifications::bookingEvent( 'booking_confirmed', $booking );
		return $booking;
	}

	/**
	 * Rechazo final: libera el horario de inmediato. El motivo que ve la persona sale de
	 * la lista editable; la nota interna nunca se le envía.
	 */
	public static function reject( int $bookingId, string $reasonKey, string $internalNote = '' ): array {
		$reason = Settings::correctionReason( $reasonKey );
		if ( null === $reason ) {
			throw new DomainException( 'reason_required', 'Elige el motivo del rechazo.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $reason, $internalNote ) {
				$booking = self::get( $bookingId, true );
				$now     = Plugin::clock()->now();
				foreach ( self::proofs( $bookingId ) as $proof ) {
					if ( in_array( $proof['status'], array( 'pending', 'correction' ), true ) ) {
						self::reviewProof( $bookingId, (int) $proof['id'], 'rejected', $internalNote, $now, $reason );
					}
				}
				self::transition( $booking, BookingStatus::REJECTED, $reason, array( 'expires_at' => null, 'correction_until' => null ) );
				self::releaseOccupancy( $bookingId, $now );
				if ( '' !== trim( $internalNote ) ) {
					Audit::log( 'booking', $bookingId, 'internal_note', array( 'note' => sanitize_textarea_field( $internalNote ) ) );
				}
			}
		);
		Notifications::cancelPending( $bookingId );
		$booking = self::get( $bookingId );
		Notifications::bookingEvent( 'booking_rejected', $booking, array( 'reason' => $reason ) );
		return $booking;
	}

	/**
	 * Vista previa económica de cancelar, marcar inasistencia o reprogramar según la política
	 * que aceptó la persona. Cancelación del negocio: sin penalización y devolución completa.
	 */
	public static function previewPolicy( array $booking, string $action, bool $byBusiness = false, ?array $newQuote = null ): array {
		$now     = Plugin::clock()->now();
		$summary = self::summary( $booking );
		$paid    = $summary['applied_cents'];
		$data    = array(
			'total_cents'   => $booking['total_cents'],
			'base_cents'    => (int) ( $booking['quote']['base_cents'] ?? $booking['total_cents'] ),
			'deposit_cents' => $booking['deposit_cents'],
			'paid_cents'    => max( 0, $paid ),
		);
		if ( $byBusiness ) {
			$range = array(
				'min_hours'         => 0,
				'max_hours'         => null,
				'charge_type'       => 'none',
				'charge_value'      => 0,
				'charge_base'       => 'total',
				'deposit'           => 'refund',
				'new_deposit_cents' => 0,
				'allowed'           => true,
			);
			$policy = null;
		} else {
			$policy = $booking['policy'][ $action ] ?? null;
			if ( ! $policy ) {
				$range = array(
					'min_hours'         => 0,
					'max_hours'         => null,
					'charge_type'       => 'none',
					'charge_value'      => 0,
					'charge_base'       => 'total',
					'deposit'           => 'credit',
					'new_deposit_cents' => 0,
					'allowed'           => true,
				);
			} else {
				$range = Policy::rangeFor( $policy['ranges'], $booking['start']->getTimestamp() - $now->getTimestamp() );
			}
		}
		$outcome                   = Policy::outcome( $action, $range, $data, $newQuote );
		$outcome['seconds_before'] = $booking['start']->getTimestamp() - $now->getTimestamp();
		$outcome['policy_version'] = $policy['version'] ?? null;
		$outcome['has_policy']     = (bool) $policy || $byBusiness;
		$outcome['by_business']    = $byBusiness;
		$outcome['range_text']     = $byBusiness ? 'Cancelación por parte del negocio: sin penalización.' : ( $policy ? Policy::describeRange( $range ) : 'La reserva no tiene política aceptada para esta acción; no se aplica cargo automático.' );
		return $outcome;
	}

	public const CANCEL_OUTCOMES = array(
		'policy' => 'Según la política aceptada',
		'lost'   => 'El anticipo se pierde',
		'credit' => 'Se acredita a favor de la persona',
		'manual' => 'Requiere resolución manual (por decidir)',
	);

	/**
	 * 7.7: cancela con motivo y actor, libera el horario y retira los eventos propios. No
	 * borra pagos, comprobantes, contrato ni historial; registra qué pasa con el anticipo.
	 *
	 * @param string $depositOutcome policy | lost | credit | manual
	 */
	public static function cancel( int $bookingId, bool $byBusiness, string $reason, int $expectedVersion, string $depositOutcome = 'policy', bool $notify = true ): array {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			throw new DomainException( 'reason_required', 'Indica el motivo de la cancelación.' );
		}
		if ( ! isset( self::CANCEL_OUTCOMES[ $depositOutcome ] ) ) {
			throw new DomainException( 'invalid_outcome', 'Indica qué pasa con el anticipo.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $byBusiness, $reason, $expectedVersion, $depositOutcome ) {
				$booking = self::get( $bookingId, true );
				self::assertVersion( $booking, $expectedVersion );
				$outcome = self::cancelOutcome( $booking, $byBusiness, $depositOutcome );
				self::transition( $booking, BookingStatus::CANCELLED, ( $byBusiness ? 'Cancelada por el negocio: ' : 'Cancelada: ' ) . $reason, array( 'expires_at' => null, 'correction_until' => null, 'cancel_outcome' => $depositOutcome ) );
				self::applyOutcomeMovements( $booking, $outcome, 'cancel' );
				self::releaseOccupancy( $bookingId, Plugin::clock()->now() );
				Audit::log( 'booking', $bookingId, 'cancel_outcome', array_merge( self::outcomeForAudit( $outcome ), array( 'deposit' => $depositOutcome, 'reason' => $reason ) ) );
				if ( 'manual' === $depositOutcome && $outcome['paid_cents'] > 0 ) {
					Audit::log( 'booking', $bookingId, 'reconciliation_needed', array( 'reason' => 'Cancelada con anticipo por resolver: decide devolución, crédito o retención.' ) );
				}
			}
		);
		Notifications::cancelPending( $bookingId );
		$booking = self::get( $bookingId );
		if ( $notify ) {
			Notifications::bookingEvent( 'booking_cancelled', $booking, array( 'reason' => $reason ) );
		}
		return $booking;
	}

	/** Consecuencia económica de cancelar según la decisión de la administradora. */
	public static function cancelOutcome( array $booking, bool $byBusiness, string $depositOutcome ): array {
		$outcome = self::previewPolicy( $booking, 'cancel', $byBusiness );
		$paid    = max( 0, (int) self::summary( $booking )['applied_cents'] );
		$outcome['paid_cents'] = $paid;
		if ( 'policy' === $depositOutcome ) {
			return $outcome;
		}
		$outcome = array_merge(
			$outcome,
			array(
				'retained'          => 'lost' === $depositOutcome ? $paid : 0,
				'credit'            => 'credit' === $depositOutcome ? $paid : 0,
				'refund_due'        => 0,
				'penalty'           => 0,
				'penalty_from_paid' => 0,
				'penalty_due'       => 0,
			)
		);
		return $outcome;
	}

	// ----- Archivo y eliminación (7.8, 12.3) -----

	/** Archivar es solo visibilidad: no cambia estado, pago ni vigencia. */
	public static function setArchived( int $bookingId, bool $archived ): array {
		$booking = self::get( $bookingId );
		Db::wpdb()->update( Schema::table( 'bookings' ), array( 'archived_at' => $archived ? Db::toSql( Plugin::clock()->now() ) : null ), array( 'id' => $bookingId ) );
		Audit::log( 'booking', $bookingId, $archived ? 'archived' : 'restored', array( 'status' => $booking['status'] ) );
		return self::get( $bookingId );
	}

	/** Lo que impide borrar definitivamente una reserva. Vacío = se puede borrar. */
	public static function deleteBlockers( array $booking ): array {
		$wpdb     = Db::wpdb();
		$blockers = array();
		if ( ! $booking['is_test'] ) {
			$blockers[] = 'No es una reserva de prueba (las reservas reales se cancelan o archivan).';
		}
		$payments = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d', $booking['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $payments ) {
			$blockers[] = sprintf( '%d movimiento(s) de dinero.', $payments );
		}
		$proofs = count( self::proofs( $booking['id'] ) );
		if ( $proofs ) {
			$blockers[] = sprintf( '%d comprobante(s).', $proofs );
		}
		if ( ! empty( $booking['contract_accepted_at'] ) ) {
			$blockers[] = 'Contrato aceptado por la persona.';
		}
		$sent = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND status IN ('accepted', 'sent_manual', 'opened_manual')", $booking['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $sent ) {
			$blockers[] = sprintf( '%d mensaje(s) enviados o abiertos para envío.', $sent );
		}
		return $blockers;
	}

	/**
	 * 12.3: elimina definitivamente una reserva de prueba sin dependencias. Borra solo sus
	 * eventos propios, tokens, ocupación y avisos pendientes; la bitácora conserva el registro.
	 */
	public static function deleteTest( int $bookingId, string $confirm ): void {
		$booking  = self::get( $bookingId );
		$blockers = self::deleteBlockers( $booking );
		if ( $blockers ) {
			throw new DomainException( 'has_dependencies', 'No se puede eliminar: ' . implode( ' ', $blockers ), 409, array( 'blockers' => $blockers ) );
		}
		if ( $booking['public_ref'] !== trim( $confirm ) ) {
			throw new DomainException( 'confirm_required', 'Para eliminar escribe la referencia exacta de la reserva (' . $booking['public_ref'] . ').' );
		}
		$wpdb = Db::wpdb();
		self::removeGoogleEvents( $bookingId );
		Db::transaction(
			static function () use ( $wpdb, $bookingId, $booking ) {
				foreach ( array( 'occupancies', 'access_tokens', 'portal_sessions', 'notifications', 'calendar_events', 'sync_jobs', 'conflicts' ) as $t ) {
					$wpdb->delete( Schema::table( $t ), array( 'booking_id' => $bookingId ) );
				}
				$wpdb->delete( Schema::table( 'bookings' ), array( 'id' => $bookingId ) );
				Audit::log( 'booking', $bookingId, 'deleted', array( 'ref' => $booking['public_ref'], 'is_test' => true ) );
			}
		);
	}

	/**
	 * Retira de Google los eventos propios de la reserva (interno y del cliente). Si Google
	 * falla por otra razón que "ya no existe", no se borra nada en el sitio.
	 */
	public static function removeGoogleEvents( int $bookingId, bool $notifyGuests = true ): void {
		if ( ! \MOBookingStudio\Integrations\Google\Connection::isActive() ) {
			return;
		}
		foreach ( \MOBookingStudio\Integrations\Google\SyncWorker::eventsFor( $bookingId ) as $row ) {
			if ( 'active' !== $row['state'] ) {
				continue;
			}
			try {
				( new \MOBookingStudio\Integrations\Google\Api() )->deleteEvent( $row['calendar_id'], $row['event_id'], 'client' === $row['kind'] && $notifyGuests ? 'all' : 'none' );
			} catch ( \MOBookingStudio\Integrations\Google\GoogleException $e ) {
				if ( ! in_array( $e->type, array( 'not_found', 'gone' ), true ) ) {
					throw new DomainException( 'google_unavailable', 'No se pudo retirar su evento de Google; intenta de nuevo. No se eliminó nada.', 503 );
				}
			}
			Db::wpdb()->update( Schema::table( 'calendar_events' ), array( 'state' => 'deleted', 'synced_at' => Db::toSql( Plugin::clock()->now() ) ), array( 'id' => $row['id'] ) );
		}
	}

	// ----- Edición administrativa (12.2) -----

	/**
	 * Calcula el efecto de editar datos, asistentes o extras sin guardar nada.
	 *
	 * @return array{changes:array,quote:?array,fields:array,contact:array}
	 */
	public static function previewEdit( array $booking, array $data ): array {
		$contact = self::validateContact( array_merge( (array) $booking['contact'], array_intersect_key( $data, array_flip( array( 'name', 'email', 'phone', 'phone_country' ) ) ) ) );
		$info    = self::validateImportantInfo( $data['important_info'] ?? $booking['important_info'] );
		$service = Services::get( $booking['service_id'] );
		$extras  = (array) ( $booking['quote']['extras_input'] ?? array() );
		if ( isset( $data['extras'] ) ) {
			$extras = (array) $data['extras'];
		}
		if ( array_key_exists( 'fast_delivery', $data ) ) {
			unset( $extras[ Services::FAST_KEY ] );
			if ( ! empty( $data['fast_delivery'] ) ) {
				$extras[ Services::FAST_KEY ] = 1;
			}
		}
		$input = array(
			'children' => $data['children'] ?? ( null === $booking['children'] ? null : (string) $booking['children'] ),
			'adults'   => $data['adults'] ?? ( null === $booking['adults'] ? null : (string) $booking['adults'] ),
			'pets'     => $data['pets'] ?? (string) $booking['pets'],
			'extras'   => $extras,
		);
		if ( null === $input['children'] && null === $input['adults'] ) {
			$input['participants'] = $booking['participants'];
		}
		$requestedAt = new \DateTimeImmutable( (string) ( $booking['quote']['requested_at'] ?? $booking['created_at'] ) );
		$context     = Catalog::pricingContext( $input, $booking['start'], $requestedAt, $booking['client_category'] );
		$quote       = ( new Pricing( Settings::timezone() ) )->quote( Services::forPricing( $service, $booking['quote'] ), Services::rules( (int) $service['id'] ), $context );

		$changes = array();
		$compare = array(
			'Nombre'                 => array( $booking['contact']['name'] ?? '', $contact['name'] ),
			'Correo'                 => array( $booking['contact']['email'] ?? '', $contact['email'] ),
			'Teléfono'               => array( $booking['contact']['phone'] ?? '', $contact['phone'] ),
			'Información importante' => array( $booking['important_info'], $info ),
			'Niños'                  => array( (string) $booking['children'], (string) $context['children'] ),
			'Adultos'                => array( (string) $booking['adults'], (string) $context['adults'] ),
			'Mascotas'               => array( (string) $booking['pets'], (string) $context['pets'] ),
			'Extras'                 => array( wp_json_encode( (object) ( $booking['quote']['extras_input'] ?? array() ) ), wp_json_encode( (object) $context['extras'] ) ),
			'Total'                  => array( (string) $booking['total_cents'], (string) $quote['total_cents'] ),
			'Anticipo'               => array( (string) $booking['deposit_cents'], (string) $quote['deposit_cents'] ),
		);
		foreach ( $compare as $label => $pair ) {
			if ( (string) $pair[0] !== (string) $pair[1] ) {
				$changes[ $label ] = array( 'from' => $pair[0], 'to' => $pair[1] );
			}
		}
		$economic = isset( $changes['Total'] ) || isset( $changes['Anticipo'] ) || isset( $changes['Extras'] ) || isset( $changes['Niños'] ) || isset( $changes['Adultos'] ) || isset( $changes['Mascotas'] );
		return array(
			'changes'  => $changes,
			'quote'    => $economic ? $quote : null,
			'contact'  => $contact,
			'info'     => $info,
			'context'  => $context,
			'economic' => $economic,
		);
	}

	/**
	 * Aplica la edición: una nueva cotización o ajuste (sin tocar movimientos), valores
	 * anteriores y nuevos en la bitácora y sincronización idempotente con Google.
	 */
	public static function applyEdit( int $bookingId, array $data, int $expectedVersion ): array {
		Db::transaction(
			static function () use ( $bookingId, $data, $expectedVersion ) {
				$booking = self::get( $bookingId, true );
				self::assertVersion( $booking, $expectedVersion );
				if ( ! BookingStatus::isOpen( $booking['status'] ) ) {
					throw new DomainException( 'invalid_transition', 'Solo se editan reservas activas.', 409 );
				}
				$p = self::previewEdit( $booking, $data );
				if ( ! $p['changes'] ) {
					throw new DomainException( 'no_changes', 'No hay cambios que guardar.' );
				}
				$fields = array(
					'contact'        => Db::json( array_merge( (array) $booking['contact'], $p['contact'] ) ),
					'important_info' => $p['info'],
				);
				if ( $p['economic'] ) {
					$fields = array_merge(
						$fields,
						array(
							'participants'  => $p['quote']['participants'],
							'pets'          => $p['quote']['pets'],
							'children'      => $p['context']['children'],
							'adults'        => $p['context']['adults'],
							'total_cents'   => $p['quote']['total_cents'],
							'deposit_cents' => $p['quote']['deposit_cents'],
							'quote'         => Db::json(
								array_merge(
									$booking['quote'],
									$p['quote'],
									array(
										'extras_input' => $p['context']['extras'],
										'previous'     => array( 'total' => $booking['total_cents'], 'deposit' => $booking['deposit_cents'], 'lines' => $booking['quote']['lines'] ?? array() ),
									)
								)
							),
						)
					);
				}
				self::update( $booking, $fields );
				Audit::log( 'booking', $bookingId, 'edited', array( 'changes' => $p['changes'], 'reason' => (string) ( $data['reason'] ?? '' ) ) );
			}
		);
		return self::get( $bookingId );
	}

	public static function markNoShow( int $bookingId, int $expectedVersion ): array {
		Db::transaction(
			static function () use ( $bookingId, $expectedVersion ) {
				$booking = self::get( $bookingId, true );
				self::assertVersion( $booking, $expectedVersion );
				$outcome = self::previewPolicy( $booking, 'no_show' );
				self::transition( $booking, BookingStatus::NO_SHOW, 'La persona no asistió.' );
				self::applyOutcomeMovements( $booking, $outcome, 'no_show' );
				Audit::log( 'booking', $bookingId, 'no_show_outcome', self::outcomeForAudit( $outcome ) );
			}
		);
		Notifications::cancelPending( $bookingId );
		return self::get( $bookingId );
	}

	public static function complete( int $bookingId ): array {
		Db::transaction(
			static function () use ( $bookingId ) {
				$booking = self::get( $bookingId, true );
				self::transition( $booking, BookingStatus::COMPLETED, 'Sesión realizada.' );
				if ( ! $booking['session_counted'] ) {
					Clients::adjustCompleted( $booking['client_id'], 1, 'Reserva ' . $booking['public_ref'] . ' completada.' );
					Db::wpdb()->update( Schema::table( 'bookings' ), array( 'session_counted' => 1 ), array( 'id' => $bookingId ) );
				}
			}
		);
		Notifications::cancelPending( $bookingId );
		return self::get( $bookingId );
	}

	/** Revierte "completada" o "inasistencia" a confirmada, con historial. */
	public static function reopen( int $bookingId, string $reason ): array {
		if ( '' === trim( $reason ) ) {
			throw new DomainException( 'reason_required', 'Indica el motivo.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $reason ) {
				$booking = self::get( $bookingId, true );
				self::transition( $booking, BookingStatus::CONFIRMED, 'Reabierta: ' . $reason );
				if ( $booking['session_counted'] ) {
					Clients::adjustCompleted( $booking['client_id'], -1, 'Reserva ' . $booking['public_ref'] . ' reabierta: ' . $reason );
					Db::wpdb()->update( Schema::table( 'bookings' ), array( 'session_counted' => 0 ), array( 'id' => $bookingId ) );
				}
			}
		);
		return self::get( $bookingId );
	}

	/**
	 * Vista previa de reprogramar a un nuevo horario. Conserva la categoría y la fecha de
	 * solicitud original para que la campaña siga aplicando si es elegible.
	 */
	public static function previewReschedule( array $booking, \DateTimeImmutable $newStart, ?array $externalBusy = null ): array {
		$service = Services::find( $booking['service_id'] );
		if ( ! $service ) {
			throw new DomainException( 'not_found', 'El servicio de la reserva ya no existe.', 404 );
		}
		$now  = Plugin::clock()->now();
		// CAL-10: antes de una nueva fecha se repite la comprobación completa.
		$externalBusy = $externalBusy ?? Catalog::freshGoogleBusy( $service, $newStart );
		$slot         = Catalog::findAvailableSlot( $service, $newStart, $now, $booking['id'], true, $externalBusy );
		if ( ! $slot ) {
			throw new DomainException( 'slot_unavailable', 'Ese horario no está disponible.', 409 );
		}
		$requestedAt = new \DateTimeImmutable( (string) ( $booking['quote']['requested_at'] ?? $booking['created_at'] ) );
		$newQuote    = ( new Pricing( Settings::timezone() ) )->quote(
			Services::forPricing( $service, $booking['quote'] ),
			Services::rules( (int) $service['id'] ),
			array(
				'start'        => $newStart,
				'requested_at' => $requestedAt,
				'category'     => $booking['client_category'],
				'participants' => $booking['participants'],
				'pets'         => $booking['pets'],
				'extras'       => (array) ( $booking['quote']['extras_input'] ?? array() ),
			)
		);
		$outcome           = self::previewPolicy( $booking, 'reschedule', false, $newQuote );
		$outcome['quote']  = $newQuote;
		$outcome['slot']   = $slot;
		return $outcome;
	}

	public static function reschedule( int $bookingId, \DateTimeImmutable $newStart, string $reason, int $expectedVersion ): array {
		$reason = trim( sanitize_textarea_field( $reason ) );
		if ( '' === $reason ) {
			throw new DomainException( 'reason_required', 'Indica el motivo del cambio.' );
		}
		$now          = Plugin::clock()->now();
		$externalBusy = Catalog::freshGoogleBusy( Services::get( self::get( $bookingId )['service_id'] ), $newStart );
		Db::transaction(
			static function () use ( $bookingId, $newStart, $reason, $expectedVersion, $now, $externalBusy ) {
				$booking = self::get( $bookingId, true );
				self::assertVersion( $booking, $expectedVersion );
				if ( ! BookingStatus::isOpen( $booking['status'] ) ) {
					throw new DomainException( 'invalid_transition', 'Solo se pueden reprogramar reservas activas.', 409 );
				}
				$service  = Services::get( $booking['service_id'] );
				$params   = Services::availabilityParams( $service );
				$occupy   = Availability::occupancyFor( $newStart, $params );
				$oldOcc   = new Interval( $booking['start']->modify( sprintf( '-%d minutes', (int) $service['buffer_before'] ) ), $booking['end']->modify( sprintf( '+%d minutes', (int) $service['buffer_after'] ) ) );
				self::lockDays( $booking['resource_id'], array_merge( $occupy->utcDays(), $oldOcc->utcDays() ) );

				$outcome = self::previewReschedule( $booking, $newStart, $externalBusy );
				if ( empty( $outcome['allowed'] ) ) {
					throw new DomainException( 'not_allowed', 'La política no permite reprogramar con esta anticipación.', 409 );
				}
				$quote = $outcome['quote'];
				self::applyOutcomeMovements( $booking, $outcome, 'reschedule' );

				$needsPayment = $outcome['amount_due_now'] > 0;
				$holdUntil    = $needsPayment ? $now->modify( sprintf( '+%d minutes', (int) round( Services::holdHours( $service ) * 60 ) ) ) : null;
				$old          = array( 'start' => $booking['start_utc'], 'total' => $booking['total_cents'] );
				$fields       = array(
					'start_utc'     => Db::toSql( $newStart ),
					'end_utc'       => Db::toSql( $outcome['slot']['end'] ),
					'total_cents'   => $quote['total_cents'],
					'deposit_cents' => (int) $outcome['new_deposit'],
					'quote'         => Db::json( array_merge( $booking['quote'], $quote, array( 'previous' => $old ) ) ),
				);
				if ( $needsPayment ) {
					$fields['expires_at']       = Db::toSql( $holdUntil );
					$fields['correction_until'] = null;
					if ( BookingStatus::PENDING_PAYMENT === $booking['status'] ) {
						self::update( $booking, $fields );
					} else {
						self::transition( $booking, BookingStatus::PENDING_PAYMENT, 'Reprogramada; requiere nuevo anticipo. ' . $reason, $fields );
					}
				} else {
					self::update( $booking, $fields );
				}
				self::releaseOccupancy( $bookingId, $now );
				Db::check(
					Db::wpdb()->insert(
						Schema::table( 'occupancies' ),
						array(
							'resource_id' => $booking['resource_id'],
							'booking_id'  => $bookingId,
							'start_utc'   => Db::toSql( $occupy->start ),
							'end_utc'     => Db::toSql( $occupy->end ),
							'kind'        => 'booking',
							'expires_at'  => Db::toSql( $holdUntil ),
							'created_at'  => Db::toSql( $now ),
						)
					),
					'occupancy_insert'
				);
				Tokens::updateExpiry( $bookingId, self::tokenExpiry( $newStart ) );
				Audit::log( 'booking', $bookingId, 'rescheduled', array_merge( self::outcomeForAudit( $outcome ), array( 'from' => $old['start'], 'to' => Db::toSql( $newStart ), 'reason' => $reason ) ) );
			}
		);
		Notifications::cancelPending( $bookingId );
		$booking = self::get( $bookingId );
		Notifications::bookingEvent( 'booking_rescheduled', $booking );
		Notifications::scheduleReminder( $booking );
		return $booking;
	}

	/**
	 * Recalcula el precio con otra categoría (por ejemplo, frecuente validado). Conserva la
	 * fecha de solicitud original. No mueve dinero: si ya pagó de más, se muestra el excedente.
	 */
	public static function previewRecalculate( array $booking, string $category ): array {
		$service = Services::get( $booking['service_id'] );
		$requestedAt = new \DateTimeImmutable( (string) ( $booking['quote']['requested_at'] ?? $booking['created_at'] ) );
		return ( new Pricing( Settings::timezone() ) )->quote(
			Services::forPricing( $service, $booking['quote'] ),
			Services::rules( (int) $service['id'] ),
			array(
				'start'        => $booking['start'],
				'requested_at' => $requestedAt,
				'category'     => $category,
				'participants' => $booking['participants'],
				'pets'         => $booking['pets'],
				'extras'       => (array) ( $booking['quote']['extras_input'] ?? array() ),
			)
		);
	}

	public static function recalculate( int $bookingId, string $category, string $reason, int $expectedVersion ): array {
		if ( '' === trim( $reason ) ) {
			throw new DomainException( 'reason_required', 'Indica el motivo del ajuste.' );
		}
		Db::transaction(
			static function () use ( $bookingId, $category, $reason, $expectedVersion ) {
				$booking = self::get( $bookingId, true );
				self::assertVersion( $booking, $expectedVersion );
				$quote = self::previewRecalculate( $booking, $category );
				self::update(
					$booking,
					array(
						'client_category' => $quote['category'],
						'frequent_match'  => FrequentCheck::isAuto( (string) ( $booking['frequent_match'] ?? '' ) ) && Pricing::CATEGORY_GENERAL === $quote['category'] ? 'reverted' : (string) ( $booking['frequent_match'] ?? '' ),
						'total_cents'     => $quote['total_cents'],
						'deposit_cents'   => $quote['deposit_cents'],
						'quote'           => Db::json( array_merge( $booking['quote'], $quote, array( 'previous' => array( 'total' => $booking['total_cents'], 'category' => $booking['client_category'] ) ) ) ),
					)
				);
				Audit::log( 'booking', $bookingId, 'recalculated', array( 'from' => $booking['total_cents'], 'to' => $quote['total_cents'], 'category' => $quote['category'], 'reason' => $reason ) );
			}
		);
		return self::get( $bookingId );
	}

	/** Un clic: quita la tarifa frecuente reconocida automáticamente y regresa al precio general. */
	public static function revertFrequent( int $bookingId, int $expectedVersion ): array {
		$booking = self::get( $bookingId );
		if ( ! FrequentCheck::isAuto( (string) ( $booking['frequent_match'] ?? '' ) ) || Pricing::CATEGORY_FREQUENT !== $booking['client_category'] ) {
			throw new DomainException( 'not_auto_frequent', 'Esta reserva no tiene una tarifa frecuente reconocida automáticamente.', 409 );
		}
		return self::recalculate( $bookingId, Pricing::CATEGORY_GENERAL, 'Se revirtió el precio frecuente reconocido por nombre y contacto.', $expectedVersion );
	}

	public static function addNote( int $bookingId, string $note ): void {
		$note = trim( sanitize_textarea_field( $note ) );
		if ( '' === $note ) {
			return;
		}
		$booking = self::get( $bookingId );
		$stamp   = Plugin::clock()->now()->setTimezone( Settings::timezone() )->format( 'Y-m-d H:i' );
		$user    = wp_get_current_user();
		$text    = trim( (string) $booking['internal_notes'] . "\n[" . $stamp . ' ' . ( $user ? $user->display_name : '' ) . '] ' . $note );
		Db::wpdb()->update( Schema::table( 'bookings' ), array( 'internal_notes' => $text ), array( 'id' => $bookingId ) );
	}

	/** Genera un enlace nuevo y anula el anterior. */
	public static function rotateToken( int $bookingId ): string {
		$booking = self::get( $bookingId );
		Tokens::revokeAll( $bookingId );
		$nonce = Tokens::newNonce();
		Db::wpdb()->update( Schema::table( 'bookings' ), array( 'token_nonce' => $nonce ), array( 'id' => $bookingId ) );
		Audit::log( 'booking', $bookingId, 'token_rotated' );
		return Tokens::register( $bookingId, $nonce, self::tokenExpiry( $booking['start'] ) );
	}

	public static function portalUrl( array $booking ): string {
		return self::portalBase() . '#acceso=' . Tokens::tokenFor( $booking['id'], $booking['token_nonce'] );
	}

	public static function portalBase(): string {
		$page = (int) Settings::get( 'portal_page_id' );
		$url  = ( $page && 'publish' === get_post_status( $page ) ) ? get_permalink( $page ) : '';
		return $url ?: add_query_arg( 'mo_portal', '1', home_url( '/' ) );
	}

	// ----- Utilidades internas -----

	/**
	 * Cambia el estado verificando la transición y la versión (bloqueo optimista).
	 */
	public static function transition( array $booking, string $to, string $reason, array $fields = array() ): void {
		BookingStatus::assertTransition( $booking['status'], $to );
		self::update( $booking, array_merge( $fields, array( 'status' => $to ) ) );
		Audit::log( 'booking', $booking['id'], 'status:' . $to, array( 'from' => $booking['status'], 'reason' => $reason, 'version' => $booking['version'] + 1 ) );
	}

	private static function update( array $booking, array $fields ): void {
		$wpdb  = Db::wpdb();
		$sets  = array();
		$vals  = array();
		foreach ( $fields as $col => $value ) {
			if ( null === $value ) {
				$sets[] = "`$col` = NULL";
			} else {
				$sets[] = "`$col` = %s";
				$vals[] = $value;
			}
		}
		$sets[] = 'version = version + 1';
		$sets[] = 'updated_at = %s';
		$vals[] = Db::toSql( Plugin::clock()->now() );
		$vals[] = $booking['id'];
		$vals[] = $booking['version'];
		$sql    = 'UPDATE ' . Schema::table( 'bookings' ) . ' SET ' . implode( ', ', $sets ) . ' WHERE id = %d AND version = %d';
		$done   = $wpdb->query( $wpdb->prepare( $sql, $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( 1 !== $done ) {
			throw new DomainException( 'version_conflict', 'La reserva cambió mientras trabajabas. Recarga la página para ver la versión actual.', 409 );
		}
		// La sincronización con Google se registra en la misma transacción que el cambio.
		CalendarSync::enqueue( (int) $booking['id'] );
	}

	private static function assertVersion( array $booking, int $expected ): void {
		if ( $expected > 0 && $booking['version'] !== $expected ) {
			throw new DomainException( 'version_conflict', 'La reserva cambió mientras trabajabas. Recarga la página para ver la versión actual.', 409 );
		}
	}

	private static function applyOutcomeMovements( array $booking, array $outcome, string $action ): void {
		$base = $action . ':' . $booking['id'] . ':' . $booking['version'];
		if ( $outcome['retained'] > 0 ) {
			self::insertMovement( $booking, 'retention', $outcome['retained'], $base . ':retention', 'Anticipo retenido por política (' . Policy::ACTION_LABELS[ $action ] . ').' );
		}
		if ( $outcome['penalty_from_paid'] > 0 ) {
			self::insertMovement( $booking, 'penalty', $outcome['penalty_from_paid'], $base . ':penalty', 'Penalización cubierta con dinero pagado.' );
		}
		if ( 'reschedule' !== $action ) {
			if ( $outcome['refund_due'] > 0 ) {
				self::insertMovement( $booking, 'refund_due', $outcome['refund_due'], $base . ':refund', 'Devolución pendiente por ' . strtolower( Policy::ACTION_LABELS[ $action ] ) . '.' );
			}
			if ( $outcome['credit'] > 0 ) {
				self::insertMovement( $booking, 'credit', $outcome['credit'], $base . ':credit', 'Saldo a favor por ' . strtolower( Policy::ACTION_LABELS[ $action ] ) . '.' );
			}
		} elseif ( $outcome['refund_due'] > 0 ) {
			self::insertMovement( $booking, 'refund_due', $outcome['refund_due'], $base . ':refund', 'Excedente tras reprogramar.' );
		}
	}

	private static function outcomeForAudit( array $o ): array {
		return array_intersect_key( $o, array_flip( array( 'retained', 'penalty', 'penalty_from_paid', 'penalty_due', 'credit', 'refund_due', 'amount_due_now', 'new_total', 'new_deposit', 'policy_version', 'by_business', 'seconds_before' ) ) );
	}

	/**
	 * Toma los bloqueos de calendario de los días indicados, en orden estable.
	 */
	public static function lockDays( int $resourceId, array $days ): void {
		$days = array_values( array_unique( $days ) );
		sort( $days );
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'calendar_locks' );
		foreach ( $days as $day ) {
			$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (resource_id, day) VALUES (%d, %s)", $resourceId, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( $days as $day ) {
			$wpdb->get_var( $wpdb->prepare( "SELECT day FROM $table WHERE resource_id = %d AND day = %s FOR UPDATE", $resourceId, $day ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}

	public static function releaseOccupancy( int $bookingId, \DateTimeImmutable $now ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'occupancies' ) . ' SET released_at = %s WHERE booking_id = %d AND released_at IS NULL', Db::toSql( $now ), $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function holdIndefinitely( int $bookingId ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'occupancies' ) . ' SET expires_at = NULL WHERE booking_id = %d AND released_at IS NULL', $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function setOccupancyExpiry( int $bookingId, \DateTimeImmutable $until ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'occupancies' ) . ' SET expires_at = %s WHERE booking_id = %d AND released_at IS NULL', Db::toSql( $until ), $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function tokenExpiry( \DateTimeImmutable $start ): \DateTimeImmutable {
		return $start->modify( sprintf( '+%d days', max( 1, (int) Settings::get( 'token_days_after' ) ) ) );
	}

	private static function newRef(): string {
		$wpdb = Db::wpdb();
		for ( $i = 0; $i < 10; $i++ ) {
			$ref = 'MO-';
			for ( $j = 0; $j < 6; $j++ ) {
				$ref .= self::REF_ALPHABET[ random_int( 0, strlen( self::REF_ALPHABET ) - 1 ) ];
			}
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bookings' ) . ' WHERE public_ref = %s', $ref ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $ref;
			}
		}
		throw new DomainException( 'ref_error', 'No pudimos generar el número de reserva. Intenta de nuevo.', 500 );
	}

	public static function localDateToUtc( string $date ): \DateTimeImmutable {
		$zone = Settings::timezone();
		$d    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $zone );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			return Plugin::clock()->now();
		}
		return $d->setTime( 12, 0 )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
