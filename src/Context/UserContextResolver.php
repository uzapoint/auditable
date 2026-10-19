<?php

namespace Uzapoint\Auditable\Context;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

/**
 * Who caused an audited change. Tried in order: gateway headers, request
 * body, a context set for a queued job, local auth, then "system".
 *
 * Every context returned has every key below, so callers never index a
 * missing key, and resolving never throws: a missing X-Person header,
 * partial person data or an undefined auth guard only leave fields null.
 */
class UserContextResolver
{
    private const array KEYS = [
        'id' => null,
        'name' => null,
        'email' => null,
        'phone' => null,
        'type' => null,
        'business_id' => null,
        'person' => [],
        'permissions' => [],
        'roles' => [],
        'terminal_id' => null,
        'terminal_information' => null,
        'source' => null,
    ];

    public static function resolve(): array
    {
        $context = self::fromGatewayHeaders();
        if (!is_null($context['id'])) return $context;

        $context = self::fromRequestBody();
        if (!is_null($context['id'])) return $context;

        $context = self::fromQueuedContext();
        if (!is_null($context['id'])) return $context;

        $context = self::fromLocalAuth();
        if (!is_null($context['id'])) return $context;

        return self::systemContext();
    }

    private static function fromGatewayHeaders(): array
    {
        $request = Request::instance();

        // The gateway sets X-Person-ID, X-User-ID and X-Business-ID from the
        // JWT. X-Person (the full person) is optional and usually absent.
        $userId = $request->header('X-Person-ID');
        $businessId = $request->header('X-Business-ID');

        $person = self::decodePersonFromHeaders($request)
            ?? self::arrayInput($request, 'person');

        if (!$userId && !$request->input('user_id')) {
            return self::emptyContext();
        }

        return self::normalize([
            'id' => $userId ?? $request->input('user_id'),
            'name' => self::personName($person),
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'type' => $person['type'] ?? 'App\\Models\\Person',
            'business_id' => $businessId,
            'person' => $person, // Full person array for extra context
            'permissions' => self::decodePermissions($request),
            'roles' => self::decodeRoles($request),
            'terminal_id' => $request->input('terminal_id'),
            'terminal_information' => $request->input('terminal_information'),
            'source' => 'gateway_header',
        ]);
    }

    /**
     * Decode person info if it was passed as a header (alternative to body).
     */
    private static function decodePersonFromHeaders($request): ?array
    {
        $encoded = $request->header('X-Person');
        if (!$encoded) {
            return null;
        }

        $decoded = json_decode((string) base64_decode($encoded, true), true);
        if (!is_array($decoded)) {
            Log::warning('Ignoring an X-Person header that is not base64-encoded JSON.');
            return null;
        }

        return $decoded;
    }

    /**
     * Decode base64-encoded permissions from gateway.
     */
    private static function decodePermissions($request): array
    {
        $encoded = $request->header('X-User-Permissions');
        if (!$encoded) {
            return [];
        }

        try {
            return json_decode(base64_decode($encoded), true) ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to decode X-User-Permissions', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Decode base64-encoded roles from gateway.
     */
    private static function decodeRoles($request): array
    {
        $encoded = $request->header('X-User-Roles');
        if (!$encoded) {
            return [];
        }

        try {
            return json_decode(base64_decode($encoded), true) ?? [];
        } catch (\Exception $e) {
            Log::warning('Failed to decode X-User-Roles', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Extract from request body (gateway merges user_id and person here).
     */
    private static function fromRequestBody(): array
    {
        $request = Request::instance();

        $userId = $request->input('user_id');
        if (!$userId) {
            return self::emptyContext();
        }

        $person = self::arrayInput($request, 'person');

        return self::normalize([
            'id' => $userId,
            'name' => self::personName($person),
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'type' => $person['type'] ?? 'App\\Models\\Person',
            'business_id' => $request->header('X-Business-ID'),
            'person' => $person,
            'permissions' => [],
            'roles' => [],
            'terminal_id' => $request->input('terminal_id'),
            'terminal_information' => $request->input('terminal_information'),
            'source' => 'request_body',
        ]);
    }

    /**
     * From queued job / RabbitMQ message context.
     */
    private static function fromQueuedContext(): array
    {
        if (!app()->bound('audit.user_context')) {
            return self::emptyContext();
        }

        $context = app('audit.user_context');

        if (!is_array($context) || $context === []) {
            return self::emptyContext();
        }

        return self::normalize(array_merge($context, ['source' => 'queued_context']));
    }

    /**
     * From local Laravel auth (direct API access, not through gateway).
     */
    private static function fromLocalAuth(): array
    {
        // auth('<guard>') throws for an undefined guard, and many services
        // define none; that must never break the model save being audited.
        $guard = config('auditable.auth_guard', 'api');
        if (!$guard || !array_key_exists($guard, (array) config('auth.guards', []))) {
            return self::emptyContext();
        }

        try {
            $user = auth($guard)->user();
        } catch (\Throwable $e) {
            Log::debug('Audit causer lookup through local auth failed', ['guard' => $guard, 'error' => $e->getMessage()]);
            return self::emptyContext();
        }

        if (!$user) {
            return self::emptyContext();
        }

        return self::normalize([
            'id' => $user->id,
            'name' => $user->name ?? self::personName([
                'first_name' => $user->first_name ?? null,
                'last_name' => $user->last_name ?? null,
            ]),
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'type' => get_class($user),
            'business_id' => null,
            'person' => [],
            'permissions' => [],
            'roles' => [],
            'terminal_id' => null,
            'terminal_information' => null,
            'source' => 'local_auth',
        ]);
    }

    /**
     * System / CLI / fallback context.
     */
    private static function systemContext(): array
    {
        return [
            'id' => null,
            'name' => 'system',
            'email' => null,
            'phone' => null,
            'type' => 'System',
            'business_id' => null,
            'person' => [],
            'permissions' => [],
            'roles' => [],
            'terminal_id' => null,
            'terminal_information' => null,
            'source' => 'system',
        ];
    }

    private static function emptyContext(): array
    {
        return ['id' => null];
    }

    /** Every key present, unknown keys kept. */
    private static function normalize(array $context): array
    {
        return array_merge(self::KEYS, $context);
    }

    /** `name`, else first and last name joined, else null. Missing parts are fine. */
    private static function personName(array $person): ?string
    {
        if (!empty($person['name']) && is_string($person['name'])) {
            return $person['name'];
        }

        $name = trim(implode(' ', array_filter([
            $person['first_name'] ?? null,
            $person['last_name'] ?? null,
        ], fn ($part) => is_string($part) && $part !== '')));

        return $name !== '' ? $name : null;
    }

    private static function arrayInput($request, string $key): array
    {
        $value = $request->input($key, []);

        return is_array($value) ? $value : [];
    }

    public static function set(array $context): void
    {
        app()->instance('audit.user_context', $context);
    }

    public static function clear(): void
    {
        if (app()->bound('audit.user_context')) {
            app()->offsetUnset('audit.user_context');
        }
    }

    public static function forMessage(): array
    {
        $context = self::normalize(self::resolve());

        return [
            'id' => $context['id'],
            'name' => $context['name'],
            'email' => $context['email'],
            'phone' => $context['phone'],
            'type' => $context['type'],
            'business_id' => $context['business_id'],
            'person' => $context['person'],
            'permissions' => $context['permissions'],
            'roles' => $context['roles'],
            'terminal_id' => $context['terminal_id'],
        ];
    }

}