<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\User;
use App\Services\PreEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdmissionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_store_replays_same_key_without_duplicating(): void
    {
        \App\Models\Admission\AdmissionCycle::factory()->active()->create();

        $this->mock(PreEnrollmentService::class, function ($mock) {
            $mock->shouldReceive('createPreEnrollment')
                ->once()
                ->andReturn([
                    'folio' => '001',
                    'downloadUrl' => 'https://example.test/pdf',
                ]);
        });

        $payload = $this->publicStorePayload();
        $headers = ['Idempotency-Key' => 'public-key-001'];

        $first = $this->postJson('/api/admissions/pre-enrollment', $payload, $headers);
        $first->assertCreated()->assertJsonPath('folio', '001');

        $second = $this->postJson('/api/admissions/pre-enrollment', $payload, $headers);
        $second->assertCreated()
            ->assertJsonPath('folio', '001');

        $this->assertDatabaseCount('admission_idempotency_keys', 1);
    }

    public function test_public_store_rejects_same_key_with_different_body(): void
    {
        \App\Models\Admission\AdmissionCycle::factory()->active()->create();

        $this->mock(PreEnrollmentService::class, function ($mock) {
            $mock->shouldReceive('createPreEnrollment')
                ->once()
                ->andReturn([
                    'folio' => '002',
                    'downloadUrl' => 'https://example.test/pdf',
                ]);
        });

        $payload = $this->publicStorePayload();
        $headers = ['Idempotency-Key' => 'public-key-002'];

        $this->postJson('/api/admissions/pre-enrollment', $payload, $headers)
            ->assertCreated();

        $payload['applicantInfo']['firstName'] = 'OTRONOMBRE';
        $payload['applicantInfo']['curp'] = 'XEXX010101HNEXXX09';

        $this->postJson('/api/admissions/pre-enrollment', $payload, $headers)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_reuse');
    }

    public function test_convert_replays_same_idempotency_key(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $academicYear = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        ClassGroup::create([
            'academic_year_id' => $academicYear->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $body = ['academic_year_id' => $academicYear->id, 'channel' => 'campaign'];
        $headers = ['Idempotency-Key' => 'convert-key-001'];

        $first = $this->postJson(
            "/api/admissions/pre-enrollments/{$pre->id}/convert-student",
            $body,
            $headers
        );
        $first->assertOk()->assertJsonPath('success', true);
        $studentId = $first->json('data.student_id');

        $second = $this->postJson(
            "/api/admissions/pre-enrollments/{$pre->id}/convert-student",
            $body,
            $headers
        );
        $second->assertOk()
            ->assertJsonPath('data.student_id', $studentId);

        $this->assertDatabaseCount('students', 1);
        $this->assertNotNull($pre->fresh()->conversion_options);
        $this->assertNotNull($pre->fresh()->conversion_policy_snapshot);
    }

    public function test_convert_same_key_different_body_returns_409(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        $academicYear = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        ClassGroup::create([
            'academic_year_id' => $academicYear->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $headers = ['Idempotency-Key' => 'convert-key-002'];

        $this->postJson(
            "/api/admissions/pre-enrollments/{$pre->id}/convert-student",
            ['academic_year_id' => $academicYear->id, 'channel' => 'campaign'],
            $headers
        )->assertOk();

        $this->postJson(
            "/api/admissions/pre-enrollments/{$pre->id}/convert-student",
            ['academic_year_id' => $academicYear->id, 'channel' => 'late'],
            $headers
        )->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_reuse');
    }

    /**
     * @return array<string, mixed>
     */
    private function publicStorePayload(): array
    {
        return [
            'email' => [
                'contactEmail' => 'tutor@example.com',
                'contactEmailConfirmation' => 'tutor@example.com',
            ],
            'applicantInfo' => [
                'firstName' => 'JUAN',
                'lastName' => 'PEREZ',
                'secondLastName' => 'LOPEZ',
                'curp' => 'PELJ010101HOCRPN09',
                'birthDate' => '2010-01-01',
                'age' => 14,
                'gender' => 'M',
                'phone' => '9511234567',
                'studentEmail' => 'juan@example.com',
                'placeOfBirth' => 'OAXACA',
            ],
            'academicInfo' => [
                'previousSchool' => 'PRIMARIA DEMO',
                'currentAverage' => 8.5,
                'hasSiblings' => false,
                'siblingsDetails' => null,
            ],
            'addressInfo' => [
                'streetType' => 'Calle',
                'streetName' => 'REFORMA',
                'houseNumber' => '10',
                'unitNumber' => null,
                'neighborhoodType' => 'COLONIA',
                'neighborhoodName' => 'CENTRO',
                'postalCode' => '68000',
                'city' => 'Oaxaca',
                'state' => 'OAXACA',
            ],
            'guardianInfo' => [
                'guardianFirstName' => 'MARIA',
                'guardianLastName' => 'LOPEZ',
                'guardianSecondLastName' => 'GARCIA',
                'guardianCurp' => 'LOGM800101MOCRRR09',
                'guardianPhone' => '9517654321',
                'guardianRelationship' => 'MADRE',
            ],
            'workshopSelect' => [
                'workshopFirstChoice' => 'Informática',
                'workshopSecondChoice' => 'Diseño Industrial',
            ],
            'tuitionVoucher' => [
                'hasSchoolVoucher' => false,
                'schoolVoucherFolio' => null,
            ],
        ];
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
