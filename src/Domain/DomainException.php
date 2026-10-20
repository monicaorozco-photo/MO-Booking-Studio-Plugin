<?php
namespace MOBookingStudio\Domain;

/**
 * Error de negocio con código estable y mensaje comprensible para la persona.
 * El código HTTP sugerido lo usa la capa REST.
 */
class DomainException extends \RuntimeException {
	/** @var string */
	private $errorCode;
	/** @var int */
	private $httpStatus;
	/** @var array<string,mixed> */
	private $details;

	public function __construct( string $errorCode, string $message, int $httpStatus = 400, array $details = array() ) {
		parent::__construct( $message );
		$this->errorCode  = $errorCode;
		$this->httpStatus = $httpStatus;
		$this->details    = $details;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}

	public function httpStatus(): int {
		return $this->httpStatus;
	}

	public function details(): array {
		return $this->details;
	}
}
