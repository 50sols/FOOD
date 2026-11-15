import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:foodex_customer_app/app.dart';
import 'package:foodex_customer_app/core/routing/customer_routes.dart';

void main() {
  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
        'Customer diagnostics has a screen-reader label and usable note input in ${locale.languageCode}',
        (tester) async {
      final semantics = tester.ensureSemantics();

      await tester.pumpWidget(FoodexCustomerApp(
        locale: locale,
        initialRoute: CustomerRoutePaths.diagnostics,
        translationFetcher: (_) async => <String, String>{},
      ));
      await tester.pumpAndSettle();

      final note = find.byKey(const ValueKey('customer-diagnostics-note'));
      expect(note, findsOneWidget);
      expect(
        Directionality.of(tester.element(note)),
        locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr,
      );
      await tester.ensureVisible(note);
      await tester.pump();
      expect(
        tester.getSize(note).height,
        greaterThanOrEqualTo(44),
        reason: 'Customer note input must retain a 44dp touch target.',
      );

      final field = tester.widget<TextField>(note);
      final label = field.decoration?.labelText;
      expect(label, isNotNull);
      expect(label!.trim(), isNotEmpty);
      expect(RegExp(r'[\u0600-\u06ff]').hasMatch(label),
          locale.languageCode == 'ar');
      final node = tester.getSemantics(note);
      expect('${node.label} ${node.hint}', contains(label));

      await tester.tap(note);
      await tester.pump();
      final input =
          find.descendant(of: note, matching: find.byType(EditableText));
      expect(tester.widget<EditableText>(input).focusNode.hasFocus, isTrue);
      await tester.enterText(note, 'FOODEX accessibility fixture');
      expect(find.text('FOODEX accessibility fixture'), findsOneWidget);

      final export = find.byKey(const ValueKey('customer-diagnostics-export'));
      await tester.ensureVisible(export);
      await tester.pump();
      expect(
        tester.getSize(export).height,
        greaterThanOrEqualTo(44),
        reason: 'Customer export must retain a 44dp touch target.',
      );
      expect(tester.widget<FilledButton>(export).onPressed, isNotNull);
      expect(tester.getSemantics(export).label.trim(), isNotEmpty);
      semantics.dispose();
    });
  }

  for (final locale in const [Locale('ar'), Locale('en')]) {
    testWidgets(
        'Customer diagnostics remains accessible at 1.5x text in ${locale.languageCode}',
        (tester) async {
      final semantics = tester.ensureSemantics();
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      tester.platformDispatcher.textScaleFactorTestValue = 1.5;
      addTearDown(() {
        tester.view.resetPhysicalSize();
        tester.view.resetDevicePixelRatio();
        tester.platformDispatcher.clearTextScaleFactorTestValue();
      });

      await tester.pumpWidget(FoodexCustomerApp(
        locale: locale,
        initialRoute: CustomerRoutePaths.diagnostics,
        translationFetcher: (_) async => <String, String>{},
      ));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull,
          reason: 'Diagnostics should render at mobile text scale.');
      final note = find.byKey(const ValueKey('customer-diagnostics-note'));
      await tester.scrollUntilVisible(note, 180,
          scrollable: find.byType(Scrollable).last);
      await tester.pumpAndSettle();
      expect(note, findsOneWidget);
      expect(MediaQuery.textScalerOf(tester.element(note)).scale(16),
          greaterThan(16));
      expect(Directionality.of(tester.element(note)),
          locale.languageCode == 'ar' ? TextDirection.rtl : TextDirection.ltr);
      await tester.ensureVisible(note);
      await tester.pump();
      expect(tester.getSize(note).height, greaterThanOrEqualTo(44));
      final label = tester.widget<TextField>(note).decoration!.labelText!;
      expect(tester.getSemantics(note).label, contains(label));
      expect(tester.takeException(), isNull);
      semantics.dispose();
    });
  }
}
