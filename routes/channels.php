<?php

use App\Domain\Documents\DocumentLibraryAccess;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('inbox', function ($user) {
    return $user->isInboxAgent();
});

Broadcast::channel('crm-dashboard', function ($user) {
    return DocumentLibraryAccess::userMayAccessCrmModule($user);
});
