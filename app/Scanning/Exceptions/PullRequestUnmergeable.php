<?php

namespace App\Scanning\Exceptions;

use RuntimeException;

/** GitHub has no merge ref for the PR: it conflicts with its base, or is closed. */
class PullRequestUnmergeable extends RuntimeException {}
