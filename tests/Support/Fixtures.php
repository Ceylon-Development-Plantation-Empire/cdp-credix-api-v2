<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\LookupType;
use App\Models\Employee;
use App\Models\User;
use App\Services\SmsService;
use App\Support\Lookups\LookupRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test data that needs several related rows (a branch needs a province, zone and region;
 * a staff member needs an employee with a designation).
 */
final class Fixtures
{
    /**
     * The id of a seeded lookup value (ReferenceDataSeeder runs before every test).
     */
    public static function lookupId(LookupType $type, string $code): int
    {
        return (int) app(LookupRegistry::class)->idFor($type, $code);
    }

    public static function branch(string $name = 'Colombo'): int
    {
        $code = strtoupper(Str::random(6));
        $now = now();
        $countryId = DB::table('org_units')->insertGetId(['unit_type' => 'country', 'name' => 'Sri Lanka', 'code' => 'C'.$code, 'created_at' => $now, 'updated_at' => $now]);
        $provinceId = DB::table('org_units')->insertGetId(['unit_type' => 'province', 'name' => 'Western', 'code' => 'P'.$code, 'parent_id' => $countryId, 'created_at' => $now, 'updated_at' => $now]);
        $zonalId = DB::table('org_units')->insertGetId(['unit_type' => 'zone', 'name' => 'Zone', 'code' => 'Z'.$code, 'parent_id' => $provinceId, 'created_at' => $now, 'updated_at' => $now]);
        $regionId = DB::table('org_units')->insertGetId(['unit_type' => 'region', 'name' => 'Region', 'code' => 'R'.$code, 'parent_id' => $zonalId, 'created_at' => $now, 'updated_at' => $now]);

        return DB::table('branches')->insertGetId([
            'name' => $name,
            'code' => 'B'.$code,
            'province_id' => $provinceId,
            'zone_id' => $zonalId,
            'region_id' => $regionId,
            'address_line1' => 'Main Street',
            'city' => $name,
            'phone_primary' => '0112345678',
            'opening_date' => '2020-01-01',
            'branch_type_id' => self::lookupId(LookupType::BranchType, 'city'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * A staff user whose employee record sits in the given branch.
     */
    public static function staffInBranch(int $branchId, string ...$permissions): User
    {
        $code = strtoupper(Str::random(6));
        $now = now();
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Credit', 'code' => 'D'.$code, 'created_at' => $now, 'updated_at' => $now]);
        $designationId = DB::table('designations')->insertGetId([
            'department_id' => $departmentId,
            'name' => 'Officer',
            'code' => 'G'.$code,
            'level_id' => self::lookupId(LookupType::DesignationLevel, 'entry'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Through the model, so the ID number is encrypted and its blind index written as in production.
        $employee = Employee::create([
            'f_name' => 'Test',
            'l_name' => 'Officer',
            'full_name' => 'Test Officer',
            'name_with_initials' => 'T. Officer',
            'designation_id' => $designationId,
            'branch_id' => $branchId,
            'employee_type' => 'permanent',
            'id_type' => 'nic',
            'id_number' => 'E'.$code,
            'date_of_birth' => '1990-01-01',
            'email' => strtolower($code).'@example.com',
            'phone_primary' => '0771234567',
        ]);

        $user = staffWith(...$permissions);
        $user->forceFill(['employee_id' => $employee->id])->save();

        return $user->fresh();
    }

    /**
     * Replace the SMS gateway with one that records messages instead of sending them.
     * The OTP only exists inside the SMS text, so this is how a test reads it.
     */
    public static function fakeSms(): FakeSms
    {
        $fake = new FakeSms;
        app()->instance(SmsService::class, $fake);

        return $fake;
    }
}

class FakeSms extends SmsService
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function sendSms($numbers, string $message, int $paymentMethod = 0): bool
    {
        $this->sent[] = ['to' => is_array($numbers) ? implode(',', $numbers) : $numbers, 'message' => $message];

        return true;
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last && preg_match('/\b(\d{6})\b/', $last['message'], $m) ? $m[1] : null;
    }
}
