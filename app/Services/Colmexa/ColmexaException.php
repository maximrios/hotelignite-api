<?php

namespace App\Services\Colmexa;

use RuntimeException;

/**
 * Colmexa no está configurado, no respondió o rechazó el pedido. El mensaje es
 * legible para el hotelero; el detalle técnico queda en el log.
 */
class ColmexaException extends RuntimeException {}
