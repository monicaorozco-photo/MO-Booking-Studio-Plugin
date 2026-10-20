<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Tablas propias del plugin. dbDelta es idempotente: activar o actualizar no
 * duplica ni borra datos. Todas las fechas se guardan en UTC (DATETIME).
 */
final class Schema {
	public const VERSION = '1';
	public const OPTION  = 'mo_booking_schema_version';

	public static function tables(): array {
		return array(
			'resources',
			'categories',
			'services',
			'availability',
			'clients',
			'bookings',
			'occupancies',
			'calendar_locks',
			'quotes',
			'price_rules',
			'policies',
			'payments',
			'proofs',
			'access_tokens',
			'portal_sessions',
			'notifications',
			'audit',
		);
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'mo_' . $name;
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = static function ( $n ) {
			return self::table( $n );
		};

		$sql = array();

		$sql[] = "CREATE TABLE {$t('resources')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('categories')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  slug varchar(190) NOT NULL,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('services')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(190) NOT NULL,
  title varchar(190) NOT NULL,
  brand varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  resource_id bigint(20) unsigned NOT NULL,
  category_id bigint(20) unsigned DEFAULT NULL,
  config longtext NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status)
) ENGINE=InnoDB $c;";

		// type: weekly | date | closed | block
		$sql[] = "CREATE TABLE {$t('availability')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  type varchar(10) NOT NULL,
  weekday tinyint(3) unsigned DEFAULT NULL,
  date date DEFAULT NULL,
  start_time char(5) DEFAULT NULL,
  end_time char(5) DEFAULT NULL,
  start_utc datetime DEFAULT NULL,
  end_utc datetime DEFAULT NULL,
  note varchar(190) NOT NULL DEFAULT '',
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_type (resource_id,type),
  KEY resource_date (resource_id,date),
  KEY resource_block (resource_id,start_utc,end_utc)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('clients')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(190) NOT NULL,
  email varchar(190) NOT NULL DEFAULT '',
  email_norm varchar(190) DEFAULT NULL,
  phone varchar(40) NOT NULL DEFAULT '',
  phone_country char(2) NOT NULL DEFAULT 'MX',
  phone_e164 varchar(20) DEFAULT NULL,
  category varchar(20) NOT NULL DEFAULT 'general',
  manual_category varchar(20) DEFAULT NULL,
  historic_sessions int(10) unsigned NOT NULL DEFAULT 0,
  completed_sessions int(10) unsigned NOT NULL DEFAULT 0,
  needs_review tinyint(1) NOT NULL DEFAULT 0,
  review_note varchar(190) NOT NULL DEFAULT '',
  notes text,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY email_norm (email_norm),
  KEY phone_e164 (phone_e164)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('bookings')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_ref varchar(20) NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  service_version int(10) unsigned NOT NULL DEFAULT 1,
  category_id bigint(20) unsigned DEFAULT NULL,
  client_id bigint(20) unsigned NOT NULL,
  resource_id bigint(20) unsigned NOT NULL,
  status varchar(30) NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  timezone varchar(64) NOT NULL,
  participants smallint(5) unsigned NOT NULL DEFAULT 1,
  pets smallint(5) unsigned NOT NULL DEFAULT 0,
  client_category varchar(20) NOT NULL DEFAULT 'general',
  frequent_claim tinyint(1) NOT NULL DEFAULT 0,
  session_counted tinyint(1) NOT NULL DEFAULT 0,
  total_cents bigint(20) NOT NULL DEFAULT 0,
  deposit_cents bigint(20) NOT NULL DEFAULT 0,
  quote longtext NOT NULL,
  policy longtext NOT NULL,
  answers longtext,
  contact longtext NOT NULL,
  consent longtext,
  expires_at datetime DEFAULT NULL,
  correction_until datetime DEFAULT NULL,
  idempotency_key varchar(64) NOT NULL,
  request_hash char(64) NOT NULL DEFAULT '',
  token_nonce char(32) NOT NULL,
  internal_notes text,
  version int(10) unsigned NOT NULL DEFAULT 1,
  source varchar(20) NOT NULL DEFAULT 'public',
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_ref (public_ref),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY status_expires (status,expires_at),
  KEY start_utc (start_utc),
  KEY client_id (client_id),
  KEY service_id (service_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('occupancies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  resource_id bigint(20) unsigned NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  start_utc datetime NOT NULL,
  end_utc datetime NOT NULL,
  kind varchar(20) NOT NULL DEFAULT 'booking',
  expires_at datetime DEFAULT NULL,
  released_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY resource_range (resource_id,start_utc,end_utc),
  KEY booking_id (booking_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('calendar_locks')} (
  resource_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  PRIMARY KEY  (resource_id,day)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('quotes')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quote_key char(32) NOT NULL,
  service_id bigint(20) unsigned NOT NULL,
  start_utc datetime NOT NULL,
  payload longtext NOT NULL,
  pricing_hash char(64) NOT NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY quote_key (quote_key),
  KEY expires_at (expires_at)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('price_rules')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL,
  name varchar(190) NOT NULL,
  kind varchar(20) NOT NULL,
  priority int(11) NOT NULL DEFAULT 0,
  amount_type varchar(10) NOT NULL DEFAULT 'fixed',
  value decimal(14,3) NOT NULL DEFAULT 0,
  stackable tinyint(1) NOT NULL DEFAULT 0,
  conditions longtext NOT NULL,
  active tinyint(1) NOT NULL DEFAULT 1,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY service_id (service_id)
) ENGINE=InnoDB $c;";

		// Cada edición crea una versión nueva; las reservas guardan la versión aceptada.
		$sql[] = "CREATE TABLE {$t('policies')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(20) NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  ranges longtext NOT NULL,
  summary text,
  is_current tinyint(1) NOT NULL DEFAULT 1,
  is_test tinyint(1) NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY service_action (service_id,action,is_current)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('payments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  proof_id bigint(20) unsigned DEFAULT NULL,
  type varchar(20) NOT NULL,
  amount_cents bigint(20) NOT NULL,
  method varchar(30) NOT NULL DEFAULT 'transfer',
  reference varchar(190) NOT NULL DEFAULT '',
  received_at datetime DEFAULT NULL,
  reverses_id bigint(20) unsigned DEFAULT NULL,
  category_id bigint(20) unsigned DEFAULT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  idempotency_key varchar(80) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY idempotency_key (idempotency_key),
  KEY booking_id (booking_id),
  KEY received_at (received_at)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('proofs')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  stored_name varchar(80) NOT NULL,
  original_name varchar(190) NOT NULL DEFAULT '',
  mime varchar(60) NOT NULL,
  size_bytes int(10) unsigned NOT NULL,
  sha256 char(64) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  review_note varchar(255) NOT NULL DEFAULT '',
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  received_late tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY booking_id (booking_id),
  KEY sha256 (sha256),
  KEY status (status)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('access_tokens')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  booking_id bigint(20) unsigned NOT NULL,
  scope varchar(20) NOT NULL DEFAULT 'booking',
  token_hash char(64) NOT NULL,
  expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY booking_id (booking_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('portal_sessions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_hash char(64) NOT NULL,
  csrf_hash char(64) NOT NULL,
  booking_id bigint(20) unsigned NOT NULL,
  token_id bigint(20) unsigned NOT NULL,
  expires_at datetime NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY session_hash (session_hash),
  KEY expires_at (expires_at)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  event varchar(40) NOT NULL,
  channel varchar(20) NOT NULL,
  booking_id bigint(20) unsigned DEFAULT NULL,
  recipient varchar(190) NOT NULL DEFAULT '',
  status varchar(30) NOT NULL DEFAULT 'pending',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  send_after datetime NOT NULL,
  valid_until datetime DEFAULT NULL,
  dedupe_key varchar(120) NOT NULL,
  payload longtext,
  last_error varchar(255) NOT NULL DEFAULT '',
  provider_ref varchar(190) NOT NULL DEFAULT '',
  locked_until datetime DEFAULT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY dedupe_key (dedupe_key),
  KEY status_send (status,send_after),
  KEY booking_id (booking_id)
) ENGINE=InnoDB $c;";

		$sql[] = "CREATE TABLE {$t('audit')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_type varchar(20) NOT NULL,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  entity varchar(30) NOT NULL,
  entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(60) NOT NULL,
  data longtext,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity,entity_id),
  KEY created_at (created_at)
) ENGINE=InnoDB $c;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function isCurrent(): bool {
		return get_option( self::OPTION ) === self::VERSION;
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		delete_option( self::OPTION );
	}
}
