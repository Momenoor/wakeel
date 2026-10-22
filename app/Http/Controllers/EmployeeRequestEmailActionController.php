<?php

namespace App\Http\Controllers;

use App\Models\EmployeeRequest;
use App\Services\MMS\EmployeeRequestService;
use Illuminate\Http\Request;

/**
 * Approve or reject an employee request from the office email's link, with no login — as a leave request's
 * (LeaveRequestEmailActionController, whose notes hold for this too): the signed URL is the only
 * authentication; GET only shows a confirm page, the POST its button sends decides. Approving here takes the
 * request as it was asked.
 */
class EmployeeRequestEmailActionController
{
    public function approve(Request $request, EmployeeRequest $employeeRequest, EmployeeRequestService $service)
    {
        return $this->decide($request, $employeeRequest, 'approve', function () use ($employeeRequest, $service): string {
            $service->approve($employeeRequest, null, comment: __('Approved by email.'));

            return __('Request approved.');
        });
    }

    public function reject(Request $request, EmployeeRequest $employeeRequest, EmployeeRequestService $service)
    {
        return $this->decide($request, $employeeRequest, 'reject', function () use ($employeeRequest, $service): string {
            $service->reject($employeeRequest, null, __('Rejected by email.'));

            return __('Request rejected.');
        });
    }

    /** @param  callable(): string  $decide  what came of it */
    private function decide(Request $request, EmployeeRequest $employeeRequest, string $action, callable $decide)
    {
        $rows = EmployeeRequestService::rows($employeeRequest);

        if (! $employeeRequest->isPending()) {
            return view('mails.request-response', [
                'type' => 'error',
                'rows' => $rows,
                'message' => __('This link has already been used. The request status is: ').$employeeRequest->status->getLabel(),
            ]);
        }

        if ($request->isMethod('get')) {
            return view('mails.request-confirm', ['rows' => $rows, 'action' => $action]);
        }

        $message = $decide();

        return view('mails.request-response', [
            'type' => $action === 'approve' ? 'approved' : 'rejected',
            'rows' => $rows,
            'message' => $message,
        ]);
    }
}
