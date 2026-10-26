<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes a User for API responses.
 *
 * Deliberately excludes: password, remember_token, email_verified_at.
 * The #[Hidden] attribute on the model already strips password and
 * remember_token, but we enumerate allowed fields here explicitly so
 * adding a new sensitive column to the model never silently leaks it.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
