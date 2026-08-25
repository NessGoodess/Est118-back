<?php

namespace App\Http\Controllers\Admission;

use App\Exports\PreEnrollmentExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Maatwebsite\Excel\Facades\Excel;

class PreEnrollmentExportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:view pre-enrollments'),
        ];
    }

    public function export()
    {
        return Excel::download(new PreEnrollmentExport, 'preinscripciones_' . date('Y-m-d') . '.xlsx');
    }
    
    public function exportFiltered(Request $request)
    {
        $export = new PreEnrollmentExport();
 
        if ($request->has('status')) {
            $export->setStatus($request->status);
        }
        
        return Excel::download($export, 'preinscripciones_filtradas_' . date('Y-m-d') . '.xlsx');
    }
}
