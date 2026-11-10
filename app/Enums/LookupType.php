<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The code lists held in the lookups table.
 *
 * The list itself is fixed by the code (a column such as branches.branch_type_id always reads the
 * branch_type list), while its values are business data: adding a bank, a relationship or an
 * income category is a row in lookups, never a code change or a migration.
 */
enum LookupType: string
{
    // branches.branch_type_id: main, city, satellite, mobile …
    case BranchType = 'branch_type';
    // employees.employee_type_id: permanent, contract …
    case EmployeeType = 'employee_type';
    // designations.level_id; meta.weight orders designations by seniority.
    case DesignationLevel = 'designation_level';
    // customer_bank_accounts.bank_id: banks a disbursement can be paid to.
    case Bank = 'bank';
    // customer_financials.category_id, one list per financial kind.
    case IncomeCategory = 'financial_category.income';
    case ExpenseCategory = 'financial_category.expense';
    case AssetCategory = 'financial_category.asset';
    case LiabilityCategory = 'financial_category.liability';
    // loan_parties.relationship_id: how a guarantor relates to the borrower.
    case GuarantorRelationship = 'guarantor_relationship';
    // collaterals.collateral_type_id, product_collateral_rules.collateral_type_id, lending_margin_plans:
    // kinds of security; meta = {valuation, reference, fields, documents} (App\Support\Collateral\CollateralTypes).
    case CollateralType = 'collateral_type';
    // Custom-field definitions for the meta JSON of customers, products and loans
    // (code = field key, meta = {"rules": [...], "label": ...}).
    case CustomerField = 'custom_field.customer';
    case ProductField = 'custom_field.product';
    case LoanField = 'custom_field.loan';

    /**
     * Human name of the list for the admin screens.
     */
    public function label(): string
    {
        return match ($this) {
            self::BranchType => 'Branch types',
            self::EmployeeType => 'Employee types',
            self::DesignationLevel => 'Designation levels',
            self::Bank => 'Banks',
            self::IncomeCategory => 'Income categories',
            self::ExpenseCategory => 'Expense categories',
            self::AssetCategory => 'Asset categories',
            self::LiabilityCategory => 'Liability categories',
            self::GuarantorRelationship => 'Guarantor relationships',
            self::CollateralType => 'Collateral types',
            self::CustomerField => 'Custom customer fields',
            self::ProductField => 'Custom product fields',
            self::LoanField => 'Custom loan fields',
        };
    }

    /**
     * Custom-field lists define fields (their meta carries validation rules) rather than values.
     */
    public function isCustomFieldList(): bool
    {
        return in_array($this, [self::CustomerField, self::ProductField, self::LoanField], true);
    }

    /**
     * The category list of a financial kind (income, expense, asset, liability).
     */
    public static function forFinancialKind(FinancialKind $kind): self
    {
        return match ($kind) {
            FinancialKind::Income => self::IncomeCategory,
            FinancialKind::Expense => self::ExpenseCategory,
            FinancialKind::Asset => self::AssetCategory,
            FinancialKind::Liability => self::LiabilityCategory,
        };
    }
}
