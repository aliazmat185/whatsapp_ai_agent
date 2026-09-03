<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff', 'vendor_owner', 'vendor_staff']);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->ownsDocument($user, $document) && $user->hasAnyRole(['vendor_owner', 'vendor_staff']);
    }

    private function ownsDocument(User $user, Document $document): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin_staff'])
            || $user->vendor_id === $document->vendor_id;
    }
}
