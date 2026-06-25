<?php

namespace Nexphant\Server\Http;

use Nexphant\Database\Model;
use Nexphant\Foundation\Data;

/**
 * Return Value Resolver — converts controller return values to ServerResponse.
 *
 * Priority:
 *   ServerResponse  → pass-through
 *   array           → JsonResponse
 *   string          → HtmlResponse
 *   Data (DTO)      → JsonResponse
 *   Model           → JsonResponse (toVisibleArray)
 *   array of Model  → JsonResponse
 *   null            → 204 No Content
 *   Generator       → StreamResponse (chunked)
 */
final class ReturnValueResolver
{
    public static function resolve(mixed $value, \Nexphant\Server\ServerResponse $res): \Nexphant\Server\ServerResponse
    {
        // Already a ServerResponse — pass through
        if ($value instanceof \Nexphant\Server\ServerResponse) {
            return $value;
        }

        // Null → 204
        if ($value === null) {
            return $res->status(204)->body('');
        }

        // Array of Models
        if (is_array($value) && count($value) > 0 && reset($value) instanceof Model) {
            return $res->json(array_map(
                fn($m) => method_exists($m, 'toVisibleArray') ? $m->toVisibleArray() : $m->toArray(),
                $value
            ));
        }

        // Plain array → JSON
        if (is_array($value)) {
            return $res->json($value);
        }

        // DTO
        if ($value instanceof Data) {
            return $res->json($value->toArray());
        }

        // Single Model
        if ($value instanceof Model) {
            $data = method_exists($value, 'toVisibleArray') ? $value->toVisibleArray() : $value->toArray();
            return $res->json($data);
        }

        // Generator → stream
        if ($value instanceof \Generator) {
            $body = '';
            foreach ($value as $chunk) {
                $body .= (string) $chunk;
            }
            return $res->body($body);
        }

        // String → HTML
        if (is_string($value)) {
            return $res->html($value);
        }

        // int/float/bool → JSON scalar
        if (is_scalar($value)) {
            return $res->json(['value' => $value]);
        }

        return $res->json(['data' => $value]);
    }
}
