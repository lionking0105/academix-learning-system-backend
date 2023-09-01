<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Redis;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class CategoryAPIController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:api')->except('index', 'show');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $cacheKey = 'categories';
        $expirationTime = 60;

        if (Redis::exists($cacheKey)) {
            $categories = json_decode(Redis::get($cacheKey));
        } else {
            $categories = Category::all();
            Redis::set($cacheKey, json_encode($categories));
            Redis::expire($cacheKey, $expirationTime);
        }
        $filterName = $request->input('name');
        $query = Category::query();

        if ($filterName) {
            $query->where('name', 'like', '%' . $filterName . '%');
        }
        $paginatedCategories = $query->paginate($perPage);

        return CategoryResource::collection($paginatedCategories);
    }


    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:categories|max:255',
        ], [
            'name.unique' => 'The category name already exists.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'error-0003',
                'timestamp' => now(),
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'path' => url()->current(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category = Category::create([
            'name' => $request->name,
        ]);

        return new CategoryResource($category);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $cacheKey = 'category:' . $id;
        $expirationTime = 60;

        if (Redis::exists($cacheKey)) {
            $categoryData = json_decode(Redis::get($cacheKey), true);
            $category = new Category($categoryData);
        } else {
            try {
                $category = Category::findOrFail($id);
                Redis::setex($cacheKey, $expirationTime, json_encode($category));
            } catch (ModelNotFoundException $exception) {
                throw new ModelNotFoundException('Category not found', $exception->getCode(), $exception);
            }
        }

        return new CategoryResource($category);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $category = Category::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'required|unique:categories,name,' . $category->id . '|max:255',
            'description' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'error-0003',
                'timestamp' => now(),
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'path' => url()->current(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $category->name = $request->name;
        $category->description = $request->description;
        $category->save();

        Redis::del('categories');

        return new CategoryResource($category, Response::HTTP_OK);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $category = Category::findOrFail($id);
        $category->delete();

        $cacheKey = 'categories:' . request()->input('name', 'all');
        Redis::del($cacheKey);

        return response()->json([
            'status' => true,
            'message' => 'Category deleted successfully.',
        ], Response::HTTP_OK);
    }

    /**
     * Search the specified resource.
     */
    public function search(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'error-0003',
                'timestamp' => now(),
                'status' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
                'path' => url()->current(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $query = Category::query();

        if ($request->has('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        $categories = $query->get();

        return CategoryResource::collection($categories);
    }
}
