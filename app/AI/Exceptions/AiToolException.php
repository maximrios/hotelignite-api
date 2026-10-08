<?php

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * Error esperable de una herramienta (destino inexistente, alojamiento no
 * visible, fechas fuera de rango). El mensaje lo lee el modelo, así que tiene
 * que decir qué hacer a continuación. El adapter lo convierte en un resultado
 * de herramienta con error, no en una falla del servidor.
 */
class AiToolException extends RuntimeException {}
