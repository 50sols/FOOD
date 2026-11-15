import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/features/b2b/wholesale_financial_summary.dart';

void main() {
  Map<Object?, Object?> debt() => {
        'currency': 'KWD',
        'balance': '-125.000',
        'balance_direction': 'customer_owes_company',
        'credit_limit': '1000.000',
        'available_credit_line': '875.000',
        'purchasing_power': '875.000',
      };

  test('valid debt is not disguised as a customer credit', () {
    final result = WholesaleFinancialSummary.tryParse(debt());
    expect(result, isNotNull);
    expect(result!.balance, -125);
    expect(
      result.direction,
      WholesaleBalanceDirection.customerOwesCompany,
    );
    expect(result.availableCreditLine, 875);
    expect(result.purchasingPower, 875);
  });

  test('customer credit adds to the available purchasing power', () {
    final result = WholesaleFinancialSummary.tryParse({
      'currency': 'KWD',
      'balance': 20,
      'balance_direction': 'company_owes_customer',
      'credit_limit': 500,
      'available_credit_line': 500,
      'purchasing_power': 520,
    });
    expect(result, isNotNull);
    expect(result!.direction, WholesaleBalanceDirection.companyOwesCustomer);
    expect(result.purchasingPower, 520);
  });

  test('zero means settled only when the full snapshot agrees', () {
    final data = debt()
      ..['balance'] = 0
      ..['balance_direction'] = 'settled'
      ..['available_credit_line'] = 1000
      ..['purchasing_power'] = 1000;
    final result = WholesaleFinancialSummary.tryParse(data);
    expect(result?.direction, WholesaleBalanceDirection.settled);
  });

  test('fail closed on forged sign, missing money or cross-field drift', () {
    final contradictory = debt()..['balance'] = 125;
    expect(WholesaleFinancialSummary.tryParse(contradictory), isNull);

    final missing = debt()..remove('purchasing_power');
    expect(WholesaleFinancialSummary.tryParse(missing), isNull);

    final impossible = debt()..['available_credit_line'] = 1000;
    expect(WholesaleFinancialSummary.tryParse(impossible), isNull);

    final falsePower = debt()..['purchasing_power'] = 999;
    expect(WholesaleFinancialSummary.tryParse(falsePower), isNull);

    final notFinite = debt()..['balance'] = double.nan;
    expect(WholesaleFinancialSummary.tryParse(notFinite), isNull);

    final noCurrency = debt()..['currency'] = '';
    expect(WholesaleFinancialSummary.tryParse(noCurrency), isNull);

    final controlCurrency = debt()..['currency'] = 'KWD\u202E';
    expect(WholesaleFinancialSummary.tryParse(controlCurrency), isNull);

    final longCurrency = debt()..['currency'] = 'KWD-FAKE';
    expect(WholesaleFinancialSummary.tryParse(longCurrency), isNull);

    final lowerCurrency = debt()..['currency'] = 'kwd';
    expect(WholesaleFinancialSummary.tryParse(lowerCurrency), isNull);
  });

  test('valid padded ISO code displays canonical currency', () {
    final normalized = debt()..['currency'] = ' KWD ';
    expect(WholesaleFinancialSummary.tryParse(normalized)?.currency, 'KWD');
  });

  test('dashboard credit requires a signed, consistent server snapshot', () {
    Map<Object?, Object?> credit() => {
          'balance': 20.0,
          'balance_direction': 'company_owes_customer',
          'customer_credit_balance': 20.0,
        };

    expect(WholesaleFinancialSummary.dashboardCustomerCredit(credit()), 20);
    final debt = credit()
      ..['balance'] = -20.0
      ..['balance_direction'] = 'customer_owes_company'
      ..['customer_credit_balance'] = 0.0;
    expect(WholesaleFinancialSummary.dashboardCustomerCredit(debt), 0);
    final settled = credit()
      ..['balance'] = 0.0
      ..['balance_direction'] = 'settled'
      ..['customer_credit_balance'] = 0.0;
    expect(WholesaleFinancialSummary.dashboardCustomerCredit(settled), 0);

    final forged = Map<Object?, Object?>.from(debt)
      ..['customer_credit_balance'] = 20.0;
    expect(WholesaleFinancialSummary.dashboardCustomerCredit(forged), isNull);
    final wrongDirection = credit()
      ..['balance_direction'] = 'customer_owes_company';
    expect(
      WholesaleFinancialSummary.dashboardCustomerCredit(wrongDirection),
      isNull,
    );
    final mismatch = credit()..['customer_credit_balance'] = 100.0;
    expect(WholesaleFinancialSummary.dashboardCustomerCredit(mismatch), isNull);
    final missing = credit()..remove('customer_credit_balance');
    expect(WholesaleFinancialSummary.dashboardCustomerCredit(missing), isNull);
    final nonFinite = credit()..['balance'] = double.infinity;
    expect(
        WholesaleFinancialSummary.dashboardCustomerCredit(nonFinite), isNull);
    final boolCredit = credit()..['customer_credit_balance'] = true;
    expect(
        WholesaleFinancialSummary.dashboardCustomerCredit(boolCredit), isNull);
  });

  test('required display totals reject invalid and fabricated zeros', () {
    expect(WholesaleFinancialSummary.nonNegativeAmount('120.500'), 120.5);
    expect(WholesaleFinancialSummary.nonNegativeAmount(0), 0);
    expect(WholesaleFinancialSummary.nonNegativeAmount(null), isNull);
    expect(WholesaleFinancialSummary.nonNegativeAmount(true), isNull);
    expect(WholesaleFinancialSummary.nonNegativeAmount(-0.1), isNull);
    expect(WholesaleFinancialSummary.nonNegativeAmount(double.nan), isNull);
    expect(WholesaleFinancialSummary.nonNegativeAmount('not-money'), isNull);
  });
}
