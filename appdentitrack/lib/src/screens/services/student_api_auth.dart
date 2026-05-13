import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class StudentApiAuth {
  static const _storage = FlutterSecureStorage();

  static Future<String> token() async {
    return await _storage.read(key: 'otp_token') ?? '';
  }

  static Future<Map<String, String>> jsonHeaders() async {
    final headers = <String, String>{
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };

    final token = await StudentApiAuth.token();
    if (token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    return headers;
  }

  static Future<Map<String, String>> multipartHeaders() async {
    final headers = <String, String>{'Accept': 'application/json'};

    final token = await StudentApiAuth.token();
    if (token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    return headers;
  }
}
