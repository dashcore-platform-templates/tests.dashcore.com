<?php

namespace App\Enums;

/**
 * A main scan measures an app's default branch and is its record: surfaces,
 * verdicts, the ratchet. A pull request scan measures a PR merged into that
 * branch and only reports — it never changes what the app's record says.
 */
enum ScanKind: string
{
    case Main = 'main';
    case PullRequest = 'pull_request';
}
