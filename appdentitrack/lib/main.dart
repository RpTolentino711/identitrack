import 'package:flutter/material.dart';
import 'src/screens/splash_screen.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const IdentiTrackApp());
}

class IdentiTrackApp extends StatelessWidget {
  const IdentiTrackApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'IdentiTrack',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF193B8C)),
      ),
      home: const SplashScreen(),
    );
  }
}