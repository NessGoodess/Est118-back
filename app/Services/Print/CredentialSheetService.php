<?php

namespace App\Services\Print;

use App\Enums\CredentialHolderKind;
use App\Models\CardDesign;
use App\Services\People\GuardianCredentialData;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Validation\ValidationException;

/**
 * Card images for the plain-paper alternative. Uses the same Zebra designs but
 * never creates CredentialPrint / PrintJob rows: nothing is recorded.
 */
class CredentialSheetService
{
    public const MAX_PER_REQUEST = 60;

    public function __construct(
        private readonly PrintJobService $printJobs,
        private readonly StudentCardRenderService $renderer,
        private readonly PersonPhotoPathService $personPhotos,
    ) {}

    /**
     * @param  array<int, int>  $ids  student ids, or teacher / staff / guardian ids
     * @param  array<int, string>  $omitFields  payload keys left blank on the card
     * @return array<int, array{id: int, name: string, design_label: string, orientation: string, front: string, back: ?string}>
     */
    public function render(CredentialHolderKind $kind, array $ids, array $omitFields = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => ['Selecciona al menos una credencial.']]);
        }

        $rows = [];
        foreach ($ids as $id) {
            [$name, $design, $payload, $studentId, $photoPath] = $kind === CredentialHolderKind::Students
                ? $this->studentCard($id)
                : $this->personCard($kind, $id);

            foreach ($omitFields as $key) {
                $payload[$key] = '';
            }

            $double = ($design->faces_mode ?? 'single') === 'double';
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'design_label' => (string) $design->name,
                'orientation' => $design->orientation === 'portrait' ? 'portrait' : 'landscape',
                'front' => $this->png($design->uuid, $studentId, $payload, 'front', $photoPath),
                'back' => $double ? $this->png($design->uuid, $studentId, $payload, 'back', $photoPath) : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array{0: string, 1: CardDesign, 2: array<string, mixed>, 3: int, 4: ?string}
     */
    private function studentCard(int $studentId): array
    {
        $student = $this->printJobs->studentForPrint($studentId);
        $design = $this->printJobs->resolveDesignForStudent($student);

        return [
            $student->profile->fullName(),
            $design,
            $this->renderer->payloadFromStudent($student),
            (int) $student->id,
            null,
        ];
    }

    /**
     * @return array{0: string, 1: CardDesign, 2: array<string, mixed>, 3: int, 4: ?string}
     */
    private function personCard(CredentialHolderKind $kind, int $id): array
    {
        $model = $kind->personModel();
        $relations = $kind === CredentialHolderKind::Guardians ? GuardianCredentialData::RELATIONS : ['profile.address'];
        $holder = $model::query()->with($relations)->find($id);
        if (! $holder?->profile) {
            throw ValidationException::withMessages(['ids' => ["{$kind->label()} {$id} no encontrado o sin perfil."]]);
        }

        $photoKind = $kind->photoKind();
        $photoPath = $photoKind
            ? ($this->personPhotos->resolvePath($photoKind, $holder->id, 'original')
                ?? $this->personPhotos->resolvePath($photoKind, $holder->id, 'profile'))
            : null;

        return [
            $holder->profile->fullName(),
            $this->printJobs->resolveDesignForPerson($kind),
            $this->renderer->payloadFromPerson($kind, $holder),
            0,
            $photoPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function png(string $designKey, int $studentId, array $payload, string $side, ?string $photoPath): string
    {
        $img = $this->renderer->compose($designKey, $studentId, $payload, $side, $photoPath);
        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($binary);
    }
}
