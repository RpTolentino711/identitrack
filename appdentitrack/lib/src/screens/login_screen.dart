import 'package:flutter/material.dart';
import 'package:pinput/pinput.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'services/auth_api.dart';
import 'dashboard_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  static const _secureStorage = FlutterSecureStorage();

  final _email = TextEditingController();
  final _pinController = TextEditingController();
  final _pinFocusNode = FocusNode();
  final _emailFocusNode = FocusNode();
  final _scrollController = ScrollController();
  final _api = AuthApi();

  String _otp = '';
  bool _loading = false;
  bool _otpSent = false;
  int _failedAttempts = 0;

  DateTime? _otpSentTime;
  bool _canResendOtp = false;
  int _resendCountdown = 0;

  // used to stop the countdown loop when leaving the screen
  int _timerToken = 0;

  static const blue = Color(0xFF193B8C);
  static const blueDark = Color(0xFF102B6B);

  @override
  void initState() {
    super.initState();
    // Auto-focus email field when screen loads
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _emailFocusNode.requestFocus();
    });
  }

  @override
  void dispose() {
    _timerToken++; // stop any running countdown
    _email.dispose();
    _pinController.dispose();
    _pinFocusNode.dispose();
    _emailFocusNode.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  bool _isValidEmail(String v) =>
      RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v.trim());

  void _snack(String msg) {
    ScaffoldMessenger.of(context)
      ..clearSnackBars()
      ..showSnackBar(
        SnackBar(
          content: Text(msg),
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          margin: const EdgeInsets.all(16),
        ),
      );
  }

  void _showOtpAndFocus() {
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;

      if (_scrollController.hasClients) {
        await _scrollController.animateTo(
          _scrollController.position.maxScrollExtent,
          duration: const Duration(milliseconds: 280),
          curve: Curves.easeOut,
        );
      }

      if (mounted) _pinFocusNode.requestFocus();
    });
  }

  void _startOtpTimer() {
    _timerToken++;
    final myToken = _timerToken;

    Future<void> tick() async {
      await Future.delayed(const Duration(seconds: 1));
      if (!mounted || myToken != _timerToken) return;

      setState(() {
        if (_resendCountdown > 0) {
          _resendCountdown--;
          if (_resendCountdown == 0) _canResendOtp = true;
        }
      });

      if (_resendCountdown > 0) {
        tick();
      }
    }

    tick();
  }

  Future<void> _sendOtp() async {
    final email = _email.text.trim();
    if (!_isValidEmail(email)) {
      _snack('Please enter a valid email.');
      return;
    }
    if (_loading) return;

    _emailFocusNode.unfocus();
    setState(() => _loading = true);

    try {
      final otpRes = await _api.requestOtp(email: email);
      if (!mounted) return;

      setState(() {
        _otpSent = true;
        _otp = '';
        _pinController.clear();
        _failedAttempts = 0;

        _otpSentTime = DateTime.now();
        _canResendOtp = false;
        _resendCountdown = 300; // 5 minutes
      });

      _showOtpAndFocus();
      if (otpRes.debugOtp != null && otpRes.debugOtp!.isNotEmpty) {
        _snack('DEV OTP for $email: ${otpRes.debugOtp}');
      } else {
        _snack('OTP sent to $email');
      }
      _startOtpTimer();
    } catch (e) {
      final msg = e.toString().replaceFirst('Exception: ', '');
      if (!mounted) return;

      if (msg.toLowerCase().contains('not found') ||
          msg.toLowerCase().contains('not registered')) {
        _snack('Account not found. Please register first.');
      } else {
        _snack(msg);
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _verifyOtp() async {
    final email = _email.text.trim();
    if (!_isValidEmail(email)) {
      _snack('Please enter a valid email.');
      return;
    }
    if (_otp.length != 6) return;
    if (_loading) return;

    // OTP validity window check (5 mins)
    if (_otpSentTime != null) {
      final difference = DateTime.now().difference(_otpSentTime!);
      if (difference.inMinutes >= 5) {
        _snack('OTP has expired. Please request a new one.');
        return;
      }
    }

    setState(() => _loading = true);
    try {
      final res = await _api.verifyOtp(email: email, otp: _otp);
      if (!mounted) return;

      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('student_id', res.studentId);
      await prefs.setString('student_name', res.studentName);
      await _secureStorage.write(key: 'otp_token', value: res.token);

      _pinController.clear();

      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => DashboardScreen(
            studentId: res.studentId,
            studentName: res.studentName,
          ),
        ),
      );
    } catch (e) {
      final msg = e.toString().replaceFirst('Exception: ', '');
      if (!mounted) return;

      if (msg.toLowerCase().contains('invalid otp') ||
          msg.toLowerCase().contains('incorrect') ||
          msg.toLowerCase().contains('wrong')) {
        _failedAttempts++;
        _snack('Wrong OTP. Please try again.');
      } else {
        _snack(msg);
      }

      _pinController.clear();
      _otp = '';
      _pinFocusNode.requestFocus();
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Widget _card({Key? key, required Widget child}) => Container(
    key: key,
    width: double.infinity,
    padding: const EdgeInsets.all(20),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      boxShadow: [
        BoxShadow(
          color: Colors.black.withValues(alpha: 0.07),
          blurRadius: 24,
          offset: const Offset(0, 8),
        ),
      ],
    ),
    child: child,
  );

  Widget _stepBadge(String label, {bool done = false}) => AnimatedContainer(
    duration: const Duration(milliseconds: 250),
    width: 28,
    height: 28,
    decoration: BoxDecoration(
      color: done ? Colors.green : blueDark,
      shape: BoxShape.circle,
    ),
    child: Center(
      child: done
          ? const Icon(Icons.check_rounded, color: Colors.white, size: 16)
          : Text(
              label,
              style: const TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.w800,
                fontSize: 13,
              ),
            ),
    ),
  );

  Widget _switcher({required Widget child}) => AnimatedSwitcher(
    duration: const Duration(milliseconds: 300),
    switchInCurve: Curves.easeOut,
    switchOutCurve: Curves.easeIn,
    transitionBuilder: (child, animation) => FadeTransition(
      opacity: animation,
      child: SizeTransition(
        sizeFactor: animation,
        axisAlignment: -1,
        child: child,
      ),
    ),
    child: child,
  );

  @override
  Widget build(BuildContext context) {
    final emailText = _email.text.trim();
    final otpHint = emailText.isEmpty ? 'your email' : emailText;

    final defaultPinTheme = PinTheme(
      width: 50,
      height: 56,
      textStyle: const TextStyle(
        fontSize: 22,
        fontWeight: FontWeight.w800,
        color: blueDark,
      ),
      decoration: BoxDecoration(
        color: const Color(0xFFF0F2FA),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: Colors.transparent, width: 1.6),
      ),
    );

    final focusedPinTheme = defaultPinTheme.copyWith(
      decoration: defaultPinTheme.decoration!.copyWith(
        color: Colors.white,
        border: Border.all(color: blueDark, width: 1.8),
        boxShadow: [
          BoxShadow(
            color: blueDark.withValues(alpha: 0.12),
            blurRadius: 8,
            offset: const Offset(0, 3),
          ),
        ],
      ),
    );

    final submittedPinTheme = defaultPinTheme.copyWith(
      decoration: defaultPinTheme.decoration!.copyWith(
        color: blueDark.withValues(alpha: 0.08),
        border: Border.all(color: blueDark.withValues(alpha: 0.4), width: 1.4),
      ),
    );

    return Scaffold(
      resizeToAvoidBottomInset: true,
      backgroundColor: blue,
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 28),
              child: Image.asset(
                'lib/assets/logo.png',
                width: 100,
                height: 100,
              ),
            ),
            Expanded(
              child: Container(
                width: double.infinity,
                decoration: const BoxDecoration(
                  color: Color(0xFFF5F6FB),
                  borderRadius: BorderRadius.only(
                    topLeft: Radius.circular(32),
                    topRight: Radius.circular(32),
                  ),
                ),
                child: SingleChildScrollView(
                  controller: _scrollController,
                  keyboardDismissBehavior:
                      ScrollViewKeyboardDismissBehavior.onDrag,
                  padding: const EdgeInsets.fromLTRB(20, 28, 20, 40),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Login',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w900,
                          color: blue,
                          letterSpacing: -0.5,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        _otpSent
                            ? 'Enter the OTP sent to your email'
                            : 'Sign in with your student email',
                        style: TextStyle(
                          fontSize: 13.5,
                          color: Colors.grey.shade600,
                        ),
                      ),
                      const SizedBox(height: 20),
                      _switcher(
                        child: !_otpSent
                            ? _card(
                                key: const ValueKey('email-form'),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        _stepBadge('1'),
                                        const SizedBox(width: 10),
                                        const Text(
                                          'Your email',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 15,
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 14),
                                    TextField(
                                      controller: _email,
                                      focusNode: _emailFocusNode,
                                      keyboardType: TextInputType.emailAddress,
                                      textInputAction: TextInputAction.done,
                                      onSubmitted: (_) =>
                                          _loading ? null : _sendOtp(),
                                      decoration: InputDecoration(
                                        labelText: 'Email address',
                                        hintText:
                                            'example@student.nu-lipa.edu.ph',
                                        filled: true,
                                        fillColor: const Color(0xFFF0F2FA),
                                        prefixIcon: const Icon(
                                          Icons.email_outlined,
                                          size: 20,
                                        ),
                                        border: OutlineInputBorder(
                                          borderRadius: BorderRadius.circular(
                                            14,
                                          ),
                                          borderSide: BorderSide.none,
                                        ),
                                        enabledBorder: OutlineInputBorder(
                                          borderRadius: BorderRadius.circular(
                                            14,
                                          ),
                                          borderSide: BorderSide.none,
                                        ),
                                        focusedBorder: OutlineInputBorder(
                                          borderRadius: BorderRadius.circular(
                                            14,
                                          ),
                                          borderSide: const BorderSide(
                                            color: blueDark,
                                            width: 1.6,
                                          ),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(height: 14),
                                    SizedBox(
                                      width: double.infinity,
                                      height: 50,
                                      child: ElevatedButton(
                                        style: ElevatedButton.styleFrom(
                                          backgroundColor: blueDark,
                                          foregroundColor: Colors.white,
                                          elevation: 0,
                                          shape: RoundedRectangleBorder(
                                            borderRadius: BorderRadius.circular(
                                              14,
                                            ),
                                          ),
                                        ),
                                        onPressed: _loading ? null : _sendOtp,
                                        child: _loading
                                            ? const SizedBox(
                                                height: 20,
                                                width: 20,
                                                child:
                                                    CircularProgressIndicator(
                                                      strokeWidth: 2.4,
                                                      color: Colors.white,
                                                    ),
                                              )
                                            : const Text(
                                                'SEND OTP',
                                                style: TextStyle(
                                                  fontWeight: FontWeight.w900,
                                                  letterSpacing: 1,
                                                ),
                                              ),
                                      ),
                                    ),
                                  ],
                                ),
                              )
                            : _card(
                                key: const ValueKey('otp-card'),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        _stepBadge('2'),
                                        const SizedBox(width: 10),
                                        const Text(
                                          'Enter OTP',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w800,
                                            fontSize: 15,
                                          ),
                                        ),
                                      ],
                                    ),
                                    const SizedBox(height: 6),
                                    Text(
                                      'Sent to $otpHint',
                                      style: TextStyle(
                                        fontSize: 12.5,
                                        color: Colors.grey.shade600,
                                      ),
                                    ),
                                    const SizedBox(height: 20),
                                    Center(
                                      child: Pinput(
                                        length: 6,
                                        controller: _pinController,
                                        focusNode: _pinFocusNode,
                                        enabled: !_loading,
                                        keyboardType: TextInputType.number,
                                        defaultPinTheme: defaultPinTheme,
                                        focusedPinTheme: focusedPinTheme,
                                        submittedPinTheme: submittedPinTheme,
                                        hapticFeedbackType:
                                            HapticFeedbackType.lightImpact,
                                        onChanged: (v) {
                                          _otp = v;
                                          if (v.length == 6 && !_loading)
                                            _verifyOtp();
                                        },
                                        validator: (value) {
                                          final v = (value ?? '').trim();
                                          if (!RegExp(r'^\d{0,6}$').hasMatch(v))
                                            return 'Digits only';
                                          return null;
                                        },
                                      ),
                                    ),
                                    const SizedBox(height: 16),
                                    if (_loading)
                                      Center(
                                        child: SizedBox(
                                          width: 24,
                                          height: 24,
                                          child: CircularProgressIndicator(
                                            strokeWidth: 2.4,
                                            color: blueDark,
                                          ),
                                        ),
                                      ),
                                    const SizedBox(height: 8),
                                    Center(
                                      child: _canResendOtp
                                          ? TextButton.icon(
                                              onPressed: _loading
                                                  ? null
                                                  : _sendOtp,
                                              icon: const Icon(
                                                Icons.refresh_rounded,
                                                size: 16,
                                              ),
                                              label: const Text('Resend OTP'),
                                              style: TextButton.styleFrom(
                                                foregroundColor:
                                                    Colors.grey.shade600,
                                                textStyle: const TextStyle(
                                                  fontSize: 13,
                                                ),
                                              ),
                                            )
                                          : Text(
                                              'Resend available in ${_resendCountdown ~/ 60}:${(_resendCountdown % 60).toString().padLeft(2, '0')}',
                                              style: TextStyle(
                                                color: Colors.grey.shade500,
                                                fontSize: 13,
                                              ),
                                            ),
                                    ),
                                    if (_failedAttempts >= 3) ...[
                                      const SizedBox(height: 12),
                                      Center(
                                        child: TextButton.icon(
                                          onPressed: () {
                                            setState(() {
                                              _otpSent = false;
                                              _failedAttempts = 0;
                                            });
                                          },
                                          icon: const Icon(
                                            Icons.arrow_back_rounded,
                                            size: 16,
                                          ),
                                          label: const Text(
                                            'Change Email / Go Back',
                                          ),
                                          style: TextButton.styleFrom(
                                            foregroundColor: blueDark,
                                            textStyle: const TextStyle(
                                              fontSize: 13,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                        ),
                                      ),
                                    ],
                                  ],
                                ),
                              ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
