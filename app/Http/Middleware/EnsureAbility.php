<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAbility
{
    /**
     * 403 and not 404 on purpose. Hiding another organisation's record is what
     * the 404 rule is for; this record belongs to the caller's own
     * organisation, and pretending it is missing would send them hunting for a
     * bug instead of asking the owner for a different role.
     */
    private const REFUSALS = [
        'team' => 'Only the owner can change who is on the team.',
        'build' => 'Your role can see the apps and work their orders, but not change them. Ask the owner if you need to.',
    ];

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless(
            $request->user()?->allows($ability),
            403,
            self::REFUSALS[$ability] ?? 'Your role does not allow that.',
        );

        return $next($request);
    }
}
