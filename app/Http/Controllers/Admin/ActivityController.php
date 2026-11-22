<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsActivity;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Everyone's activity, for admins (FR-18). */
class ActivityController extends Controller
{
    use ListsActivity;

    public function index(Request $request): Response
    {
        return Inertia::render('activity/index', [
            ...$this->activityPage(ActivityLog::query(), $request, filterByUser: true),
            'scope' => 'all',
        ]);
    }
}
