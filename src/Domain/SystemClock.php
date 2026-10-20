<?php
namespace MOBookingStudio\Domain;

final class SystemClock implements Clock {
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}
}
