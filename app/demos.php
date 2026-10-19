<?php
/*
 * VERSIÓN 14 — DEMO POR SOLICITUD. (18 oct 2026) «Nada se da gratis, todo se pide demo»: ya no existe la prueba gratis
 * automática ni el registro abierto. El interesado pide su demo en /solicitar-demo; Tukán decide a quién se la crea y por
 * cuántos días (Ventas → Solicitudes de demo, o el Panel Tukán Central).
 */

/** Enlace del botón principal de la página de venta: siempre la solicitud de demo (no hay prueba gratis ni registro abierto). */
function url_empezar(string $tipo = '', ?int $plan = null): string
{
    return 'index.php?v=solicitar' . ($tipo === 'escuela' ? '&tipo=escuela' : '') . ($plan ? '&plan=' . $plan : '');
}

/** Texto del botón principal. */
function texto_empezar(bool $corto = false): string
{
    return $corto ? 'Pedir demo' : '📅 Solicitar demo';
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
