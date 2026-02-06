<?php

namespace Database\Factories;

use App\Models\PreEnrollment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PreEnrollment>
 */
class PreEnrollmentFactory extends Factory
{
    protected $model = PreEnrollment::class;
    /**
     * Static counter for the incremental folio
    */
    protected static int $folioCounter = 1;
    /**
     * Define the model's default state.
    *
    * @return array<string, mixed>
    */
    
    public function definition(): array
    {
        // Incremental folio 001 - 999
        $folio = str_pad(self::$folioCounter++, 3, '0', STR_PAD_LEFT);

        // Safety limit
        if (self::$folioCounter > 999) {
            self::$folioCounter = 1;
        }

        // Voucher
        $hasVoucher = $this->faker->boolean(40);

        return [
            'admission_cycle_id' => 1,
            'folio' => $folio,
            'status' => 'pending',

            'contact_email' => $this->faker->unique()->safeEmail(),
            'first_name' => strtoupper($this->faker->firstName()),
            'last_name' => strtoupper($this->faker->lastName()),
            'second_last_name' => strtoupper($this->faker->lastName()),

            // Unique CURP
            'curp' => $this->faker->unique()->regexify('[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9]{2}'),

            'birth_date' => $this->faker->dateTimeBetween('-14 years', '-12 years')->format('Y-m-d'),
            'age' => $this->faker->numberBetween(12, 14),
            'gender' => $this->faker->randomElement(['M', 'F']),

            'phone' => '951' . $this->faker->numberBetween(1000000, 9999999),
            'student_email' => $this->faker->unique()->safeEmail(),

            'place_of_birth' => 'OAXACA',
            'previous_school' => strtoupper($this->faker->company()),
            'current_average' => $this->faker->randomFloat(2, 6, 10),

            'has_siblings' => $this->faker->boolean(),
            'siblings_details' => $this->faker->optional()->sentence(3),

            'street_type' => 'Ampliación',
            'street_name' => strtoupper($this->faker->streetName()),
            'house_number' => $this->faker->buildingNumber(),
            'unit_number' => $this->faker->optional()->bothify('Apt-##'),

            'neighborhood_type' => 'COLONIA',
            'neighborhood_name' => strtoupper($this->faker->citySuffix()),
            'postal_code' => '68' . $this->faker->numberBetween(000, 999),
            'city' => 'Oaxaca de Juárez',
            'state' => 'OAXACA',

            'guardian_first_name' => strtoupper($this->faker->firstName()),
            'guardian_last_name' => strtoupper($this->faker->lastName()),
            'guardian_second_last_name' => strtoupper($this->faker->lastName()),
            'guardian_curp' => $this->faker->regexify('[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9]{2}'),
            'guardian_phone' => '951' . $this->faker->numberBetween(1000000, 9999999),
            'guardian_relationship' => $this->faker->randomElement(['MADRE', 'PADRE', 'TUTOR']),

            'workshop_first_choice' => $this->faker->randomElement([
                'CONFECCION_VESTIDO',
                'DISEÑO_INDUSTRIAL',
                'ELECTRONICA',
                'INFORMATICA'
            ]),
            'workshop_second_choice' => $this->faker->randomElement([
                'CONFECCION_VESTIDO',
                'DISEÑO_INDUSTRIAL',
                'ELECTRONICA',
                'INFORMATICA'
            ]),

            'has_school_voucher' => $hasVoucher,
            'school_voucher_folio' => $hasVoucher
                ? strtoupper(Str::random(8))
                : '0',

            // Documents (NULL allowed)
            'birth_certificate_path' => null,
            'curp_document_path' => null,
            'address_proof_path' => null,
            'study_certificate_path' => null,
            'photo_path' => null,

            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),

            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
