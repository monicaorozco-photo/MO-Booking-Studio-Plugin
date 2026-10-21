<?php
namespace MOBookingStudio\Integrations\Google;

/**
 * Error de la API de Google clasificado para decidir si se reintenta (SYNC-02).
 *
 * Tipos: auth (credenciales revocadas o inválidas), rate (límite de uso), transient
 * (5xx, red, tiempo de espera), gone (410), not_found (404), conflict (409),
 * permanent (datos o permisos: no se reintenta).
 */
final class GoogleException extends \RuntimeException {
	/** @var string */
	public $type;
	/** @var int */
	public $http;
	/** @var string */
	public $requestId;

	public function __construct( string $type, int $http, string $message, string $requestId = '' ) {
		parent::__construct( $message );
		$this->type      = $type;
		$this->http      = $http;
		$this->requestId = $requestId;
	}

	public function retryable(): bool {
		return in_array( $this->type, array( 'rate', 'transient' ), true );
	}
}
