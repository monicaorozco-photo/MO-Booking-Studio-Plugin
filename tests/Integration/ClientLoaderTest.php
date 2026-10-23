<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Clients;
use MOBookingStudio\Infrastructure\Schema;
use MOCargarClientes\Loader;

require_once dirname( __DIR__, 2 ) . '/tools/mo-cargar-clientes/mo-cargar-clientes.php';

/**
 * Cargador de clientes de un solo uso (tools/mo-cargar-clientes), con el formato de la
 * base de ejemplo de Mónica (datos ficticios).
 */
final class ClientLoaderTest extends IntegrationTestCase {
	protected function setUp(): void {
		parent::setUp();
		delete_option( Loader::BATCH_OPTION );
	}

	private function csv( string $content ): string {
		$path = tempnam( sys_get_temp_dir(), 'mocsv' );
		file_put_contents( $path, $content );
		return $path;
	}

	private function sample(): string {
		return $this->csv(
			"Nombre,Apellido,No. Sesiones,Email,Teléfono\n" .
			"Angelic,Falis,5,fala@mail.com,656 062 6707\n" .
			"Itz,Marti,5,Itz@mail.com,52 656286380\n" .
			"Isabel,luna,7,Isague4@hotmail.com,#ERROR!\n" .
			"Suzet,De la torre,2,suzett@gmail.com,6566678939\n" .
			",,,,\n,,,,\n,,,,52\n"
		);
	}

	private function clientCount(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) );
	}

	public function test_sample_file_is_reviewed_without_saving(): void {
		$a = Loader::analyze( Loader::read( $this->sample() ) );
		$this->assertSame( 0, $this->clientCount(), 'La revisión no guarda nada.' );
		$rows = array_column( $a['rows'], null, 'line' );
		$this->assertCount( 4, $rows, 'Filas vacías y la celda con solo "52" se ignoran.' );

		$this->assertSame( 'new', $rows[2]['status'] );
		$this->assertSame( 'Angelic Falis', $rows[2]['name'] );
		$this->assertSame( '+526560626707', $rows[2]['phone_e164'] );
		$this->assertSame( 5, $rows[2]['sessions'] );

		// "52 656286380": solo 9 dígitos después del 52.
		$this->assertSame( 'new', $rows[3]['status'] );
		$this->assertNull( $rows[3]['phone_e164'] );
		$this->assertStringContainsString( 'incompleto', implode( ' ', $rows[3]['warnings'] ) );
		$this->assertSame( 'itz@mail.com', $rows[3]['email_norm'] );

		// "#ERROR!" de la hoja de cálculo: se carga con el correo y se avisa.
		$this->assertNull( $rows[4]['phone_e164'] );
		$this->assertStringContainsString( 'error de la hoja de cálculo', implode( ' ', $rows[4]['warnings'] ) );
		$this->assertSame( 'Isabel luna', $rows[4]['name'], 'No se cambia la ortografía.' );

		$this->assertSame( '+526566678939', $rows[5]['phone_e164'] );
		$this->assertSame( 4, $a['summary']['new'] );
		$this->assertSame( 3, $a['summary']['frequent'], '5, 5 y 7 sesiones (umbral 3); Suzet con 2 no.' );
	}

	public function test_commit_creates_frequent_clients_and_is_not_repeated(): void {
		$a      = Loader::analyze( Loader::read( $this->sample() ) );
		$result = Loader::commit( $a, false );
		$this->assertSame( 4, $result['created'] );
		$this->assertSame( 4, $this->clientCount() );
		global $wpdb;
		$angelic = Clients::find( (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'clients' ) . " WHERE email_norm = 'fala@mail.com'" ) );
		$this->assertSame( 'frequent', Clients::effectiveCategory( $angelic ) );
		$this->assertSame( '+526560626707', $angelic['phone_e164'] );
		$suzet = Clients::find( (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'clients' ) . " WHERE email_norm = 'suzett@gmail.com'" ) );
		$this->assertSame( 'general', Clients::effectiveCategory( $suzet ) );

		// El mismo archivo otra vez: bloqueado, y una revisión nueva no propone duplicarlos.
		try {
			Loader::commit( Loader::analyze( Loader::read( $this->sample() ) ), true );
			$this->fail( 'El mismo archivo no se carga dos veces.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'ya se cargó', $e->getMessage() );
		}
		$again = Loader::analyze( Loader::read( $this->sample() ) );
		$this->assertSame( 0, $again['summary']['new'] );
		$this->assertSame( 4, $again['summary']['same'] );
		$this->assertSame( 4, $this->clientCount() );
	}

	public function test_updates_are_proposed_never_lower_and_undo_restores(): void {
		$existing = Clients::create( array( 'name' => 'Angélica Falis', 'email' => 'fala@mail.com', 'historic_sessions' => 2 ) );
		$a        = Loader::analyze( Loader::read( $this->sample() ) );
		$row      = array_column( $a['rows'], null, 'line' )[2];
		$this->assertSame( 'update', $row['status'] );
		$this->assertSame( array( 2, 5 ), $row['changes']['historic_sessions'] );
		$this->assertSame( array( '', '656 062 6707' ), $row['changes']['phone'] );
		$this->assertStringContainsString( 'el nombre no se cambia', implode( ' ', $row['warnings'] ) );

		// Sin autorizar actualizaciones, el existente no cambia.
		$result = Loader::commit( $a, false );
		$this->assertSame( 0, $result['updated'] );
		$this->assertSame( 2, (int) Clients::find( $existing )['historic_sessions'] );

		$second = Loader::analyze( Loader::read( $this->csv( "Nombre,Apellido,No. Sesiones,Email,Teléfono\nAngelic,Falis,6,fala@mail.com,656 062 6707\n" ) ) );
		$res    = Loader::commit( $second, true );
		$this->assertSame( 1, $res['updated'] );
		$this->assertSame( 6, (int) Clients::find( $existing )['historic_sessions'] );
		$this->assertSame( '+526560626707', Clients::find( $existing )['phone_e164'] );
		$this->assertSame( 'Angélica Falis', Clients::find( $existing )['name'] );

		// Un número menor nunca reduce lo que ya hay.
		$lower = Loader::analyze( Loader::read( $this->csv( "Nombre,Email,No. Sesiones\nAngelica Falis,fala@mail.com,1\n" ) ) );
		$this->assertSame( 'same', $lower['rows'][0]['status'] );

		// Deshacer el segundo lote regresa los valores anteriores.
		$undo = Loader::undo( $res['batch'] );
		$this->assertSame( 1, $undo['restored'] );
		$this->assertSame( 2, (int) Clients::find( $existing )['historic_sessions'] );
		$this->assertSame( '', (string) Clients::find( $existing )['phone'] );
	}

	public function test_undo_keeps_clients_that_already_booked(): void {
		$res = Loader::commit( Loader::analyze( Loader::read( $this->sample() ) ), false );
		$service = $this->christmasService();
		$this->book( $service, '2026-11-20T17:00:00Z', array( 'name' => 'Suzet De la torre', 'email' => 'suzett@gmail.com', 'phone' => '6566678939' ) );
		$undo = Loader::undo( $res['batch'] );
		$this->assertSame( 3, $undo['deleted'] );
		$this->assertSame( 1, $undo['kept'] );
		$this->assertSame( 1, $this->clientCount() );
		$this->expectException( \RuntimeException::class );
		Loader::undo( $res['batch'] );
	}

	public function test_duplicates_conflicts_errors_and_similar_names(): void {
		Clients::create( array( 'name' => 'Laura Uno', 'email' => 'laura@x.mx' ) );
		Clients::create( array( 'name' => 'Laura Dos', 'phone' => '6561112233' ) );
		$a    = Loader::analyze(
			Loader::read(
				$this->csv(
					"Nombre,Email,Teléfono,Sesiones\n" .
					"María José Núñez,mj@x.mx,6560000001,3\n" .
					"Maria Jose Nunez,otra@x.mx,6560000002,1\n" .
					"MJ repetida,MJ@X.MX,,4\n" .
					"Laura,laura@x.mx,656 111 2233,2\n" .
					"Sin contacto,correo-malo,123,1\n" .
					",sin-nombre@x.mx,,\n" .
					"Pedro EUA,,1 915 555 0101,dos\n"
				)
			)
		);
		$rows = array_column( $a['rows'], null, 'line' );
		$this->assertStringContainsString( 'Nombre parecido a la fila 2', implode( ' ', $rows[3]['warnings'] ), 'Acentos distintos: sugerencia, no fusión.' );
		$this->assertSame( 'new', $rows[3]['status'] );
		$this->assertSame( 'duplicate', $rows[4]['status'], 'Mismo correo con mayúsculas.' );
		$this->assertSame( 'conflict', $rows[5]['status'], 'Correo de un cliente y teléfono de otro.' );
		$this->assertSame( 'error', $rows[6]['status'] );
		$this->assertSame( 'error', $rows[7]['status'] );
		$this->assertSame( '+19155550101', $rows[8]['phone_e164'] );
		$this->assertSame( 0, $rows[8]['sessions'] );
		$this->assertStringContainsString( 'no es un número entero', implode( ' ', $rows[8]['warnings'] ) );
	}

	public function test_semicolons_windows_encoding_and_safe_report(): void {
		$content = mb_convert_encoding( "Nombre;Apellido;Email;Teléfono;Notas\nJosé;Peña;jose@x.mx;6561234567;=HYPERLINK(\"http://x\")\n", 'Windows-1252', 'UTF-8' );
		$a       = Loader::analyze( Loader::read( $this->csv( $content ) ) );
		$this->assertSame( 'José Peña', $a['rows'][0]['name'] );
		$report = Loader::reportCsv( $a );
		$this->assertStringContainsString( 'José Peña', $report );
		$this->assertStringNotContainsString( ',=HYPERLINK', $report );
		$this->assertSame( "'=1+1", Loader::csvCell( '=1+1' ) );
		$this->assertSame( "'+52 656", Loader::csvCell( '+52 656' ) );
	}

	public function test_missing_columns_are_explained(): void {
		$this->expectExceptionMessage( 'columna del nombre' );
		Loader::analyze( Loader::read( $this->csv( "Cliente X,Email\nAna,a@x.mx\n" ) ) );
	}
}
