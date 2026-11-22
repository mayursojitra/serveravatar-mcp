<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait ListsActivity
{
    /**
     * Filtered, newest-first page of activity rows, shaped for the activity page.
     *
     * @param  Builder<ActivityLog>  $query
     * @return array<string, mixed>
     */
    protected function activityPage(Builder $query, Request $request, bool $filterByUser = false): array
    {
        $kind = $request->string('kind')->toString();
        $status = $request->string('status')->toString();

        if (in_array($kind, ['auth', 'admin', 'mcp', 'token'], true)) {
            $query->where('kind', 'like', $kind.'.%');
        }

        if (in_array($status, [ActivityLog::STATUS_OK, ActivityLog::STATUS_FAILED, ActivityLog::STATUS_DENIED], true)) {
            $query->where('status', $status);
        }

        // A tool name from the configured list only, so the filter value is never free text in a query.
        $tools = $this->toolNames();
        $tool = $request->string('tool')->toString();

        if (in_array($tool, $tools, true)) {
            $query->where('tool', $tool);
        }

        // Admins can narrow to one person: part of an e-mail address, with LIKE wildcards escaped.
        $user = $filterByUser ? mb_substr(trim($request->string('user')->toString()), 0, 100) : '';

        if ($user !== '') {
            $query->where('user_email', 'like', '%'.addcslashes($user, '%_\\').'%');
        }

        $page = $query->latest('id')->paginate(25)->withQueryString();

        return [
            'rows' => $page->getCollection()->map(fn (ActivityLog $log) => $log->toRow())->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'prev_page_url' => $page->previousPageUrl(),
                'next_page_url' => $page->nextPageUrl(),
            ],
            'filters' => [
                'kind' => $kind ?: null,
                'status' => $status ?: null,
                'tool' => in_array($tool, $tools, true) ? $tool : null,
                'user' => $user ?: null,
            ],
            'tools' => $tools,
        ];
    }

    /** @return list<string> the names of every tool the server offers, sorted */
    private function toolNames(): array
    {
        $names = array_map(fn (string $class) => app($class)->name(), config('serveravatar.mcp_tools', []));
        sort($names);

        return $names;
    }
}
