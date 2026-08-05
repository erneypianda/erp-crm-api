<?php

namespace App\Exceptions;

use Exception;

/**
 * Se lanza cuando se intenta vender más unidades de un producto
 * de las que hay disponibles en stock. Se captura en el controlador
 * para abortar la transacción y responder con HTTP 400.
 */
class InsufficientStockException extends Exception
{
    //
}
