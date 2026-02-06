<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreEnrollmentListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'full_name' => trim("{$this->first_name} {$this->last_name} {$this->second_last_name}"),
            'curp' => $this->curp,
            'gender' => $this->gender,
            'age' => $this->age,
            'guardian_name' => trim("{$this->guardian_first_name} {$this->guardian_last_name} {$this->guardian_second_last_name}"),
            'guardian_phone' => $this->guardian_phone,
            'contact_email' => $this->contact_email,
            'created_at' => $this->created_at,
        ];
    }
}
