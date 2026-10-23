<?php

declare(strict_types=1);

use App\Http\Controllers\V1\ActivityLogController;
use App\Http\Controllers\V1\AgencyCaseController;
use App\Http\Controllers\V1\ApprovalRequestController;
use App\Http\Controllers\V1\AuthController;
use App\Http\Controllers\V1\BorrowerGroupController;
use App\Http\Controllers\V1\BranchController;
use App\Http\Controllers\V1\CdpCustomerVerificationController;
use App\Http\Controllers\V1\CustomerInvestmentController;
use App\Http\Controllers\V1\External\CorePolicyHoldController;
use App\Http\Controllers\V1\GlobalSearchController;
use App\Http\Controllers\V1\LoanSecurityController;
use App\Http\Controllers\V1\ChargeTypeController;
use App\Http\Controllers\V1\CollateralTypeController;
use App\Http\Controllers\V1\CountryController;
use App\Http\Controllers\V1\CustomerBankAccountController;
use App\Http\Controllers\V1\CustomerController;
use App\Http\Controllers\V1\CustomerDocumentController;
use App\Http\Controllers\V1\CustomerFinancialController;
use App\Http\Controllers\V1\CustomerKycController;
use App\Http\Controllers\V1\DatabaseController;
use App\Http\Controllers\V1\DepartmentController;
use App\Http\Controllers\V1\DesignationController;
use App\Http\Controllers\V1\DocumentController;
use App\Http\Controllers\V1\EnumController;
use App\Http\Controllers\V1\DocumentRequirementController;
use App\Http\Controllers\V1\DocumentTypeController;
use App\Http\Controllers\V1\EmployeeHierarchyController;
use App\Http\Controllers\V1\GlAccountController;
use App\Http\Controllers\V1\GlJournalController;
use App\Http\Controllers\V1\GlReportController;
use App\Http\Controllers\V1\GoldLoanRenewalController;
use App\Http\Controllers\V1\GroupController;
use App\Http\Controllers\V1\GuarantorController;
use App\Http\Controllers\V1\ImportController;
use App\Http\Controllers\V1\LegalDocumentController;
use App\Http\Controllers\V1\LegalTemplateController;
use App\Http\Controllers\V1\LoanCollateralController;
use App\Http\Controllers\V1\LoanController;
use App\Http\Controllers\V1\LoanDisbursementController;
use App\Http\Controllers\V1\LoanDocumentController;
use App\Http\Controllers\V1\LoanPartyController;
use App\Http\Controllers\V1\LoanReviewLinkController;
use App\Http\Controllers\V1\LoanServicingController;
use App\Http\Controllers\V1\LoanWorkflowController;
use App\Http\Controllers\V1\LoanWriteOffController;
use App\Http\Controllers\V1\LookupController;
use App\Http\Controllers\V1\NotificationController;
use App\Http\Controllers\V1\PaymentController;
use App\Http\Controllers\V1\PermissionController;
use App\Http\Controllers\V1\ProductCategoryController;
use App\Http\Controllers\V1\ProductController;
use App\Http\Controllers\V1\ProfileController;
use App\Http\Controllers\V1\ProvinceController;
use App\Http\Controllers\V1\PublicLoanReviewController;
use App\Http\Controllers\V1\RecoveryCaseController;
use App\Http\Controllers\V1\RegionController;
use App\Http\Controllers\V1\ReportController;
use App\Http\Controllers\V1\RoleController;
use App\Http\Controllers\V1\SettingController;
use App\Http\Controllers\V1\StaffDirectoryController;
use App\Http\Controllers\V1\TemplateController;
use App\Http\Controllers\V1\UserController;
use App\Http\Controllers\V1\ZonalController;
use Illuminate\Support\Facades\Route;

/* public routes */

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    // Second step while SMS 2FA is switched on (setting security.staff_login_otp).
    Route::post('login/verify-otp', [AuthController::class, 'verifyOtp'])->middleware('throttle:login');
    // A staff member who forgot the password: a code to the mobile on record, then the new password.
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
});

/* server-to-server routes for CDP Core (X-Core-Key, no JWT) */
Route::middleware(['core.key', 'throttle:60,1'])->prefix('v1/external/core')->group(function () {
    Route::get('policy-hold', [CorePolicyHoldController::class, 'index']);
});

/* the customer review link (SMS): no sign-in, the token in the link is the key */
Route::prefix('v1/public/loan-review/{token}')->group(function () {
    Route::get('/', [PublicLoanReviewController::class, 'show'])->middleware('throttle:review-open');
    Route::post('request-otp', [PublicLoanReviewController::class, 'requestOtp'])->middleware('throttle:review-otp');
    Route::post('confirm', [PublicLoanReviewController::class, 'confirm'])->middleware('throttle:review-confirm');
    Route::post('correction-request', [PublicLoanReviewController::class, 'requestCorrection'])->middleware('throttle:review-open');
});

/* external recovery agencies: only the cases assigned to them */
Route::middleware(['auth:api', 'token.current', 'password.changed', 'user.type:agency'])->prefix('v1/agency')->group(function () {
    Route::get('recovery-cases', [AgencyCaseController::class, 'index']);
    Route::get('recovery-cases/{publicId}', [AgencyCaseController::class, 'show']);
    Route::post('recovery-cases/{publicId}/activities', [AgencyCaseController::class, 'addActivity']);
});

/* routes for any logged-in user (staff, agency) */
Route::middleware(['auth:api', 'token.current', 'password.changed'])->prefix('v1')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    // The fixed option lists (payment channels, loan stages, charge behaviours ...) screens build dropdowns from.
    Route::get('enums', [EnumController::class, 'index']);
    Route::put('me', [ProfileController::class, 'update']);
    Route::post('password/change', [ProfileController::class, 'changePassword'])->middleware('throttle:password-change');
});

/* back-office routes: closed to agency logins even where an endpoint has no permission */
Route::middleware(['auth:api', 'token.current', 'password.changed', 'user.type:admin,staff'])->prefix('v1')->group(function () {

    Route::get('permissions/list', [PermissionController::class, 'getPermissionList']);
    Route::apiResource('permissions', PermissionController::class);

    Route::get('roles/list/', [RoleController::class, 'getAvailableRoles']);
    Route::apiResource('roles', RoleController::class);

    Route::get('users/list', [StaffDirectoryController::class, 'users']);
    Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
    Route::apiResource('users', UserController::class);

    // Countries
    Route::apiResource('countries', CountryController::class)->where(['country' => '[0-9]+']);
    Route::prefix('countries')->group(function () {
        Route::patch('{id}/toggle-status', [CountryController::class, 'toggleStatus']);
        Route::get('list', [CountryController::class, 'getActiveList']);
    });

    // Provinces
    Route::apiResource('provinces', ProvinceController::class)->where(['province' => '[0-9]+']);
    Route::prefix('provinces')->group(function () {
        Route::patch('{id}/toggle-status', [ProvinceController::class, 'toggleStatus']);
        Route::get('list', [ProvinceController::class, 'getProvinceList']);
    });

    // Zonals (Zones)
    Route::apiResource('zonals', ZonalController::class)->where(['zonal' => '[0-9]+']);
    Route::prefix('zonals')->group(function () {
        Route::patch('{id}/toggle-status', [ZonalController::class, 'toggleStatus']);
        Route::get('list', [ZonalController::class, 'getZonalList']);
    });

    // Regions
    Route::apiResource('regions', RegionController::class)->where(['region' => '[0-9]+']);
    Route::prefix('regions')->group(function () {
        Route::patch('{id}/toggle-status', [RegionController::class, 'toggleStatus']);
        Route::get('list', [RegionController::class, 'getRegionList']);
    });

    // Branches
    Route::apiResource('branches', BranchController::class)->where(['branch' => '[0-9]+']);
    Route::prefix('branches')->group(function () {
        Route::patch('{id}/toggle-status', [BranchController::class, 'toggleStatus']);
        Route::get('list', [BranchController::class, 'getBranchList']);
    });

    // Departments
    Route::apiResource('departments', DepartmentController::class)->where(['department' => '[0-9]+']);
    Route::prefix('departments')->group(function () {
        Route::get('list', [DepartmentController::class, 'getDepartmentList']);
        Route::get('{id}/designations', [DepartmentController::class, 'getDesignations'])->whereNumber('id');
        Route::patch('{id}/toggle-status', [DepartmentController::class, 'toggleStatus']);
    });

    // Designations
    Route::apiResource('designations', DesignationController::class)->where(['designation' => '[0-9]+']);
    Route::prefix('designations')->group(function () {
        Route::get('list', [DesignationController::class, 'getDesignationList']);
        Route::patch('{id}/toggle-status', [DesignationController::class, 'toggleStatus']);
    });

    // Groups
    Route::apiResource('groups', GroupController::class)->where(['group' => '[0-9]+']);
    Route::prefix('groups')->group(function () {
        Route::get('list', [GroupController::class, 'getActiveList']);
        Route::patch('{id}/toggle-status', [GroupController::class, 'toggleStatus']);
    });

    // Employee Hierarchy
    Route::prefix('employees')->group(function () {
        Route::get('list', [StaffDirectoryController::class, 'employees']);
        Route::get('{id}/manager-history', [EmployeeHierarchyController::class, 'getHistory']);
        Route::post('{id}/reassign-manager', [EmployeeHierarchyController::class, 'reassignManager']);
        Route::get('{id}/hierarchy-as-of', [EmployeeHierarchyController::class, 'getHierarchyAsOf']);
    });

    // System Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('{id}', [NotificationController::class, 'destroy']);
    });

    // Activity Logs
    Route::apiResource('activity-logs', ActivityLogController::class)->only(['index', 'show']);

    // Database & Data Export
    Route::prefix('database')->group(function () {
        Route::get('export', [DatabaseController::class, 'export']);
        Route::get('export-data', [DatabaseController::class, 'exportData']);
        Route::get('export-table/{table}', [DatabaseController::class, 'exportTable']);
        Route::get('export-all-tables', [DatabaseController::class, 'exportAllTables']);
    });

    // Bulk Import
    Route::prefix('import')->group(function () {
        Route::get('tables', [ImportController::class, 'listTables']);
        Route::get('catalog', [ImportController::class, 'index']);
        Route::get('template/{table}', [ImportController::class, 'downloadTemplate']);
        Route::post('{table}', [ImportController::class, 'import']);
    });

    // System Settings
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingController::class, 'index']);
        Route::get('{key}', [SettingController::class, 'show']);
        Route::put('{key}', [SettingController::class, 'update']);
    });

    // Code lists managed by the business (banks, categories, relationships, branch / employee
    // types, designation levels, custom fields). Values are deactivated, never deleted.
    Route::prefix('lookups')->group(function () {
        Route::get('types', [LookupController::class, 'types']);
        Route::get('options/{type}', [LookupController::class, 'options']);
        Route::get('/', [LookupController::class, 'index']);
        Route::post('/', [LookupController::class, 'store']);
        Route::get('{id}', [LookupController::class, 'show'])->whereNumber('id');
        Route::put('{id}', [LookupController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [LookupController::class, 'destroy'])->whereNumber('id');
    });

    // Fee and charge types (upfront fees are data; late payment, early settlement and
    // safekeeping are built in)
    Route::prefix('charge-types')->group(function () {
        Route::get('/', [ChargeTypeController::class, 'index']);
        Route::post('/', [ChargeTypeController::class, 'store']);
        Route::get('{code}', [ChargeTypeController::class, 'show']);
        Route::put('{code}', [ChargeTypeController::class, 'update']);
        Route::delete('{code}', [ChargeTypeController::class, 'destroy']);
    });

    // Collateral types (data: lookups of type collateral_type) with their fields and documents,
    // for the loan security step.
    Route::get('collateral-types', [CollateralTypeController::class, 'index']);

    // Product Categories (General / Islamic → Standard Borrowing / Development Fund → …)
    Route::prefix('product-categories')->group(function () {
        Route::get('/', [ProductCategoryController::class, 'index']);
        Route::get('tree', [ProductCategoryController::class, 'tree']);
        Route::post('/', [ProductCategoryController::class, 'store']);
        Route::get('{slug}', [ProductCategoryController::class, 'show']);
        Route::put('{slug}', [ProductCategoryController::class, 'update']);
        Route::delete('{slug}', [ProductCategoryController::class, 'destroy']);
        Route::patch('{slug}/toggle-status', [ProductCategoryController::class, 'toggleStatus']);
    });

    // Products
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('options', [ProductController::class, 'options']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('{slug}', [ProductController::class, 'show']);
        Route::put('{slug}', [ProductController::class, 'update']);
        Route::delete('{slug}', [ProductController::class, 'destroy']);
        Route::patch('{slug}/toggle-status', [ProductController::class, 'toggleStatus']);
        Route::post('{slug}/shariah-review', [ProductController::class, 'submitForShariahReview']);
        Route::get('{slug}/charges', [ProductController::class, 'charges']);
        Route::put('{slug}/charges', [ProductController::class, 'replaceCharges']);
        Route::get('{slug}/collateral-rules', [ProductController::class, 'collateralRules']);
        Route::put('{slug}/collateral-rules', [ProductController::class, 'replaceCollateralRules']);
        Route::post('{slug}/quote', [ProductController::class, 'quote']);
    });

    // Approval Requests (maker-checker inbox)
    Route::prefix('approval-requests')->group(function () {
        Route::get('/', [ApprovalRequestController::class, 'index']);
        Route::get('{publicId}', [ApprovalRequestController::class, 'show'])->name('approval-requests.show');
        Route::post('{publicId}/approve', [ApprovalRequestController::class, 'approve']);
        Route::post('{publicId}/reject', [ApprovalRequestController::class, 'reject']);
        Route::post('{publicId}/cancel', [ApprovalRequestController::class, 'cancel']);
    });

    // Document Types and Requirements
    Route::prefix('document-types')->group(function () {
        Route::get('/', [DocumentTypeController::class, 'index']);
        Route::post('/', [DocumentTypeController::class, 'store']);
        Route::get('{slug}', [DocumentTypeController::class, 'show']);
        Route::put('{slug}', [DocumentTypeController::class, 'update']);
        Route::delete('{slug}', [DocumentTypeController::class, 'destroy']);
        Route::patch('{slug}/toggle-status', [DocumentTypeController::class, 'toggleStatus']);
    });

    Route::prefix('document-requirements')->group(function () {
        Route::get('/', [DocumentRequirementController::class, 'index']);
        Route::post('/', [DocumentRequirementController::class, 'store']);
        Route::put('{id}', [DocumentRequirementController::class, 'update']);
        Route::delete('{id}', [DocumentRequirementController::class, 'destroy']);
    });

    // CDP investment check (CDP Connect), for Customer 360 and customer search
    Route::get('global-search/id-types', [GlobalSearchController::class, 'idTypes']);
    Route::get('global-search', [GlobalSearchController::class, 'search']);
    Route::post('loan-securities/investment-lookup', [LoanSecurityController::class, 'investmentLookup']);
    Route::match(['get', 'post'], 'cdp/verify-customer', [CdpCustomerVerificationController::class, 'checkCustomer']);

    // Lists across customers: every guarantee, and every financial line (assets included)
    Route::get('guarantors', [GuarantorController::class, 'index']);
    Route::get('customer-financials', [CustomerFinancialController::class, 'all']);

    // Customers (addressed by public_id; other branches' customers are invisible)
    Route::prefix('customers')->group(function () {
        Route::get('/', [CustomerController::class, 'index']);
        Route::get('lookup', [CustomerController::class, 'lookup']);
        Route::get('{publicId}/investments', [CustomerInvestmentController::class, 'index']);
        Route::get('bank-options', [CustomerController::class, 'bankOptions']);
        Route::post('/', [CustomerController::class, 'store']);
        Route::get('{publicId}', [CustomerController::class, 'show']);
        Route::get('{publicId}/eligibility', [CustomerController::class, 'eligibility']);
        Route::get('{publicId}/credit-score', [CustomerController::class, 'creditScore']);
        Route::put('{publicId}', [CustomerController::class, 'update']);
        Route::delete('{publicId}', [CustomerController::class, 'destroy']);
        Route::patch('{publicId}/toggle-status', [CustomerController::class, 'toggleStatus']);

        // KYC by OTP – tight limits so codes cannot be sprayed or guessed
        Route::post('{publicId}/kyc/send-otp', [CustomerKycController::class, 'sendOtp'])->middleware('throttle:otp-send');
        Route::post('{publicId}/kyc/resend-otp', [CustomerKycController::class, 'resendOtp'])->middleware('throttle:otp-send');
        Route::post('{publicId}/kyc/verify', [CustomerKycController::class, 'verify'])->middleware('throttle:otp-verify');

        Route::get('{publicId}/financials', [CustomerFinancialController::class, 'index']);
        Route::post('{publicId}/financials', [CustomerFinancialController::class, 'store']);
        Route::put('{publicId}/financials/{id}', [CustomerFinancialController::class, 'update']);
        Route::delete('{publicId}/financials/{id}', [CustomerFinancialController::class, 'destroy']);
        Route::post('{publicId}/financials/{id}/verify', [CustomerFinancialController::class, 'verify']);

        Route::get('{publicId}/bank-accounts', [CustomerBankAccountController::class, 'index']);
        Route::post('{publicId}/bank-accounts', [CustomerBankAccountController::class, 'store']);
        Route::put('{publicId}/bank-accounts/{accountId}', [CustomerBankAccountController::class, 'update']);
        Route::delete('{publicId}/bank-accounts/{accountId}', [CustomerBankAccountController::class, 'destroy']);

        Route::get('{publicId}/documents', [CustomerDocumentController::class, 'index']);
        Route::post('{publicId}/documents', [CustomerDocumentController::class, 'store'])->middleware('throttle:30,1');
        Route::get('{publicId}/document-checklist', [CustomerDocumentController::class, 'checklist']);
    });

    // Documents (download only through this authorised endpoint, never by path)
    Route::prefix('documents')->group(function () {
        Route::get('/', [DocumentController::class, 'index']);
        Route::get('{publicId}/download', [DocumentController::class, 'download']);
        // A new file needs multipart, which PHP only reads on POST; JSON details can use PUT or PATCH.
        Route::match(['put', 'patch', 'post'], '{publicId}', [DocumentController::class, 'update']);
        Route::post('{publicId}/verify', [DocumentController::class, 'verify']);
        Route::post('{publicId}/reject', [DocumentController::class, 'reject']);
        Route::delete('{publicId}', [DocumentController::class, 'destroy']);
    });

    // Borrower Groups
    Route::prefix('borrower-groups')->group(function () {
        Route::get('/', [BorrowerGroupController::class, 'index']);
        Route::post('/', [BorrowerGroupController::class, 'store']);
        Route::get('{publicId}', [BorrowerGroupController::class, 'show']);
        Route::put('{publicId}', [BorrowerGroupController::class, 'update']);
        Route::delete('{publicId}', [BorrowerGroupController::class, 'destroy']);
        Route::post('{publicId}/members', [BorrowerGroupController::class, 'addMember']);
        Route::patch('{publicId}/members/{memberId}', [BorrowerGroupController::class, 'changeRole']);
        Route::post('{publicId}/members/{memberId}/leave', [BorrowerGroupController::class, 'removeMember']);
    });

    // Loans (applications → active loans). Workflow steps are explicit commands.
    Route::prefix('loans')->group(function () {
        Route::get('/', [LoanController::class, 'index']);
        Route::post('/', [LoanController::class, 'store']);
        Route::get('{publicId}', [LoanController::class, 'show']);
        Route::put('{publicId}', [LoanController::class, 'update']);
        Route::get('{publicId}/schedule', [LoanController::class, 'schedule']);
        Route::get('{publicId}/schedule-versions', [LoanServicingController::class, 'scheduleVersions']);
        Route::get('{publicId}/restructures', [LoanServicingController::class, 'restructures']);
        Route::get('{publicId}/activities', [LoanController::class, 'activities']);
        Route::post('{publicId}/notes', [LoanController::class, 'addNote']);
        Route::get('{publicId}/document-checklist', [LoanController::class, 'checklist']);

        Route::post('{publicId}/submit', [LoanWorkflowController::class, 'submit']);
        Route::post('{publicId}/start-review', [LoanWorkflowController::class, 'startReview']);
        Route::post('{publicId}/return', [LoanWorkflowController::class, 'returnForCorrection']);
        Route::post('{publicId}/verify', [LoanWorkflowController::class, 'verify']);
        Route::post('{publicId}/approve', [LoanWorkflowController::class, 'approve']);
        Route::post('{publicId}/reject', [LoanWorkflowController::class, 'reject']);
        Route::post('{publicId}/cancel', [LoanWorkflowController::class, 'cancel']);

        Route::post('{publicId}/guarantors', [LoanPartyController::class, 'addGuarantor']);
        Route::delete('{publicId}/guarantors/{partyId}', [LoanPartyController::class, 'removeGuarantor'])->whereNumber('partyId');
        Route::post('{publicId}/parties/{partyId}/offer/accept', [LoanPartyController::class, 'acceptOffer'])->whereNumber('partyId');
        // Customer review link: one SMS link and one code per borrower (the customer can ask for corrections)
        Route::get('{publicId}/review', [LoanReviewLinkController::class, 'status']);
        Route::get('{publicId}/review/preview', [LoanReviewLinkController::class, 'preview']);
        Route::get('{publicId}/review/customers/{customerPublicId}', [LoanReviewLinkController::class, 'customerDetails']);
        Route::post('{publicId}/review/send-link', [LoanReviewLinkController::class, 'sendLink'])->middleware('throttle:otp-send');
        Route::post('{publicId}/review/customers/{customerPublicId}/send-otp', [LoanReviewLinkController::class, 'sendOtp'])->middleware('throttle:otp-send');
        Route::post('{publicId}/review/customers/{customerPublicId}/verify-otp', [LoanReviewLinkController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
        Route::get('{publicId}/review/corrections', [LoanReviewLinkController::class, 'corrections']);
        Route::post('{publicId}/review/corrections/{requestId}/resolve', [LoanReviewLinkController::class, 'resolveCorrection'])->whereNumber('requestId');

        Route::post('{publicId}/offer/hold', [LoanPartyController::class, 'holdOffer']);
        Route::post('{publicId}/offer/decline', [LoanPartyController::class, 'declineOffer']);

        Route::get('{publicId}/collaterals', [LoanCollateralController::class, 'index']);
        Route::post('{publicId}/collaterals', [LoanCollateralController::class, 'store']);
        Route::post('{publicId}/collaterals/cdp-investment', [LoanCollateralController::class, 'storeInvestment']);
        Route::post('{publicId}/collaterals/{collateralId}/verify', [LoanCollateralController::class, 'verify']);
        Route::post('{publicId}/collaterals/{collateralId}/perfect', [LoanCollateralController::class, 'perfect']);
        Route::delete('{publicId}/collaterals/{collateralId}', [LoanCollateralController::class, 'destroy']);
        Route::post('{publicId}/collaterals/{collateralId}/documents', [LoanCollateralController::class, 'uploadDocument'])->middleware('throttle:30,1');

        Route::get('{publicId}/documents', [LoanDocumentController::class, 'index']);
        Route::post('{publicId}/documents', [LoanDocumentController::class, 'store'])->middleware('throttle:30,1');

        Route::post('{publicId}/disburse', [LoanDisbursementController::class, 'disburse']);

        // Servicing
        Route::get('{publicId}/statement', [LoanServicingController::class, 'statement']);
        Route::get('{publicId}/settlement-quote', [LoanServicingController::class, 'settlementQuote']);
        Route::post('{publicId}/settle', [LoanServicingController::class, 'settle']);
        Route::post('{publicId}/installments/{installmentNo}/penalty-waiver', [LoanServicingController::class, 'waivePenalty'])->whereNumber('installmentNo');
        Route::post('{publicId}/restructure/preview', [LoanServicingController::class, 'restructurePreview']);
        Route::post('{publicId}/restructure', [LoanServicingController::class, 'restructure']);

        // Gold loan renewal (POST needs an Idempotency-Key header)
        Route::get('{publicId}/renewal-quote', [GoldLoanRenewalController::class, 'quote']);
        Route::post('{publicId}/renew', [GoldLoanRenewalController::class, 'renew'])->middleware('throttle:60,1');

        // Write-off (approval inbox) and recoveries afterwards (POST needs an Idempotency-Key header)
        Route::post('{publicId}/write-off', [LoanWriteOffController::class, 'request']);
        Route::get('{publicId}/write-off', [LoanWriteOffController::class, 'summary']);
        Route::post('{publicId}/recoveries', [LoanWriteOffController::class, 'recover'])->middleware('throttle:60,1');
    });

    // Recovery
    Route::prefix('recovery-cases')->group(function () {
        Route::get('/', [RecoveryCaseController::class, 'index']);
        Route::get('{publicId}', [RecoveryCaseController::class, 'show']);
        Route::post('{publicId}/assign', [RecoveryCaseController::class, 'assign']);
        Route::post('{publicId}/legal', [RecoveryCaseController::class, 'moveToLegal']);
        Route::get('{publicId}/activities', [RecoveryCaseController::class, 'activities']);
        Route::post('{publicId}/activities', [RecoveryCaseController::class, 'addActivity']);
    });

    // Message templates and the log of messages sent
    Route::get('templates', [TemplateController::class, 'index']);
    Route::post('templates', [TemplateController::class, 'store']);
    Route::get('templates/{id}', [TemplateController::class, 'show'])->whereNumber('id');
    Route::put('templates/{id}', [TemplateController::class, 'update'])->whereNumber('id');
    Route::delete('templates/{id}', [TemplateController::class, 'destroy'])->whereNumber('id');
    Route::get('notification-logs', [TemplateController::class, 'logs']);

    // Legal documents: the wording (templates of the document channel) and what is prepared for each loan
    Route::prefix('legal-document-templates')->group(function () {
        Route::get('/', [LegalTemplateController::class, 'index']);
        Route::get('list', [LegalTemplateController::class, 'activeList']);
        Route::post('/', [LegalTemplateController::class, 'store']);
        Route::get('{id}', [LegalTemplateController::class, 'show'])->whereNumber('id');
        Route::put('{id}', [LegalTemplateController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [LegalTemplateController::class, 'destroy'])->whereNumber('id');
    });
    Route::prefix('legal-documents')->group(function () {
        Route::get('/', [LegalDocumentController::class, 'index']);
        Route::get('applications', [LegalDocumentController::class, 'applications']);
        Route::post('/', [LegalDocumentController::class, 'store']);
        Route::get('{publicId}', [LegalDocumentController::class, 'show'])->name('legal-documents.show');
        Route::put('{publicId}', [LegalDocumentController::class, 'update']);
        Route::delete('{publicId}', [LegalDocumentController::class, 'destroy']);
        Route::patch('{publicId}/record-print', [LegalDocumentController::class, 'recordPrint']);
    });

    // Reports and dashboard
    Route::get('dashboard/overview', [ReportController::class, 'overview']);
    Route::get('dashboard/trend', [ReportController::class, 'trend']);
    Route::prefix('reports')->group(function () {
        Route::get('portfolio', [ReportController::class, 'portfolio']);
        Route::get('arrears-aging', [ReportController::class, 'arrearsAging']);
        Route::get('collections', [ReportController::class, 'collections']);
        Route::get('disbursements', [ReportController::class, 'disbursements']);
        Route::get('branch-performance', [ReportController::class, 'branchPerformance']);
        Route::get('recovery', [ReportController::class, 'recovery']);
    });

    // General ledger (manual journals are approved in the approval inbox)
    Route::prefix('gl')->group(function () {
        Route::get('accounts', [GlAccountController::class, 'index']);
        Route::post('accounts', [GlAccountController::class, 'store']);
        Route::put('accounts/{id}', [GlAccountController::class, 'update'])->whereNumber('id');
        Route::delete('accounts/{id}', [GlAccountController::class, 'destroy'])->whereNumber('id');
        Route::get('accounts/{id}/statement', [GlReportController::class, 'statement'])->whereNumber('id');

        Route::get('journals', [GlJournalController::class, 'index']);
        Route::post('journals', [GlJournalController::class, 'store']);
        Route::get('journals/export', [GlJournalController::class, 'export'])->middleware('throttle:10,1');
        Route::get('journals/{publicId}', [GlJournalController::class, 'show']);

        Route::get('trial-balance', [GlReportController::class, 'trialBalance']);
        Route::get('reconciliation', [GlReportController::class, 'reconciliation']);
        Route::get('period', [GlReportController::class, 'period']);
        Route::post('period/close', [GlReportController::class, 'closePeriod']);
    });

    // Payments (POST needs an Idempotency-Key header; reversals go through approval requests)
    Route::prefix('payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index']);
        Route::post('/', [PaymentController::class, 'store'])->middleware('throttle:60,1');
        Route::get('{publicId}', [PaymentController::class, 'show']);
        Route::post('{publicId}/reverse', [PaymentController::class, 'reverse']);
    });
});
