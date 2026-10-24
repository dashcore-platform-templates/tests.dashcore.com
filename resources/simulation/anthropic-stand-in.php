<?php

/*
 * The Anthropic stand-in's router, run by `php -S` (AnthropicStandIn): it
 * answers POST /v1/messages the way the Messages API does, with a canned
 * reply chosen by what the request says. A stand-in app pointed at it
 * (ANTHROPIC_BASE_URL) drafts and briefs with no key and no cost, and the
 * same way every run.
 *
 * STAND_IN_DIR holds replies.json — [{"when": "<text the request contains>",
 * "text": "<the reply>"}], the first match wins — and every request is kept
 * there under requests/, in order. A request nothing matches is answered 500
 * and named, so the step that made it fails saying why.
 */

$dir = (string) getenv('STAND_IN_DIR');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/v1/messages') {
    http_response_code(404);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'not_found_error', 'message' => "The Anthropic stand-in answers only POST /v1/messages, not {$path}."]]);

    return true;
}

$body = (string) file_get_contents('php://input');
$request = json_decode($body, true);

@mkdir("{$dir}/requests", 0755, true);
$number = count(glob("{$dir}/requests/*.json") ?: []) + 1;
file_put_contents(sprintf('%s/requests/%03d.json', $dir, $number), $body);

$replies = json_decode((string) @file_get_contents("{$dir}/replies.json"), true);
$reply = null;

foreach (is_array($replies) ? $replies : [] as $candidate) {
    if (is_array($candidate) && isset($candidate['when'], $candidate['text']) && str_contains($body, (string) $candidate['when'])) {
        $reply = (string) $candidate['text'];

        break;
    }
}

if ($reply === null) {
    http_response_code(500);
    echo json_encode(['type' => 'error', 'error' => ['type' => 'api_error', 'message' => "The Anthropic stand-in has no canned reply for request {$number} (kept in {$dir}/requests)."]]);

    return true;
}

echo json_encode([
    'id' => sprintf('msg_stand_in_%03d', $number),
    'type' => 'message',
    'role' => 'assistant',
    'model' => is_array($request) ? (string) ($request['model'] ?? 'stand-in') : 'stand-in',
    'content' => [['type' => 'text', 'text' => $reply]],
    'stop_reason' => 'end_turn',
    'stop_sequence' => null,
    'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
]);

return true;
