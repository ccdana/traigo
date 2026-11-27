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

    // En el panel web, con estas unidades disponibles o menos el stock es «bajo».
    'low_stock_threshold' => 5,

    // Pedidos de la tienda web.
    'web' => [
        // Pedidos sin confirmar que puede tener a la vez una cuenta o un WhatsApp.
        'max_open_orders' => 3,
        // Por si falta la configuración `order_reservation_hours`.
        'default_reservation_hours' => 2,
        // Ingresos por minuto: para un correo desde una IP, y desde una IP.
        'logins_per_minute' => 5,
        'logins_per_minute_by_ip' => 20,
        // Cuentas nuevas desde una IP y enlaces para recuperar la contraseña.
        'registrations_per_hour' => 10,
        'password_links_per_minute' => 3,
        // Intentos por minuto de guardar una contraseña nueva.
        'password_changes_per_minute' => 6,
        // Envíos por minuto y pedidos por hora desde una misma IP.
        'checkouts_per_minute' => 5,
        'checkouts_per_hour' => 20,
        'proofs_per_minute' => 6,
        'quotes_per_minute' => 3,
    ],

    // La cuenta de Traigo que Jime compara con cada comprobante.
    'payment_account' => 'Traigo',

];
