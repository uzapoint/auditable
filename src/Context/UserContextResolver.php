<?php

namespace Uzapoint\Auditable\Context;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class UserContextResolver
{
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

        // Your gateway sets these headers
        $userId = $request->header('X-Person-ID');
        $businessId = $request->header('X-Business-ID');

        // User info might be in headers or body
        $person = self::decodePersonFromHeaders($request)
            ?? $request->input('person', []);

        if (!$userId && !$request->input('user_id')) {
            return self::emptyContext();
        }

        return [
            'id' => $userId ?? $request->input('user_id'),
            'name' => $person['name'] ?? ($person['first_name'] . ' ' . $person['last_name']) ?? null,
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'type' => 'App\\Models\\Person',
            'business_id' => $businessId,
            'person' => $person, // Full person array for extra context
            'permissions' => self::decodePermissions($request),
            'roles' => self::decodeRoles($request),
            'terminal_id' => $request->input('terminal_id'),
            'terminal_information' => $request->input('terminal_information'),
            'source' => 'gateway_header',
        ];
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

        try {
            return json_decode(base64_decode($encoded), true);
        } catch (\Exception $e) {
            Log::warning('Failed to decode X-Person header', ['error' => $e->getMessage()]);
            return null;
        }
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

        $person = $request->input('person', []);

        return [
            'id' => $userId,
            'name' => $person['name'] ?? ($person['first_name'] . ' ' . $person['last_name']) ?? null,
            'email' => $person['email'] ?? null,
            'phone' => $person['phone'] ?? null,
            'type' => 'App\\Models\\Person',
            'business_id' => $request->header('X-Business-ID'),
            'person' => $person,
            'permissions' => [],
            'roles' => [],
            'terminal_id' => $request->input('terminal_id'),
            'terminal_information' => $request->input('terminal_information'),
            'source' => 'request_body',
        ];
    }

    /**
     * From queued job / RabbitMQ message context.
     */
    private static function fromQueuedContext(): array
    {
        $context = app('audit.user_context');

        if (!$context) {
            return self::emptyContext();
        }

        return array_merge($context, ['source' => 'queued_context']);
    }

    /**
     * From local Laravel auth (direct API access, not through gateway).
     */
    private static function fromLocalAuth(): array
    {
        $user = auth('api')->user();

        if (!$user) {
            return self::emptyContext();
        }

        return [
            'id' => $user->id,
            'name' => $user->name ?? ($user->first_name . ' ' . $user->last_name) ?? null,
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
        ];
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

    public static function set(array $context): void
    {
        app()->instance('audit.user_context', $context);
    }

    public static function clear(): void
    {
        app()->offsetUnset('audit.user_context');
    }

    public static function forMessage(): array {
        $context = self::resolve();

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