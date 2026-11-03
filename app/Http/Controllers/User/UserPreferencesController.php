<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserPreferencesRequest;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;

class UserPreferencesController extends Controller
{
    public function update(UpdateUserPreferencesRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $preference = UserPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            UserPreference::defaults(),
        );

        if (array_key_exists('shell_colors', $data)) {
            $data['shell_colors'] = UserPreference::normalizeShellColors(
                is_array($data['shell_colors']) ? $data['shell_colors'] : null,
                is_array($preference->shell_colors) ? $preference->shell_colors : null,
            );
        }

        $preference->fill($data);
        $preference->save();

        return response()->json([
            'success' => true,
            'data' => $preference->toSessionArray(),
        ]);
    }
}
