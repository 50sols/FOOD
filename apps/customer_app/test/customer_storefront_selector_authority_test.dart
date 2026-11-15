import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_store.dart';
import 'package:foodex_customer_app/core/routing/customer_commerce_context_switcher.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  const platformSession = CustomerSession.platformCustomer(
    accessToken: 'platform-identity-token',
  );

  test('authenticated selector never invents Wholesale from public discovery',
      () async {
    final requests = <http.Request>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://foodex.example.test',
      token: 'platform-identity-token',
      client: MockClient((request) async {
        requests.add(request);
        if (request.url.path != '/api/v1/store-selector') {
          return http.Response('public discovery must not be consulted', 500);
        }
        return http.Response(
            jsonEncode({
              'entitlements': {
                'direct_b2b': false,
                'retail_context_ids': <int>[],
                'support_access': false,
                'principal_wholesale_store_id': null,
              },
              'retail_stores': [
                {'id': 22, 'name': 'Retail Twenty Two', 'channel': 'b2c'},
              ],
              'wholesale_stores': <Object>[],
            }),
            200);
      }),
    );
    final persisted = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: api,
      contextStore: persisted,
    );

    final targets = await switcher.availableTargets(platformSession);
    expect(targets, hasLength(1));
    expect(targets.single.context.channel, CustomerCommerceChannel.retail);
    expect(targets.single.context.storeId, 22);
    expect(targets.single.isPrincipalWholesale, isFalse);
    expect(requests, hasLength(1));
    expect(requests.single.url.path, '/api/v1/store-selector');
    expect(requests.single.headers['authorization'],
        'Bearer platform-identity-token');

    await expectLater(
      switcher.switchTo(
        session: platformSession,
        requested: const CustomerCommerceContext(
          channel: CustomerCommerceChannel.wholesale,
          storeId: 7,
        ),
      ),
      throwsA(isA<CustomerCommerceContextSwitchException>().having(
        (error) => error.code,
        'code',
        'commerce_context_not_authorized',
      )),
    );
    expect(persisted.value, isNull);
    expect(requests, hasLength(2));
  });

  test('authorized B2B and Retail contexts use one identity with exact routes',
      () async {
    final requests = <http.Request>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://foodex.example.test',
      token: 'platform-identity-token',
      client: MockClient((request) async {
        requests.add(request);
        return http.Response(
            jsonEncode({
              'entitlements': {
                'direct_b2b': true,
                'retail_context_ids': <int>[],
                'support_access': false,
                'principal_wholesale_store_id': 7,
              },
              'wholesale_stores': [
                {
                  'id': 7,
                  'name': 'FOODEX Wholesale',
                  'is_platform_principal': true,
                  'channel': 'b2b',
                },
              ],
              'retail_stores': [
                {'id': 22, 'name': 'Retail 22', 'channel': 'b2c'},
              ],
            }),
            200);
      }),
    );
    final store = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: api,
      contextStore: store,
    );

    final wholesale = await switcher.switchTo(
      session: platformSession,
      requested: const CustomerCommerceContext(
        channel: CustomerCommerceChannel.wholesale,
        storeId: 7,
      ),
    );
    expect(wholesale.location, '/b2b/home?channel=wholesale&store_id=7');
    expect(wholesale.session.accessToken, 'platform-identity-token');
    expect(wholesale.session.platformWide, isTrue);
    expect(wholesale.session.channel, isNull);
    expect(store.value, wholesale.context);

    final retail = await switcher.switchTo(
      session: wholesale.session,
      requested: const CustomerCommerceContext(
        channel: CustomerCommerceChannel.retail,
        storeId: 22,
      ),
    );
    expect(retail.location, '/retail/22/home?channel=retail&store_id=22');
    expect(retail.session.accessToken, wholesale.session.accessToken);
    expect(store.value, retail.context);
    expect(requests.map((r) => r.url.path).toList(),
        ['/api/v1/store-selector', '/api/v1/store-selector']);
  });

  test('authenticated 403 is never downgraded to guest store discovery',
      () async {
    final paths = <String>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://foodex.example.test',
      token: 'platform-identity-token',
      client: MockClient((request) async {
        paths.add(request.url.path);
        if (request.url.path == '/api/v1/store-selector') {
          return http.Response(jsonEncode({'message': 'Not authorized'}), 403);
        }
        return http.Response(
          jsonEncode({
            'main_wholesale_store': {'id': 7}
          }),
          200,
        );
      }),
    );

    await expectLater(
      api.selection(),
      throwsA(isA<StorefrontApiException>().having(
        (error) => error.statusCode,
        'statusCode',
        403,
      )),
    );
    expect(paths, ['/api/v1/store-selector']);
  });

  test('malformed authenticated envelope fails before persisting any scope',
      () async {
    final store = _MemoryContextStore();
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: HttpStorefrontApi(
        baseUrl: 'https://foodex.example.test',
        token: 'platform-identity-token',
        client: MockClient((request) async => http.Response(
            jsonEncode({
              'entitlements': {'direct_b2b': false},
              'retail_stores': <Object>[],
              'wholesale_stores': 'unexpected, must be a list',
            }),
            200)),
      ),
      contextStore: store,
    );

    await expectLater(
      switcher.switchTo(
        session: platformSession,
        requested: const CustomerCommerceContext(
          channel: CustomerCommerceChannel.retail,
          storeId: 22,
        ),
      ),
      throwsA(isA<CustomerCommerceContextSwitchException>().having(
        (error) => error.code,
        'code',
        'wholesale_selector_rows_invalid',
      )),
    );
    expect(store.value, isNull);
  });

  test('public storefront remains available to guests without a token',
      () async {
    final requests = <http.Request>[];
    final api = HttpStorefrontApi(
      baseUrl: 'https://foodex.example.test',
      client: MockClient((request) async {
        requests.add(request);
        return http.Response(
            jsonEncode({
              'main_wholesale_store': {
                'id': 7,
                'name': 'Public Wholesale',
                'is_platform_principal': true,
              },
              'retail_stores': [
                {'id': 22, 'name': 'Public Retail'},
              ],
            }),
            200);
      }),
    );

    final result = await api.selection();
    expect(requests, hasLength(1));
    expect(requests.single.url.path, '/api/v1/platform/storefront');
    expect(requests.single.headers.containsKey('authorization'), isFalse);
    expect(result['wholesale_stores'], hasLength(1));
    expect((result['entitlements'] as Map)['direct_b2b'], isFalse);
  });

  test('missing platform access token rejects a context switch before I/O',
      () async {
    final requests = <String>[];
    final switcher = CustomerCommerceContextSwitcher(
      storefrontApi: HttpStorefrontApi(
        baseUrl: 'https://foodex.example.test',
        client: MockClient((request) async {
          requests.add(request.url.path);
          return http.Response('{}', 200);
        }),
      ),
      contextStore: _MemoryContextStore(),
    );

    await expectLater(
      switcher.availableTargets(const CustomerSession.platformCustomer()),
      throwsA(isA<CustomerCommerceContextSwitchException>().having(
        (error) => error.code,
        'code',
        'platform_customer_session_required',
      )),
    );
    expect(requests, isEmpty);
  });
}

class _MemoryContextStore implements CustomerCommerceContextStore {
  CustomerCommerceContext? value;

  @override
  Future<CustomerCommerceContext?> read() async => value;

  @override
  Future<void> write(CustomerCommerceContext context) async {
    value = context;
  }

  @override
  Future<void> clear() async {
    value = null;
  }
}
