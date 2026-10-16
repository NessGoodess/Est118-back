<?php

namespace App\Http\Controllers\Print;

use App\Enums\CredentialHolderKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Print\Concerns\LogsPrintActions;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Teacher;
use App\Services\People\GuardianCredentialData;
use App\Services\People\PersonPhotoPathService;
use App\Services\Print\CredentialPrintService;
use App\Services\Print\PrintJobService;
use App\Services\StudentPhotoPathService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Teacher / staff / guardian badges. Student cards keep their own endpoints and permissions.
 */
class PersonCredentialPrintController extends Controller
{
    use LogsPrintActions;

    private const PERSON_KINDS = ['teachers', 'staff', 'guardians'];

    public function __construct(
        private readonly CredentialPrintService $cards,
        private readonly CredentialPrintController $cardSerializer,
        private readonly PersonPhotoPathService $photos,
        private readonly StudentPhotoPathService $studentPhotos,
        private readonly GuardianCredentialData $guardians
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::PERSON_KINDS)],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        $kind = CredentialHolderKind::from($data['kind']);

        $holders = $kind === CredentialHolderKind::Guardians
            ? $this->guardians->query()->get()
            : $kind->personModel()::query()
                ->with('profile:id,first_name,last_name,second_last_name,national_id')
                ->when($data['status'] ?? 'active', fn ($q, $status) => $q->where('status', $status))
                ->get();

        $holders = $holders
            ->filter(fn ($holder) => $holder->profile !== null)
            ->sortBy(fn ($holder) => $holder->profile->fullLastName().' '.$holder->profile->first_name)
            ->values();

        $latest = $this->cards->latestByProfileIds($kind, $holders->pluck('profile_id')->all());
        $photoKind = $kind->photoKind();
        $year = AcademicYear::query()->where('is_active', true)->first();

        return response()->json([
            'success' => true,
            'data' => $holders->map(function ($holder) use ($kind, $latest, $photoKind, $year) {
                $photoAt = $photoKind ? $this->photos->lastModifiedAt($photoKind, $holder->id) : null;
                $row = [
                    'id' => $holder->id,
                    'profile_id' => $holder->profile_id,
                    'kind' => $kind->value,
                    'name' => $holder->profile->fullName(),
                    'first_name' => (string) ($holder->profile->first_name ?? ''),
                    'last_names' => $holder->profile->fullLastName(),
                    'curp' => $holder->profile->national_id,
                    'role' => $this->roleFor($kind, $holder),
                    'status' => $holder->status?->value ?? null,
                    'has_photo' => $photoKind !== null && $this->photos->fileExists($photoKind, $holder->id, 'profile'),
                    'photo_updated_at' => $photoAt?->toIso8601String(),
                    'photo_freshness' => $this->studentPhotos->resolveFreshness($photoAt, $year),
                    'latest_card' => isset($latest[$holder->profile_id])
                        ? $this->cardSerializer->serializeCard($latest[$holder->profile_id])
                        : null,
                ];

                if ($holder instanceof Guardian) {
                    $row += [
                        'folio' => $this->guardians->folio($holder),
                        'kinship' => $this->guardians->kinship($holder),
                        'dependents' => $this->guardians->dependents($holder),
                        'second_guardian' => $this->guardians->secondGuardian($holder),
                    ];
                }

                return $row;
            })->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::PERSON_KINDS)],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'template_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'printer_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);
        $kind = CredentialHolderKind::from($data['kind']);
        $printerId = $data['printer_id'] ?? PrintJobService::DEFAULT_PRINTER_ID;

        $batch = $this->logPrintAction(
            'crear lote de gafetes',
            [
                'kind' => $kind->value,
                'ids' => $data['ids'],
                'printer_id' => $printerId,
            ],
            fn () => $this->cards->createPersonBatch(
                $kind,
                $data['ids'],
                $request->user(),
                $data['template_key'] ?? null,
                $printerId
            ),
            fn (array $batch) => [
                'batch_uuid' => $batch['batch_uuid'],
                'cards' => $batch['cards']->count(),
                'skipped' => count($batch['skipped']),
            ]
        );

        return response()->json([
            'success' => true,
            'data' => [
                'batch_uuid' => $batch['batch_uuid'],
                'strategy' => $batch['strategy']->value,
                'cards' => $batch['cards']->map(fn ($card) => $this->cardSerializer->serializeCard($card))->values(),
                'skipped' => $batch['skipped'],
            ],
        ], $batch['cards']->isEmpty() ? 200 : 202);
    }

    private function roleFor(CredentialHolderKind $kind, mixed $holder): string
    {
        if ($holder instanceof Guardian) {
            return $this->guardians->kinship($holder) ?: $kind->label();
        }

        return (string) ($holder->position ?: ($holder instanceof Teacher ? 'Docente' : $kind->label()));
    }
}
