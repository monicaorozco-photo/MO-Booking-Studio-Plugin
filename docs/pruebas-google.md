# Pruebas de Google Calendar en LocalWP (AC-CAL-01 a 10)

Marca cada casilla al comprobarla. Si algo no coincide, toma una captura y anota la referencia `MO-XXXX`.

## Preparación

- [ ] LocalWP en modo router **localhost**, plugin 0.2 instalado y Google conectado (`docs/guia-google-cloud.md`).
- [ ] En Google Calendar crea dos calendarios de prueba: **"MO Reservas (prueba)"** y **"Ocupado (prueba)"**.
- [ ] En el panel: Leer ocupación en ambos; destino "MO Reservas (prueba)".
- [ ] Ajustes → Crear datos de prueba. Crea una página con `[mo_booking service="prueba-minis-navidenas-2026"]`.
- [ ] Para recibir invitaciones, reserva con **tu propio correo** (o uno tuyo secundario). Los correos `@example.com` de los datos de prueba nunca se invitan.
- [ ] Ten abierta la pantalla **Reservas → Google Calendar** en otra pestaña.

## Casos

**AC-CAL-01 · Varios calendarios bloquean**
- [ ] En "Ocupado (prueba)" crea un evento el primer día de minis a la primera hora.
- [ ] En "MO Reservas (prueba)" crea otro evento (tuyo, no del plugin) a la segunda hora.
- [ ] Espera 3 minutos (caché de la vista) y recarga la página de reservas: ambas horas aparecen como no disponibles.
- [ ] Un evento marcado "Disponible" (transparente) **no** bloquea.
- [ ] Un evento de día completo bloquea todo ese día.

**AC-CAL-02 · Mismo horario, dos personas**
- [ ] Abre la página en dos navegadores (o uno en incógnito), elige la misma hora en ambos y envía casi al mismo tiempo. Solo una reserva se crea; la otra ve "ese horario ya no está disponible".

**AC-CAL-03 · Reserva pendiente**
- [ ] Haz una reserva con tu correo. En "MO Reservas (prueba)" aparece **un** evento "PRUEBA · MO PENDIENTE · MO-XXXX".
- [ ] El evento es privado, no tiene invitados y **no te llegó ninguna invitación**.

**AC-CAL-04 · Aprobación**
- [ ] Sube el comprobante desde el enlace privado y aprueba el pago en el panel.
- [ ] El evento interno cambia a "MO CONFIRMADA".
- [ ] Aparece un segundo evento "Sesión con …" y te llega **una** invitación de Google.

**AC-CAL-05 · Privacidad del evento del cliente**
- [ ] Abre la invitación: solo fecha, hora, ubicación, indicaciones y referencia. Sin teléfono, montos, pagos, notas, enlaces del panel ni el enlace privado.
- [ ] El evento interno sí tiene el teléfono y el enlace al panel (que pide iniciar sesión).

**AC-CAL-06 · Vencimiento**
- [ ] Haz otra reserva y no subas comprobante. Para no esperar 12 h: antes de reservar, en Servicios → el servicio de prueba → "Horas para pagar el anticipo" pon 1 (o 0.5), y espera ese tiempo.
- [ ] Al vencer, el evento "MO PENDIENTE" desaparece de Google.

**AC-CAL-07 · Empalme posterior**
- [ ] Sobre la hora de la reserva confirmada, crea un evento en "Ocupado (prueba)".
- [ ] Google Calendar → **Sincronizar y conciliar ahora**: aparece en "Empalmes con Google", la reserva sigue Confirmada y nada se movió. Se registra un aviso al correo del negocio (en modo simulación lo ves en Notificaciones, sin enviarse).
- [ ] "Marcar resuelto" no se permite mientras el evento siga ahí. Bórralo y entonces sí.

**AC-CAL-08 · Repetir no duplica**
- [ ] Pulsa "Sincronizar y conciliar ahora" varias veces: no aparecen eventos repetidos ni invitaciones nuevas.
- [ ] Mueve a mano el evento "Sesión con …" a otra hora y concilia: aparece en "Cambios hechos en Google" como *movido*. **Restaurar desde WordPress** lo regresa a su hora.

**AC-CAL-09 · Revocación**
- [ ] En tu cuenta de Google → Seguridad → Apps de terceros, quita el acceso de "MO Booking Studio".
- [ ] Haz un cambio en una reserva (por ejemplo, reprogramar) y concilia: el estado pasa a "Acceso revocado"; la reserva en línea muestra "No pudimos confirmar la disponibilidad".
- [ ] **Volver a conectar**: los cambios pendientes se envían solos y la reserva en línea vuelve.

**AC-CAL-10 · Zona horaria y cambio de horario**
- [ ] Crea una fecha de disponibilidad el **31 de octubre** y reserva a las 10:00. En Google aparece a las 10:00.
- [ ] Una reserva de noviembre a las 10:00 también aparece a las 10:00 (el 1 de noviembre termina el horario de verano).

## Al terminar

- [ ] Borra los datos de prueba desde Ajustes (sus eventos de Google se borran solos al cancelarse o vencer; los que queden llevan el prefijo "PRUEBA").
- [ ] Desconecta Google en LocalWP si ya no lo usarás.
