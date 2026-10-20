<?php
namespace MOBookingStudio\Domain;

/**
 * Fuente única de "ahora". Siempre en UTC. Las pruebas inyectan un reloj fijo
 * para simular fechas sin cambiar el reloj del servidor.
 */
interface Clock {
	public function now(): \DateTimeImmutable;
}
