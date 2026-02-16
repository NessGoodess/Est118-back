<?php

namespace App\Http\Controllers\Admission;

use App\Exports\PreEnrollmentExport;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class PreEnrollmentExportController extends Controller
{
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
