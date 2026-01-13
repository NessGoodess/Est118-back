<?php

namespace App\Http\Controllers;

use App\Models\Schedule;
use App\Http\Requests\StoreScheduleRequest;
use App\Http\Requests\UpdateScheduleRequest;

class ScheduleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        $teacherId = 1;
        $dayName = 'Lunes'; // ejemplo: "martes"
        // $teacherId = auth()->id(); // cuando uses auth

        $schedules = Schedule::select('id', 'day', 'start_time', 'end_time', 'school_class_id')
            ->where('day', $dayName)
            ->whereHas('schoolClass', function ($q) use ($teacherId) {
                $q->where('teacher_id', $teacherId);
            })
            ->with([
                'schoolClass.subject:id,name',
                'schoolClass.classGroup:id,name,grade_level_id',
                'schoolClass.classGroup.gradeLevel:id,name',
            ])
            ->get();

        $schedules = $schedules->map(function ($schedule) {
            return [
                'id'          => $schedule->id,
                'day'         => $schedule->day,
                'start_time'  => $schedule->start_time,
                'end_time'    => $schedule->end_time,
                'subject'     => $schedule->schoolClass->subject->name ?? null,
                'group'       => $schedule->schoolClass->classGroup->name ?? null,
                'grade_level' => $schedule->schoolClass->classGroup->gradeLevel->name ?? null,
                'class_group_id' => $schedule->schoolClass->classGroup->id ?? null,
            ];
        });

        return response()->json([
            'status'    => "success",
            'schedules' => $schedules,
        ], 200);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreScheduleRequest $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Schedule $schedule)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Schedule $schedule)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateScheduleRequest $request, Schedule $schedule)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Schedule $schedule)
    {
        //
    }
}
