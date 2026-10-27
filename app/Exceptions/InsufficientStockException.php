<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by StockService when a stock_out or correction would result in
 * negative stock for a product. The controller catches this and returns
 * a 422 response so the client can inform the user.
 */
class InsufficientStockException extends RuntimeException {}
