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
