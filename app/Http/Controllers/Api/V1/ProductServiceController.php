<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProductService;
use Illuminate\Http\Request;

class ProductServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductService::orderBy('name');

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && mb_strlen($search) >= 3) {
            $tokens = array_slice(array_filter(preg_split('/\s+/u', $search) ?: []), 0, 8);
            foreach ($tokens as $token) {
                $like = '%'.addcslashes($token, '%_\\').'%';
                $query->where('name', 'like', $like);
            }
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:products_services,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:service,product',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
        ]);

        return response()->json(ProductService::create($data), 201);
    }

    public function update(Request $request, ProductService $productService)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:service,product',
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $productService->update($data);

        return response()->json($productService->fresh());
    }

    public function destroy(ProductService $productService)
    {
        $productService->delete();

        return response()->json(['message' => 'Product/service removed.']);
    }
}
