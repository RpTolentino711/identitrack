import 'dart:convert';
import 'package:http/http.dart' as http;

import '../../config.dart';
import 'student_api_auth.dart';

class StudentProfile {
  final String studentId;
  final String studentFn;
  final String studentLn;
  final String studentEmail;
  final String phoneNumber;
  final String guardianFn;
  final String guardianLn;
  final String guardianEmail;
  final String guardianNumber;

  StudentProfile({
    required this.studentId,
    required this.studentFn,
    required this.studentLn,
    required this.studentEmail,
    required this.phoneNumber,
    required this.guardianFn,
    required this.guardianLn,
    required this.guardianEmail,
    required this.guardianNumber,
  });

  factory StudentProfile.fromJson(Map<String, dynamic> json) {
    return StudentProfile(
      studentId: (json['student_id'] ?? '').toString(),
      studentFn: (json['student_fn'] ?? '').toString(),
      studentLn: (json['student_ln'] ?? '').toString(),
      studentEmail: (json['student_email'] ?? '').toString(),
      phoneNumber: (json['phone_number'] ?? '').toString(),
      guardianFn: (json['guardian_fn'] ?? '').toString(),
      guardianLn: (json['guardian_ln'] ?? '').toString(),
      guardianEmail: (json['guardian_email'] ?? '').toString(),
      guardianNumber: (json['guardian_number'] ?? '').toString(),
    );
  }
}

class ProfileApi {
  Future<StudentProfile> getProfile(String studentId) async {
    final headers = await StudentApiAuth.jsonHeaders();
    final res = await http.post(
      Uri.parse(AppConfig.profileUrl),
      headers: headers,
      body: jsonEncode({
        'student_id': studentId,
        'action': 'get',
      }),
    );

    final body = res.body.trim();
    if (body.isEmpty) throw Exception('Server returned empty response.');

    dynamic decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      throw Exception('Server returned non-JSON:\n$body');
    }

    if (decoded is! Map) throw Exception('Invalid server response format.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Failed to load profile.').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }

    final dataRaw = decoded['data'];
    if (dataRaw is! Map) throw Exception('Missing profile data from server.');

    return StudentProfile.fromJson(dataRaw.cast<String, dynamic>());
  }

  Future<void> updateProfile({
    required String studentId,
    required String phoneNumber,
    required String guardianFn,
    required String guardianLn,
    required String guardianEmail,
    required String guardianNumber,
  }) async {
    final headers = await StudentApiAuth.jsonHeaders();
    final res = await http.post(
      Uri.parse(AppConfig.profileUrl),
      headers: headers,
      body: jsonEncode({
        'student_id': studentId,
        'phone_number': phoneNumber,
        'guardian_fn': guardianFn,
        'guardian_ln': guardianLn,
        'guardian_email': guardianEmail,
        'guardian_number': guardianNumber,
      }),
    );

    final body = res.body.trim();
    if (body.isEmpty) throw Exception('Server returned empty response.');

    dynamic decoded;
    try {
      decoded = jsonDecode(body);
    } catch (_) {
      throw Exception('Server returned non-JSON:\n$body');
    }

    if (decoded is! Map) throw Exception('Invalid server response format.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Failed to update profile.').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }
  }
}
