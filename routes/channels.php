<?php

use Illuminate\Support\Facades\Broadcast;

// Private channel for authenticated users
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});


// Public channel
Broadcast::channel('public-chat', function () {
    return true; // Allow all users to listen to this channel
});

// Private channel for credential reads (NFC reader events)
Broadcast::channel('credential-read-channel', function ($user) {
    return $user->can('manage nfc readings') ? [
        'id' => $user->id,
        'name' => $user->name,
    ] : false;
});

// Canal de equipo para preinscripciones (base para chat futuro entre usuarios del módulo)
Broadcast::channel('team.pre-enrollments', function ($user) {
    return $user->can('view pre-enrollments') ? [
        'id' => $user->id,
        'name' => $user->name,
    ] : false;
});
