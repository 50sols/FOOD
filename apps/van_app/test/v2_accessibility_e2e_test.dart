import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_van_app/core/auth/van_session.dart';
import 'package:foodex_van_app/features/auth/van_login_screen.dart';

class _OfflineVanAuthRepository implements VanAuthRepository {
  @override
  Future<VanSession> login({required String email, required String password}) {
    throw StateError('The accessibility test must never submit credentials.');
  }

  @override
  Future<void> logout(String token) async {}
}

void main() {
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
      'Van login exposes accessible localized fields, focus, and validation in ${locale.languageCode}',
      (tester) async {
        final semantics = tester.ensureSemantics();

        await tester.pumpWidget(
          MaterialApp(
            locale: locale,
            supportedLocales: const [Locale('ar'), Locale('en')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            home: VanLoginScreen(
              repository: _OfflineVanAuthRepository(),
              onAuthenticated: (_, __, ___) async {},
            ),
          ),
        );
        await tester.pumpAndSettle();

        final email = find.byKey(const Key('van-login-email'));
        final password = find.byKey(const Key('van-login-password'));
        final submit = find.byKey(const Key('van-login-submit'));
        expect(email, findsOneWidget);
        expect(password, findsOneWidget);
        expect(submit, findsOneWidget);
        final expectedDirection =
            locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr;
        expect(Directionality.of(tester.element(email)), expectedDirection);
        expect(Directionality.of(tester.element(password)), expectedDirection);

        await tester.ensureVisible(email);
        await tester.pump();
        expect(
          tester.getSize(email).height,
          greaterThanOrEqualTo(48),
          reason: 'Van email must retain a 48dp touch target.',
        );
        final hint = tester.widget<TextField>(email).decoration?.hintText;
        expect(
          hint,
          locale.languageCode == 'ar' ? 'البريد الإلكتروني' : 'Email',
        );
        expect(find.bySemanticsLabel(hint!), findsWidgets);

        await tester.tap(email);
        await tester.pump();
        final editable = find.descendant(
          of: email,
          matching: find.byType(EditableText),
        );
        expect(
          tester.widget<EditableText>(editable).focusNode.hasFocus,
          isTrue,
        );

        await tester.ensureVisible(submit);
        await tester.pump();
        expect(tester.getSemantics(submit).label.trim(), isNotEmpty);
        expect(
          tester.getSize(submit).height,
          greaterThanOrEqualTo(48),
          reason: 'Van sign-in must retain a 48dp touch target.',
        );
        await tester.tap(submit);
        await tester.pump();
        expect(
          find.text(
            locale.languageCode == 'ar'
                ? 'البريد الإلكتروني مطلوب.'
                : 'Email is required.',
          ),
          findsWidgets,
        );
        expect(tester.widget<TextField>(password).obscureText, isTrue);
        await tester.ensureVisible(password);
        expect(
          tester.getSize(password).height,
          greaterThanOrEqualTo(48),
          reason: 'Van password must retain a 48dp touch target.',
        );
        await tester.tap(password);
        await tester.pump();
        final passwordInput = find.descendant(
          of: password,
          matching: find.byType(EditableText),
        );
        expect(
          tester.widget<EditableText>(passwordInput).focusNode.hasFocus,
          isTrue,
        );
        await tester.enterText(password, 'non-production-password');
        expect(
          tester.widget<EditableText>(passwordInput).controller.text,
          'non-production-password',
        );
        semantics.dispose();
      },
    );
  }

  for (final viewport in const [
    (size: Size(390, 844), scale: 1.5),
    (size: Size(320, 700), scale: 2.0),
  ]) {
    for (final locale in const [Locale('ar'), Locale('en')]) {
      testWidgets(
          'Van login remains accessible at ${viewport.scale}x text in ${locale.languageCode}',
          (tester) async {
        final semantics = tester.ensureSemantics();
        tester.view.physicalSize = viewport.size;
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = viewport.scale;
        addTearDown(() {
          tester.view.resetPhysicalSize();
          tester.view.resetDevicePixelRatio();
          tester.platformDispatcher.clearTextScaleFactorTestValue();
        });

        await tester.pumpWidget(
          MaterialApp(
            locale: locale,
            supportedLocales: const [Locale('ar'), Locale('en')],
            localizationsDelegates: const [
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            home: VanLoginScreen(
              repository: _OfflineVanAuthRepository(),
              onAuthenticated: (_, __, ___) async {},
            ),
          ),
        );
        await tester.pumpAndSettle();
        final email = find.byKey(const Key('van-login-email'));
        final submit = find.byKey(const Key('van-login-submit'));
        expect(email, findsOneWidget);
        expect(submit, findsOneWidget);
        expect(MediaQuery.textScalerOf(tester.element(email)).scale(16),
            greaterThan(16));
        expect(
            Directionality.of(tester.element(email)),
            locale.languageCode == 'ar'
                ? TextDirection.rtl
                : TextDirection.ltr);
        await tester.ensureVisible(email);
        await tester.pump();
        expect(tester.getSize(email).height, greaterThanOrEqualTo(48));
        final hint = tester.widget<TextField>(email).decoration!.hintText!;
        expect(find.bySemanticsLabel(hint), findsWidgets);
        await tester.ensureVisible(submit);
        await tester.pump();
        expect(tester.getSize(submit).height, greaterThanOrEqualTo(48));
        expect(tester.takeException(), isNull);
        semantics.dispose();
      });
    }
  }
}
