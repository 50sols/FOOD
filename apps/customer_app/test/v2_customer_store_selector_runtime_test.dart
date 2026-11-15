import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:foodex_customer_app/core/api/b2c_account_api.dart';
import 'package:foodex_customer_app/core/api/b2c_catalog_api.dart';
import 'package:foodex_customer_app/core/api/storefront_api.dart';
import 'package:foodex_customer_app/core/auth/customer_session.dart';
import 'package:foodex_customer_app/core/localization/app_translations.dart';
import 'package:foodex_customer_app/features/storefront/retail_multistore_screens.dart';

void main() {
  for (final locale in const <Locale>[Locale('ar'), Locale('en')]) {
    testWidgets(
        'signed-in store selector shows only server-authorized Retail '
        'in ${locale.languageCode} and keeps the exact route', (tester) async {
      await tester.binding.setSurfaceSize(const Size(440, 940));
      addTearDown(() => tester.binding.setSurfaceSize(null));

      await tester.pumpWidget(_harness(
        locale: locale,
        api: const _SelectorApi({
          'entitlements': {
            'direct_b2b': false,
            'retail_context_ids': <int>[],
            'principal_wholesale_store_id': null,
          },
          'retail_stores': [
            {'id': 22, 'name': 'Retail Twenty Two'},
          ],
          'wholesale_stores': <Object>[],
        }),
      ));
      await tester.pumpAndSettle();

      expect(
          find.text(
              locale.languageCode == 'ar' ? 'اختر المتجر' : 'Choose a store'),
          findsOneWidget);
      expect(find.byKey(const ValueKey('b2c-store-22')), findsOneWidget);
      expect(
          find.text(
              locale.languageCode == 'ar' ? 'متجر الجملة' : 'Wholesale store'),
          findsNothing);

      await tester.tap(find.byKey(const ValueKey('b2c-store-22')));
      await tester.pumpAndSettle();

      expect(
        find.text('/retail/22/home?channel=retail&store_id=22'),
        findsOneWidget,
      );
    });
  }

  testWidgets(
      'Wholesale selector shows only principal and navigates with '
      'explicit channel and store identity', (tester) async {
    await tester.binding.setSurfaceSize(const Size(440, 940));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(_harness(
      locale: const Locale('en'),
      api: const _SelectorApi({
        'entitlements': {
          'direct_b2b': true,
          'principal_wholesale_store_id': 7,
          'retail_context_ids': <int>[],
        },
        'retail_stores': <Object>[],
        'wholesale_stores': [
          {
            'id': 7,
            'is_platform_principal': true,
            'retail_context_ids': <int>[],
          },
          {'id': 8, 'is_platform_principal': false},
        ],
      }),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Wholesale store'), findsOneWidget);
    expect(find.text('Authorized buyers only'), findsOneWidget);
    await tester.tap(find.text('Wholesale store'));
    await tester.pumpAndSettle();
    expect(
      find.text('/b2b/home?channel=wholesale&store_id=7'),
      findsOneWidget,
    );
  });

  testWidgets(
      'malformed selector rows present localized error, not a '
      'partial unauthorized store list', (tester) async {
    await tester.binding.setSurfaceSize(const Size(440, 940));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(_harness(
      locale: const Locale('en'),
      api: const _SelectorApi({
        'entitlements': {
          'direct_b2b': false,
          'retail_context_ids': <int>[],
        },
        'retail_stores': [
          {'id': 22, 'name': 'Valid Store'},
          {'id': 22, 'name': 'Forged Duplicate'},
        ],
        'wholesale_stores': <Object>[],
      }),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Could not load available stores.'), findsOneWidget);
    expect(find.byKey(const ValueKey('b2c-store-22')), findsNothing);
    expect(find.text('Valid Store'), findsNothing);
    expect(find.text('Forged Duplicate'), findsNothing);
  });
}

Widget _harness({
  required Locale locale,
  required StorefrontApi api,
}) =>
    AppTranslations(
      locale: locale,
      overrides: const {},
      child: MaterialApp(
        locale: locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        home: StoreSelectionDesignScreen(
          catalogApi: _FakeCatalogApi(),
          accountApi: _FakeAccountApi(),
          storefrontApi: api,
          session: const CustomerSession.platformCustomer(
            accessToken: 'selector-session-token',
          ),
          enterWholesale: (_) {},
        ),
        onGenerateRoute: (settings) => MaterialPageRoute<void>(
          settings: settings,
          builder: (_) => Scaffold(
            body: Center(child: Text(settings.name ?? '')),
          ),
        ),
      ),
    );

class _FakeAccountApi extends Fake implements B2cAccountApi {
  @override
  Future<Object?> addresses() async => const <String, Object?>{
        'data': <Object>[],
      };
}

class _FakeCatalogApi extends Fake implements B2cCatalogApi {}

class _SelectorApi implements StorefrontApi {
  const _SelectorApi(this.payload);

  final Map<String, dynamic> payload;

  @override
  Future<Map<String, dynamic>> selection({
    String? countryCode,
    String? city,
    String? area,
    bool support = false,
  }) async =>
      payload;

  @override
  Future<Map<String, dynamic>> retailHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> wholesaleHome(int storeId) async => const {};

  @override
  Future<Map<String, dynamic>> b2bCheckoutOptions(int storeId) async =>
      const {};
}
