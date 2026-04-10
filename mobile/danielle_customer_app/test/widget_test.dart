import 'package:danielle_customer_app/main.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('Splash screen renders', (WidgetTester tester) async {
    await tester.pumpWidget(const MaterialApp(home: SplashScreen()));

    expect(find.text('Kundenportal wird vorbereitet...'), findsOneWidget);
    expect(find.text('DANIELLE Kundenportal'), findsOneWidget);
  });
}
