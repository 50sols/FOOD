import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('Wholesale GET retries transient failure with B2B and locale scope',
      () async {
    var attempts = 0;
    final api = HttpWholesaleCommerceApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      retailStoreContextId: 17,
      locale: 'en',
      client: MockClient((request) async {
        attempts++;
        expect(request.method, 'GET');
        expect(request.url.path, '/api/v1/cart');
        expect(request.url.queryParameters['store'], '12');
        expect(request.headers['Authorization'], 'Bearer opaque-token');
        expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
        expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
        expect(request.headers['Accept-Language'], 'en');
        if (attempts == 1) {
          throw http.ClientException('transient network failure', request.url);
        }
        return http.Response('{"data":[]}', 200);
      }),
    );

    expect(await api.cart(12), isA<Map>());
    expect(attempts, 2);
  });

  test('GET retries HTTP 502/503/504 with scoped headers', () async {
    for (final transientStatus in [502, 503, 504]) {
      var attempts = 0;
      final api = HttpWholesaleCommerceApi(
        baseUrl: 'https://foodex.example',
        token: 'opaque-token',
        retailStoreContextId: 17,
        locale: 'en',
        client: MockClient((request) async {
          attempts++;
          expect(request.method, 'GET');
          expect(request.url.path, '/api/v1/cart');
          expect(request.url.queryParameters['store'], '12');
          expect(request.headers['Authorization'], 'Bearer opaque-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          expect(request.headers['Accept-Language'], 'en');
          if (attempts == 1) {
            return http.Response('{"message":"transient"}', transientStatus);
          }
          return http.Response('{"data":[]}', 200);
        }),
      );

      expect(await api.cart(12), isA<Map>());
      expect(attempts, 2);
    }
  });

  test('GET HTTP 503 exhaustion is bounded and bilingual', () async {
    for (final language in ['ar', 'en']) {
      var attempts = 0;
      final api = HttpWholesaleCommerceApi(
        baseUrl: 'https://foodex.example',
        token: 'opaque-token',
        locale: language,
        maxGetAttempts: 2,
        client: MockClient((request) async {
          attempts++;
          expect(request.headers['Accept-Language'], language);
          return http.Response('{"message":"internal stack trace"}', 503);
        }),
      );

      await expectLater(
        api.cart(12),
        throwsA(
          isA<WholesaleCommerceException>()
              .having((error) => error.statusCode, 'status', 503)
              .having(
                (error) => error.message,
                'localized server error',
                language == 'en'
                    ? 'The server is temporarily unavailable. Try again.'
                    : 'الخادم غير متاح مؤقتًا. حاول مرة أخرى.',
              ),
        ),
      );
      expect(attempts, 2);
    }
  });

  test('HTTP 401 requires a fresh session without retry', () async {
    for (final language in ['ar', 'en']) {
      var attempts = 0;
      final api = HttpWholesaleCommerceApi(
        baseUrl: 'https://foodex.example',
        token: 'expired-token',
        locale: language,
        maxGetAttempts: 3,
        client: MockClient((request) async {
          attempts++;
          expect(request.headers['Authorization'], 'Bearer expired-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          return http.Response('{"message":"internal auth context"}', 401);
        }),
      );

      await expectLater(
        api.cart(12),
        throwsA(
          isA<WholesaleCommerceException>()
              .having((error) => error.statusCode, 'status', 401)
              .having(
                (error) => error.message,
                'localized session error',
                language == 'en'
                    ? 'Your session has expired. Sign in again.'
                    : 'انتهت صلاحية جلستك. سجّل الدخول مرة أخرى.',
              ),
        ),
      );
      expect(attempts, 1);
    }
  });

  test('Wholesale GET timeout is bounded and localized', () async {
    var attempts = 0;
    final api = HttpWholesaleCommerceApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      locale: 'en',
      requestTimeout: const Duration(milliseconds: 5),
      maxGetAttempts: 1,
      client: MockClient((_) async {
        attempts++;
        await Future<void>.delayed(const Duration(milliseconds: 80));
        return http.Response('{}', 200);
      }),
    );

    await expectLater(
      api.cart(12),
      throwsA(
        isA<WholesaleCommerceException>().having(
          (error) => error.message,
          'localized timeout',
          'The server request timed out.',
        ),
      ),
    );
    expect(attempts, 1);
  });

  test('Quote and cart mutations never retry an HTTP failure', () async {
    final calls = <String>[];
    final api = HttpWholesaleCommerceApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      maxGetAttempts: 3,
      client: MockClient((request) async {
        calls.add('${request.method} ${request.url.path}');
        return http.Response('{"message":"Unavailable"}', 503);
      }),
    );

    await expectLater(
      api.quote(storeId: 12),
      throwsA(isA<WholesaleCommerceException>()),
    );
    await expectLater(
      api.addItem(12, 44, 1),
      throwsA(isA<WholesaleCommerceException>()),
    );
    await expectLater(
      api.updateItem(4, 2),
      throwsA(isA<WholesaleCommerceException>()),
    );
    await expectLater(
      api.removeItem(4),
      throwsA(isA<WholesaleCommerceException>()),
    );

    expect(calls, [
      'POST /api/v1/quote',
      'POST /api/v1/cart/items',
      'PATCH /api/v1/cart/items/4',
      'DELETE /api/v1/cart/items/4',
    ]);
  });

  test('Checkout never auto-retries; explicit replay retains the caller key',
      () async {
    var attempts = 0;
    final receivedKeys = <String>[];
    final bodies = <Object?>[];
    final api = HttpWholesaleCommerceApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      locale: 'en',
      maxGetAttempts: 3,
      client: MockClient((request) async {
        attempts++;
        expect(request.method, 'POST');
        expect(request.url.path, '/api/v1/checkout');
        receivedKeys.add(request.headers['Idempotency-Key']!);
        bodies.add(jsonDecode(request.body));
        if (attempts == 1) {
          throw http.ClientException(
              'connection lost after commit', request.url);
        }
        return http.Response('{"id":123}', 200);
      }),
    );

    Future<Object?> checkout() => api.checkout(
          storeId: 12,
          addressId: 9,
          paymentMethod: 'account_credit',
          note: ' Warehouse ',
          couponCode: 'save20',
          idempotencyKey: 'checkout-fixed-intent-0001',
        );

    await expectLater(
      checkout(),
      throwsA(
        isA<WholesaleCommerceException>().having(
          (error) => error.message,
          'localized error',
          'Unable to connect to the server.',
        ),
      ),
    );
    expect(attempts, 1);
    expect(await checkout(), isA<Map>());
    expect(receivedKeys,
        ['checkout-fixed-intent-0001', 'checkout-fixed-intent-0001']);
    expect(bodies[0], bodies[1]);
    final payload = bodies[1] as Map;
    expect(payload['note'], 'Warehouse');
    expect(payload['coupon_code'], 'SAVE20');
    expect(payload, isNot(contains('unit_price')));
    expect(payload, isNot(contains('credit_limit')));
    expect(attempts, 2);
  });

  test('HTTP 403 remains a single scoped denial without retry', () async {
    var attempts = 0;
    final api = HttpWholesaleCommerceApi(
      baseUrl: 'https://foodex.example',
      token: 'opaque-token',
      locale: 'ar',
      maxGetAttempts: 3,
      client: MockClient((request) async {
        attempts++;
        expect(request.headers['Accept-Language'], 'ar');
        expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
        return http.Response('{}', 403);
      }),
    );

    await expectLater(
      api.cart(12),
      throwsA(
        isA<WholesaleCommerceException>()
            .having((error) => error.statusCode, 'status', 403),
      ),
    );
    expect(attempts, 1);
  });

  test('200 invalid quote checkout and cart responses fail closed in AR/EN',
      () async {
    final invalidResponses = <({String mime, String body})>[
      (mime: 'text/html', body: '<html>Log in</html>'),
      (mime: 'application/json', body: ''),
      (mime: 'application/json', body: '{"data":'),
      (mime: 'application/json', body: '[]'),
      (mime: 'application/json', body: 'null'),
      (mime: 'text/plain', body: '{"data":{"grand_total":0}}'),
    ];
    for (final locale in ['ar', 'en']) {
      for (final endpoint in [
        '/api/v1/cart',
        '/api/v1/quote',
        '/api/v1/checkout',
      ]) {
        for (final invalid in invalidResponses) {
          var calls = 0;
          final api = HttpWholesaleCommerceApi(
            baseUrl: 'https://foodex.example',
            token: 'scoped-token',
            retailStoreContextId: 17,
            locale: locale,
            maxGetAttempts: 3,
            client: MockClient((request) async {
              calls++;
              expect(request.url.path, endpoint);
              expect(request.method,
                  endpoint == '/api/v1/cart' ? 'GET' : 'POST');
              expect(request.headers['Authorization'], 'Bearer scoped-token');
              expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
              expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
              expect(request.headers['Accept-Language'], locale);
              if (endpoint == '/api/v1/checkout') {
                expect(request.headers['Idempotency-Key'],
                    'fixed-financial-intent-0001');
              }
              return http.Response(invalid.body, 200,
                  headers: {'content-type': invalid.mime});
            }),
          );

          Future<Object?> action;
          if (endpoint == '/api/v1/cart') {
            action = api.cart(12);
          } else if (endpoint == '/api/v1/quote') {
            action = api.quote(storeId: 12);
          } else {
            action = api.checkout(
              storeId: 12,
              addressId: 9,
              paymentMethod: 'account_credit',
              idempotencyKey: 'fixed-financial-intent-0001',
            );
          }
          await expectLater(
            action,
            throwsA(isA<WholesaleCommerceException>().having(
              (error) => error.message,
              'localized invalid response',
              locale == 'en'
                  ? 'The server returned an invalid response. Try again.'
                  : 'أعاد الخادم استجابة غير صالحة. حاول مرة أخرى.',
            )),
          );
          // Never retry a committed POST, or disguise a malformed 200 as
          // a valid empty cart / approved credit checkout.
          expect(calls, 1);
        }
      }
    }
  });

  test('Scoped commerce API does not recalculate three-decimal quote money',
      () async {
    for (final locale in ['ar', 'en']) {
      final api = HttpWholesaleCommerceApi(
        baseUrl: 'https://foodex.example',
        token: 'scoped-token',
        retailStoreContextId: 17,
        locale: locale,
        client: MockClient((request) async {
          expect(request.url.path, '/api/v1/quote');
          expect(request.headers['Authorization'], 'Bearer scoped-token');
          expect(request.headers['X-FOODEX-Customer-Domain'], 'b2b');
          expect(request.headers['X-FOODEX-Retail-Store-ID'], '17');
          expect(request.headers['Accept-Language'], locale);
          final body = jsonDecode(request.body) as Map;
          expect(body['store_id'], 12);
          expect(body, isNot(contains('unit_price')));
          expect(body, isNot(contains('credit_limit')));
          expect(body, isNot(contains('discount_total')));
          return http.Response(
            '{"data":{"grand_total":20.125,"discount_total":0.001,"pricing_source":"backend_authoritative_quote_v1"}}',
            200,
            headers: {'content-type': 'application/json'},
          );
        }),
      );
      final quote = await api.quote(storeId: 12) as Map;
      final data = quote['data'] as Map;
      expect(data['grand_total'], 20.125);
      expect(data['discount_total'], 0.001);
      expect(data['pricing_source'], 'backend_authoritative_quote_v1');
    }
  });
}
