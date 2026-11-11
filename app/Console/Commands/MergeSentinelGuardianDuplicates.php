<?php

namespace App\Console\Commands;

use App\Models\Guardian;
use App\Models\Profile;
use App\Services\People\StudentGuardianPrimaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Merge NO_ESPECIFICADO_* guardian profiles into a real-CURP twin with the same name + phone.
 */
class MergeSentinelGuardianDuplicates extends Command
{
    protected $signature = 'guardians:merge-sentinel-duplicates
                            {--apply : Write changes (default is dry-run)}';

    protected $description = 'Merge placeholder NO_ESPECIFICADO tutors into real-CURP duplicates matched by name + phone';

    public function handle(StudentGuardianPrimaryService $primaries): int
    {
        $apply = (bool) $this->option('apply');
        $pairs = $this->findPairs();

        $mergeable = array_values(array_filter($pairs, fn (array $p) => $p['mergeable']));
        $nameOnly = array_values(array_filter($pairs, fn (array $p) => ! $p['mergeable']));

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').': '.count($mergeable).' pares fusionables, '.count($nameOnly).' solo por nombre (omitidos).');

        foreach ($nameOnly as $pair) {
            $this->line(sprintf(
                '  omitido (tel distinto/vacío): %s | sentinel #%d → real #%d',
                $pair['name'],
                $pair['sentinel_guardian_id'],
                $pair['real_guardian_id']
            ));
        }

        $applied = 0;
        foreach ($mergeable as $pair) {
            $this->line(sprintf(
                '  %s: %s | sentinel #%d → real #%d (tel %s)',
                $apply ? 'fusionar' : 'plan',
                $pair['name'],
                $pair['sentinel_guardian_id'],
                $pair['real_guardian_id'],
                $pair['phone']
            ));

            if (! $apply) {
                continue;
            }

            DB::transaction(function () use ($pair, $primaries) {
                $survivor = Guardian::query()->with(['profile', 'students'])->findOrFail($pair['real_guardian_id']);
                $discard = Guardian::query()->with(['profile', 'students'])->findOrFail($pair['sentinel_guardian_id']);
                $affected = [];

                foreach ($discard->students as $linkedStudent) {
                    $affected[] = (int) $linkedStudent->id;
                    $pivotRel = $linkedStudent->pivot->relationship;
                    $discardPrimary = (bool) ($linkedStudent->pivot->is_primary ?? false);
                    $discardSecondary = (bool) ($linkedStudent->pivot->is_secondary ?? false);
                    $existing = $linkedStudent->guardians()->where('guardians.id', $survivor->id)->first();

                    if ($existing) {
                        $keepPrimary = (bool) ($existing->pivot->is_primary ?? false) || $discardPrimary;
                        $keepSecondary = ((bool) ($existing->pivot->is_secondary ?? false) || $discardSecondary) && ! $keepPrimary;
                        $linkedStudent->guardians()->syncWithoutDetaching([
                            $survivor->id => [
                                'relationship' => $pivotRel ?: $existing->pivot->relationship,
                                'is_primary' => $keepPrimary,
                                'is_secondary' => $keepSecondary,
                            ],
                        ]);
                    } else {
                        $linkedStudent->guardians()->syncWithoutDetaching([
                            $survivor->id => [
                                'relationship' => $pivotRel,
                                'is_primary' => $discardPrimary,
                                'is_secondary' => $discardSecondary && ! $discardPrimary,
                                'sort_order' => (int) ($linkedStudent->pivot->sort_order ?? 0),
                            ],
                        ]);
                    }
                    $linkedStudent->guardians()->detach($discard->id);
                }

                $discard->refresh();
                if (! $discard->students()->exists()) {
                    $orphan = $discard->profile;
                    $discard->delete();
                    if ($orphan) {
                        $hasOther = $orphan->teacher()->exists()
                            || $orphan->staff()->exists()
                            || $orphan->student()->exists()
                            || $orphan->guardian()->exists();
                        if (! $hasOther) {
                            $orphan->delete();
                        }
                    }
                }

                foreach (array_unique($affected) as $studentId) {
                    $primaries->normalizeRoles($studentId);
                }
            });
            $applied++;
        }

        $this->info($apply ? "Fusionados: {$applied}" : 'Sin escritura (usa --apply para aplicar).');

        return self::SUCCESS;
    }

    /**
     * @return list<array{
     *   name: string,
     *   phone: string,
     *   sentinel_guardian_id: int,
     *   real_guardian_id: int,
     *   mergeable: bool
     * }>
     */
    private function findPairs(): array
    {
        $sentinels = Profile::query()
            ->where('national_id', 'like', 'NO_ESPECIFICADO%')
            ->whereHas('guardian')
            ->with('guardian')
            ->get();

        $pairs = [];
        foreach ($sentinels as $sentinel) {
            $nameKey = $this->nameKey($sentinel);
            $phone = $this->digits((string) $sentinel->phone_number);

            $reals = Profile::query()
                ->whereHas('guardian')
                ->whereNotNull('national_id')
                ->where('national_id', '!=', '')
                ->where('national_id', 'not like', 'NO_ESPECIFICADO%')
                ->where('national_id', 'not like', 'TUT%')
                ->where('id', '!=', $sentinel->id)
                ->get()
                ->filter(fn (Profile $p) => $this->nameKey($p) === $nameKey)
                ->values();

            if ($reals->isEmpty()) {
                continue;
            }

            $samePhone = $phone !== null
                ? $reals->first(fn (Profile $p) => $this->digits((string) $p->phone_number) === $phone)
                : null;
            $chosen = $samePhone ?? $reals->first();
            if (! $chosen?->guardian) {
                continue;
            }

            $pairs[] = [
                'name' => trim($sentinel->first_name.' '.$sentinel->last_name.' '.($sentinel->second_last_name ?? '')),
                'phone' => $phone ?? '',
                'sentinel_guardian_id' => (int) $sentinel->guardian->id,
                'real_guardian_id' => (int) $chosen->guardian->id,
                'mergeable' => $samePhone !== null,
            ];
        }

        return $pairs;
    }

    private function nameKey(Profile $profile): string
    {
        return mb_strtoupper(trim(implode('|', [
            (string) $profile->first_name,
            (string) $profile->last_name,
            (string) ($profile->second_last_name ?? ''),
        ])));
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8 ? $digits : null;
    }
}
