import 'dart:convert';
import 'package:http/http.dart' as http;

import '../../config.dart';

class AuthApi {
  Future<RequestOtpResult> requestOtp({required String email}) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.requestOtpUrl),
          headers: const {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({'email': email}),
        )
        .timeout(const Duration(seconds: 60));

    final body = res.body.trim();
    if (body.isEmpty) {
      throw Exception('Server returned empty response. Check Apache/PHP errors.');
    }

    dynamic decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      throw Exception('Server returned non-JSON:\n$body');
    }

    if (decoded is! Map) throw Exception('Invalid server response format.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Failed to request OTP').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }

    final dataRaw = decoded['data'];
    String? debugOtp;
    if (dataRaw is Map && dataRaw['debug_otp'] != null) {
      debugOtp = dataRaw['debug_otp'].toString();
    }

    return RequestOtpResult(message: msg, debugOtp: debugOtp);
  }

  Future<VerifyResult> verifyOtp({required String email, required String otp}) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.verifyOtpUrl),
          headers: const {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({'email': email, 'otp': otp}),
        )
        .timeout(const Duration(seconds: 30));

    final body = res.body.trim();
    if (body.isEmpty) {
      throw Exception('Server returned empty response. Check Apache/PHP errors.');
    }

    dynamic decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      throw Exception('Server returned non-JSON:\n$body');
    }

    if (decoded is! Map) throw Exception('Invalid server response format.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Failed to verify OTP').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }

    final dataRaw = decoded['data'];
    if (dataRaw is! Map) throw Exception('Missing data from server.');

    final data = dataRaw.cast<String, dynamic>();

    return VerifyResult(
      token: (data['token'] ?? '').toString(),
      studentId: (data['student_id'] ?? '').toString(),
      studentName: (data['student_name'] ?? '').toString(), // ✅ NEW
    );
  }
}

class RequestOtpResult {
  final String message;
  final String? debugOtp;

  RequestOtpResult({required this.message, this.debugOtp});
}

class VerifyResult {
  final String token;
  final String studentId;
  final String studentName; // ✅ NEW

  VerifyResult({
    required this.token,
    required this.studentId,
    required this.studentName,
  });
}