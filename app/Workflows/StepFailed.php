<?php

namespace App\Workflows;

use RuntimeException;

/** A step failure that retrying cannot fix; staff must correct the data and resend. */
class StepFailed extends RuntimeException {}
