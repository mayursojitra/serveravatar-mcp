<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsActivity;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** A user's own activity. Never shows another user's rows. */
class ActivityController extends Controller
{
    use ListsActivity;

    public function index(Request $request): Response
    {
        $query = ActivityLog::query()->where('user_id', $request->user()->id);

        return Inertia::render('activity/index', [
            ...$this->activityPage($query, $request),
            'scope' => 'mine',
        ]);
    }
}
