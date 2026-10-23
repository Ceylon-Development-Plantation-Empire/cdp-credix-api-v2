<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AllocationComponent;
use App\Enums\ApprovalAction;
use App\Enums\ApprovalStatus;
use App\Enums\BorrowerGroupStatus;
use App\Enums\CollateralStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Gender;
use App\Enums\IdType;
use App\Enums\InstallmentStatus;
use App\Enums\KycStatus;
use App\Enums\Language;
use App\Enums\LoanActivityType;
use App\Enums\LoanStatus;
use App\Enums\LookupType;
use App\Enums\PartyRole;
use App\Enums\PaymentChannel;
use App\Enums\PricingMethod;
use App\Enums\RecoveryStage;
use App\Enums\RecoveryStatus;
use App\Enums\RepaymentFrequency;
use App\Enums\TransactionType;
use App\Models\ApprovalRequest;
use App\Models\BorrowerGroup;
use App\Models\BorrowerGroupMember;
use App\Models\Branch;
use App\Models\Collateral;
use App\Models\Customer;
use App\Models\CustomerBankAccount;
use App\Models\CustomerFinancial;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanActivity;
use App\Models\LoanAllocation;
use App\Models\LoanInstallment;
use App\Models\LoanParty;
use App\Models\LoanTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\RecoveryCase;
use App\Models\Role;
use App\Models\User;
use App\Services\Loans\LoanPricingService;
use App\Support\Lookups\LookupRegistry;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoDatabaseSeeder extends Seeder
{
    /**
     * STRICT USER REQUIREMENT:
     * Only these two numbers may ever be used across the entire database!
     * Real SMS gateway is active, so under NO circumstance should any other number be stored.
     */
    private const ALLOWED_PHONES = [
        '0762570074',
        '0754524174',
    ];

    private function getPhone(int $index): string
    {
        return self::ALLOWED_PHONES[$index % count(self::ALLOWED_PHONES)];
    }

    private function lookupId(LookupType $type, string $code): int
    {
        return (int) app(LookupRegistry::class)->idFor($type, $code);
    }

    public function run(): void
    {
        $this->command->info('Seeding complete comprehensive live data for CrediX...');

        // Clean previous demo tables idempotently
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        foreach ([
            'notifications',
            'approval_requests',
            'loan_activities',
            'recovery_cases',
            'collaterals',
            'loan_transactions',
            'loan_installments',
            'loan_parties',
            'loans',
            'borrower_group_members',
            'borrower_groups',
            'documents',
            'customer_financials',
            'customer_bank_accounts',
            'customers',
        ] as $tbl) {
            DB::table($tbl)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        // 1. Geographic Hierarchy & Real Branches
        // ══════════════════════════════════════════════════════════════
        $now = now();
        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'country', 'code' => 'LK'],
            ['name' => 'Sri Lanka', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $country = DB::table('org_units')->where('code', 'LK')->first();

        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'province', 'code' => 'WP'],
            ['name' => 'Western Province', 'parent_id' => $country->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'province', 'code' => 'CP'],
            ['name' => 'Central Province', 'parent_id' => $country->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'province', 'code' => 'SP'],
            ['name' => 'Southern Province', 'parent_id' => $country->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'province', 'code' => 'NP'],
            ['name' => 'Northern Province', 'parent_id' => $country->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );

        $wp = DB::table('org_units')->where('code', 'WP')->first();
        $cp = DB::table('org_units')->where('code', 'CP')->first();
        $sp = DB::table('org_units')->where('code', 'SP')->first();
        $np = DB::table('org_units')->where('code', 'NP')->first();

        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'zone', 'code' => 'ZN-MAIN'],
            ['name' => 'National Banking Zone', 'parent_id' => $wp->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $zone = DB::table('org_units')->where('code', 'ZN-MAIN')->first();

        DB::table('org_units')->updateOrInsert(
            ['unit_type' => 'region', 'code' => 'REG-1'],
            ['name' => 'Metro Region', 'parent_id' => $zone->id, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]
        );
        $region = DB::table('org_units')->where('code', 'REG-1')->first();

        $cityType = $this->lookupId(LookupType::BranchType, 'city');
        $mainType = $this->lookupId(LookupType::BranchType, 'main');

        $branchColombo = Branch::updateOrCreate(['code' => 'B-CMB'], [
            'name' => 'Colombo Head Office',
            'province_id' => $wp->id,
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'address_line1' => 'No. 120, Galle Road',
            'city' => 'Colombo 03',
            'postal_code' => '00300',
            'phone_primary' => $this->getPhone(0),
            'email' => 'colombo.branch@credix.lk',
            'opening_date' => '2020-01-15',
            'branch_type_id' => $mainType,
            'is_head_office' => true,
            'is_active' => true,
        ]);

        $branchKandy = Branch::updateOrCreate(['code' => 'B-KDY'], [
            'name' => 'Kandy City Branch',
            'province_id' => $cp->id,
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'address_line1' => 'No. 45, Dalada Veediya',
            'city' => 'Kandy',
            'postal_code' => '20000',
            'phone_primary' => $this->getPhone(1),
            'email' => 'kandy.branch@credix.lk',
            'opening_date' => '2021-03-10',
            'branch_type_id' => $cityType,
            'is_head_office' => false,
            'is_active' => true,
        ]);

        $branchGalle = Branch::updateOrCreate(['code' => 'B-GAL'], [
            'name' => 'Galle Fort Branch',
            'province_id' => $sp->id,
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'address_line1' => 'No. 88, Main Street',
            'city' => 'Galle',
            'postal_code' => '80000',
            'phone_primary' => $this->getPhone(0),
            'email' => 'galle.branch@credix.lk',
            'opening_date' => '2022-06-01',
            'branch_type_id' => $cityType,
            'is_head_office' => false,
            'is_active' => true,
        ]);

        $branchJaffna = Branch::updateOrCreate(['code' => 'B-JAF'], [
            'name' => 'Jaffna Town Branch',
            'province_id' => $np->id,
            'zone_id' => $zone->id,
            'region_id' => $region->id,
            'address_line1' => 'No. 12, Hospital Road',
            'city' => 'Jaffna',
            'postal_code' => '40000',
            'phone_primary' => $this->getPhone(1),
            'email' => 'jaffna.branch@credix.lk',
            'opening_date' => '2023-01-20',
            'branch_type_id' => $cityType,
            'is_head_office' => false,
            'is_active' => true,
        ]);

        // ══════════════════════════════════════════════════════════════
        // 2. Departments & Designations
        // ══════════════════════════════════════════════════════════════
        $depCredit = Department::updateOrCreate(['code' => 'DEP-CRD'], ['name' => 'Credit & Underwriting', 'description' => 'Credit risk assessment and loan approvals', 'is_active' => true]);
        $depOps = Department::updateOrCreate(['code' => 'DEP-OPS'], ['name' => 'Operations & Servicing', 'description' => 'Disbursements and servicing', 'is_active' => true]);
        $depRec = Department::updateOrCreate(['code' => 'DEP-REC'], ['name' => 'Recovery & Legal', 'description' => 'Delinquency management and recoveries', 'is_active' => true]);
        $depFin = Department::updateOrCreate(['code' => 'DEP-FIN'], ['name' => 'Finance & Treasury', 'description' => 'Accounts and fund management', 'is_active' => true]);

        $mgrLevel = $this->lookupId(LookupType::DesignationLevel, 'Manager');
        $senLevel = $this->lookupId(LookupType::DesignationLevel, 'senior');
        $entryLevel = $this->lookupId(LookupType::DesignationLevel, 'entry');

        $desManager = Designation::updateOrCreate(['code' => 'DES-BM'], ['department_id' => $depOps->id, 'name' => 'Branch Manager', 'level_id' => $mgrLevel, 'is_active' => true]);
        $desCreditOfficer = Designation::updateOrCreate(['code' => 'DES-CO'], ['department_id' => $depCredit->id, 'name' => 'Senior Credit Officer', 'level_id' => $senLevel, 'is_active' => true]);
        $desRecoveryOfficer = Designation::updateOrCreate(['code' => 'DES-RO'], ['department_id' => $depRec->id, 'name' => 'Recovery Officer', 'level_id' => $entryLevel, 'is_active' => true]);

        // ══════════════════════════════════════════════════════════════
        // 3. Employees & Users (HR Staff)
        // ══════════════════════════════════════════════════════════════
        $permType = $this->lookupId(LookupType::EmployeeType, 'permanent');

        $emp1 = Employee::updateOrCreate(['employee_code' => 'EMP-001'], [
            'branch_id' => $branchColombo->id,
            'department_id' => $depOps->id,
            'designation_id' => $desManager->id,
            'employee_type_id' => $permType,
            'f_name' => 'Kamal',
            'l_name' => 'Perera',
            'full_name' => 'Kamal Susantha Perera',
            'name_with_initials' => 'K. S. Perera',
            'id_type' => 'nic',
            'id_number' => '198512301234',
            'date_of_birth' => '1985-05-12',
            'phone' => $this->getPhone(0),
            'phone_primary' => $this->getPhone(0),
            'email' => 'kamal.manager@credix.lk',
            'address_line_1' => 'No. 120, Galle Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2020-02-01',
            'is_active' => true,
        ]);

        $emp2 = Employee::updateOrCreate(['employee_code' => 'EMP-002'], [
            'branch_id' => $branchColombo->id,
            'department_id' => $depCredit->id,
            'designation_id' => $desCreditOfficer->id,
            'employee_type_id' => $permType,
            'f_name' => 'Sunethra',
            'l_name' => 'Bandara',
            'full_name' => 'Sunethra Bandara',
            'name_with_initials' => 'S. Bandara',
            'id_type' => 'nic',
            'id_number' => '199056701234',
            'date_of_birth' => '1990-08-24',
            'phone' => $this->getPhone(1),
            'phone_primary' => $this->getPhone(1),
            'email' => 'sunethra.credit@credix.lk',
            'address_line_1' => 'No. 33, Baseline Road',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2021-04-15',
            'is_active' => true,
        ]);

        $emp3 = Employee::updateOrCreate(['employee_code' => 'EMP-003'], [
            'branch_id' => $branchColombo->id,
            'department_id' => $depRec->id,
            'designation_id' => $desRecoveryOfficer->id,
            'employee_type_id' => $permType,
            'f_name' => 'Arumugam',
            'l_name' => 'Sivakumar',
            'full_name' => 'Arumugam Sivakumar',
            'name_with_initials' => 'A. Sivakumar',
            'id_type' => 'nic',
            'id_number' => '198811204567',
            'date_of_birth' => '1988-11-03',
            'phone' => $this->getPhone(0),
            'phone_primary' => $this->getPhone(0),
            'email' => 'siva.recovery@credix.lk',
            'address_line_1' => 'No. 88, Kotahena Street',
            'city' => 'Colombo',
            'country' => 'Sri Lanka',
            'start_date' => '2022-08-01',
            'is_active' => true,
        ]);

        // Update devadmin user with employee link
        $devAdmin = User::where('email', 'dev@localhost.com')->first();
        if ($devAdmin) {
            $devAdmin->employee_id = $emp1->id;
            $devAdmin->phone = $this->getPhone(0);
            $devAdmin->save();
        }

        // Create standard staff users
        $officerUser = User::updateOrCreate(['email' => 'officer@credix.lk'], [
            'name' => 'Sunethra Bandara (Credit Officer)',
            'username' => 'sunethra.officer',
            'password' => 'password',
            'employee_id' => $emp2->id,
            'phone' => $this->getPhone(1),
            'user_type' => 'staff',
            'must_change_password' => false,
            'is_active' => true,
            'can_login' => true,
        ]);
        $officerUser->assignRole('Super Admin');

        $recoveryUser = User::updateOrCreate(['email' => 'recovery@credix.lk'], [
            'name' => 'Arumugam Sivakumar (Recovery Agent)',
            'username' => 'siva.recovery',
            'password' => 'password',
            'employee_id' => $emp3->id,
            'phone' => $this->getPhone(0),
            'user_type' => 'staff',
            'must_change_password' => false,
            'is_active' => true,
            'can_login' => true,
        ]);
        $recoveryUser->assignRole('Super Admin');

        $agencyUser = User::updateOrCreate(['email' => 'agency@credix.lk'], [
            'name' => 'Apex Recovery Agency Ltd',
            'username' => 'apex.agency',
            'password' => 'password',
            'phone' => $this->getPhone(1),
            'user_type' => 'agency',
            'must_change_password' => false,
            'is_active' => true,
            'can_login' => true,
        ]);
        $agencyUser->assignRole('Super Admin');

        // ══════════════════════════════════════════════════════════════
        // 4. Products: Ensure limits and active status
        // ══════════════════════════════════════════════════════════════
        $products = Product::with(['category', 'charges', 'collateralRules'])->get();
        foreach ($products as $p) {
            $currentRate = (float) $p->default_rate;
            $p->forceFill([
                'status' => 'active',
                'min_amount' => ((float) $p->min_amount <= 0) ? '50000.00' : $p->min_amount,
                'max_amount' => ((float) $p->max_amount <= 0) ? '3000000.00' : $p->max_amount,
                'default_rate' => ($currentRate <= 0) ? '16.5000' : $p->default_rate,
                'min_rate' => ((float) $p->min_rate <= 0) ? '12.0000' : $p->min_rate,
                'max_rate' => ((float) $p->max_rate <= 0) ? '24.0000' : $p->max_rate,
                'min_tenure' => $p->min_tenure ?: 6,
                'max_tenure' => $p->max_tenure ?: 60,
                'repayment_frequency' => $p->repayment_frequency ?: RepaymentFrequency::Monthly,
                'allows_individual' => true,
                'allows_joint' => true,
                'shariah_approved_at' => now(),
            ])->save();

            if ($p->charges()->count() === 0) {
                $p->charges()->create([
                    'charge_type' => 'processing_fee',
                    'calculation' => 'percent_of_principal',
                    'value' => '1.50',
                    'collection' => 'deduct_from_disbursement',
                    'recurrence' => 'once',
                    'is_charity' => false,
                    'is_active' => true,
                ]);
            }
        }

        // ══════════════════════════════════════════════════════════════
        // 5. Customers (Strictly using 0762570074 & 0754524174)
        // ══════════════════════════════════════════════════════════════
        $customerData = [
            [
                'nic' => '198812345678',
                'name' => 'Kasun Kalhara Jayawardena',
                'initials' => 'W. K. K. Jayawardena',
                'gender' => Gender::Male,
                'dob' => '1988-05-12',
                'phone' => $this->getPhone(0), // 0762570074
                'address' => 'No. 45, Temple Road',
                'city' => 'Colombo 07',
                'gn_division' => 'Kollupitiya North',
                'ds_division' => 'Colombo',
                'province' => 'Western',
                'district' => 'Colombo',
                'employment' => EmploymentStatus::Employed,
                'occupation' => 'Senior Accountant',
                'employer' => 'Lanka Holdings PLC',
                'salary' => '185000',
                'bank' => 'Bank of Ceylon',
                'branch_name' => 'Kollupitiya',
                'acc_no' => '0089123456',
                'kyc' => KycStatus::Verified,
                'branch' => $branchColombo,
                'score' => 760,
            ],
            [
                'nic' => '199223456789',
                'name' => 'Dilshan Ruwan Mendis',
                'initials' => 'M. D. R. Mendis',
                'gender' => Gender::Male,
                'dob' => '1992-08-20',
                'phone' => $this->getPhone(1), // 0754524174
                'address' => 'No. 112, Kandy Road',
                'city' => 'Peradeniya',
                'gn_division' => 'Peradeniya East',
                'ds_division' => 'Yatinuwara',
                'province' => 'Central',
                'district' => 'Kandy',
                'employment' => EmploymentStatus::SelfEmployed,
                'occupation' => 'Wholesale Tea Merchant',
                'employer' => 'Mendis Tea Exports',
                'salary' => '250000',
                'bank' => 'Commercial Bank of Ceylon',
                'branch_name' => 'Kandy City',
                'acc_no' => '1000458923',
                'kyc' => KycStatus::Verified,
                'branch' => $branchKandy,
                'score' => 710,
            ],
            [
                'nic' => '199556789012',
                'name' => 'Samanthi Kumari Rathnayake',
                'initials' => 'R. S. K. Rathnayake',
                'gender' => Gender::Female,
                'dob' => '1995-03-15',
                'phone' => $this->getPhone(0), // 0762570074
                'address' => 'No. 24/A, Lake Drive',
                'city' => 'Rajagiriya',
                'gn_division' => 'Rajagiriya',
                'ds_division' => 'Sri Jayawardenepura Kotte',
                'province' => 'Western',
                'district' => 'Colombo',
                'employment' => EmploymentStatus::Employed,
                'occupation' => 'Digital Marketing Lead',
                'employer' => 'Creative Hub Colombo',
                'salary' => '140000',
                'bank' => 'Hatton National Bank',
                'branch_name' => 'Rajagiriya',
                'acc_no' => '0450201948',
                'kyc' => KycStatus::Pending, // Ready for manual KYC OTP test!
                'branch' => $branchColombo,
                'score' => 650,
            ],
            [
                'nic' => '198334567890',
                'name' => 'Mahesh Pradeep Kumara',
                'initials' => 'K. M. P. Kumara',
                'gender' => Gender::Male,
                'dob' => '1983-11-28',
                'phone' => $this->getPhone(1), // 0754524174
                'address' => 'No. 70, Fort View',
                'city' => 'Galle',
                'gn_division' => 'Fort',
                'ds_division' => 'Galle Four Gravets',
                'province' => 'Southern',
                'district' => 'Galle',
                'employment' => EmploymentStatus::SelfEmployed,
                'occupation' => 'Building Contractor',
                'employer' => 'Kumara Constructions',
                'salary' => '210000',
                'bank' => 'Sampath Bank',
                'branch_name' => 'Galle Main',
                'acc_no' => '0012398475',
                'kyc' => KycStatus::Verified,
                'branch' => $branchGalle,
                'score' => 520, // Delinquent loan borrower
            ],
            [
                'nic' => '199078901234',
                'name' => 'Fatima Rameeza Mohamed',
                'initials' => 'M. F. Rameeza',
                'gender' => Gender::Female,
                'dob' => '1990-07-04',
                'phone' => $this->getPhone(0), // 0762570074
                'address' => 'No. 18, Ward Place',
                'city' => 'Colombo 07',
                'gn_division' => 'Cinnamon Gardens',
                'ds_division' => 'Colombo',
                'province' => 'Western',
                'district' => 'Colombo',
                'employment' => EmploymentStatus::Employed,
                'occupation' => 'College Lecturer',
                'employer' => 'International Language Academy',
                'salary' => '165000',
                'bank' => 'Amana Bank',
                'branch_name' => 'Colombo',
                'acc_no' => '0078945612',
                'kyc' => KycStatus::Verified,
                'branch' => $branchColombo,
                'score' => 690,
            ],
            [
                'nic' => '198645678901',
                'name' => 'Niroshan Bandara Senanayake',
                'initials' => 'S. N. B. Senanayake',
                'gender' => Gender::Male,
                'dob' => '1986-09-10',
                'phone' => $this->getPhone(1), // 0754524174
                'address' => 'No. 55, Kings Street',
                'city' => 'Kandy',
                'gn_division' => 'Central',
                'ds_division' => 'Kandy Four Gravets',
                'province' => 'Central',
                'district' => 'Kandy',
                'employment' => EmploymentStatus::Employed,
                'occupation' => 'Principal Software Engineer',
                'employer' => 'Virtusa Pvt Ltd',
                'salary' => '420000',
                'bank' => 'Commercial Bank of Ceylon',
                'branch_name' => 'Kandy',
                'acc_no' => '1008765432',
                'kyc' => KycStatus::Verified,
                'branch' => $branchKandy,
                'score' => 795, // Closed pristine loan
            ],
            [
                'nic' => '197912349876',
                'name' => 'Priyantha Chandrasiri Silva',
                'initials' => 'S. P. C. Silva',
                'gender' => Gender::Male,
                'dob' => '1979-02-18',
                'phone' => $this->getPhone(0), // 0762570074
                'address' => 'No. 10, Farm Lane',
                'city' => 'Jaffna',
                'gn_division' => 'Town Central',
                'ds_division' => 'Jaffna',
                'province' => 'Northern',
                'district' => 'Jaffna',
                'employment' => EmploymentStatus::SelfEmployed,
                'occupation' => 'Agricultural Produce Trader',
                'employer' => 'Northern Agro Farms',
                'salary' => '175000',
                'bank' => 'Bank of Ceylon',
                'branch_name' => 'Jaffna',
                'acc_no' => '0034567891',
                'kyc' => KycStatus::Verified,
                'branch' => $branchJaffna,
                'score' => 720,
            ],
        ];

        $createdCustomers = [];
        foreach ($customerData as $c) {
            $customer = Customer::where('id_number_hash', Customer::idNumberHash($c['nic']))->first();
            if (! $customer) {
                $customer = new Customer([
                    'branch_id' => $c['branch']->id,
                    'id_type' => IdType::Nic,
                    'id_number' => $c['nic'],
                    'full_name' => $c['name'],
                    'name_with_initials' => $c['initials'],
                    'date_of_birth' => $c['dob'],
                    'gender' => $c['gender'],
                    'preferred_language' => Language::English,
                    'phone_primary' => $c['phone'],
                    'phone_secondary' => $c['phone'],
                    'whatsapp_number' => $c['phone'],
                    'address_line_1' => $c['address'],
                    'city' => $c['city'],
                    'gn_division' => $c['gn_division'],
                    'ds_division' => $c['ds_division'],
                    'district' => $c['district'],
                    'province' => $c['province'],
                    'employment_status' => $c['employment'],
                    'occupation' => $c['occupation'],
                    'employer_name' => $c['employer'],
                    'employer_phone' => $c['phone'],
                    'is_active' => true,
                ]);
                $customer->save();
            } else {
                $customer->update([
                    'phone_primary' => $c['phone'],
                    'phone_secondary' => $c['phone'],
                    'whatsapp_number' => $c['phone'],
                    'employer_phone' => $c['phone'],
                    'gn_division' => $c['gn_division'],
                    'ds_division' => $c['ds_division'],
                ]);
            }

            $customer->forceFill([
                'kyc_status' => $c['kyc'],
                'kyc_verified_at' => $c['kyc'] === KycStatus::Verified ? now()->subMonths(2) : null,
                'kyc_expires_at' => $c['kyc'] === KycStatus::Verified ? now()->addYear() : null,
                'kyc_snapshot_hash' => $c['kyc'] === KycStatus::Verified ? $customer->kycFingerprint() : null,
                'credit_score' => $c['score'],
            ])->save();

            // Bank details
            $bankAcc = CustomerBankAccount::where('customer_id', $customer->id)->first();
            if (! $bankAcc) {
                $bankAcc = new CustomerBankAccount([
                    'customer_id' => $customer->id,
                    'bank_name' => $c['bank'],
                    'branch_name' => $c['branch_name'],
                    'account_holder_name' => $c['initials'],
                    'account_number' => $c['acc_no'],
                    'is_primary' => true,
                    'is_active' => true,
                ]);
                $bankAcc->save();
            }

            // Financials
            CustomerFinancial::create([
                'customer_id' => $customer->id,
                'kind' => 'income',
                'category' => 'salary',
                'amount' => $c['salary'],
            ]);

            // Verified documents for verified customers
            if ($c['kyc'] === KycStatus::Verified) {
                foreach (['nic-copy', 'salary-confirmation-letter', 'salary-slip'] as $slug) {
                    $dt = DocumentType::findBySlug($slug);
                    if ($dt && ! Document::where('documentable_id', $customer->id)->where('document_type_id', $dt->id)->exists()) {
                        Document::create([
                            'documentable_type' => $customer->getMorphClass(),
                            'documentable_id' => $customer->id,
                            'document_type_id' => $dt->id,
                            'original_name' => "{$slug}.pdf",
                            'path' => "documents/customers/{$customer->id}/{$slug}.pdf",
                            'mime_type' => 'application/pdf',
                            'size_bytes' => 2048,
                            'checksum_sha256' => hash('sha256', "cust-{$customer->id}-{$slug}"),
                            'issued_on' => now()->subMonths(3)->toDateString(),
                            'status' => 'verified',
                            'verified_at' => now()->subMonths(2),
                        ]);
                    }
                }
            }

            $createdCustomers[$c['nic']] = $customer;
        }

        // ══════════════════════════════════════════════════════════════
        // 6. Borrower Groups
        // ══════════════════════════════════════════════════════════════
        $group = BorrowerGroup::updateOrCreate(['name' => 'Lanka Samurdhi Women Enterprise Group'], [
            'branch_id' => $branchColombo->id,
            'formed_on' => '2025-06-01',
            'meeting_day' => 'Monday',
            'status' => BorrowerGroupStatus::Active,
        ]);
        BorrowerGroupMember::updateOrCreate([
            'borrower_group_id' => $group->id,
            'customer_id' => $createdCustomers['199556789012']->id,
        ], [
            'role' => 'leader',
            'joined_on' => '2025-06-01',
        ]);
        BorrowerGroupMember::updateOrCreate([
            'borrower_group_id' => $group->id,
            'customer_id' => $createdCustomers['199078901234']->id,
        ], [
            'role' => 'member',
            'joined_on' => '2025-06-01',
        ]);

        $pricing = app(LoanPricingService::class);
        $smeProduct = Product::with(['category', 'charges', 'collateralRules'])->where('slug', 'sme-loan')->first() ?? $products->first();
        $personalProduct = Product::with(['category', 'charges', 'collateralRules'])->where('slug', 'education-loan')->first() ?? $products->first();
        $murabahaProduct = Product::with(['category', 'charges', 'collateralRules'])->where('slug', 'murabaha-asset-finance')->first() ?? $products->first();

        // ══════════════════════════════════════════════════════════════
        // 7. LOAN SCENARIOS
        // ══════════════════════════════════════════════════════════════

        // SCENARIO 1: ACTIVE HEALTHY LOAN WITH REPAYMENTS (Kasun Kalhara)
        $cust1 = $createdCustomers['198812345678'];
        $loan1 = new Loan([
            'product_id' => $personalProduct->id,
            'branch_id' => $branchColombo->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust1->id,
            'requested_amount' => '600000.00',
            'tenure' => 12,
        ]);
        $loan1->setRelation('product', $personalProduct);
        $loan1->forceFill([
            'status' => 'active',
            'loan_no' => Loan::nextLoanNo(),
            'finance_basis' => $personalProduct->financeBasis(),
            'pricing_method' => $personalProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '16.00',
            'terms_snapshot' => $pricing->snapshot($personalProduct),
            'approved_amount' => '600000.00',
            'approved_at' => now()->subMonths(3),
            'first_due_date' => now()->subMonths(2)->startOfMonth()->addDays(4)->toDateString(),
            'disbursed_at' => now()->subMonths(3),
            'risk_stage' => 1,
            'days_past_due' => 0,
        ]);
        $loan1->forceFill($pricing->price($loan1, '600000.00', '16.00')->columns())->save();

        $party1 = new LoanParty(['customer_id' => $cust1->id, 'role' => PartyRole::Borrower]);
        $party1->forceFill(['consented_at' => now()->subMonths(3), 'open_loan_key' => $cust1->id]);
        $loan1->parties()->save($party1);

        // Disburse loan 1
        LoanTransaction::create([
            'loan_id' => $loan1->id,
            'type' => TransactionType::Disbursement,
            'amount' => '600000.00',
            'balance_after' => (string) ($loan1->financed_amount + $loan1->profit_amount),
            'value_date' => now()->subMonths(3)->toDateString(),
            'channel' => PaymentChannel::Cash,
            'external_reference' => 'REC-DISB-001',
            'posted_by' => $devAdmin ? $devAdmin->id : null,
        ]);

        // Generate 12 installments: 3 paid on time, 9 upcoming
        $totalFinanced1 = (float) $loan1->financed_amount;
        $totalProfit1 = (float) $loan1->profit_amount;
        $pPerMonth1 = round($totalFinanced1 / 12, 4);
        $profitPerMonth1 = round($totalProfit1 / 12, 4);

        for ($i = 1; $i <= 12; $i++) {
            $dueDate = now()->subMonths(3)->addMonths($i)->startOfMonth()->addDays(4)->toDateString();
            $isPaid = $i <= 3;
            $paidDate = Carbon::parse($dueDate)->subDays(2)->toDateString();

            $inst = LoanInstallment::create([
                'loan_id' => $loan1->id,
                'schedule_version' => 1,
                'installment_no' => $i,
                'due_date' => $dueDate,
                'principal_due' => $pPerMonth1,
                'profit_due' => $profitPerMonth1,
                'charges_due' => 0,
                'penalty_due' => 0,
                'principal_paid' => $isPaid ? $pPerMonth1 : 0,
                'profit_paid' => $isPaid ? $profitPerMonth1 : 0,
                'charges_paid' => 0,
                'penalty_paid' => 0,
                'status' => $isPaid ? InstallmentStatus::Paid->value : InstallmentStatus::Upcoming->value,
                'grace_ends_on' => Carbon::parse($dueDate)->addDays(3)->toDateString(),
                'settled_on' => $isPaid ? $paidDate : null,
            ]);

            if ($isPaid) {
                $paidAmt = $pPerMonth1 + $profitPerMonth1;
                $balanceRemaining = ($totalFinanced1 + $totalProfit1) - ($paidAmt * $i);
                $txn = LoanTransaction::create([
                    'loan_id' => $loan1->id,
                    'payer_customer_id' => $cust1->id,
                    'type' => TransactionType::Repayment,
                    'amount' => (string) $paidAmt,
                    'balance_after' => (string) $balanceRemaining,
                    'value_date' => $dueDate,
                    'channel' => PaymentChannel::BankTransfer,
                    'external_reference' => "RCPT-2026-00{$i}",
                    'narration' => "Monthly installment #{$i} paid via Bank Transfer",
                    'posted_by' => $devAdmin ? $devAdmin->id : null,
                ]);

                LoanAllocation::create([
                    'loan_transaction_id' => $txn->id,
                    'loan_installment_id' => $inst->id,
                    'component' => AllocationComponent::Principal,
                    'amount' => (string) $pPerMonth1,
                ]);
                LoanAllocation::create([
                    'loan_transaction_id' => $txn->id,
                    'loan_installment_id' => $inst->id,
                    'component' => AllocationComponent::Profit,
                    'amount' => (string) $profitPerMonth1,
                ]);
            }
        }

        // Add Property Collateral to Loan 1
        $col1 = new Collateral([
            'loan_id' => $loan1->id,
            'type' => 'property_mortgage',
            'owner_customer_id' => $cust1->id,
            'description' => 'Commercial Property — No. 45 Temple Road, Colombo 07',
            'details' => ['deed_no' => 'DEED-CMB-8891', 'extent' => '15.5 Perches', 'address' => 'Colombo 07'],
            'market_value' => '8500000.00',
            'forced_sale_value' => '6800000.00',
            'valued_on' => now()->subMonths(4)->toDateString(),
        ]);
        $col1->forceFill([
            'ltv_percent' => '70.00',
            'eligible_amount' => '5950000.00',
            'status' => CollateralStatus::Verified,
            'verified_at' => now()->subMonths(3),
        ])->save();


        // SCENARIO 2: DELINQUENT LOAN IN RECOVERY (>95 DPD) (Mahesh Pradeep)
        $cust4 = $createdCustomers['198334567890'];
        $loan2 = new Loan([
            'product_id' => $smeProduct->id,
            'branch_id' => $branchGalle->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust4->id,
            'requested_amount' => '1200000.00',
            'tenure' => 24,
        ]);
        $loan2->setRelation('product', $smeProduct);
        $loan2->forceFill([
            'status' => 'active',
            'loan_no' => Loan::nextLoanNo(),
            'finance_basis' => $smeProduct->financeBasis(),
            'pricing_method' => $smeProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '18.00',
            'terms_snapshot' => $pricing->snapshot($smeProduct),
            'approved_amount' => '1200000.00',
            'approved_at' => now()->subMonths(6),
            'disbursed_at' => now()->subMonths(6),
            'first_due_date' => now()->subMonths(5)->startOfMonth()->addDays(5)->toDateString(),
            'days_past_due' => 98,
            'risk_stage' => 3,
            'arrears_amount' => '186000.00',
        ]);
        $loan2->forceFill($pricing->price($loan2, '1200000.00', '18.00')->columns())->save();

        $party2 = new LoanParty(['customer_id' => $cust4->id, 'role' => PartyRole::Borrower]);
        $party2->forceFill(['consented_at' => now()->subMonths(6), 'open_loan_key' => $cust4->id]);
        $loan2->parties()->save($party2);

        $totalFinanced2 = (float) $loan2->financed_amount;
        $totalProfit2 = (float) $loan2->profit_amount;
        $pPerMonth2 = round($totalFinanced2 / 24, 4);
        $profitPerMonth2 = round($totalProfit2 / 24, 4);

        for ($i = 1; $i <= 6; $i++) {
            $due = now()->subMonths(6)->addMonths($i)->startOfMonth()->addDays(5)->toDateString();
            $paid = $i === 1;
            $overdue = $i > 1 && $i <= 4;
            $st = $paid ? InstallmentStatus::Paid : ($overdue ? InstallmentStatus::Overdue : InstallmentStatus::Upcoming);

            LoanInstallment::create([
                'loan_id' => $loan2->id,
                'schedule_version' => 1,
                'installment_no' => $i,
                'due_date' => $due,
                'principal_due' => $pPerMonth2,
                'profit_due' => $profitPerMonth2,
                'charges_due' => 0,
                'penalty_due' => $overdue ? 2500.00 : 0,
                'principal_paid' => $paid ? $pPerMonth2 : 0,
                'profit_paid' => $paid ? $profitPerMonth2 : 0,
                'charges_paid' => 0,
                'penalty_paid' => 0,
                'status' => $st->value,
                'grace_ends_on' => Carbon::parse($due)->addDays(3)->toDateString(),
                'settled_on' => $paid ? $due : null,
            ]);
        }

        // Add Vehicle Collateral to Loan 2
        $col2 = new Collateral([
            'loan_id' => $loan2->id,
            'type' => 'vehicle',
            'owner_customer_id' => $cust4->id,
            'description' => 'Commercial Van — Toyota HiAce KDH 201',
            'details' => ['registration_no' => 'WP-CAD-4589', 'chassis_no' => 'KDH201-0091823', 'make' => 'Toyota', 'model' => 'HiAce', 'year' => 2018],
            'market_value' => '9200000.00',
            'forced_sale_value' => '7300000.00',
            'valued_on' => now()->subMonths(7)->toDateString(),
        ]);
        $col2->forceFill([
            'ltv_percent' => '65.00',
            'eligible_amount' => '5980000.00',
            'status' => CollateralStatus::Perfected,
            'registration_ref' => 'CR-GAL-2024-991',
            'verified_at' => now()->subMonths(6),
            'perfected_at' => now()->subMonths(6),
        ])->save();

        // Open a Recovery Case on Loan 2
        $recCase = new RecoveryCase([
            'loan_id' => $loan2->id,
            'branch_id' => $branchGalle->id,
            'opened_dpd' => 90,
            'opened_arrears' => '186000.00',
            'opened_at' => now()->subDays(8),
        ]);
        $recCase->forceFill([
            'stage' => RecoveryStage::Internal,
            'status' => RecoveryStatus::Open,
            'assigned_user_id' => $recoveryUser->id,
            'assigned_at' => now()->subDays(6),
            'open_case_key' => $loan2->id,
        ])->save();

        // Recovery activities on Loan 2
        LoanActivity::create([
            'loan_id' => $loan2->id,
            'recovery_case_id' => $recCase->id,
            'type' => LoanActivityType::Call->value,
            'remarks' => 'Contacted borrower regarding 3 overdue installments. Promised to visit branch on Friday.',
            'performed_by' => $recoveryUser->id,
            'performed_at' => now()->subDays(5),
        ]);
        LoanActivity::create([
            'loan_id' => $loan2->id,
            'recovery_case_id' => $recCase->id,
            'type' => LoanActivityType::Visit->value,
            'remarks' => 'Visited business site at Galle Fort. Customer met in person; business cashflow delayed due to weather.',
            'performed_by' => $recoveryUser->id,
            'performed_at' => now()->subDays(3),
        ]);
        LoanActivity::create([
            'loan_id' => $loan2->id,
            'recovery_case_id' => $recCase->id,
            'type' => LoanActivityType::PromiseToPay->value,
            'remarks' => 'Agreed to pay partial arrears of Rs. 45,000 next Monday.',
            'promised_amount' => '45000.00',
            'promised_date' => now()->addDays(4)->toDateString(),
            'performed_by' => $recoveryUser->id,
            'performed_at' => now()->subDay(),
        ]);


        // SCENARIO 3: APPROVED LOAN AWAITING REVIEW & CUSTOMER CONFIRMATION (Dilshan Ruwan Mendis)
        $cust2 = $createdCustomers['199223456789'];
        $loan3 = new Loan([
            'product_id' => $smeProduct->id,
            'branch_id' => $branchKandy->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust2->id,
            'requested_amount' => '1500000.00',
            'tenure' => 36,
        ]);
        $loan3->setRelation('product', $smeProduct);
        $loan3->forceFill([
            'status' => 'approved',
            'finance_basis' => $smeProduct->financeBasis(),
            'pricing_method' => $smeProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '17.50',
            'terms_snapshot' => $pricing->snapshot($smeProduct),
            'approved_amount' => '1500000.00',
            'approved_at' => now()->subDays(2),
        ]);
        $loan3->forceFill($pricing->price($loan3, '1500000.00', '17.50')->columns())->save();

        $party3 = new LoanParty(['customer_id' => $cust2->id, 'role' => PartyRole::Borrower]);
        $party3->forceFill(['open_loan_key' => $cust2->id]);
        $loan3->parties()->save($party3);

        $col3 = new Collateral([
            'loan_id' => $loan3->id,
            'type' => 'property_mortgage',
            'owner_customer_id' => $cust2->id,
            'description' => 'Commercial Tea Warehouse Property — Kandy',
            'details' => ['deed_no' => 'DEED-KDY-4512', 'extent' => '24 Perches', 'address' => 'Peradeniya Road, Kandy'],
            'market_value' => '12000000.00',
            'forced_sale_value' => '9600000.00',
            'valued_on' => now()->subDays(10)->toDateString(),
        ]);
        $col3->forceFill([
            'ltv_percent' => '70.00',
            'eligible_amount' => '8400000.00',
            'status' => CollateralStatus::Verified,
            'verified_at' => now()->subDays(5),
        ])->save();


        // SCENARIO 4: SUBMITTED (UNDER REVIEW) (Fatima Rameeza)
        $cust5 = $createdCustomers['199078901234'];
        $loan4 = new Loan([
            'product_id' => $murabahaProduct->id,
            'branch_id' => $branchColombo->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust5->id,
            'requested_amount' => '800000.00',
            'tenure' => 24,
        ]);
        $loan4->setRelation('product', $murabahaProduct);
        $loan4->forceFill([
            'status' => 'submitted',
            'finance_basis' => $murabahaProduct->financeBasis(),
            'pricing_method' => $murabahaProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '15.00',
            'terms_snapshot' => $pricing->snapshot($murabahaProduct),
            'submitted_at' => now()->subHours(8),
        ]);
        $loan4->forceFill($pricing->price($loan4, '800000.00', '15.00')->columns())->save();

        $party4 = new LoanParty(['customer_id' => $cust5->id, 'role' => PartyRole::Borrower]);
        $party4->forceFill(['open_loan_key' => $cust5->id]);
        $loan4->parties()->save($party4);

        $col4 = new Collateral([
            'loan_id' => $loan4->id,
            'type' => 'vehicle',
            'owner_customer_id' => $cust5->id,
            'description' => 'Passenger Car — Toyota Aqua Hybrid',
            'details' => ['registration_no' => 'WP-CAG-8823', 'chassis_no' => 'NHP10-2098471', 'make' => 'Toyota', 'model' => 'Aqua', 'year' => 2017],
            'market_value' => '6500000.00',
            'forced_sale_value' => '5200000.00',
            'valued_on' => now()->subDays(3)->toDateString(),
        ]);
        $col4->forceFill([
            'ltv_percent' => '65.00',
            'eligible_amount' => '4225000.00',
            'status' => CollateralStatus::Proposed,
        ])->save();


        // SCENARIO 5: DRAFT LOAN IN WIZARD (Samanthi Kumari)
        $cust3 = $createdCustomers['199556789012'];
        $loan5 = new Loan([
            'product_id' => $personalProduct->id,
            'branch_id' => $branchColombo->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust3->id,
            'requested_amount' => '300000.00',
            'tenure' => 12,
        ]);
        $loan5->setRelation('product', $personalProduct);
        $loan5->forceFill([
            'status' => 'draft',
            'finance_basis' => $personalProduct->financeBasis(),
            'pricing_method' => $personalProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '16.50',
            'terms_snapshot' => $pricing->snapshot($personalProduct),
        ]);
        $loan5->forceFill($pricing->price($loan5, '300000.00', '16.50')->columns())->save();

        $party5 = new LoanParty(['customer_id' => $cust3->id, 'role' => PartyRole::Borrower]);
        $party5->forceFill(['open_loan_key' => $cust3->id]);
        $loan5->parties()->save($party5);


        // SCENARIO 6: CLOSED / SETTLED LOAN (Niroshan Bandara)
        $cust6 = $createdCustomers['198645678901'];
        $loan6 = new Loan([
            'product_id' => $personalProduct->id,
            'branch_id' => $branchKandy->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust6->id,
            'requested_amount' => '200000.00',
            'tenure' => 6,
        ]);
        $loan6->setRelation('product', $personalProduct);
        $loan6->forceFill([
            'status' => 'settled',
            'loan_no' => Loan::nextLoanNo(),
            'finance_basis' => $personalProduct->financeBasis(),
            'pricing_method' => $personalProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '14.00',
            'terms_snapshot' => $pricing->snapshot($personalProduct),
            'approved_amount' => '200000.00',
            'approved_at' => now()->subMonths(8),
            'disbursed_at' => now()->subMonths(8),
            'closed_at' => now()->subMonths(1),
            'closure_reason' => \App\Enums\ClosureReason::Settled,
        ]);
        $loan6->forceFill($pricing->price($loan6, '200000.00', '14.00')->columns())->save();

        $party6 = new LoanParty(['customer_id' => $cust6->id, 'role' => PartyRole::Borrower]);
        $party6->forceFill(['consented_at' => now()->subMonths(8), 'open_loan_key' => null]);
        $loan6->parties()->save($party6);


        // SCENARIO 7: RETURNED / CORRECTION REQUESTED (Priyantha Silva)
        $cust7 = $createdCustomers['197912349876'];
        $loan7 = new Loan([
            'product_id' => $smeProduct->id,
            'branch_id' => $branchJaffna->id,
            'borrower_type' => 'individual',
            'customer_id' => $cust7->id,
            'requested_amount' => '400000.00',
            'tenure' => 12,
        ]);
        $loan7->setRelation('product', $smeProduct);
        $loan7->forceFill([
            'status' => 'returned',
            'finance_basis' => $smeProduct->financeBasis(),
            'pricing_method' => $smeProduct->pricing_method,
            'repayment_frequency' => RepaymentFrequency::Monthly,
            'rate' => '18.00',
            'terms_snapshot' => $pricing->snapshot($smeProduct),
        ]);
        $loan7->forceFill($pricing->price($loan7, '400000.00', '18.00')->columns())->save();

        $party7 = new LoanParty(['customer_id' => $cust7->id, 'role' => PartyRole::Borrower]);
        $party7->forceFill(['open_loan_key' => $cust7->id]);
        $loan7->parties()->save($party7);

        LoanActivity::create([
            'loan_id' => $loan7->id,
            'type' => LoanActivityType::StatusChange->value,
            'from_status' => 'submitted',
            'to_status' => 'returned',
            'remarks' => 'Income proof requires employer official stamp and updated land ownership record.',
            'performed_by' => $officerUser->id,
            'performed_at' => now()->subDays(1),
        ]);


        // ══════════════════════════════════════════════════════════════
        // 8. APPROVAL REQUESTS (Maker-Checker inbox)
        // ══════════════════════════════════════════════════════════════
        ApprovalRequest::create([
            'subject_type' => $loan2->getMorphClass(),
            'subject_id' => $loan2->id,
            'branch_id' => $branchGalle->id,
            'action' => ApprovalAction::Restructure,
            'payload' => ['tenure' => 36, 'rate' => '16.00'],
            'reason' => 'Extend tenure to 36 months to reduce monthly installment for flood-affected contractor.',
            'requested_by' => $recoveryUser->id,
        ]);

        ApprovalRequest::create([
            'subject_type' => $loan1->getMorphClass(),
            'subject_id' => $loan1->id,
            'branch_id' => $branchColombo->id,
            'action' => ApprovalAction::PaymentReversal,
            'payload' => ['amount' => '54000.00', 'external_ref' => 'RCPT-2026-003'],
            'reason' => 'Customer mistakenly deposited duplicate installment via CDM; reverse one transfer.',
            'requested_by' => $officerUser->id,
        ]);


        // ══════════════════════════════════════════════════════════════
        // 9. Notifications for Staff
        // ══════════════════════════════════════════════════════════════
        DB::table('notifications')->insert([
            [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\LoanApplicationSubmitted',
                'channel' => 'database',
                'notifiable_type' => 'user',
                'notifiable_id' => $devAdmin ? $devAdmin->id : 1,
                'loan_id' => $loan4->id,
                'data' => json_encode([
                    'title' => 'New Loan Application Submitted',
                    'message' => 'Fatima Rameeza submitted Murabaha Asset Finance application (Rs. 800,000)',
                    'loan_id' => $loan4->id,
                    'application_no' => $loan4->application_no,
                ]),
                'read_at' => null,
                'created_at' => now()->subHours(2),
                'updated_at' => now()->subHours(2),
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\LoanRestructureRequested',
                'channel' => 'database',
                'notifiable_type' => 'user',
                'notifiable_id' => $devAdmin ? $devAdmin->id : 1,
                'loan_id' => $loan2->id,
                'data' => json_encode([
                    'title' => 'Loan Restructure Approval Required',
                    'message' => 'Recovery officer requested restructure for delinquent loan (Mahesh Pradeep)',
                    'loan_id' => $loan2->id,
                    'case_no' => $recCase->case_no,
                ]),
                'read_at' => null,
                'created_at' => now()->subHours(5),
                'updated_at' => now()->subHours(5),
            ],
        ]);

        $this->command->info('✅ Live database populated with complete comprehensive scenarios!');
        $this->command->info('   - 4 Real Branches (Colombo Head Office, Kandy, Galle, Jaffna)');
        $this->command->info('   - 7 Customers (Salaried, Business, Verified, Pending KYC)');
        $this->command->info('   - ONLY allowed numbers used: 0762570074 & 0754524174');
        $this->command->info('   - 7 Loans covering Active, Delinquent (Recovery), Approved, Submitted, Draft, Settled, Returned');
        $this->command->info('   - Real Property & Vehicle Collaterals with LTV & Margin Calculations');
        $this->command->info('   - Installments, Repayments, Recovery Cases, Approval Requests, and Staff Alerts');
    }
}
