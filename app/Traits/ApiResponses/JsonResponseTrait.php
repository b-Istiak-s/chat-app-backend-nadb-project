<?php

namespace App\Traits\ApiResponses;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

trait JsonResponseTrait
{
    /**
     * Send a success response.
     *
     * @param  mixed  $data
     */
    public function sendSuccessResponse($data, string $message = 'Response Successful', int $status = Response::HTTP_OK): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    /**
     * Send an error response.
     */
    public function sendErrorResponse(string $message, int $status = Response::HTTP_BAD_REQUEST, array $extra = []): JsonResponse
    {
        if ($status < 400 || $status > 599) {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        $response = [
            'success' => false,
            'message' => $message,
            'data' => [],
        ];

        if (! empty($extra)) {
            $response = array_merge($response, $extra);
        }

        return response()->json($response, $status);
    }

    /**
     * Handle errors and return appropriate response.
     */
    protected function handleError(\Throwable $e): JsonResponse
    {
        if ($e instanceof HttpResponseException) {
            $response = $e->getResponse();
            $statusCode = $response->getStatusCode();
            $payload = $response instanceof JsonResponse
                ? $response->getData(true)
                : [];

            $message = is_array($payload) && isset($payload['message']) && is_string($payload['message'])
                ? $payload['message']
                : ($e->getMessage() ?: 'Request failed.');

            $extra = [];
            if (is_array($payload) && isset($payload['errors']) && is_array($payload['errors'])) {
                $extra['errors'] = $payload['errors'];
            }

            return $this->sendErrorResponse($message, $statusCode, $extra);
        }

        if ($e instanceof AuthorizationException) {
            return $this->sendErrorResponse(
                $e->getMessage() ?: 'This action is unauthorized.',
                Response::HTTP_FORBIDDEN
            );
        }

        $statusCode = $e instanceof HttpExceptionInterface
            ? (int) $e->getStatusCode()
            : (int) $e->getCode();

        if ($statusCode < 400 || $statusCode > 599) {
            $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
        }

        Log::error('API Exception', [
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'status' => $statusCode,
            'url' => request()->fullUrl(),
            'input' => request()->except(['password', 'token']),
            'user_id' => Auth::check() ? Auth::id() : null,
            'trace' => $e->getTraceAsString(),
        ]);

        return $this->sendErrorResponse($e->getMessage(), $statusCode);
    }

    /**
     * Send a response containing validation error details.
     */
    public function sendValidationErrorResponse(ValidatorContract $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
        );
    }
}
