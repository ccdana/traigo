<?php

/**
 * Ajustes de la API de la app. Los datos del negocio están en la base
 * (tabla `settings`); aquí solo van límites de seguridad.
 */
return [

    'login' => [
        // Intentos por minuto para un mismo código desde una misma IP.
        'attempts_per_minute' => 5,
        // Intentos por minuto desde una IP, con cualquier código.
        'attempts_per_minute_by_ip' => 20,
        // Al llegar a estos PIN fallidos seguidos, la cuenta se bloquea.
        'lock_after_failures' => 5,
        'lock_minutes' => 15,
    ],

    'two_factor' => [
        'ttl_minutes' => 5,
        'resend_after_seconds' => 45,
        'max_attempts' => 5,
        // Códigos que se pueden pedir por cuenta en la ventana.
        'max_codes' => 5,
        'window_minutes' => 15,
    ],

    // Minutos que sirve el enlace firmado de un comprobante.
    'file_link_minutes' => 60,

    // Horas que se guarda la respuesta de un envío con Idempotency-Key.
    'idempotency_hours' => 24,

    // Peticiones por minuto de una sesión.
    'api_requests_per_minute' => 120,

];
