<?php

namespace App\Services\Coder;

use RuntimeException;

/** A Coder operation failed for a reason that the user can read. The message is Spanish, for the panel. */
class CoderException extends RuntimeException {}
