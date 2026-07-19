<?php

namespace App\Domain\Deploy;

use RuntimeException;

/**
 * Raised when a deploy pipeline step fails (docs/SPEC.md §6).
 */
class DeployException extends RuntimeException {}
