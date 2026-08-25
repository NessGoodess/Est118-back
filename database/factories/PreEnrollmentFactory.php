<?php

namespace Database\Factories;

use App\Enums\AdmissionWorkshop;
use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\Admission\AdmissionCycle;
use App\Models\PreEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PreEnrollment>
 */
class PreEnrollmentFactory extends Factory
{
    protected $model = PreEnrollment::class;

    public function definition(): array
    {
        $hasVoucher = $this->faker->boolean(40);
        $hasSiblings = $this->faker->boolean(25);
        $workshops = AdmissionWorkshop::values();
        $firstWorkshop = $this->faker->randomElement($workshops);
        $secondWorkshop = $this->faker->randomElement(
            array_values(array_diff($workshops, [$firstWorkshop]))
        );

        return [
            'admission_cycle_id' => AdmissionCycle::factory()->active(),
            'status' => PreEnrollmentStatus::PENDING,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,

            'contact_email' => $this->faker->unique()->safeEmail(),
            'first_name' => strtoupper($this->faker->firstName()),
            'last_name' => strtoupper($this->faker->lastName()),
            'second_last_name' => strtoupper($this->faker->lastName()),
            'curp' => $this->faker->unique()->regexify('[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9]{2}'),
            'birth_date' => $this->faker->dateTimeBetween('-14 years', '-12 years')->format('Y-m-d'),
            'age' => $this->faker->numberBetween(12, 14),
            'gender' => $this->faker->randomElement(['M', 'F']),
            'phone' => '951'.$this->faker->numerify('#######'),
            'student_email' => $this->faker->unique()->safeEmail(),
            'place_of_birth' => 'OAXACA',
            'previous_school' => 'ESCUELA '.$this->faker->randomElement([
                'PRIMARIA BENITO JUAREZ',
                'PRIMARIA VICENTE GUERRERO',
                'PRIMARIA IGNACIO ZARAGOZA',
                'PRIMARIA JOSE MARIA MORELOS',
                'PRIMARIA MIGUEL HIDALGO',
            ]),
            'current_average' => $this->faker->randomFloat(2, 6.5, 10),
            'admission_exam_score' => $this->faker->optional(0.7)->randomFloat(2, 5, 10),
            'has_siblings' => $hasSiblings,
            'siblings_details' => $hasSiblings ? $this->faker->sentence(4) : null,

            'street_type' => $this->faker->randomElement(['Calle', 'Avenida', 'Privada', 'Ampliación']),
            'street_name' => strtoupper($this->faker->streetName()),
            'house_number' => (string) $this->faker->numberBetween(1, 420),
            'unit_number' => $this->faker->optional(0.2)->bothify('Int-##'),
            'neighborhood_type' => 'COLONIA',
            'neighborhood_name' => strtoupper($this->faker->randomElement([
                'Reforma', 'Centro', 'Santa Rosa', 'San Felipe', 'Volcanes',
            ])),
            'postal_code' => '68'.$this->faker->numerify('###'),
            'city' => 'Oaxaca de Juárez',
            'state' => 'OAXACA',

            'guardian_first_name' => strtoupper($this->faker->firstName()),
            'guardian_last_name' => strtoupper($this->faker->lastName()),
            'guardian_second_last_name' => strtoupper($this->faker->lastName()),
            'guardian_curp' => $this->faker->regexify('[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9]{2}'),
            'guardian_phone' => '951'.$this->faker->numerify('#######'),
            'guardian_relationship' => $this->faker->randomElement(['MADRE', 'PADRE', 'TUTOR']),

            'workshop_first_choice' => $firstWorkshop,
            'workshop_second_choice' => $secondWorkshop,
            'has_school_voucher' => $hasVoucher,
            'school_voucher_folio' => $hasVoucher ? strtoupper(Str::random(8)) : '0',

            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
        ];
    }

    public function readyToConvert(): static
    {
        return $this->state(fn () => [
            'status' => PreEnrollmentStatus::PENDING,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
            'admission_exam_score' => $this->faker->randomFloat(2, 6, 10),
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
        ]);
    }

    public function docsCompletePaymentPending(): static
    {
        return $this->state(fn () => [
            'status' => PreEnrollmentStatus::PENDING,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::PENDING,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => PreEnrollmentStatus::REJECTED,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
        ]);
    }
}
