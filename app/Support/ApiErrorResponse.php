<?php

namespace App\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Builds the storefront-facing JSON error body for `/api/*`:
 * `{message, code}` (plus `errors` for 422). `message` is always safe to
 * display verbatim — unexpected failures (database/cache/Redis outages,
 * bugs) collapse to a generic 500 `server_error` so no SQL, connection
 * string, class name or trace ever reaches a client.
 */
final class ApiErrorResponse
{
    public static function from(Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'validation_failed',
                'errors' => $e->errors(),
            ], $e->status);
        }

        if ($e instanceof AuthenticationException) {
            return self::body(401, 'unauthenticated', 'Please sign in to continue.');
        }

        if ($e instanceof AuthorizationException) {
            return self::body(403, 'forbidden', "You don't have permission to do that.");
        }

        if ($e instanceof TokenMismatchException) {
            return self::body(419, 'session_expired', 'Your session expired. Please refresh and try again.');
        }

        if ($e instanceof ModelNotFoundException) {
            return self::body(404, 'not_found', "We couldn't find what you were looking for.");
        }

        if ($e instanceof HttpExceptionInterface) {
            return self::fromHttpException($e);
        }

        return self::body(500, 'server_error', 'Something went wrong on our side. Please try again in a moment.');
    }

    private static function fromHttpException(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = $e->getHeaders();

        if ($status >= 500) {
            return $status === 503
                ? self::body(503, 'service_unavailable', "We're temporarily unavailable. Please try again shortly.", $headers)
                : self::body($status, 'server_error', 'Something went wrong on our side. Please try again in a moment.', $headers);
        }

        $message = trim($e->getMessage());

        return match ($status) {
            401 => self::body(401, 'unauthenticated', $message !== '' ? $message : 'Please sign in to continue.', $headers),
            403 => self::body(403, 'forbidden', $message !== '' ? $message : "You don't have permission to do that.", $headers),
            404 => self::body(404, 'not_found', "We couldn't find what you were looking for.", $headers),
            405 => self::body(405, 'method_not_allowed', "That request isn't supported.", $headers),
            409 => self::body(409, 'conflict', $message !== '' ? $message : 'That request conflicts with the current state. Please refresh and try again.', $headers),
            419 => self::body(419, 'session_expired', 'Your session expired. Please refresh and try again.', $headers),
            429 => self::body(429, 'too_many_requests', $message !== '' ? $message : 'Too many attempts. Please try again later.', $headers + ['Retry-After' => '60']),
            default => self::body($status, 'request_failed', $message !== '' ? $message : 'We could not complete that request.', $headers),
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function body(int $status, string $code, string $message, array $headers = []): JsonResponse
    {
        $body = ['message' => $message, 'code' => $code];

        if ($status === 429) {
            $body['retry_after'] = (int) ($headers['Retry-After'] ?? 60);
        }

        return response()->json($body, $status, $headers);
    }
}
