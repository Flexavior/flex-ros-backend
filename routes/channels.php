<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('inbox', function ($user) {
    return $user->isInboxAgent();
});
