<?php
/**
 * Plugin Name:       MO Booking Studio – Cargar clientes (una vez)
 * Description:       Carga única de la base de clientes desde un archivo CSV, con revisión previa, reporte y opción de deshacer. Requiere MO Booking Studio. Elimínalo después de usarlo.
 * Version:           1.0.0
 * Requires PHP:      7.4
 * Author:            Mónica Orozco
 * License:           GPL-2.0-or-later
 *
 * El archivo se lee directamente en tu sitio: no se envía a ningún servicio externo y no
 * se guarda en la carpeta de medios. Los datos revisados se guardan temporalmente (30
 * minutos) solo para la persona que hizo la revisión y se borran al cargar o cancelar.
 */

namespace MOCargarClientes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectura, revisión y carga. Sin dependencias de pantalla para poder probarlo.
 */
final class Loader {
	public const MAX_BYTES    = 2097152; // 2 MB.
	public const MAX_ROWS     = 5000;
	public const BATCH_OPTION = 'mo_cargar_clientes_lotes';

	/** Encabezados reconocidos (sin acentos, minúsculas, sin signos). */
	private const FIELDS = array(
		'first'    => array( 'nombre', 'nombres', 'name', 'first name' ),
		'last'     => array( 'apellido', 'apellidos', 'last name' ),
		'full'     => array( 'nombre completo', 'nombre y apellido', 'cliente' ),
		'sessions' => array( 'no sesiones', 'num sesiones', 'numero de sesiones', 'sesiones', 'sesiones previas', 'n sesiones', 'no de sesiones' ),
		'email'    => array( 'email', 'e mail', 'correo', 'correo electronico', 'mail' ),
		'phone'    => array( 'telefono', 'tel', 'celular', 'whatsapp', 'movil', 'telefono whatsapp' ),
		'country'  => array( 'pais', 'pais del telefono', 'lada pais' ),
		'notes'    => array( 'notas', 'nota', 'comentarios', 'observaciones' ),
	);

	/** Valores de error que las hojas de cálculo escriben en una celda. */
	private const SHEET_ERRORS = array( '#ERROR!', '#N/A', '#VALUE!', '#REF!', '#NAME?', '#DIV/0!', '#NUM!', '#NULL!', '#SPILL!', '#VALOR!', '#NOMBRE?', '#REF', '#¡VALOR!', '#¡REF!', '#¿NOMBRE?' );

	public static function key( string $text ): string {
		$text = strtolower( remove_accents( trim( $text ) ) );
		$text = (string) preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

	/** Nombre comparable: sin acentos, mayúsculas, signos ni espacios repetidos. */
	public static function nameKey( string $name ): string {
		return self::key( $name );
	}

	/** Nombres parecidos: iguales sin acentos o con una o dos letras de diferencia. */
	public static function similarNames( string $a, string $b ): bool {
		$a = self::nameKey( $a );
		$b = self::nameKey( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		$limit = min( strlen( $a ), strlen( $b ) ) >= 8 ? 2 : 1;
		return abs( strlen( $a ) - strlen( $b ) ) <= $limit && levenshtein( $a, $b ) <= $limit;
	}

	/**
	 * Lee el CSV (coma o punto y coma; UTF-8 o Windows-1252).
	 *
	 * @return array{headers:string[],rows:array<int,string[]>}
	 */
	public static function read( string $path ): array {
		if ( ! is_readable( $path ) || filesize( $path ) === 0 ) {
			throw new \RuntimeException( 'El archivo está vacío o no se pudo leer.' );
		}
		if ( filesize( $path ) > self::MAX_BYTES ) {
			throw new \RuntimeException( 'El archivo pesa más de 2 MB. Divídelo en partes.' );
		}
		$raw = (string) file_get_contents( $path );
		if ( 0 === strpos( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = (string) mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
		}
		if ( false !== strpos( $raw, "\0" ) ) {
			throw new \RuntimeException( 'El archivo no parece ser un CSV de texto. Expórtalo como "Valores separados por comas (.csv)".' );
		}
		$firstLine = strtok( $raw, "\n" );
		$delimiter = substr_count( (string) $firstLine, ';' ) > substr_count( (string) $firstLine, ',' ) ? ';' : ',';
		$handle    = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $raw );
		rewind( $handle );
		$headers = fgetcsv( $handle, 0, $delimiter );
		if ( ! $headers || ! array_filter( array_map( 'trim', array_map( 'strval', $headers ) ) ) ) {
			throw new \RuntimeException( 'La primera fila debe tener los encabezados (Nombre, Email, Teléfono…).' );
		}
		$rows = array();
		$line = 1;
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			++$line;
			if ( count( $rows ) >= self::MAX_ROWS ) {
				throw new \RuntimeException( 'El archivo tiene más de ' . self::MAX_ROWS . ' filas. Divídelo en partes.' );
			}
			$rows[ $line ] = array_map( 'strval', (array) $row );
		}
		fclose( $handle );
		return array(
			'headers' => array_map( 'strval', $headers ),
			'rows'    => $rows,
		);
	}

	/** @return array<string,int> campo => índice de columna */
	public static function mapHeaders( array $headers ): array {
		$map = array();
		foreach ( $headers as $i => $header ) {
			$k = self::key( (string) $header );
			foreach ( self::FIELDS as $field => $names ) {
				if ( ! isset( $map[ $field ] ) && in_array( $k, $names, true ) ) {
					$map[ $field ] = (int) $i;
				}
			}
		}
		if ( ! isset( $map['first'] ) && ! isset( $map['full'] ) ) {
			throw new \RuntimeException( 'No encontré la columna del nombre. Llámala "Nombre" (y, si la tienes aparte, "Apellido").' );
		}
		if ( ! isset( $map['email'] ) && ! isset( $map['phone'] ) ) {
			throw new \RuntimeException( 'No encontré columnas de correo ni de teléfono. Llámalas "Email" y "Teléfono".' );
		}
		return $map;
	}

	private static function clean( string $value ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', $value ) ) );
	}

	private static function isSheetError( string $value ): bool {
		return in_array( strtoupper( trim( $value ) ), array_map( 'strtoupper', self::SHEET_ERRORS ), true );
	}

	/**
	 * Teléfono a formato internacional. México por omisión; un número de 11 dígitos que
	 * empieza con 1, o la columna País = US/EUA, se toma como Estados Unidos.
	 *
	 * @return array{0:?string,1:string} [e164, aviso]
	 */
	public static function phone( string $raw, string $country = '' ): array {
		$raw = self::clean( $raw );
		if ( '' === $raw ) {
			return array( null, '' );
		}
		if ( self::isSheetError( $raw ) ) {
			return array( null, 'La celda del teléfono tiene un error de la hoja de cálculo (' . $raw . '); se carga sin teléfono.' );
		}
		$digits = (string) preg_replace( '/\D+/', '', $raw );
		if ( in_array( $digits, array( '', '52', '521', '1' ), true ) ) {
			return array( null, 'El teléfono solo tiene la lada; se carga sin teléfono.' );
		}
		$country = strtoupper( self::key( $country ) );
		$isUs    = in_array( $country, array( 'US', 'USA', 'EUA', 'EU', 'EE UU', 'ESTADOS UNIDOS' ), true ) || ( 11 === strlen( $digits ) && '1' === $digits[0] && 0 !== strpos( $raw, '+52' ) );
		$e164    = \MOBookingStudio\Domain\Contact::normalizePhone( $raw, $isUs ? 'US' : 'MX' );
		if ( ! $e164 ) {
			return array( null, sprintf( 'El teléfono "%s" está incompleto o no es válido (%d dígitos); se carga sin teléfono.', $raw, strlen( $digits ) ) );
		}
		return array( $e164, '' );
	}

	/**
	 * Revisa todas las filas sin guardar nada.
	 *
	 * @return array{rows:array,summary:array,hash:string,map:array}
	 */
	public static function analyze( array $data ): array {
		$map     = self::mapHeaders( $data['headers'] );
		$get     = static function ( array $row, string $field ) use ( $map ): string {
			return isset( $map[ $field ] ) ? self::clean( (string) ( $row[ $map[ $field ] ] ?? '' ) ) : '';
		};
		$wpdb    = \MOBookingStudio\Infrastructure\Db::wpdb();
		$table   = \MOBookingStudio\Infrastructure\Schema::table( 'clients' );
		$byEmail = array();
		$byPhone = array();
		$seen    = array();
		$out     = array();
		$existingNames = $wpdb->get_results( "SELECT id, name FROM $table", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $data['rows'] as $line => $row ) {
			$nonEmpty = array_filter( array_map( 'trim', $row ), 'strlen' );
			if ( ! $nonEmpty ) {
				continue; // Fila vacía: no se reporta.
			}
			$name = isset( $map['full'] ) && '' !== $get( $row, 'full' ) ? $get( $row, 'full' ) : trim( $get( $row, 'first' ) . ' ' . $get( $row, 'last' ) );
			// Restos de la hoja (por ejemplo una celda con solo "52"): se ignoran sin reportar.
			if ( '' === $name && '' === $get( $row, 'email' ) && in_array( (string) preg_replace( '/\D+/', '', $get( $row, 'phone' ) ), array( '', '52', '521', '1' ), true ) ) {
				continue;
			}
			$r    = array(
				'line'      => (int) $line,
				'name'      => mb_substr( $name, 0, 190 ),
				'email'     => '',
				'email_norm' => null,
				'phone'     => $get( $row, 'phone' ),
				'phone_e164' => null,
				'country'   => 'MX',
				'sessions'  => 0,
				'notes'     => mb_substr( sanitize_textarea_field( $get( $row, 'notes' ) ), 0, 2000 ),
				'status'    => 'new',
				'reason'    => '',
				'warnings'  => array(),
				'client_id' => 0,
				'changes'   => array(),
			);
			if ( '' === $name ) {
				$r['status'] = 'error';
				$r['reason'] = 'Fila sin nombre; no se carga.';
				$out[]       = $r;
				continue;
			}
			$emailRaw = $get( $row, 'email' );
			if ( '' !== $emailRaw ) {
				$norm = \MOBookingStudio\Domain\Contact::normalizeEmail( $emailRaw );
				if ( $norm ) {
					$r['email']      = sanitize_email( $emailRaw );
					$r['email_norm'] = $norm;
				} else {
					$r['warnings'][] = 'El correo "' . $emailRaw . '" no es válido; se carga sin correo.';
				}
			}
			list( $e164, $warn ) = self::phone( $r['phone'], $get( $row, 'country' ) );
			if ( $warn ) {
				$r['warnings'][] = $warn;
				$r['phone']      = '';
			}
			$r['phone_e164'] = $e164;
			$r['country']    = $e164 && 0 === strpos( $e164, '+1' ) ? 'US' : 'MX';
			$sessions        = $get( $row, 'sessions' );
			if ( '' !== $sessions ) {
				if ( preg_match( '/^\d{1,4}$/', $sessions ) ) {
					$r['sessions'] = (int) $sessions;
				} else {
					$r['warnings'][] = 'Sesiones "' . $sessions . '" no es un número entero; se carga con 0.';
				}
			}
			if ( ! $r['email_norm'] && ! $r['phone_e164'] ) {
				$r['status'] = 'error';
				$r['reason'] = 'Sin correo ni teléfono válidos; no se carga (corrígelo y vuelve a cargar el archivo).';
				$out[]       = $r;
				continue;
			}

			// Repetidos dentro del mismo archivo (mismo correo o teléfono).
			$dup = ( $r['email_norm'] && isset( $byEmail[ $r['email_norm'] ] ) ) ? $byEmail[ $r['email_norm'] ] : ( ( $r['phone_e164'] && isset( $byPhone[ $r['phone_e164'] ] ) ) ? $byPhone[ $r['phone_e164'] ] : 0 );
			if ( $dup ) {
				$r['status'] = 'duplicate';
				$r['reason'] = 'Mismo correo o teléfono que la fila ' . $dup . '; se carga solo una vez.';
				$out[]       = $r;
				continue;
			}
			if ( $r['email_norm'] ) {
				$byEmail[ $r['email_norm'] ] = $r['line'];
			}
			if ( $r['phone_e164'] ) {
				$byPhone[ $r['phone_e164'] ] = $r['line'];
			}
			foreach ( $seen as $otherLine => $otherName ) {
				if ( self::similarNames( $name, $otherName ) ) {
					$r['warnings'][] = 'Nombre parecido a la fila ' . $otherLine . ' con otros datos de contacto: revisa si es la misma persona (se cargan las dos).';
					break;
				}
			}
			$seen[ $r['line'] ] = $name;

			// Contra los clientes que ya están en el plugin.
			$byE = $r['email_norm'] ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE email_norm = %s", $r['email_norm'] ) ) ?: array() ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$byP = $r['phone_e164'] ? array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE phone_e164 = %s", $r['phone_e164'] ) ) ?: array() ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = array_values( array_unique( array_merge( $byE, $byP ) ) );
			if ( count( $ids ) > 1 ) {
				$r['status'] = 'conflict';
				$r['reason'] = 'El correo y el teléfono pertenecen a clientes distintos que ya existen; no se cambia nada. Revísalo en Clientes.';
				$out[]       = $r;
				continue;
			}
			if ( 1 === count( $ids ) ) {
				$client         = \MOBookingStudio\Application\Clients::find( $ids[0] );
				$r['client_id'] = $ids[0];
				if ( $r['sessions'] > (int) $client['historic_sessions'] ) {
					$r['changes']['historic_sessions'] = array( (int) $client['historic_sessions'], $r['sessions'] );
				}
				if ( $r['email_norm'] && '' === (string) $client['email'] ) {
					$r['changes']['email'] = array( '', $r['email'] );
				}
				if ( $r['phone_e164'] && '' === (string) $client['phone'] ) {
					$r['changes']['phone'] = array( '', $r['phone'] );
				}
				if ( self::nameKey( $client['name'] ) !== self::nameKey( $name ) ) {
					$r['warnings'][] = 'En el plugin se llama "' . $client['name'] . '"; el nombre no se cambia.';
				}
				$r['status'] = $r['changes'] ? 'update' : 'same';
				$r['reason'] = $r['changes'] ? 'Ya existe: se propone actualizar lo indicado (nunca se borra ni se reduce un dato).' : 'Ya existe con los mismos datos; no se cambia.';
				$out[]       = $r;
				continue;
			}
			foreach ( $existingNames as $existing ) {
				if ( self::similarNames( $name, (string) $existing['name'] ) ) {
					$r['warnings'][] = 'Nombre parecido a un cliente que ya existe ("' . $existing['name'] . '") con otro correo y teléfono: revisa si es la misma persona.';
					break;
				}
			}
			$out[] = $r;
		}

		$threshold = \MOBookingStudio\Application\Clients::frequentThreshold();
		$count     = array_count_values( array_column( $out, 'status' ) );
		$frequent  = 0;
		foreach ( $out as $r ) {
			if ( in_array( $r['status'], array( 'new', 'update' ), true ) && $r['sessions'] >= $threshold ) {
				++$frequent;
			}
		}
		return array(
			'rows'    => $out,
			'map'     => $map,
			'hash'    => hash( 'sha256', wp_json_encode( $data ) ),
			'summary' => array(
				'new'       => $count['new'] ?? 0,
				'update'    => $count['update'] ?? 0,
				'same'      => $count['same'] ?? 0,
				'duplicate' => $count['duplicate'] ?? 0,
				'conflict'  => $count['conflict'] ?? 0,
				'error'     => $count['error'] ?? 0,
				'warnings'  => count( array_filter( $out, static function ( $r ) { return (bool) $r['warnings']; } ) ),
				'frequent'  => $frequent,
				'threshold' => $threshold,
			),
		);
	}

	/**
	 * Guarda lo revisado. Crea los nuevos y, si se autoriza, aplica las actualizaciones.
	 *
	 * @return array{batch:string,created:int,updated:int}
	 */
	public static function commit( array $analysis, bool $applyUpdates ): array {
		$batches = (array) get_option( self::BATCH_OPTION, array() );
		foreach ( $batches as $b ) {
			if ( $b['hash'] === $analysis['hash'] && empty( $b['undone'] ) ) {
				throw new \RuntimeException( 'Este mismo archivo ya se cargó el ' . $b['at'] . '. No se cargó de nuevo.' );
			}
		}
		$batchId = 'L' . gmdate( 'YmdHis' ) . '-' . substr( $analysis['hash'], 0, 6 );
		$created = array();
		$updated = array();
		$wpdb    = \MOBookingStudio\Infrastructure\Db::wpdb();
		$table   = \MOBookingStudio\Infrastructure\Schema::table( 'clients' );
		\MOBookingStudio\Infrastructure\Db::transaction(
			static function () use ( $analysis, $applyUpdates, $batchId, &$created, &$updated, $wpdb, $table ) {
				foreach ( $analysis['rows'] as $r ) {
					if ( 'new' === $r['status'] ) {
						// Se vuelve a comprobar por si alguien reservó mientras tanto.
						$exists = ( $r['email_norm'] && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email_norm = %s", $r['email_norm'] ) ) ) || ( $r['phone_e164'] && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE phone_e164 = %s", $r['phone_e164'] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						if ( $exists ) {
							continue;
						}
						$id = \MOBookingStudio\Application\Clients::create(
							array(
								'name'              => $r['name'],
								'email'             => $r['email'],
								'phone'             => $r['phone'],
								'phone_country'     => $r['country'],
								'historic_sessions' => $r['sessions'],
								'notes'             => $r['notes'],
							)
						);
						\MOBookingStudio\Infrastructure\Audit::log( 'client', $id, 'imported', array( 'batch' => $batchId, 'line' => $r['line'] ) );
						$created[] = $id;
					} elseif ( 'update' === $r['status'] && $applyUpdates ) {
						$client = \MOBookingStudio\Application\Clients::find( (int) $r['client_id'] );
						if ( ! $client ) {
							continue;
						}
						$fields   = array();
						$previous = array();
						foreach ( $r['changes'] as $field => $pair ) {
							$previous[ $field ] = $client[ $field ];
							if ( 'historic_sessions' === $field ) {
								$fields['historic_sessions'] = max( (int) $client['historic_sessions'], (int) $pair[1] );
							} elseif ( 'email' === $field ) {
								$fields['email']      = $r['email'];
								$fields['email_norm'] = $r['email_norm'];
								$previous['email_norm'] = $client['email_norm'];
							} elseif ( 'phone' === $field ) {
								$fields['phone']         = $r['phone'];
								$fields['phone_e164']    = $r['phone_e164'];
								$fields['phone_country'] = $r['country'];
								$previous['phone_e164']    = $client['phone_e164'];
								$previous['phone_country'] = $client['phone_country'];
							}
						}
						$fields['updated_at'] = \MOBookingStudio\Infrastructure\Db::toSql( \MOBookingStudio\Plugin::clock()->now() );
						$wpdb->update( $table, $fields, array( 'id' => (int) $r['client_id'] ) );
						\MOBookingStudio\Infrastructure\Audit::log( 'client', (int) $r['client_id'], 'import_updated', array( 'batch' => $batchId, 'changes' => $r['changes'] ) );
						$updated[ (int) $r['client_id'] ] = $previous;
					}
				}
			}
		);
		$batches[ $batchId ] = array(
			'hash'    => $analysis['hash'],
			'at'      => current_time( 'Y-m-d H:i' ),
			'user'    => get_current_user_id(),
			'created' => $created,
			'updated' => $updated,
			'undone'  => false,
		);
		update_option( self::BATCH_OPTION, $batches, false );
		return array(
			'batch'   => $batchId,
			'created' => count( $created ),
			'updated' => count( $updated ),
		);
	}

	/**
	 * Deshace un lote: borra los clientes que creó (si todavía no tienen reservas) y
	 * regresa los datos que actualizó.
	 *
	 * @return array{deleted:int,kept:int,restored:int}
	 */
	public static function undo( string $batchId ): array {
		$batches = (array) get_option( self::BATCH_OPTION, array() );
		if ( empty( $batches[ $batchId ] ) || ! empty( $batches[ $batchId ]['undone'] ) ) {
			throw new \RuntimeException( 'Ese lote no existe o ya se deshizo.' );
		}
		$batch    = $batches[ $batchId ];
		$wpdb     = \MOBookingStudio\Infrastructure\Db::wpdb();
		$clients  = \MOBookingStudio\Infrastructure\Schema::table( 'clients' );
		$bookings = \MOBookingStudio\Infrastructure\Schema::table( 'bookings' );
		$deleted  = 0;
		$kept     = 0;
		foreach ( (array) $batch['created'] as $id ) {
			if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $bookings WHERE client_id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				++$kept; // Ya tiene reservas: se conserva.
				continue;
			}
			$wpdb->delete( $clients, array( 'id' => (int) $id ) );
			\MOBookingStudio\Infrastructure\Audit::log( 'client', (int) $id, 'import_undone', array( 'batch' => $batchId ) );
			++$deleted;
		}
		foreach ( (array) $batch['updated'] as $id => $previous ) {
			$wpdb->update( $clients, (array) $previous, array( 'id' => (int) $id ) );
			\MOBookingStudio\Infrastructure\Audit::log( 'client', (int) $id, 'import_update_undone', array( 'batch' => $batchId ) );
		}
		$batches[ $batchId ]['undone'] = true;
		update_option( self::BATCH_OPTION, $batches, false );
		return array(
			'deleted'  => $deleted,
			'kept'     => $kept,
			'restored' => count( (array) $batch['updated'] ),
		);
	}

	/** Celda segura para CSV: evita que Excel la interprete como fórmula. */
	public static function csvCell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	public static function reportCsv( array $analysis ): string {
		$labels = self::statusLabels();
		$out    = fopen( 'php://temp', 'r+' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'Fila', 'Resultado', 'Nombre', 'Correo', 'Teléfono', 'Sesiones', 'Motivo', 'Avisos' ) );
		foreach ( $analysis['rows'] as $r ) {
			fputcsv( $out, array_map( array( self::class, 'csvCell' ), array( (string) $r['line'], $labels[ $r['status'] ], $r['name'], (string) $r['email'], (string) ( $r['phone_e164'] ?: $r['phone'] ), (string) $r['sessions'], $r['reason'], implode( ' | ', $r['warnings'] ) ) ) );
		}
		rewind( $out );
		$csv = (string) stream_get_contents( $out );
		fclose( $out );
		return $csv;
	}

	public static function statusLabels(): array {
		return array(
			'new'       => 'Nuevo: se carga',
			'update'    => 'Ya existe: actualizar',
			'same'      => 'Ya existe: sin cambios',
			'duplicate' => 'Repetido en el archivo: se omite',
			'conflict'  => 'Conflicto: se omite',
			'error'     => 'Error: no se carga',
		);
	}
}

/**
 * Pantalla en el panel: Reservas → Cargar clientes.
 */
final class Screen {
	public const SLUG = 'mo-cargar-clientes';
	public const CAP  = 'mo_manage_clients';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_mo_cargar_clientes', array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'requirementNotice' ) );
	}

	public static function ready(): bool {
		return class_exists( '\\MOBookingStudio\\Application\\Clients' ) && version_compare( defined( 'MO_BOOKING_VERSION' ) ? MO_BOOKING_VERSION : '0', '0.2.0-beta.2', '>=' );
	}

	public static function requirementNotice(): void {
		if ( ! self::ready() && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>"Cargar clientes" necesita MO Booking Studio 0.2.0-beta.2 o superior activo.</p></div>';
		}
	}

	public static function menu(): void {
		if ( self::ready() ) {
			add_submenu_page( 'mo-booking', 'Cargar clientes', 'Cargar clientes', self::CAP, self::SLUG, array( self::class, 'render' ) );
		}
	}

	private static function stateKey(): string {
		return 'mo_cargar_' . get_current_user_id();
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private static function form( string $step, string $inner, bool $multipart = false ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="mo_cargar_clientes"><input type="hidden" name="step" value="' . esc_attr( $step ) . '">' . wp_nonce_field( 'mo_cargar_' . $step, '_mo_nonce', true, false ) . $inner . '</form>';
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'No tienes permiso para cargar clientes.', '', array( 'response' => 403 ) );
		}
		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';
		check_admin_referer( 'mo_cargar_' . $step, '_mo_nonce' );
		$message = '';
		try {
			switch ( $step ) {
				case 'analyze':
					$file = $_FILES['archivo'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( ! $file || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
						throw new \RuntimeException( 'No se recibió el archivo. Elige un .csv e intenta de nuevo.' );
					}
					if ( 'csv' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
						throw new \RuntimeException( 'El archivo debe ser .csv. En Google Sheets: Archivo → Descargar → Valores separados por comas (.csv).' );
					}
					$analysis = Loader::analyze( Loader::read( $file['tmp_name'] ) );
					@unlink( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					set_transient( self::stateKey(), $analysis, 30 * MINUTE_IN_SECONDS );
					wp_safe_redirect( self::url( array( 'paso' => 'revisar' ) ) );
					exit;
				case 'commit':
					$analysis = get_transient( self::stateKey() );
					if ( ! is_array( $analysis ) ) {
						throw new \RuntimeException( 'La revisión venció (30 minutos). Vuelve a elegir el archivo.' );
					}
					$result = Loader::commit( $analysis, ! empty( $_POST['aplicar_actualizaciones'] ) );
					set_transient( self::stateKey() . '_reporte', Loader::reportCsv( $analysis ), 30 * MINUTE_IN_SECONDS );
					delete_transient( self::stateKey() );
					$message = sprintf( 'Listo: %d clientes nuevos y %d actualizados (lote %s).', $result['created'], $result['updated'], $result['batch'] );
					break;
				case 'cancel':
					delete_transient( self::stateKey() );
					$message = 'Revisión cancelada. No se guardó nada.';
					break;
				case 'undo':
					$r       = Loader::undo( sanitize_text_field( wp_unslash( (string) ( $_POST['lote'] ?? '' ) ) ) );
					$message = sprintf( 'Lote deshecho: %d clientes borrados, %d conservados porque ya tienen reservas, %d actualizaciones regresadas.', $r['deleted'], $r['kept'], $r['restored'] );
					break;
				case 'report':
					$csv = get_transient( self::stateKey() . '_reporte' );
					if ( ! is_string( $csv ) ) {
						$a   = get_transient( self::stateKey() );
						$csv = is_array( $a ) ? Loader::reportCsv( $a ) : '';
					}
					if ( '' === $csv ) {
						throw new \RuntimeException( 'No hay un reporte disponible.' );
					}
					nocache_headers();
					header( 'Content-Type: text/csv; charset=utf-8' );
					header( 'Content-Disposition: attachment; filename="reporte-carga-clientes.csv"' );
					echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
					exit;
			}
		} catch ( \Throwable $e ) {
			$message = 'error:' . $e->getMessage();
		}
		set_transient( self::stateKey() . '_msg', $message, 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	public static function render(): void {
		echo '<div class="wrap"><h1>Cargar clientes (una vez)</h1>';
		$msg = get_transient( self::stateKey() . '_msg' );
		if ( $msg ) {
			delete_transient( self::stateKey() . '_msg' );
			$error = 0 === strpos( (string) $msg, 'error:' );
			echo '<div class="notice notice-' . ( $error ? 'error' : 'success' ) . '"><p>' . esc_html( $error ? substr( (string) $msg, 6 ) : (string) $msg ) . '</p></div>';
		}
		$analysis = get_transient( self::stateKey() );
		if ( is_array( $analysis ) && isset( $_GET['paso'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::review( $analysis );
		} else {
			self::start();
		}
		echo '</div>';
	}

	private static function start(): void {
		echo '<p>Sube tu base de clientes en formato <strong>.csv</strong>. Primero verás una revisión completa; <strong>no se guarda nada hasta que confirmes</strong>. El archivo se lee aquí mismo y no se guarda en tu sitio.</p>';
		echo '<ul class="ul-disc"><li>Columnas reconocidas: <code>Nombre</code>, <code>Apellido</code>, <code>No. Sesiones</code>, <code>Email</code>, <code>Teléfono</code> y, opcionales, <code>País</code> y <code>Notas</code>. Las demás se ignoran.</li>';
		echo '<li>Teléfonos de 10 dígitos se toman como México (+52). Para Estados Unidos escribe <code>1 915 555 0101</code> o agrega la columna País = US.</li>';
		echo '<li>Clientes con ' . (int) \MOBookingStudio\Application\Clients::frequentThreshold() . ' sesiones o más quedan como frecuentes (se cambia en Ajustes).</li></ul>';
		echo self::form( 'analyze', '<p><input type="file" name="archivo" accept=".csv,text/csv" required></p><p><button class="button button-primary">Revisar archivo</button></p>', true ); // phpcs:ignore WordPress.Security.EscapeOutput

		$batches = (array) get_option( Loader::BATCH_OPTION, array() );
		if ( $batches ) {
			echo '<h2>Cargas anteriores</h2><table class="widefat striped"><thead><tr><th>Lote</th><th>Fecha</th><th>Nuevos</th><th>Actualizados</th><th></th></tr></thead><tbody>';
			foreach ( array_reverse( $batches, true ) as $id => $b ) {
				echo '<tr><td>' . esc_html( $id ) . '</td><td>' . esc_html( $b['at'] ) . '</td><td>' . count( (array) $b['created'] ) . '</td><td>' . count( (array) $b['updated'] ) . '</td><td>' .
					( empty( $b['undone'] ) ? self::form( 'undo', '<input type="hidden" name="lote" value="' . esc_attr( $id ) . '"><button class="button button-link-delete" onclick="return confirm(\'¿Deshacer esta carga? Se borran los clientes que creó (salvo los que ya tengan reservas) y se regresan los datos que cambió.\')">Deshacer</button>' ) : 'Deshecho' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			if ( get_transient( self::stateKey() . '_reporte' ) ) {
				echo '<p>' . self::form( 'report', '<button class="button">Descargar reporte de la última carga</button>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<p class="description">Cuando termines, desactiva y elimina este plugin (Plugins → MO Booking Studio – Cargar clientes). Tus clientes se quedan en MO Booking Studio.</p>';
		}
	}

	private static function review( array $a ): void {
		$s      = $a['summary'];
		$labels = Loader::statusLabels();
		echo '<h2>Revisión (todavía no se guardó nada)</h2>';
		echo '<ul class="ul-disc"><li><strong>' . (int) $s['new'] . '</strong> clientes nuevos (' . (int) $s['frequent'] . ' quedarán como frecuentes: ' . (int) $s['threshold'] . ' sesiones o más).</li>';
		echo '<li><strong>' . (int) $s['update'] . '</strong> ya existen con cambios propuestos · ' . (int) $s['same'] . ' ya existen sin cambios.</li>';
		echo '<li><strong>' . (int) $s['duplicate'] . '</strong> repetidos dentro del archivo · <strong>' . (int) $s['conflict'] . '</strong> conflictos · <strong>' . (int) $s['error'] . '</strong> con error (no se cargan).</li>';
		echo '<li><strong>' . (int) $s['warnings'] . '</strong> filas con avisos (se cargan, pero revisa el aviso).</li></ul>';
		echo '<table class="widefat striped"><thead><tr><th>Fila</th><th>Resultado</th><th>Nombre</th><th>Correo</th><th>Teléfono</th><th>Sesiones</th><th>Detalle</th></tr></thead><tbody>';
		foreach ( $a['rows'] as $r ) {
			$detail = $r['reason'];
			foreach ( $r['changes'] as $field => $pair ) {
				$detail .= ' ' . esc_html( array( 'historic_sessions' => 'Sesiones', 'email' => 'Correo', 'phone' => 'Teléfono' )[ $field ] ) . ': "' . esc_html( (string) $pair[0] ) . '" → "' . esc_html( (string) $pair[1] ) . '".';
			}
			echo '<tr><td>' . (int) $r['line'] . '</td><td>' . esc_html( $labels[ $r['status'] ] ) . '</td><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( (string) $r['email'] ) . '</td><td>' . esc_html( (string) ( $r['phone_e164'] ?: '—' ) ) . '</td><td>' . (int) $r['sessions'] . '</td><td>' . wp_kses( $detail, array() ) . ( $r['warnings'] ? '<br><em>' . esc_html( implode( ' ', $r['warnings'] ) ) . '</em>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$commit = ( $s['update'] ? '<p><label><input type="checkbox" name="aplicar_actualizaciones" value="1"> Aplicar también las actualizaciones propuestas a clientes que ya existen</label></p>' : '' ) .
			'<p><button class="button button-primary">Cargar ' . (int) $s['new'] . ' clientes</button></p>';
		echo self::form( 'commit', $commit ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p>' . self::form( 'report', '<button class="button">Descargar esta revisión (CSV)</button>' ) . ' ' . self::form( 'cancel', '<button class="button-link">Cancelar y elegir otro archivo</button>' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}

Screen::register();
