import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_driver_app/app.dart';

void main() {
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
      'Driver login exposes localized semantics and keyboard-editable credentials in ${locale.languageCode}',
      (tester) async {
        final semantics = tester.ensureSemantics();

        // A non-production base makes the form available; no HTTP operation is sent.
        await tester.pumpWidget(
          FoodexDriverApp(
            locale: locale,
            apiBaseUrl: 'https://example.invalid',
            translationFetcher: (_) async => <String, String>{},
          ),
        );
        await tester.pumpAndSettle();

        final email = find.byKey(const Key('driver-login-email'));
        final password = find.byKey(const Key('driver-login-password'));
        expect(email, findsOneWidget);
        expect(password, findsOneWidget);
        final expectedDirection =
            locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr;
        expect(Directionality.of(tester.element(email)), expectedDirection);
        expect(Directionality.of(tester.element(password)), expectedDirection);
        await tester.ensureVisible(email);
        await tester.pump();
        expect(
          tester.getSize(email).height,
          greaterThanOrEqualTo(44),
          reason: 'Driver email must retain a 44dp touch target.',
        );

        final emailLabel =
            tester.widget<TextField>(email).decoration?.labelText;
        expect(emailLabel, isNotNull);
        expect(emailLabel!.trim(), isNotEmpty);
        expect(
          RegExp(r'[\u0600-\u06ff]').hasMatch(emailLabel),
          locale.languageCode == 'ar',
        );
        expect(find.bySemanticsLabel(emailLabel), findsWidgets);

        await tester.tap(email);
        await tester.pump();
        final emailInput = find.descendant(
          of: email,
          matching: find.byType(EditableText),
        );
        expect(
          tester.widget<EditableText>(emailInput).focusNode.hasFocus,
          isTrue,
        );
        await tester.enterText(email, 'driver@example.test');
        expect(find.text('driver@example.test'), findsOneWidget);

        await tester.ensureVisible(password);
        await tester.pump();
        expect(
          tester.getSize(password).height,
          greaterThanOrEqualTo(44),
          reason: 'Driver password must retain a 44dp touch target.',
        );
        final passwordLabel =
            tester.widget<TextField>(password).decoration?.labelText;
        expect(passwordLabel, isNotNull);
        expect(passwordLabel!.trim(), isNotEmpty);
        expect(find.bySemanticsLabel(passwordLabel), findsWidgets);
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
        expect(tester.widget<TextField>(password).obscureText, isTrue);
        await tester.enterText(password, 'non-production-password');
        expect(
          tester.widget<EditableText>(passwordInput).controller.text,
          'non-production-password',
        );

        final submit = find.byKey(const Key('driver-login-submit'));
        await tester.ensureVisible(submit);
        await tester.pump();
        expect(
          tester.getSize(submit).height,
          greaterThanOrEqualTo(44),
          reason: 'Driver sign-in must retain a 44dp touch target.',
        );
        expect(tester.widget<FilledButton>(submit).onPressed, isNotNull);
        expect(tester.getSemantics(submit).label.trim(), isNotEmpty);
        semantics.dispose();
      },
    );
  }
}
