<?php
/*
 * VERSIÓN 14 — DEMO POR SOLICITUD
 * Ya no hay prueba gratis automática: el interesado pide una demo en /solicitar-demo y
 * QF Producciones decide a quién se la crea y por cuántos días (Ventas → Solicitudes de demo).
 * Si algún día se quiere volver a la prueba automática: Ajustes → "Permitir prueba gratis automática".
 */

function prueba_automatica(): bool
{
    return (int)ajuste('registro_abierto', 0) === 1;
}

/** Enlace del botón principal de la página de venta: prueba automática o solicitud de demo. */
function url_empezar(string $tipo = '', ?int $plan = null): string
{
    $q = ($tipo === 'escuela' ? '&tipo=escuela' : '') . ($plan ? '&plan=' . $plan : '');
    return prueba_automatica() ? 'index.php?v=registro' . $q : 'index.php?v=solicitar' . $q;
}

/** Texto del botón principal. */
function texto_empezar(bool $corto = false): string
{
    if (prueba_automatica()) return $corto ? 'Prueba gratis' : '🎁 Prueba gratis ' . (int)ajuste('dias_prueba', 7) . ' días';
    return $corto ? 'Demo gratis' : '🎁 Demo gratis de ' . (int)ajuste('dias_demo', 7) . ' días'; // (v27)
}

function estados_solicitud_demo(): array
{
    return ['nueva' => 'Nueva', 'contactada' => 'Contactada', 'creada' => 'Demo creada', 'descartada' => 'Descartada'];
}

function solicitudes_demo_nuevas(): int
{
    return (int)valor("SELECT COUNT(*) FROM solicitudes_demo WHERE estado = 'nueva'");
}

/** Mensaje de WhatsApp para contactar a quien pidió la demo. */
function mensaje_contacto_demo(array $s): string
{
    return 'Hola ' . $s['contacto'] . ', le saluda ' . ajuste('empresa', 'QF Producciones') . ' de ' . marca() . '. Recibimos su solicitud de demo para '
        . $s['organizacion'] . '. ¿En qué horario le podemos llamar para mostrarle el sistema y crearle su acceso de prueba?';
}
