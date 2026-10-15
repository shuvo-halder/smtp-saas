<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAuditLogResource;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Throwable;

class AdminAuditLogApiController extends Controller
{
    /**
     * GET /api/admin/audit-logs
     * Paginated list of persistent audit log records with bounded limit and filters.
     */
    public function index(Request $request)
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 50);

        $query = AuditLog::query()->with('actor:id,name,email');

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->input('entity_type'));
        }

        if ($request->filled('entity_id')) {
            $query->where('entity_id', $request->input('entity_id'));
        }

        if ($request->filled('actor_user_id')) {
            $query->where('actor_user_id', $request->input('actor_user_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                  ->orWhere('actor_email', 'like', "%{$search}%")
                  ->orWhere('entity_type', 'like', "%{$search}%")
                  ->orWhere('reason', 'like', "%{$search}%");
            });
        }

        if ($request->filled('date_from')) {
            try {
                $from = Carbon::parse($request->input('date_from'))->startOfDay();
                $query->where('created_at', '>=', $from);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if ($request->filled('date_to')) {
            try {
                $to = Carbon::parse($request->input('date_to'))->endOfDay();
                $query->where('created_at', '<=', $to);
            } catch (Throwable) {
                // Ignore invalid date format
            }
        }

        if ($request->filled('outcome')) {
            $outcome = strtolower((string) $request->input('outcome'));
            if ($outcome === 'denial') {
                $query->where(function ($q) {
                    $q->where('action', 'like', '%.denied')
                      ->orWhere('action', 'like', '%denial%')
                      ->orWhere('after_state->outcome', 'denial');
                });
            } elseif ($outcome === 'failure') {
                $query->where(function ($q) {
                    $q->where('action', 'like', '%.failed')
                      ->orWhere('after_state->outcome', 'failure');
                });
            } elseif ($outcome === 'success') {
                $query->where(function ($q) {
                    $q->where('after_state->outcome', 'success')
                      ->orWhere(function ($sub) {
                          $sub->where('action', 'not like', '%.failed')
                              ->where('action', 'not like', '%.denied')
                              ->where('action', 'not like', '%denial%')
                              ->where(function ($s2) {
                                  $s2->whereNull('after_state->outcome')
                                     ->orWhereNotIn('after_state->outcome', ['failure', 'denial']);
                              });
                      });
                });
            }
        }

        $allowedSortBy = ['id', 'created_at', 'action', 'entity_type'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortBy, true) ? $request->input('sort_by') : 'id';
        $direction = strtolower((string) $request->input('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        $paginator = $query->orderBy($sortBy, $direction)->paginate($perPage);

        return AdminAuditLogResource::collection($paginator);
    }

    /**
     * GET /api/admin/audit-logs/{id}
     * Retrieve single audit log entry with detailed before/after diff.
     */
    public function show(int $id)
    {
        $auditLog = AuditLog::with('actor:id,name,email')->findOrFail($id);

        return new AdminAuditLogResource($auditLog);
    }
}
