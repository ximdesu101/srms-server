<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Resolve the currently authenticated Admin or Staff user.
     * Prefer Admin when both are somehow present.
     */
    protected function actor(Request $request)
    {
        return $request->user('admin') ?? $request->user('staff');
    }

    /**
     * Role string for audit logging.
     */
    protected function actorRole(Request $request): string
    {
        if ($request->user('admin')) {
            return 'admin';
        }
        if ($request->user('staff')) {
            return 'staff';
        }
        return 'unknown';
    }
}