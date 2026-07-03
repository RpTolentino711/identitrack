import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'login_screen.dart';
import 'dashboard_screen.dart';


class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _fadeIn;
  late final Animation<double> _scaleIn;
  late final Animation<double> _scaleOut;
  late final Animation<double> _fadeOut;

  static const blue = Color(0xFF193B8C);

  @override
  void initState() {
    super.initState();

    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 2500),
    );

    _fadeIn = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(
        parent: _ctrl,
        curve: const Interval(0.0, 0.5, curve: Curves.easeInOutCubic),
      ),
    );

    _scaleIn = Tween<double>(begin: 0.8, end: 1.0).animate(
      CurvedAnimation(
        parent: _ctrl,
        curve: const Interval(0.0, 0.5, curve: Curves.easeOutCubic),
      ),
    );

    _scaleOut = Tween<double>(begin: 1.0, end: 0.9).animate(
      CurvedAnimation(
        parent: _ctrl,
        curve: const Interval(0.7, 1.0, curve: Curves.easeInOutCubic),
      ),
    );

    _fadeOut = Tween<double>(begin: 1.0, end: 0.0).animate(
      CurvedAnimation(
        parent: _ctrl,
        curve: const Interval(0.7, 1.0, curve: Curves.easeOut),
      ),
    );

    // Start the animation sequence
    _ctrl.forward();

    // Navigate to dashboard if logged in, otherwise login screen after animation completes
    _ctrl.addStatusListener((status) async {
      if (status == AnimationStatus.completed) {
        if (!mounted) return;

        try {
          final prefs = await SharedPreferences.getInstance();
          final studentId = prefs.getString('student_id') ?? '';
          final studentName = prefs.getString('student_name') ?? '';

          const secureStorage = FlutterSecureStorage();
          final token = await secureStorage.read(key: 'otp_token') ?? '';

          if (studentId.isNotEmpty && token.isNotEmpty) {
            if (!mounted) return;
            Navigator.of(context).pushReplacement(
              MaterialPageRoute(
                builder: (_) => DashboardScreen(
                  studentId: studentId,
                  studentName: studentName,
                ),
              ),
            );
            return;
          }
        } catch (e) {
          debugPrint('Error checking login session: $e');
        }

        if (!mounted) return;
        Navigator.of(context).pushReplacement(
          PageRouteBuilder(
            transitionDuration: const Duration(milliseconds: 300),
            pageBuilder: (context, animation, secondaryAnimation) => FadeTransition(
              opacity: animation,
              child: const LoginScreen(),
            ),
          ),
        );
      }
    });
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: blue,
      body: SafeArea(
        child: AnimatedBuilder(
          animation: _ctrl,
          builder: (context, child) {
            // Calculate current opacity and scale
            double opacity;
            double scale;

            if (_ctrl.value < 0.6) {
              // First phase: fade in and scale up
              opacity = _fadeIn.value;
              scale = _scaleIn.value;
            } else {
              // Second phase: fade out and scale down
              opacity = _fadeOut.value;
              scale = _scaleOut.value;
            }

            return Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.center,
                children: [
                  // Animated logo centered
                  Transform.scale(
                    scale: scale,
                    child: Opacity(
                      opacity: opacity,
                      child: Image.asset(
                        'lib/assets/logo.png',
                        width: 130,
                        height: 130,
                      ),
                    ),
                  ),

                  const SizedBox(height: 24),

                  // School name fade-in (only during first phase)
                  if (_ctrl.value < 0.6)
                    Opacity(
                      opacity: _fadeIn.value,
                      child: Column(
                        children: [
                          const Text(
                            'National University',
                            style: TextStyle(
                              color: Colors.white,
                              fontSize: 20,
                              fontWeight: FontWeight.w900,
                              letterSpacing: 0.4,
                            ),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Lipa',
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.75),
                              fontSize: 15,
                              fontWeight: FontWeight.w600,
                              letterSpacing: 1.2,
                            ),
                          ),
                        ],
                      ),
                    ),

                  const SizedBox(height: 24),

                  // Bottom loading indicator (only during first phase)
                  if (_ctrl.value < 0.6)
                    Opacity(
                      opacity: _fadeIn.value,
                      child: SizedBox(
                        width: 24,
                        height: 24,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.2,
                          color: Colors.white.withValues(alpha: 0.5),
                        ),
                      ),
                    ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}