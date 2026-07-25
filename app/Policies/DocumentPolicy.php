<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $document->pengusul_id === $user->id
            || $user->hasRole('super_admin')
            || $user->hasRole('auditor')
            || $document->verifications()->where('verifikator_id', $user->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('dokumen.buat');
    }

    public function update(User $user, Document $document): bool
    {
        return $document->pengusul_id === $user->id && in_array($document->status, [Document::STATUS_DRAFT, Document::STATUS_REVISI]);
    }

    public function delete(User $user, Document $document): bool
    {
        return $document->pengusul_id === $user->id && $document->isDraft();
    }
}
