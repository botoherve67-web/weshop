<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ErrorLogController extends Controller
{
    public function index(Request $request)
    {
        $journalFailure = null;

        try {
            $query = ErrorLog::with('user')->latest();

            if ($request->filled('q')) {
                $search = $request->q;
                $query->where(function ($builder) use ($search) {
                    $builder->where('message', 'like', "%{$search}%")
                        ->orWhere('exception_class', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            }

            $errorLogs = $query->paginate(30)->withQueryString();
        } catch (Throwable $exception) {
            Log::error('Lecture journal erreurs impossible.', ['exception' => $exception]);
            $journalFailure = $exception->getMessage();
            $errorLogs = new LengthAwarePaginator([], 0, 30);
        }

        return view('admin.errors.index', compact('errorLogs', 'journalFailure'));
    }
}
