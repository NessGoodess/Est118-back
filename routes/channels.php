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
// Only authenticated users can subscribe to this channel
Broadcast::channel('credential-read-channel', function ($user) {
    return $user !== null;
    
    //users with role 'admin' or 'nfc-reader'
    //return $user->can('nfc-reader');
    //return $user->role === 'admin';
});
