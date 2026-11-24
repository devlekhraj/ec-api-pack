<?php

declare(strict_types=1);

namespace EcApi\Domains\Role\Controllers;

use EcApi\Models\Permission;
use App\Http\Controllers\Controller;

final class PermissionApiController extends Controller
{
    public function index()
    {
        $permissions = Permission::with('roles:id,name')->get();

        return response()->json([
            'permissions' => $permissions,
        ]);
    }
}
