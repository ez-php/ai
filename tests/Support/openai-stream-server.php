<?php

declare(strict_types=1);

/**
 * Router for the `php -S` server started by AiStreamEndToEndTest.
 *
 * Serves an OpenAI-shaped chat completion stream with pauses between tokens.
 */

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);

if ($path !== '/v1/chat/completions') {
    http_response_code(404);

    return true;
}

$emit = static function (string $data): void {
    echo 'data: ' . $data . "\n\n";

    if (ob_get_level() > 0) {
        ob_flush();
    }

    flush();
};

$chunk = static fn (string $content, ?string $finishReason): string => (string) json_encode([
    'choices' => [['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => $finishReason]],
]);

header('Content-Type: text/event-stream');

foreach (['Hel', 'lo', ' world'] as $i => $piece) {
    if ($i > 0) {
        usleep(300_000);
    }

    $emit($chunk($piece, null));
}

$emit($chunk('', 'stop'));
$emit('[DONE]');

return true;
