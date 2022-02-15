<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Timmicom\Api\InvalidParameterException;
use Timmicom\Reports\GenerateReportJob;
use Timmicom\Reports\Report;
use Timmicom\Reports\ReportResource;
use Timmicom\Reports\Templates\ActiveOrdersReport;
use Timmicom\Reports\Templates\CompletedOrdersReport;
use Timmicom\Reports\Templates\TasksReport;
use Timmicom\Reports\Templates\ActiveTaskReport;
use Timmicom\Support\Result;
use Timmicom\Workflows\Workflow;

class ReportController extends Controller
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index(Request $request)
    {
        $this->authorize(Report::class);

        $reports = Report::orderBy('updated_at', 'desc')->get();

        return ReportResource::collection($reports);
    }

    /**
     * @param Report $report
     * @return \Illuminate\Http\Resources\Json\Resource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(Report $report)
    {
        $this->authorize(Report::class);

        return $report->getResource();
    }

    /**
     * @param Report $report
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function download(Report $report)
    {
        $this->authorize(Report::class);

        $fullpath = $report->path;
        if (!Storage::has($fullpath)) {
            abort(404);
        }

        $mimeType = Storage::mimeType($fullpath);
        $mimeFromReport = $report->type === 'csv' ? 'text/csv' : null;
        $filename = basename($fullpath);

        $stream = Storage::getDriver()->readStream($fullpath);
        $headers = [
            'Content-Type' => $mimeType ?: $mimeFromReport,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, DELETE',
        ];

        return response()->stream(function () use ($stream) {
            fpassthru($stream);
        }, 200, $headers);
    }

    /**
     * @param Request $request
     * @return Result
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function create(ReportRequest $request)
    {
        $this->authorize(Report::class);
        $data = $request->validated();

        if (isset($data['workflow_id']) && !Workflow::where('id', $data['workflow_id'])->exists()) {
            throw new InvalidParameterException('Invalid workflow selected');
        }

        $mode = $data['mode'] ?? 'default';
        $mode = 'sync';
        $templates = [
            'active_orders' => ActiveOrdersReport::class,
            'completed_orders' => CompletedOrdersReport::class,
            'tasks' => TasksReport::class,
            'active_tasks' => ActiveTaskReport::class,
        ];
        $templateClass = $templates[$data['type']];
        $template = new $templateClass($data);

        $job = new GenerateReportJob($template);

        if ($mode === 'default') {
            $this->dispatch($job);

            $report = $job->getReport();

            return Result::success([
                'message' => 'Report added to queue',
                'report_id' => $report->id,
                'report' => $report->getResource()->toArray($request)
            ]);

        } else if ($mode === 'sync') {
            $this->dispatchNow($job);

            $report = $job->getReport()->fresh();

            return Result::success([
                'message' => 'Report generated successfully',
                'report_id' => $report->id,
                'report' => $report->getResource()->toArray($request)
            ]);
        }

        return Result::error('Invalid mode selected.');
    }

}
