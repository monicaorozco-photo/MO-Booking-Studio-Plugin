<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Schedules;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\FixedClock;
use MOBookingStudio\Infrastructure\Installer;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;
use MOBookingStudio\Tests\Fixtures\Christmas;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase {
	/** @var FixedClock */
	protected $clock;

	protected function setUp(): void {
		if ( ! getenv( 'MO_WP_DIR' ) ) {
			$this->markTestSkipped( 'Define MO_WP_DIR para ejecutar pruebas de integración.' );
		}
		global $wpdb;
		foreach ( Schema::tables() as $t ) {
			$wpdb->query( 'TRUNCATE TABLE ' . Schema::table( $t ) );
		}
		delete_option( Settings::OPTION );
		delete_option( 'mo_booking_templates' );
		delete_option( \MOBookingStudio\Integrations\Google\Connection::OPTION );
		delete_option( \MOBookingStudio\Integrations\Google\SyncWorker::MOVE_BLOCKED_OPTION );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mo_gbusy_%' OR option_name LIKE '_transient_timeout_mo_gbusy_%' OR option_name LIKE '_transient_mo_booking_scan_lock%'" );
		wp_cache_flush(); // Las opciones borradas con SQL siguen en la caché de la solicitud.
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mo_rl_%' OR option_name LIKE '_transient_timeout_mo_rl_%'" );
		update_option( \MOBookingStudio\Infrastructure\Migrations::BACKUP_OPTION, Schema::VERSION );
		Installer::upgrade();
		\MOBookingStudio\Infrastructure\PrivateStorage::ensure();
		Settings::update( array( 'uploads_verified' => 'ok', 'uploads_verified_path' => \MOBookingStudio\Infrastructure\PrivateStorage::canonical( \MOBookingStudio\Infrastructure\PrivateStorage::dir() ), 'admin_email' => 'estudio@example.com' ) );
		$this->clock = FixedClock::at( '2026-10-20T18:00:00Z' );
		Plugin::setClock( $this->clock );
		wp_set_current_user( 1 );
		$_COOKIE = array();
	}

	protected function tearDown(): void {
		Plugin::setClock( null );
	}

	/** Plantilla de contrato publicada para los servicios de prueba. */
	protected function contractId(): int {
		global $wpdb;
		$found = (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'contracts' ) . " WHERE name = 'Contrato de prueba' LIMIT 1" );
		return $found ?: \MOBookingStudio\Application\Contracts::save( null, 'Contrato de prueba', 'Contrato de minis', '<p>Acepto las condiciones de la sesión.</p>', true );
	}

	/** Crea el servicio de minis navideñas con la agenda y la matriz de tarifas. */
	protected function christmasService( array $overrides = array() ): array {
		$overrides += array( 'contract_id' => $this->contractId() );
		$resource = Schedules::defaultResourceId();
		$id       = Services::save(
			array_merge(
				array(
					'title'  => 'Minis navideñas 2026',
					'slug'   => 'minis-navidad',
					'brand'  => 'Clics con Amor Fotografía',
					'status' => 'published',
					'min_notice_hours' => 0,
					'horizon_days'     => 0,
				),
				Christmas::service(),
				$overrides
			)
		);
		foreach ( Christmas::dates() as $date => $windows ) {
			Schedules::saveDate( $resource, $date, $windows );
		}
		foreach ( Christmas::rules() as $rule ) {
			unset( $rule['id'] );
			Services::saveRule( $id, $rule );
		}
		Services::savePolicy( $id, 'reschedule', Christmas::reschedulePolicy() );
		Services::savePolicy( $id, 'cancel', Christmas::cancelPolicy() );
		Services::savePolicy( $id, 'no_show', Christmas::cancelPolicy() );
		return Services::get( $id );
	}

	/** Mismo servicio pero con la tabla de precios por temporada (forma simple del panel). */
	protected function christmasTableService(): array {
		$resource = Schedules::defaultResourceId();
		$contract = $this->contractId();
		$id       = Services::save(
			array_merge(
				array(
					'title'            => 'Minis con tabla',
					'slug'             => 'minis-tabla',
					'status'           => 'published',
					'min_notice_hours' => 0,
					'horizon_days'     => 0,
					'price_table'      => Christmas::priceTable(),
					'contract_id'      => $contract,
				),
				Christmas::service()
			)
		);
		foreach ( Christmas::dates() as $date => $windows ) {
			Schedules::saveDate( $resource, $date, $windows );
		}
		return Services::get( $id );
	}

	protected function contact( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'          => 'Ana Prueba',
				'email'         => 'ana@example.com',
				'phone'         => '656 123 4567',
				'phone_country' => 'MX',
				'accept_terms'  => true,
				'whatsapp_consent' => true,
			),
			$overrides
		);
	}

	protected function book( array $service, string $startUtc, array $extra = array() ): array {
		$quote = \MOBookingStudio\Application\Catalog::quote( $service, array_merge( array( 'start' => $startUtc, 'participants' => 6 ), $extra ) );
		return \MOBookingStudio\Application\Bookings::create(
			$service,
			array_merge( $this->contact(), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() ), $extra )
		);
	}

	protected function fakeUpload( string $kind ): array {
		$tmp = tempnam( sys_get_temp_dir(), 'mo' );
		switch ( $kind ) {
			case 'png':
				// PNG válido de 1x1 píxel.
				file_put_contents( $tmp, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
				$name = 'comprobante.png';
				break;
			case 'pdf':
				file_put_contents( $tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF" );
				$name = 'comprobante.pdf';
				break;
			case 'svg':
				file_put_contents( $tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' );
				$name = 'comprobante.svg';
				break;
			case 'php-as-png':
				file_put_contents( $tmp, '<?php echo "hola"; ?>' );
				$name = 'comprobante.png';
				break;
			default:
				throw new \InvalidArgumentException( $kind );
		}
		return array( 'tmp_name' => $tmp, 'name' => $name, 'size' => filesize( $tmp ), 'error' => UPLOAD_ERR_OK );
	}
}
