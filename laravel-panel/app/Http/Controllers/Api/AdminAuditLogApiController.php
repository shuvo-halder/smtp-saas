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

        $paginator = $query->latest('id')->paginate($perPage);

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
