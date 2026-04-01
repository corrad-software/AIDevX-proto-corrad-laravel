<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 20);
        $q = $request->input('q');

        $query = Customer::query()->orderBy('customer_code');

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('customer_code', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%");
            });
        }

        $total = $query->count();
        $rows = $query->skip(($page - 1) * $limit)->take($limit)->get();

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return $this->sendCreated($customer);
    }

    public function show(int $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return $this->sendError(404, 'NOT_FOUND', 'Customer not found');
        }

        return $this->sendOk($customer);
    }

    public function update(UpdateCustomerRequest $request, int $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return $this->sendError(404, 'NOT_FOUND', 'Customer not found');
        }

        $customer->update($request->validated());

        return $this->sendOk($customer);
    }

    public function destroy(int $id): JsonResponse
    {
        $customer = Customer::find($id);

        if (! $customer) {
            return $this->sendError(404, 'NOT_FOUND', 'Customer not found');
        }

        if ($customer->users()->exists()) {
            return $this->sendError(409, 'CUSTOMER_IN_USE', 'Customer has users and cannot be deleted.');
        }

        $customer->delete();

        return $this->sendOk(['success' => true]);
    }

    /**
     * List active customers for dropdown (e.g. registration form).
     */
    public function listActive(): JsonResponse
    {
        $customers = Customer::where('is_active', true)
            ->orderBy('customer_code')
            ->get(['id', 'customer_code', 'customer_name']);

        return $this->sendOk($customers);
    }
}
