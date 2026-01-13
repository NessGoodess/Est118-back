<?php

namespace App\Http\Controllers;

use App\Models\PreEnrollment;
use App\Http\Requests\StorePreEnrollmentRequest;
use App\Http\Requests\UpdatePreEnrollmentRequest;

class PreEnrollmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return PreEnrollment::all()
            ->orderBy('id', 'desc');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePreEnrollmentRequest $request)
    {
        $preEnrollment = PreEnrollment::create($request->validate());
        return response()->json(
            [
                "data" => $preEnrollment,
                "message" => "PreEnrollment created successfully"
            ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(PreEnrollment $preEnrollment)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PreEnrollment $preEnrollment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePreEnrollmentRequest $request, PreEnrollment $preEnrollment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PreEnrollment $preEnrollment)
    {
        //
    }
}
