<?php

namespace Tests\Feature\School;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NewIntakeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_new_intake_creates_a_student_without_a_pre_enrollment(): void
    {
        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $second = GradeLevel::query()->create(['name' => '2°']);
        GradeLevel::query()->create(['name' => '1°']);
        $group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $second->id,
            'name' => 'C',
        ]);
        $workshop = Workshop::query()->create([
            'code' => 'INFORMATICA',
            'name' => 'Informática',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        Permission::findOrCreate('edit students', 'web');
        $user->givePermissionTo('edit students');
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/new-intakes', $this->payload($second->id, $group->id, $workshop->id));

        $response->assertCreated();
        $studentId = $response->json('data.student_id');
        $enrollment = Enrollment::query()->where('student_id', $studentId)->first();
        $this->assertNotNull($enrollment);
        $this->assertTrue($enrollment->is_new_admission);
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertSame($group->id, $enrollment->class_group_id);
        $this->assertSame(
            WorkshopEnrollmentSource::Manual,
            WorkshopEnrollment::query()->where('student_id', $studentId)->first()?->source
        );
        $this->assertSame(0, PreEnrollment::query()->count());

        $this->postJson('/api/new-intakes', $this->payload($second->id, $group->id, $workshop->id))
            ->assertStatus(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $gradeId, int $groupId, int $workshopId): array
    {
        return [
            'first_name' => 'MARIANA',
            'last_name' => 'LOPEZ',
            'second_last_name' => 'RUIZ',
            'curp' => 'LORM120615MOCPZNA1',
            'birth_date' => '2012-06-15',
            'gender' => 'F',
            'phone' => '5512345678',
            'email' => 'mariana@example.com',
            'contact_email' => 'rosa@example.com',
            'place_of_birth' => 'Estado de México',
            'previous_school' => 'Primaria Centro',
            'current_average' => '8.5',
            'sibling_ids' => [],
            'school_voucher_folio' => null,
            'street_type' => 'Calle',
            'street_name' => 'Juarez',
            'house_number' => '12',
            'unit_number' => '',
            'neighborhood_type' => 'Colonia',
            'neighborhood_name' => 'Centro',
            'postal_code' => '56600',
            'city' => 'Chalco',
            'state' => 'México',
            'guardian_first_name' => 'ROSA',
            'guardian_last_name' => 'RUIZ',
            'guardian_second_last_name' => 'DIAZ',
            'guardian_curp' => 'RUDR850101MMCZNSA8',
            'guardian_phone' => '5587654321',
            'guardian_relationship' => 'Madre',
            'grade_level_id' => $gradeId,
            'class_group_id' => $groupId,
            'workshop_id' => $workshopId,
        ];
    }
}
