<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderUpdateRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Timmicom\Orders\Actions\CreateOrderFromWorkflow;
use Timmicom\Orders\Actions\InitiateOrderRedesign;
use Timmicom\Orders\Events\OrderAssignmentCompleted;
use Timmicom\Orders\Events\OrderOnholdStatusChanged;
use Timmicom\Orders\Events\OrderPriorityChanged;
use Timmicom\Orders\Events\OrderRedesignInitiated;
use Timmicom\Orders\Events\OrderUpdated;
use Timmicom\Orders\Import\Assignments;
use Timmicom\Orders\Import\Importer;
use Timmicom\Orders\Import\Sources\Xlsx;
use Timmicom\Orders\Order;
use Timmicom\Orders\OrderResource;
use Timmicom\Orders\TaskResource;
use Timmicom\System\Events\BulkAssignmentCompleted;
use Timmicom\System\Events\BulkImportCompleted;
use Timmicom\Workflows\Workflow;

class OrderController extends Controller
{
    /**
     * @param Request $request
     * @return mixed
     */
    public function overview(Request $request)
    {
        $isFilterUsed = !empty($request->input());
        $isOrderNumberSearch = false;

        $query = Order::with('taskGroup');

        if ($search = $request->input('search')) {
            $isOrderNumberSearch = Order::where('number', $search)->exists();
            $query = $query->search($search);
        }

        if ($startDate = $request->input('start_date')) {
            $startDate = Carbon::createFromFormat('Y-m-d', $startDate);
            $query = $query->where('due_at', '>=', $startDate->startOfDay());
        }

        if ($endDate = $request->input('end_date')) {
            $endDate = Carbon::createFromFormat('Y-m-d', $endDate);
            $query = $query->where('due_at', '<=', $endDate->endOfDay());
        }

        if ($user = $request->input('user_id')) {
            $query = $query->assignedToActiveTask($user);
        }

        if (!$isOrderNumberSearch) {
            $query = $query->complete(false);
        }

        $orders = $this->getOrderResults($query, $request);

        return OrderResource::formattedCollection($orders, 'overview')->additional(['meta' => [
            'number_match' => (int)$isOrderNumberSearch,
        ]]);
    }

    /**
     * @param Builder $query
     * @param Request $request
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator|Builder[]|\Illuminate\Database\Eloquent\Collection
     */
    protected function getOrderResults(Builder $query, Request $request)
    {
        $mode = $request->input('mode', 'default');

        $orders = null;
        if ($mode === 'paginated') {
            $orders = $query->paginate($request->input('per-page') ?: 50);
        } else {
            $orders = $query->get();
        }

        return $orders;
    }

    /**
     * @param Request $request
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function assignment(Request $request)
    {
        $this->authorize(Order::class);

        $assignments = $request->json('assignments', []);
        $assignmentsManager = new Assignments;

        $results = $assignmentsManager->apply($assignments);

        foreach ($assignmentsManager->getOrders() as $order) {
            event(new OrderAssignmentCompleted($order));
        }

        $anySuccessful = Collection::wrap($results)->filter->isSuccessful()->count() > 0;

        if ($anySuccessful) {
            $user = auth()->user();
            event(new BulkAssignmentCompleted($user));
        }

        return $results;
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function imported(Request $request)
    {
        $request->merge([
            'order-format' => 'simple',
        ]);

        $this->authorize(Order::class);

        $query = Order::query();

        $orders = $query->imported()->get();

        return OrderResource::collection($orders);
    }

    /**
     * @param Request $request
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function import(Request $request)
    {
        $this->authorize(Order::class);

        $file = $request->file('file');

        $importer = new Importer;
        $report = $importer->import(Xlsx::createFromFile($file));
        $results = $report->getResults();

        if (!empty($results)) {
            $user = auth()->user();
            event(new BulkImportCompleted($user));
        }

        return $report->toArray();
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $query = Order::query();

        $orders = $this->getOrderResults($query, $request);

        return OrderResource::collection($orders);
    }

    /**
     * @param $orderId
     * @param Request $request
     * @return mixed
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update($orderId, OrderUpdateRequest $request)
    {
        $order = Order::allOrders()->findOrFail($orderId);

        $this->authorize($order);

        $data = $request->only([
            'comments', 'proposed_design_solution'
        ]);

        $order->fill($data);
        $orderWasUpdated = $order->isDirty();
        $order->save();

        if ($orderWasUpdated) {
            event(new OrderUpdated($order));
        }

        return $order->getResource();
    }

    /**
     * @param Order $order
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\Resource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function onhold(Order $order, Request $request)
    {
        $this->authorize($order);

        $value = intval($request->input('onhold', 1), 10);
        $onholdChanged = $order->onhold !== $value;

        $order = $order->setOnhold($value);

        if ($onholdChanged) {
            event(new OrderOnholdStatusChanged($order));
        }

        return $order->getResource();
    }

    /**
     * @param Order $order
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\Resource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function escalate(Order $order, Request $request)
    {
        $this->authorize($order);

        $value = intval($request->input('escalate', 1), 10);
        $priorityChanged = $order->escalated !== $value;

        $order = $order->setEscalated($value);

        if ($priorityChanged) {
            $event = $value
                ? OrderPriorityChanged::priorityChangedToEscalated($order)
                : OrderPriorityChanged::priorityChangedToStandard($order);
            event($event);
        }

        return $order->getResource();
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\Resource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function createFromWorkflow(Request $request)
    {
        $this->authorize(Order::class);

        $data = $request->validated();

        $workflow = Workflow::findOrFail($data['workflow']);
        $orderData = array_except($data, 'workflow');
        $orderData['service_number'] = $orderData['fnn'];

        $action = new CreateOrderFromWorkflow($workflow);
        $order = $action->execute($orderData);

        return $this->details($order, $request);
    }

    /**
     * @param Request $request
     * @return array
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function delete(Request $request)
    {
        $this->authorize(Order::class);

        $orders = $request->input('orders', []);
        if (empty($orders)) {
            return [
                'success' => true,
                'deletedCount' => 0
            ];
        }

        $ordersToDelete = Order::imported()->whereIn('id', $orders)->get();

        if (!$ordersToDelete->isEmpty())
            $ordersToDelete->map->delete();
        {
        }

        return [
            'success' => true,
            'deletedCount' => $ordersToDelete->count()
        ];
    }

    /**
     * @param Order $order
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Validation\ValidationException
     */
    public function redesign(Order $order, Request $request)
    {
        $this->authorize(Order::class);

        $tasks = $request->input('tasks', []);

        $tasksIncluded = ['can', 'tech', 'design'];
        $fieldsPerTask = ['due_at', 'assignee', 'verifier'];

        // need to validate each task, but only if it has any data submitted
        $rules = [];
        $taskData = [];
        foreach ($tasksIncluded as $taskKey) {
            $data = array_get($tasks, $taskKey, []);
            $hasSubmittedData = !empty(array_filter($data));
            if ($hasSubmittedData) {
                $rules = array_merge($rules, [
                    "tasks.$taskKey.due_at" => ['required', 'date_format:Y-m-d'],
                    "tasks.$taskKey.assignee" => ['required', 'numeric'],
                    "tasks.$taskKey.verifier" => ['required', 'numeric'],
                ]);
                $taskData[$taskKey] = $data;
            }
        }

        $this->validate($request, $rules);

        $tasks = (new InitiateOrderRedesign($order))->execute($taskData);

        event(new OrderRedesignInitiated($order, $tasks));

        return TaskResource::collection($tasks);
    }

    /**
     * @param Order $order
     * @param Request $request
     * @return \Illuminate\Http\Resources\Json\Resource
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function details(Order $order, Request $request)
    {
        $this->authorize($order);

        return $order->getResource('full');
    }
}
