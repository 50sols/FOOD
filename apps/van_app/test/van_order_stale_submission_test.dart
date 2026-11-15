import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/features/orders/van_order_contract.dart';
import 'package:foodex_van_app/features/orders/van_order_review_page.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';

void main() {
  testWidgets('an unchanged B2B draft accepts its own completed order',
      (tester) async {
    final repository = _DelayedOrderRepository();
    final draft = _readyDraft();
    final accepted = <VanOrderRecord>[];

    await tester.pumpWidget(_review(repository, draft, accepted.add));
    await tester.tap(find.byKey(const ValueKey('van-order-submit')));
    await tester.pump();

    expect(repository.createCalls, 1);
    repository.complete(_createdOrder);
    await tester.pump();

    expect(accepted, hasLength(1));
    expect(accepted.single.id, 42);
  });

  testWidgets(
      'late create response cannot confirm the previous store after a switch',
      (tester) async {
    final repository = _DelayedOrderRepository();
    final draft = _readyDraft();
    final accepted = <VanOrderRecord>[];

    await tester.pumpWidget(_review(repository, draft, accepted.add));
    await tester.tap(find.byKey(const ValueKey('van-order-submit')));
    await tester.pump();

    expect(repository.createCalls, 1);
    expect(repository.sentCustomer?.storeId, 3);

    draft.selectCustomer(const VanCustomerScope(
        type: 'b2b', id: 7, name: 'Customer', storeId: 4));
    expect(draft.hasCurrentQuote, isFalse);
    repository.complete(_createdOrder);
    await tester.pump();

    expect(accepted, isEmpty);
    expect(find.textContaining('Check order history'), findsOneWidget);
  });

  testWidgets('late create response cannot confirm a previous cart revision',
      (tester) async {
    final repository = _DelayedOrderRepository();
    final draft = _readyDraft();
    final accepted = <VanOrderRecord>[];

    await tester.pumpWidget(_review(repository, draft, accepted.add));
    await tester.tap(find.byKey(const ValueKey('van-order-submit')));
    await tester.pump();

    draft.changeQuantity(_product, 2);
    repository.complete(_createdOrder);
    await tester.pump();

    expect(accepted, isEmpty);
    expect(find.textContaining('Check order history'), findsOneWidget);
  });
}

Widget _review(VanOrderRepository repository, VanOrderDraftController draft,
    ValueChanged<VanOrderRecord> onSubmitted) {
  return MaterialApp(
    home: Scaffold(
      body: VanOrderReviewPage(
        repository: repository,
        draft: draft,
        onSubmitted: onSubmitted,
        onSessionExpired: () async {},
      ),
    ),
  );
}

VanOrderDraftController _readyDraft() {
  final draft = VanOrderDraftController();
  draft.selectCustomer(_customer);
  draft.add(_product);
  draft.applyOptions(_options);
  final revision = draft.revision;
  expect(draft.setQuoteForRevision(_quote, revision), isTrue);
  expect(draft.hasCurrentQuote, isTrue);
  return draft;
}

class _DelayedOrderRepository implements VanOrderRepository {
  final Completer<VanOrderRecord> _result = Completer<VanOrderRecord>();
  VanCustomerScope? sentCustomer;
  int createCalls = 0;

  @override
  Future<VanOrderRecord> createOrder({
    required VanCustomerScope customer,
    required List<VanOrderLine> lines,
    required String paymentMethod,
    required String idempotencyKey,
    int? addressId,
    int? warehouseId,
    String? customerNote,
  }) {
    sentCustomer = customer;
    createCalls++;
    return _result.future;
  }

  void complete(VanOrderRecord order) => _result.complete(order);

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

const _customer =
    VanCustomerScope(type: 'b2b', id: 7, name: 'Customer', storeId: 3);
const _product = VanCatalogProduct(
  id: 100,
  name: 'Product',
  sku: 'SKU',
  unitPrice: 12,
  minimumQuantity: 1,
  orderingIncrement: 1,
  isAvailable: true,
);
const _options = VanOrderOptions(
  addresses: [],
  warehouses: [],
  paymentMethods: ['cash'],
  defaultPaymentMethod: 'cash',
);
const _quote = VanOrderQuote(
  currency: 'KWD',
  subtotal: 12,
  discountTotal: 0,
  deliveryTotal: 0,
  taxTotal: 0,
  grandTotal: 12,
  hasUnavailableItems: false,
);
const _createdOrder = VanOrderRecord(
  id: 42,
  orderNumber: 'V-42',
  customerType: 'b2b',
  customerId: 7,
  storeId: 3,
  status: 'new',
  currency: 'KWD',
  grandTotal: 12,
);
