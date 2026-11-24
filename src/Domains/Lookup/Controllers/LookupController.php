<?php

declare(strict_types=1);

namespace EcApi\Domains\Lookup\Controllers;

use EcApi\Models\Category;
use EcApi\Models\Role;
use EcApi\Models\User;
use App\Http\Controllers\Controller;

final class LookupController extends Controller
{
    public function index()
    {
        try {
            $lookups = [
                'categories' => Category::select('id as value', 'name as text', 'slug')->where('is_deleted', 0)->get(),
                'authors' => User::whereIn('role_name', [User::ROLE_ADMIN, User::ROLE_AUTHOR])
                    ->select('id as value', 'name as text', 'slug', 'username')
                    ->orderBy('name', 'asc')
                    ->get(),
                'roles' => Role::select('id as value', 'name as text')->get(),
            ];

            return response()->json(['data' => $lookups], 200);
        } catch (\Throwable $th) {
            return response()->json(['error' => $th->getMessage() . ' line:' . $th->getFile(), 'status' => 'failed'], 422);
        }
    }
}
