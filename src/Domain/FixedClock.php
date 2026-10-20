<?php
namespace MOBookingStudio\Domain;

final class FixedClock implements Clock {
	/** @var \DateTimeImmutable */
	private $now;

	public function __construct( \DateTimeImmutable $now ) {
		$this->now = $now->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	public static function at( string $iso ): self {
		return new self( new \DateTimeImmutable( $iso ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}

	public function advance( string $modifier ): void {
		$this->now = $this->now->modify( $modifier );
	}
}
