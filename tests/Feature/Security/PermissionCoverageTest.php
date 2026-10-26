<?php

declare(strict_types=1);

use Database\Seeders\PermissionsSeeder;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * The permission map is checked as a whole, so a new endpoint cannot ship without a permission and a
 * module cannot lose its create / update / delete.
 */
function routeMiddleware(LaravelRoute $route): array
{
    // Aliases ('permission', 'user.type') resolved to their classes, controller middleware included.
    return array_values(array_filter(Route::resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware()), 'is_string'));
}

function backOfficeRoutes(): array
{
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/'))
        ->filter(fn (LaravelRoute $r) => collect(routeMiddleware($r))->contains(fn ($m) => str_contains($m, 'EnsureUserType:admin,staff')))
        ->values()
        ->all();

    // A guard against this test passing on an empty list.
    expect(count($routes))->toBeGreaterThan(250);

    return $routes;
}

/**
 * @return list<string>
 */
function permissionsOf(LaravelRoute $route): array
{
    return collect(routeMiddleware($route))
        ->filter(fn ($m) => preg_match('/(?:permission|PermissionMiddleware):(.+)$/', $m))
        ->flatMap(fn (string $m) => explode('|', preg_replace('/^.*(?:permission|PermissionMiddleware):/', '', $m)))
        ->values()
        ->all();
}

/**
 * Endpoints open to every signed-in back-office user on purpose: dropdown lists (id, name, code only),
 * the staff pick-lists, and withdrawing your own approval request.
 */
const OPEN_ON_PURPOSE = [
    'GET api/v1/collateral-types',
    'GET api/v1/countries/list',
    'GET api/v1/provinces/list',
    'GET api/v1/zonals/list',
    'GET api/v1/regions/list',
    'GET api/v1/departments/list',
    'GET api/v1/departments/{id}/designations',
    'GET api/v1/designations/list',
    'GET api/v1/groups/list',
    'GET api/v1/lookups/options/{type}',
    'GET api/v1/global-search/id-types',
    'GET api/v1/employees/list',
    'GET api/v1/users/list',
    'POST api/v1/approval-requests/{publicId}/cancel',
];

it('has a permission on every back-office endpoint except the dropdowns listed on purpose', function () {
    $open = collect(backOfficeRoutes())
        ->filter(fn (LaravelRoute $r) => permissionsOf($r) === [])
        ->flatMap(fn (LaravelRoute $r) => collect($r->methods())->reject(fn ($m) => in_array($m, ['HEAD'], true))->map(fn ($m) => "{$m} {$r->uri()}"))
        ->values()
        ->all();

    expect(array_values(array_diff($open, OPEN_ON_PURPOSE)))->toBe([], 'These endpoints have no permission. Give them one, or list them in OPEN_ON_PURPOSE if they are a dropdown.');
});

it('only checks permissions that exist in the seeder', function () {
    $known = array_column(PermissionsSeeder::catalog(), 'name');
    $used = collect(backOfficeRoutes())->flatMap(fn (LaravelRoute $r) => permissionsOf($r))->unique()->values()->all();

    expect(array_values(array_diff($used, $known)))->toBe([]);
});

it('has no permission that nothing checks', function () {
    // Checked by services (approval inbox, branch scope, special approval), not by a route.
    $checkedInCode = [
        'Product Shariah Approve', 'Payment Reversal Approve', 'Payment Backdate Approve', 'Penalty Waiver Approve',
        'Loan Restructure Approve', 'Loan Write Off Approve', 'GL Journal Approve', 'Loan Approve Special',
        'Branch Data All', 'Customer View Sensitive', 'Loan Multiple Open Allow', 'Loan Delete After Payout',
    ];
    $known = array_column(PermissionsSeeder::catalog(), 'name');
    $used = collect(backOfficeRoutes())->flatMap(fn (LaravelRoute $r) => permissionsOf($r))->unique()->all();

    expect(array_values(array_diff($known, $used, $checkedInCode)))->toBe([], 'These permissions are never checked by any route.');
});

it('gives every module its own index, create, update and delete permission', function () {
    // module => [index, create, update, delete]; null where the action does not apply to the module.
    $modules = [
        'Permission' => ['Permission Index', 'Permission Create', 'Permission Update', 'Permission Delete'],
        'Role' => ['Role Index', 'Role Create', 'Role Update', 'Role Delete'],
        'User' => ['User Index', 'User Create', 'User Update', 'User Delete'],
        'Employee' => ['Employee Index', 'Employee Create', 'Employee Update', 'Employee Delete'],
        'Country' => ['Country Index', 'Country Create', 'Country Update', 'Country Delete'],
        'Province' => ['Province Index', 'Province Create', 'Province Update', 'Province Delete'],
        'Zonal' => ['Zonal Index', 'Zonal Create', 'Zonal Update', 'Zonal Delete'],
        'Region' => ['Region Index', 'Region Create', 'Region Update', 'Region Delete'],
        'Branch' => ['Branch Index', 'Branch Create', 'Branch Update', 'Branch Delete'],
        'Department' => ['Department Index', 'Department Create', 'Department Update', 'Department Delete'],
        'Designation' => ['Designation Index', 'Designation Create', 'Designation Update', 'Designation Delete'],
        'Group' => ['Group Index', 'Group Create', 'Group Update', 'Group Delete'],
        'Setting' => ['Setting Index', 'Setting Create', 'Setting Update', 'Setting Delete'],
        'Lookup' => ['Lookup Index', 'Lookup Create', 'Lookup Update', 'Lookup Delete'],
        'Charge Type' => ['Charge Type Index', 'Charge Type Create', 'Charge Type Update', 'Charge Type Delete'],
        'Product Category' => ['Product Category Index', 'Product Category Create', 'Product Category Update', 'Product Category Delete'],
        'Product' => ['Product Index', 'Product Create', 'Product Update', 'Product Delete'],
        'Document Type' => ['Document Type Index', 'Document Type Create', 'Document Type Update', 'Document Type Delete'],
        'Document Requirement' => ['Document Requirement Index', 'Document Requirement Create', 'Document Requirement Update', 'Document Requirement Delete'],
        'Document' => ['Document Index', 'Document Upload', 'Document Update', 'Document Delete'],
        'Customer' => ['Customer Index', 'Customer Create', 'Customer Update', 'Customer Delete'],
        'Customer Financial' => ['Customer Financial Index', 'Customer Financial Create', 'Customer Financial Update', 'Customer Financial Delete'],
        'Customer Bank Account' => ['Customer Bank Account Index', 'Customer Bank Account Create', 'Customer Bank Account Update', 'Customer Bank Account Delete'],
        'Borrower Group' => ['Borrower Group Index', 'Borrower Group Create', 'Borrower Group Update', 'Borrower Group Delete'],
        'Borrower Group Member' => [null, 'Borrower Group Member Create', 'Borrower Group Member Update', 'Borrower Group Member Delete'],
        'Loan' => ['Loan Index', 'Loan Create', 'Loan Update', 'Loan Delete'],
        'Loan Guarantor' => ['Loan Guarantor Index', 'Loan Guarantor Create', 'Loan Guarantor Update', 'Loan Guarantor Delete'],
        'Loan Collateral' => ['Loan Collateral Index', 'Loan Collateral Create', 'Loan Collateral Update', 'Loan Collateral Delete'],
        'Loan Note' => ['Loan Note Index', 'Loan Note Create', 'Loan Note Update', 'Loan Note Delete'],
        'Payment' => ['Payment Index', 'Payment Create', 'Payment Update', 'Payment Delete'],
        'Recovery Case' => ['Recovery Case Index', 'Recovery Case Create', 'Recovery Case Update', 'Recovery Case Delete'],
        'Template' => ['Template Index', 'Template Create', 'Template Update', 'Template Delete'],
        'Legal Template' => ['Legal Template Index', 'Legal Template Create', 'Legal Template Update', 'Legal Template Delete'],
        'Legal Document' => ['Legal Document Index', 'Legal Document Create', 'Legal Document Update', 'Legal Document Delete'],
        'GL Account' => ['GL Account Index', 'GL Account Create', 'GL Account Update', 'GL Account Delete'],
        'GL Journal' => ['GL Journal Index', 'GL Journal Request', 'GL Journal Update', 'GL Journal Delete'],
        // Notifications are written by the system; a user reads, marks read and deletes their own.
        'Notification' => ['Notification Index', null, 'Notification Mark Read', 'Notification Delete'],
    ];

    $known = array_column(PermissionsSeeder::catalog(), 'name');
    $missing = collect($modules)->flatMap(fn (array $set, string $module) => collect($set)->filter()->reject(fn ($p) => in_array($p, $known, true))->map(fn ($p) => "{$module}: {$p}"))->values()->all();

    expect($missing)->toBe([]);
});

it('no longer has combined "Manage" permissions', function () {
    $manage = collect(array_column(PermissionsSeeder::catalog(), 'name'))->filter(fn ($n) => str_ends_with($n, ' Manage') && ! str_starts_with($n, 'Loan Offer'))->values()->all();

    expect($manage)->toBe([]);
});
