<?php

namespace App\Services\People;

use App\Models\EmergencyContact;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

class EmergencyContactSyncService
{
    /**
     * Replace all emergency contacts for a profile.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function sync(Profile $profile, array $rows): void
    {
        $normalized = [];
        foreach (array_values($rows) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            $phone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? '')) ?? '';
            if ($name === '' || $phone === '') {
                continue;
            }
            $relationship = trim((string) ($row['relationship'] ?? ''));
            $normalized[] = [
                'name' => $name,
                'phone' => $phone,
                'relationship' => $relationship === '' ? null : $relationship,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'sort_order' => array_key_exists('sort_order', $row)
                    ? (int) $row['sort_order']
                    : $index,
            ];
        }

        if ($normalized === []) {
            EmergencyContact::query()->where('profile_id', $profile->id)->delete();

            return;
        }

        $primaryIndex = null;
        foreach ($normalized as $index => $row) {
            if ($row['is_primary']) {
                $primaryIndex = $index;
                break;
            }
        }
        if ($primaryIndex === null) {
            $primaryIndex = 0;
        }
        foreach ($normalized as $index => $_) {
            $normalized[$index]['is_primary'] = $index === $primaryIndex;
        }

        DB::transaction(function () use ($profile, $normalized) {
            EmergencyContact::query()->where('profile_id', $profile->id)->delete();
            foreach ($normalized as $row) {
                EmergencyContact::query()->create([
                    'profile_id' => $profile->id,
                    ...$row,
                ]);
            }
        });
    }

    /**
     * Primary contact formatted for credentials: name, phone, relationship.
     */
    public function credentialLine(?Profile $profile): string
    {
        if (! $profile) {
            return '';
        }

        $contact = $profile->relationLoaded('emergencyContacts')
            ? $profile->emergencyContacts
                ->sortBy([
                    ['is_primary', 'desc'],
                    ['sort_order', 'asc'],
                    ['id', 'asc'],
                ])
                ->first()
            : EmergencyContact::query()
                ->where('profile_id', $profile->id)
                ->orderByDesc('is_primary')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->first();

        if (! $contact) {
            return '';
        }

        $name = trim((string) $contact->name);
        $phone = trim((string) $contact->phone);
        $relationship = trim((string) ($contact->relationship ?? ''));

        $namePhone = implode(' · ', array_values(array_filter([$name, $phone], fn (string $part) => $part !== '')));
        if ($relationship === '') {
            return $namePhone;
        }

        return $namePhone === '' ? $relationship : $namePhone."\n".$relationship;
    }
}
