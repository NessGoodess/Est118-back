<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\PreEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreEnrollmentProcessStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_review_moves_pending_to_in_review(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::PENDING,
            'documents_status' => DocumentsStatus::PENDING,
        ]);

        $response = $this->postJson("/api/admissions/pre-enrollments/{$pre->id}/initial-review", [
            'notes' => 'Documentos incompletos, revisar mañana',
            'expected_updated_at' => $pre->updated_at?->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'in_review')
            ->assertJsonPath('review_notes', 'Documentos incompletos, revisar mañana')
            ->assertJsonPath('reviewed_by', $user->id);

        $this->assertNotNull($pre->fresh()->reviewed_at);
    }

    public function test_initial_review_rejects_non_pending(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
        ]);

        $this->postJson("/api/admissions/pre-enrollments/{$pre->id}/initial-review")
            ->assertStatus(422);
    }

    public function test_stale_expected_updated_at_returns_409(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::PENDING,
        ]);

        $this->postJson("/api/admissions/pre-enrollments/{$pre->id}/initial-review", [
            'expected_updated_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'stale_version');
    }

    public function test_process_blocks_in_review_to_pending(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
        ]);

        $this->patchJson("/api/admissions/pre-enrollments/{$pre->id}/process", [
            'status' => PreEnrollmentStatus::PENDING->value,
        ])->assertStatus(422);
    }

    public function test_process_allows_reject_and_reopen(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
        ]);

        $this->patchJson("/api/admissions/pre-enrollments/{$pre->id}/process", [
            'status' => PreEnrollmentStatus::REJECTED->value,
            'review_notes' => 'CURP inválida',
        ])->assertOk()->assertJsonPath('status', 'rejected');

        $this->patchJson("/api/admissions/pre-enrollments/{$pre->id}/process", [
            'status' => PreEnrollmentStatus::IN_REVIEW->value,
        ])->assertOk()->assertJsonPath('status', 'in_review');
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
