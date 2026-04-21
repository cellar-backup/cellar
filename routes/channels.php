<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Public channels — no auth required. Cellar is a single-user homelab app
| so all WebSocket data is accessible to any authenticated dashboard user.
|
*/

Broadcast::channel('jobs', function () {
    return true;
});
