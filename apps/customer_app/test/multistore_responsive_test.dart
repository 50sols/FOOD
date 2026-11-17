import 'dart:async';

// ignore_for_file: deprecated_member_use, prefer_interpolation_to_compose_strings

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/api/b2b_api.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/customer_action_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/api/wholesale_commerce_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';

void main() {
  const devices = <Size>[
    Size(360, 800),
    Size(390, 844),
    Size(412, 915),
    Size(480, 960),
  ];
  const routes = <String>[
    '/customer/store-selector',
    '/retail/7/home',
    '/retail/8/home',
    '/b2b/home?store_id=70',
    '/b2b/products?store_id=70',
    '/retail/7/products/42',
    '/b2b/products/42?store_id=70',
    '/b2b/cart?store=70',
    '/b2b/checkout?store_id=70',
    '/b2b/orders',
  ];

  for (final device in devices) {
    testWidgets(
      'multi-store screens render without overflow at ' +
          device.width.toInt().toString() +
          'x' +
          device.height.toInt().toString(),
      (tester) async {
        await tester.binding.setSurfaceSize(device);
        addTearDown(() => tester.binding.setSurfaceSize(null));

        for (final route in routes) {
          await tester.pumpWidget(
            FoodexCustomerApp(
              initialRoute: route,
              session: const CustomerSession.authenticated(
                CustomerChannel.b2c,
                accessToken: 'responsive-token',
                b2bRetailStoreId: 7,
              ),
              b2cCatalogApi: const _ResponsiveCatalogApi(),
              b2cAccountApi: const _ResponsiveAccountApi(),
              actionApi: const _ResponsiveActionApi(),
              b2bApi: const _ResponsiveB2bApi(),
              storefrontApi: const _ResponsiveStorefrontApi(),
              wholesaleCommerceApi: const _ResponsiveWholesaleApi(),
            ),
          );
          await tester.pumpAndSettle();

          final exception = tester.takeException();
          expect(
            exception,
            isNull,
            reason: 'Route ' +
                route +
                ' failed at ' +
                device.width.toInt().toString() +
                'x' +
                device.height.toInt().toString(),
          );
        }
      },
    );
  }

  // MOB-C03 price labels are display-only; never turn a malformed API price
  // into a genuine zero or invent any quote/credit decision in Flutter.
  final priceScenarios = <({
    String name,
    List<Object?> amounts,
    List<String> labels,
  })>[
    (
      name: 'valid prices and genuine zero',
      amounts: <Object?>[0, 12.625, '1.2'],
      labels: <String>['0 EGP', '13 EGP', '1 EGP'],
    ),
    (
      name: 'missing malformed and NaN',
      amounts: <Object?>[null, 'not-a-price', double.nan],
      labels: <String>['—', '—', '—'],
    ),
    (
      name: 'infinite negative and forged string',
      amounts: <Object?>[double.infinity, -5, '-Infinity'],
      labels: <String>['—', '—', '—'],
    ),
  ];

  for (final language in const <String>['ar', 'en']) {
    for (final scenario in priceScenarios) {
      testWidgets(
        'MOB-C03 wholesale home price truthfulness ${scenario.name} $language',
        (tester) async {
          await tester.binding.setSurfaceSize(const Size(390, 844));
          addTearDown(() => tester.binding.setSurfaceSize(null));

          final rows = <Map<String, dynamic>>[
            for (var index = 0; index < scenario.amounts.length; index++)
              <String, dynamic>{
                'id': 901 + index,
                'name': 'Wholesale product ${index + 1}',
                'sku': 'WHOLESALE-PRICE-${index + 1}',
                'currency': 'EGP',
                'available_quantity': 10,
                if (scenario.amounts[index] != null)
                  'account_price': scenario.amounts[index],
              },
          ];
          final api = _PriceBadgeB2bApi(rows);

          await tester.pumpWidget(
            FoodexCustomerApp(
              locale: Locale(language),
              initialRoute: '/b2b/home?store_id=70',
              session: const CustomerSession.authenticated(
                CustomerChannel.b2b,
                accessToken: 'scoped-price-test-token',
                b2bRetailStoreId: 7,
              ),
              b2cCatalogApi: const _ResponsiveCatalogApi(),
              b2cAccountApi: const _ResponsiveAccountApi(),
              actionApi: const _ResponsiveActionApi(),
              b2bApi: api,
              storefrontApi: const _PriceBadgeStorefrontApi(),
              wholesaleCommerceApi: const _ResponsiveWholesaleApi(),
            ),
          );
          // The wholesale Home has a continuously animated ticker. Drive a
          // bounded set of frames to resolve the remote product cards instead
          // of waiting indefinitely for every animation to settle.
          for (var frame = 0; frame < 30; frame++) {
            await tester.pump(const Duration(milliseconds: 50));
          }

          expect(
              api.productPaths, contains('/api/v1/b2b/products?store_id=70'));
          for (var index = 0; index < scenario.labels.length; index++) {
            final badge = find.byKey(
              ValueKey('wholesale-product-price-${901 + index}'),
            );
            expect(badge, findsOneWidget);
            final labelFinder = find.descendant(
              of: badge,
              matching: find.byType(Text),
            );
            expect(
                tester.widget<Text>(labelFinder).data, scenario.labels[index]);
            expect(
              Directionality.of(tester.element(badge)),
              language == 'ar' ? TextDirection.rtl : TextDirection.ltr,
            );
          }
          expect(tester.takeException(), isNull);
        },
      );
    }
  }

  const orderReferenceDevices = <Size>[
    Size(320, 568),
    Size(360, 800),
    Size(390, 844),
    Size(412, 915),
    Size(430, 932),
    Size(480, 960),
  ];

  for (final device in orderReferenceDevices) {
    testWidgets(
      'wholesale Orders reference stays dense and single-line at ' +
          device.width.toInt().toString() +
          'x' +
          device.height.toInt().toString(),
      (tester) async {
        await tester.binding.setSurfaceSize(device);
        addTearDown(() => tester.binding.setSurfaceSize(null));

        await tester.pumpWidget(
          FoodexCustomerApp(
            initialRoute: '/b2b/orders?channel=wholesale&store_id=70',
            session: const CustomerSession.authenticated(
              CustomerChannel.b2c,
              accessToken: 'responsive-orders-token',
              b2bRetailStoreId: 7,
            ),
            b2cCatalogApi: const _ResponsiveCatalogApi(),
            b2cAccountApi: const _ResponsiveAccountApi(),
            actionApi: const _ResponsiveActionApi(),
            b2bApi: const _ResponsiveB2bApi(),
            storefrontApi: const _ResponsiveStorefrontApi(),
            wholesaleCommerceApi: const _ResponsiveWholesaleApi(),
          ),
        );
        await tester.pumpAndSettle();

        expect(find.byKey(const ValueKey('b2b-orders-screen')), findsOneWidget);
        expect(
            find.byKey(const ValueKey('b2b-orders-refresh')), findsOneWidget);
        expect(
            find.byKey(const ValueKey('b2b-order-row-1001')), findsOneWidget);
        expect(find.byKey(const ValueKey('b2b-order-receipt-1001')),
            findsOneWidget);
        expect(find.byKey(const ValueKey('customer-persistent-footer')),
            findsOneWidget);

        const filterKeys = <String>[
          'all',
          'pending',
          'preparing',
          'out_for_delivery',
          'delivered',
        ];
        final firstTop = tester
            .getTopLeft(
              find.byKey(const ValueKey('b2b-orders-filter-all')),
            )
            .dy;
        for (final filter in filterKeys) {
          final finder = find.byKey(ValueKey('b2b-orders-filter-' + filter));
          expect(finder, findsOneWidget);
          expect(tester.getTopLeft(finder).dy, closeTo(firstTop, .5));
        }

        final cardWidth = tester
            .getSize(find.byKey(const ValueKey('b2b-order-row-1001')))
            .width;
        expect(cardWidth, lessThanOrEqualTo(device.width - 20));
        expect(tester.takeException(), isNull);
      },
    );
  }

  for (final language in const <String>['ar', 'en']) {
    testWidgets(
      'MOB-C03 checkout ignores an obsolete failed coupon quote in $language',
      (tester) async {
        await tester.binding.setSurfaceSize(const Size(390, 844));
        addTearDown(() => tester.binding.setSurfaceSize(null));
        final api = _DelayedQuoteWholesaleApi();
        await tester.pumpWidget(
          FoodexCustomerApp(
            locale: Locale(language),
            initialRoute: '/b2b/checkout?store_id=70',
            session: const CustomerSession.authenticated(
              CustomerChannel.b2b,
              accessToken: 'scoped-checkout-token',
              b2bRetailStoreId: 7,
            ),
            b2cCatalogApi: const _ResponsiveCatalogApi(),
            b2cAccountApi: const _ResponsiveAccountApi(),
            actionApi: const _ResponsiveActionApi(),
            b2bApi: const _ResponsiveB2bApi(),
            storefrontApi: const _ResponsiveStorefrontApi(),
            wholesaleCommerceApi: api,
          ),
        );
        await tester.pumpAndSettle();
        final field = find.byKey(const ValueKey('b2b-checkout-coupon'));
        final apply = find.byKey(const ValueKey('b2b-checkout-apply-coupon'));
        await tester.ensureVisible(field);
        await tester.enterText(field, 'OLD');
        await tester.ensureVisible(apply);
        await tester.tap(apply);
        await tester.pump();
        expect(api.pendingQuotes, hasLength(1));
        expect(api.couponCodes, ['OLD']);

        // Editing intent while the earlier server request is still pending
        // must not let its later error appear in this new checkout context.
        await tester.enterText(field, 'NEW');
        api.pendingQuotes.first.completeError(
          StateError('obsolete quote must not appear in the new intent'),
        );
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 100));
        expect(find.byKey(const ValueKey('b2b-checkout-coupon-applied')),
            findsNothing);
        expect(tester.widget<FilledButton>(apply).onPressed, isNotNull);
        expect(tester.takeException(), isNull);

        await tester.tap(apply);
        await tester.pump();
        expect(api.pendingQuotes, hasLength(2));
        expect(api.couponCodes, ['OLD', 'NEW']);
        api.pendingQuotes.last.complete({
          'data': {
            'coupon': {'code': 'NEW'},
            'currency': 'EGP',
            'grand_total': 60.125,
          },
        });
        await tester.pumpAndSettle();
        final applied =
            find.byKey(const ValueKey('b2b-checkout-coupon-applied'));
        expect(applied, findsOneWidget);
        expect(find.textContaining('NEW'), findsWidgets);
        expect(tester.takeException(), isNull);
      },
    );
  }
}


class _DelayedQuoteWholesaleApi extends _ResponsiveWholesaleApi
    implements WholesaleQuoteApi {
  final pendingQuotes = <Completer<Object?>>[];
  final couponCodes = <String?>[];

  @override
  Future<Object?> quote({
    required int storeId,
    String? couponCode,
    String? paymentMethod,
  }) {
    if (storeId != 70) {
      throw StateError('unexpected store scope');
    }
    couponCodes.add(couponCode);
    final pending = Completer<Object?>();
    pendingQuotes.add(pending);
    return pending.future;
  }

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'channel': 'b2b',
        'currency': 'EGP',
        'minimum_order_egp': 0,
        'quote': {'grand_total': 80.125, 'currency': 'EGP'},
        'items': [
          {
            'id': 1,
            'product_id': 44,
            'quantity': 5,
            'unit_price': 16.025,
          },
        ],
      };
}

class _PriceBadgeStorefrontApi extends _ResponsiveStorefrontApi {
  const _PriceBadgeStorefrontApi();

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async {
    final home = await super.wholesaleHome(storeId);
    return {
      ...home,
      'sections': [
        {
          'type': 'product_grid',
          'title_ar': 'المنتجات',
          'title_en': 'Products',
          'sort_order': 10,
        },
      ],
    };
  }
}

class _PriceBadgeB2bApi extends _ResponsiveB2bApi {
  _PriceBadgeB2bApi(this.rows);

  final List<Map<String, dynamic>> rows;
  final List<String> productPaths = [];

  @override
  Future<Object?> get(String path) async {
    if (path.startsWith('/api/v1/b2b/products?')) {
      productPaths.add(path);
      return {'data': rows};
    }
    return super.get(path);
  }
}

class _ResponsiveCatalogApi implements B2cCatalogApi {
  const _ResponsiveCatalogApi();

  @override
  Future<List<B2cStore>> stores() async => const [
        B2cStore(id: 7, name: 'Fresh Market', code: 'FRESH'),
        B2cStore(
          id: 8,
          name: 'Pharmacy',
          code: 'PHARMACY',
          themeCode: 'retail_pharmacy',
        ),
      ];

  @override
  Future<List<B2cCategory>> categories(int storeId) async => const [
        B2cCategory(id: 1, name: 'خضروات'),
        B2cCategory(id: 2, name: 'ألبان'),
        B2cCategory(id: 3, name: 'مخبوزات'),
        B2cCategory(id: 4, name: 'مشروبات'),
      ];

  @override
  Future<List<B2cProduct>> products(
    int storeId, {
    String? query,
    int? categoryId,
    String? sort,
    String? direction,
  }) async =>
      const [
        B2cProduct(id: 42, name: 'منتج تجريبي', sku: 'SKU-42', price: 25),
        B2cProduct(id: 43, name: 'منتج ثان', sku: 'SKU-43', price: 18),
        B2cProduct(id: 44, name: 'منتج ثالث', sku: 'SKU-44', price: 12),
      ];

  @override
  Future<List<B2cOffer>> offers(int storeId) async => const [
        B2cOffer(id: 1, name: 'عرض اليوم', type: 'percentage', value: 10),
      ];

  @override
  Future<List<B2cBanner>> banners(int storeId) async => const [];

  @override
  Future<B2cProduct> product(
    int productId, {
    required int storeId,
  }) async =>
      const B2cProduct(
        id: 42,
        name: 'منتج تجريبي',
        sku: 'SKU-42',
        price: 25,
        description: 'وصف المنتج',
      );
}

class _ResponsiveStorefrontApi implements StorefrontApi {
  const _ResponsiveStorefrontApi();

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      {
        'retail_stores': [
          {
            'id': 7,
            'code': 'FRESH',
            'name': 'Fresh Market',
            'theme_code': 'retail_grocery',
          },
          {
            'id': 8,
            'code': 'PHARMACY',
            'name': 'Pharmacy',
            'theme_code': 'retail_pharmacy',
          },
        ],
        'wholesale_stores': [
          {
            'id': 70,
            'code': 'WHOLESALE',
            'name': 'Wholesale',
            'retail_context_ids': [7],
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => {
        'store': {
          'id': storeId,
          'name': storeId == 8 ? 'Pharmacy' : 'Fresh Market',
        },
        'theme': {
          'code': storeId == 8 ? 'retail_pharmacy' : 'retail_grocery',
        },
        'branding': {'address': 'عنوان التوصيل'},
        'sections': [
          {'type': 'hero'},
          {'type': 'categories', 'title_ar': 'التصنيفات'},
          {'type': 'products', 'title_ar': 'منتجات مميزة'},
        ],
      };

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => {
        'store': {
          'id': storeId,
          'code': 'WHOLESALE-$storeId',
          'name': 'FOODEX Wholesale',
          'theme_code': 'wholesale_b2b',
        },
        'theme': {
          'code': 'wholesale_b2b',
          'primary': '#5D2A91',
          'primary_dark': '#35195E',
          'accent': '#B983F0',
          'background': '#FBFAFD',
        },
        'branding': {
          'address': 'تغطية توريد الجملة',
          'custom': {
            'brand_title_ar': 'FOODEX جملة',
            'brand_subtitle_ar': 'أفضل الأسعار لمتاجر التجزئة',
            'hero_cta_ar': 'تصفح الكتالوج',
          },
        },
        'hero': {
          'title': 'عرض الجملة',
          'image_url': null,
        },
        'sections': [
          {'key': 'hero', 'type': 'hero', 'sort_order': 10},
          {
            'key': 'categories',
            'type': 'categories',
            'title_ar': 'التصنيفات',
            'sort_order': 20,
          },
          {
            'key': 'offers',
            'type': 'offers',
            'title_ar': 'عروض الجملة',
            'sort_order': 30,
          },
        ],
      };

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async => {
        'store_id': storeId,
        'addresses': [
          {
            'id': 5,
            'label': 'المتجر',
            'line1': 'عنوان المتجر',
            'city': 'الإسكندرية',
          },
        ],
        'delivery_dates': ['2026-09-29'],
        'payment_methods': ['cash_on_delivery'],
      };
}

class _ResponsiveB2bApi implements B2bApi {
  const _ResponsiveB2bApi();

  @override
  Future<Object?> get(String path) async {
    if (path.contains('/products/42')) {
      return {
        'id': 42,
        'name': 'كرتونة مياه',
        'sku': 'WATER-42',
        'account_price': 72.5,
        'base_wholesale_price': 75,
        'retail_reference_price': 90,
        'minimum_order_quantity': 5,
        'ordering_increment': 5,
        'pack_size': 12,
        'available_quantity': 120,
      };
    }
    if (path.contains('/orders')) {
      return {
        'data': [
          {
            'id': 1001,
            'order_number': 'FDX-B2B-20261005-JV3MRNRDF',
            'status': 'pending',
            'store_name': 'FOODEX wholesale',
            'created_at': '2026-10-05T14:30:00Z',
            'currency': 'EGP',
            'grand_total': 1000,
          },
          {
            'id': 1002,
            'order_number': 'FDX-B2B-20261005-MOY92072Y6',
            'status': 'preparing',
            'store_name': 'FOODEX wholesale',
            'created_at': '2026-10-06T10:15:00Z',
            'currency': 'EGP',
            'grand_total': 480,
          },
        ],
      };
    }
    return {
      'data': [
        {
          'id': 42,
          'name': 'كرتونة مياه',
          'sku': 'WATER-42',
          'account_price': 72.5,
          'minimum_order_quantity': 5,
          'ordering_increment': 5,
          'pack_size': 12,
        },
        {
          'id': 43,
          'name': 'أرز جملة',
          'sku': 'RICE-43',
          'account_price': 150,
          'minimum_order_quantity': 2,
          'ordering_increment': 1,
          'pack_size': 10,
        },
      ],
    };
  }
}

class _ResponsiveWholesaleApi implements WholesaleCommerceApi {
  const _ResponsiveWholesaleApi();

  @override
  Future<Object?> cart(int storeId) async => {
        'store_id': storeId,
        'subtotal': 725,
        'items': [
          {
            'id': 1,
            'name': 'كرتونة مياه',
            'quantity': 10,
            'unit_price': 72.5,
            'line_total': 725,
            'minimum_order_quantity': 5,
            'ordering_increment': 5,
          },
        ],
      };

  @override
  Future<Object?> addItem(
    int storeId,
    int productId,
    double quantity,
  ) async =>
      cart(storeId);

  @override
  Future<Object?> updateItem(int itemId, double quantity) async =>
      {'id': itemId, 'quantity': quantity};

  @override
  Future<void> removeItem(int itemId) async {}

  @override
  Future<Object?> checkout({
    required int storeId,
    required int addressId,
    required String paymentMethod,
    String? requestedDeliveryDate,
    String? note,
    String? couponCode,
    required String idempotencyKey,
  }) async =>
      {'id': 1, 'status': 'pending'};
}

class _ResponsiveAccountApi implements B2cAccountApi {
  const _ResponsiveAccountApi();

  @override
  dynamic noSuchMethod(Invocation invocation) => Future<Object?>.value(null);
}

class _ResponsiveActionApi implements CustomerActionApi {
  const _ResponsiveActionApi();

  @override
  dynamic noSuchMethod(Invocation invocation) => Future<Object?>.value({});
}
