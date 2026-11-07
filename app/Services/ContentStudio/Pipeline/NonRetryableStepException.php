<?php

namespace App\Services\ContentStudio\Pipeline;

use RuntimeException;

/** Throw from a step when retrying cannot help (bad input, rights blocked, 401...). */
class NonRetryableStepException extends RuntimeException {}
