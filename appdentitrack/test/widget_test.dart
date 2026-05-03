import 'package:flutter_test/flutter_test.dart';
import 'package:identitrack_app/main.dart';

void main() {
  testWidgets('App builds', (WidgetTester tester) async {
    await tester.pumpWidget(const IdentiTrackApp());
    await tester.pumpAndSettle();

    expect(find.byType(IdentiTrackApp), findsOneWidget);
  });
}