import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/api/http_van_api.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/wallet/http_van_wallet_repository.dart';
import 'package:foodex_van_app/features/wallet/van_wallet_contract.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Van finance HTTP contract keeps authoritative scope and idempotency',
      () async {
    final seen = <String>[];
    final client = MockClient((request) async {
      seen.add('${request.method} ${request.url.path}');
      expect(request.headers['authorization'], 'Bearer van-token');

      if (request.method == 'GET' &&
          request.url.path == '/api/v1/van/customers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'type': 'b2b',
                'id': 44,
                'name': 'Scoped Customer',
                'store_id': 7,
              },
            ],
          }),
          200,
        );
      }

      if (request.method == 'GET' &&
          request.url.path ==
              '/api/v1/van/customers/b2b/44/collection-context') {
        expect(request.url.queryParameters['store_id'], '7');
        return http.Response(
          jsonEncode({
            'data': {
              'customer_type': 'b2b',
              'customer_id': 44,
              'store_id': 7,
              'invoices': [
                {
                  'id': 91,
                  'number': 'INV-91',
                  'currency': 'EGP',
                  'total': 100,
                  'outstanding_amount': 65,
                },
              ],
            },
          }),
          200,
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/van/customers/b2b/44/collect') {
        expect(request.headers['idempotency-key'], 'collect-retry-1');
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['store_id'], 7);
        expect(body['invoice_id'], 91);
        expect(body['amount'], 25);
        return http.Response(
          jsonEncode({
            'data': {
              'receipt': {
                'id': 501,
                'payment_id': 902,
                'amount': 25,
                'currency': 'EGP',
                'status': 'posted',
                'created_at': '2026-10-06T03:00:00Z',
              },
              'remaining_outstanding': 40,
              'wallet': {
                'id': 12,
                'store_id': 7,
                'currency': 'EGP',
                'status': 'active',
                'custody_balance': 25,
                'available_to_remit': 25,
                'transactions': [],
                'remittances': [],
              },
            },
          }),
          201,
        );
      }

      if (request.method == 'POST' &&
          request.url.path == '/api/v1/van/remittances') {
        expect(request.headers['idempotency-key'], 'remit-retry-1');
        return http.Response(
          jsonEncode({
            'data': {
              'remittance': {
                'id': 77,
                'amount': 20,
                'currency': 'EGP',
                'method': 'bank_deposit',
                'status': 'pending',
                'created_at': '2026-10-06T03:05:00Z',
              },
              'wallet': {
                'id': 12,
                'store_id': 7,
                'currency': 'EGP',
                'status': 'active',
                'custody_balance': 25,
                'available_to_remit': 5,
                'transactions': [],
                'remittances': [],
              },
            },
          }),
          201,
        );
      }

      return http.Response('{}', 404);
    });

    final repository = HttpVanWalletRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: client,
      ),
    );

    final customers = await repository.customers();
    expect(customers.single.name, 'Scoped Customer');

    final context = await repository.collectionContext(customers.single);
    expect(context.invoices.single.outstandingAmount, 65);

    final collection = await repository.collect(
      customer: customers.single,
      invoiceId: 91,
      amount: 25,
      idempotencyKey: 'collect-retry-1',
    );
    expect(collection.receipt.id, 501);
    expect(collection.receipt.paymentId, 902);
    expect(collection.remainingOutstanding, 40);
    expect(collection.wallet.custodyBalance, 25);

    final wallet = await repository.remit(
      collectionAccountId: 12,
      amount: 20,
      method: 'bank_deposit',
      idempotencyKey: 'remit-retry-1',
    );
    expect(wallet.availableToRemit, 5);

    expect(
      seen,
      containsAll([
        'GET /api/v1/van/customers',
        'GET /api/v1/van/customers/b2b/44/collection-context',
        'POST /api/v1/van/customers/b2b/44/collect',
        'POST /api/v1/van/remittances',
      ]),
    );
  });

  test('Van finance HTTP errors retain sanitized status for actionable UI',
      () async {
    final client = MockClient((request) async => http.Response(
          jsonEncode({
            'message': 'The given data was invalid.',
            'errors': {
              'amount': ['Remittance exceeds available custody.'],
            },
          }),
          422,
        ));

    final repository = HttpVanWalletRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: client,
      ),
    );

    await expectLater(
      repository.remit(
        collectionAccountId: 12,
        amount: 20,
        method: 'bank_transfer',
        idempotencyKey: 'remit-invalid-1',
      ),
      throwsA(
        isA<VanApiException>().having(
          (error) => error.statusCode,
          'statusCode',
          422,
        ),
      ),
    );
  });

  test('Van wallet preserves payment identity and genuine three-place money',
      () async {
    final api = VanApiClient(
      'https://foodex.example/',
      'van-token',
      client: MockClient((request) async {
        expect(request.url.path, '/api/v1/van/wallet');
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 12,
                'store_id': 7,
                'currency': 'KWD',
                'status': 'active',
                'custody_balance': '25.125',
                'available_to_remit': '25.125',
                'transactions': [
                  {
                    'id': 501,
                    'payment_id': 902,
                    'amount': '25.125',
                    'currency': 'KWD',
                    'status': 'posted',
                    'created_at': '2026-11-16T03:00:00Z',
                  },
                  {
                    'id': 502,
                    'amount': '0.000',
                    'currency': 'KWD',
                    'status': 'posted',
                    'created_at': '2026-11-16T03:01:00Z',
                  },
                ],
                'remittances': [],
              },
            ],
          }),
          200,
        );
      }),
    );

    final accounts = await HttpVanWalletRepository(api).wallet();
    final wallet = accounts.single;
    expect(wallet.storeId, 7);
    expect(wallet.custodyBalance, 25.125);
    expect(wallet.receipts.first.paymentId, 902);
    expect(wallet.receipts.first.amount, 25.125);
    // Legacy history may omit payment_id; do not invent one or a paid status.
    expect(wallet.receipts.last.paymentId, isNull);
    expect(wallet.receipts.last.amount, 0);
  });

  test('Van collection rejects a forged payment ID after a scoped response',
      () async {
    const customer = VanCustomerScope(
      type: 'b2b',
      id: 44,
      name: 'Wholesale customer',
      storeId: 7,
    );
    for (final invalidId in <Object?>[0, -1, 'invalid', 'NaN']) {
      final repository = HttpVanWalletRepository(
        VanApiClient(
          'https://foodex.example/',
          'van-token',
          client: MockClient((request) async {
            expect(request.method, 'POST');
            expect(
              request.url.path,
              '/api/v1/van/customers/b2b/44/collect',
            );
            expect(request.headers['idempotency-key'], 'collect-safe-key');
            return http.Response(
              jsonEncode({
                'data': {
                  'receipt': {
                    'id': 501,
                    'payment_id': invalidId,
                    'amount': '25.125',
                    'currency': 'KWD',
                    'status': 'posted',
                    'created_at': '2026-11-16T03:00:00Z',
                  },
                  'remaining_outstanding': '40.000',
                  'wallet': {
                    'id': 12,
                    'store_id': 7,
                    'currency': 'KWD',
                    'status': 'active',
                    'custody_balance': '25.125',
                    'available_to_remit': '25.125',
                    'transactions': [],
                    'remittances': [],
                  },
                },
              }),
              201,
            );
          }),
        ),
      );

      await expectLater(
        repository.collect(
          customer: customer,
          invoiceId: 91,
          amount: 25.125,
          idempotencyKey: 'collect-safe-key',
        ),
        throwsA(isA<VanApiException>()),
      );
    }
  });

  test('Van scoped collection context rejects foreign-store responses',
      () async {
    const customer = VanCustomerScope(
      type: 'b2b',
      id: 44,
      name: 'Wholesale customer',
      storeId: 7,
    );
    final repository = HttpVanWalletRepository(
      VanApiClient(
        'https://foodex.example/',
        'van-token',
        client: MockClient((request) async {
          expect(request.url.queryParameters['store_id'], '7');
          return http.Response(
            jsonEncode({
              'data': {'store_id': 8, 'invoices': []}
            }),
            200,
          );
        }),
      ),
    );
    await expectLater(
      repository.collectionContext(customer),
      throwsA(isA<VanApiException>()),
    );
  });

  test('Van finance rejects missing, malformed and non-finite money', () async {
    const customer = VanCustomerScope(
      type: 'b2b',
      id: 44,
      name: 'Wholesale customer',
      storeId: 7,
    );
    for (final invalidAmount in <Object?>[
      null,
      'broken',
      'NaN',
      'Infinity',
      '-Infinity',
    ]) {
      final repository = HttpVanWalletRepository(
        VanApiClient(
          'https://foodex.example/',
          'van-token',
          client: MockClient((request) async {
            expect(request.url.queryParameters['store_id'], '7');
            return http.Response(
              jsonEncode({
                'data': {
                  'store_id': 7,
                  'invoices': [
                    {
                      'id': 91,
                      'number': 'INV-91',
                      'currency': 'KWD',
                      'total': invalidAmount,
                      'outstanding_amount': '40.000',
                    },
                  ],
                },
              }),
              200,
            );
          }),
        ),
      );
      await expectLater(
        repository.collectionContext(customer),
        throwsA(isA<VanApiException>()),
      );
    }
  });}
