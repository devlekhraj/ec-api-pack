<?php

declare(strict_types=1);

namespace EcApi\Domains\Role\Controllers;

use EcApi\Models\Permission;
use EcApi\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class RolePermissionApiController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions:id,name')->get();
        $permissions = Permission::select('id', 'name')->with('roles:id,name')->get();

        return response()->json([
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function assignPermissions(Request $request, $id)
    {
        $role = Role::findOrFail($id);
        $role->permissions()->sync($request->permissions);

        return response()->json($role->load('permissions'));
    }
}
