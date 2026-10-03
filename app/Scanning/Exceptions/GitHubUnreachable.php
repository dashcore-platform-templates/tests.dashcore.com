<?php

namespace App\Scanning\Exceptions;

use RuntimeException;

/** GitHub could not be reached; the work should be tried again, not failed. */
class GitHubUnreachable extends RuntimeException {}
