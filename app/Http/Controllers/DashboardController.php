<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $totalApplicants = Applicant::count();
        $todayApplicants = Applicant::whereDate('created_at', today())->count();
        $missingDocsCount = Applicant::whereNotNull('missing_documents')
            ->where('missing_documents', '!=', '')
            ->count();

        $recentApplicants = Applicant::latest()->take(6)->get();

        return view('dashboard', compact(
            'totalApplicants',
            'todayApplicants',
            'missingDocsCount',
            'recentApplicants'
        ));
    }
}