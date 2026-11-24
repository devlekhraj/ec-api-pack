<?php

declare(strict_types=1);

namespace EcApi\Domains\Role\Controllers;

use EcApi\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class RoleApiController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions:id,name')->get();

        return response()->json(['data' => $roles], 200);
    }

    public function show($id)
    {
        return response()->json(Role::findOrFail($id));
    }

    public function store(Request $request)
    {
        $role = Role::create($request->all());

        return response()->json($role, 201);
    }

    public function update(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $role->update($request->all());

        return response()->json($role);
    }

    public function destroy($id)
    {
        Role::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
