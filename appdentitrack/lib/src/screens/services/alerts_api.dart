import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

import '../../config.dart';
import 'student_api_auth.dart';

class AlertsApi {
  Future<List<StudentAlert>> getAlerts(String studentId) async {
    final headers = await StudentApiAuth.jsonHeaders();
    final res = await http.post(
      Uri.parse(AppConfig.alertsUrl),
      headers: headers,
      body: jsonEncode({'student_id': studentId}),
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
    final msg = (decoded['message'] ?? 'Failed to load alerts.').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }

    final dataRaw = decoded['data'];
    if (dataRaw is! List) throw Exception('Missing data from server.');

    return dataRaw
        .map((e) => StudentAlert.fromJson((e as Map<String, dynamic>)))
        .toList();
  }
}

class StudentAlert {
  final String alertType;
  final String title;
  final String message;
  final String createdAt;
  final Map<String, dynamic>?
  metadata; // for storing extra data like offense_count, category, etc.

  StudentAlert({
    required this.alertType,
    required this.title,
    required this.message,
    required this.createdAt,
    this.metadata,
  });

  factory StudentAlert.fromJson(Map<String, dynamic> json) => StudentAlert(
    alertType: (json['alert_type'] ?? 'INFO').toString(),
    title: (json['title'] ?? 'Notification').toString(),
    message: (json['message'] ?? '').toString(),
    createdAt: (json['created_at'] ?? '').toString(),
    metadata: json['metadata'] is Map
        ? json['metadata'] as Map<String, dynamic>
        : null,
  );

  String get badgeLabel {
    switch (alertType) {
      case 'GUARDIAN_ALERT':
        return 'Guardian Alert';
      case 'UPCC_DECISION':
        return 'UPCC Decision';
      case 'UPCC_CASE_DECISION':
        return 'UPCC Case Decision';
      case 'APPEAL_SUBMITTED':
        return 'Appeal Submitted';
      case 'APPEAL_RESPONSE':
        return 'Appeal Response';
      case 'OFFENSE_RECORDED':
        return 'Offense Recorded';
      case 'HEARING_SCHEDULE':
        return 'Hearing Schedule';
      case 'HEARING_REMINDER':
        return 'Hearing Reminder';
      case 'SERVICE_ACTIVE':
        return 'Timer Running';
      case 'SERVICE_LOGGED_OUT':
        return 'Logged Out';
      default:
        return 'Notification';
    }
  }

  Color get badgeColor {
    switch (alertType) {
      case 'GUARDIAN_ALERT':
        return const Color(0xFFE8470B); // Orange
      case 'UPCC_DECISION':
        return const Color(0xFF193B8C); // Blue
      case 'UPCC_CASE_DECISION':
        return const Color(0xFF4A148C); // Deep purple
      case 'APPEAL_SUBMITTED':
        return const Color(0xFF1565C0); // Blue
      case 'APPEAL_RESPONSE':
        return const Color(0xFF2E7D32); // Green
      case 'OFFENSE_RECORDED':
        return const Color(0xFFC62828); // Red
      case 'HEARING_SCHEDULE':
        return const Color(0xFF1565C0); // Indigo-blue
      case 'HEARING_REMINDER':
        return const Color(0xFF6D4C41); // Brown
      case 'SERVICE_ACTIVE':
        return const Color(0xFF2E7D32); // Green
      case 'SERVICE_LOGGED_OUT':
        return const Color(0xFFC62828); // Red
      default:
        return const Color(0xFF757575); // Gray
    }
  }

  IconData get badgeIcon {
    switch (alertType) {
      case 'GUARDIAN_ALERT':
        return Icons.mail_rounded;
      case 'UPCC_DECISION':
        return Icons.gavel_rounded;
      case 'UPCC_CASE_DECISION':
        return Icons.balance_rounded;
      case 'APPEAL_SUBMITTED':
        return Icons.hourglass_top_rounded;
      case 'APPEAL_RESPONSE':
        return Icons.check_circle_rounded;
      case 'OFFENSE_RECORDED':
        return Icons.warning_rounded;
      case 'HEARING_SCHEDULE':
        return Icons.event_note_rounded;
      case 'HEARING_REMINDER':
        return Icons.alarm_on_rounded;
      case 'SERVICE_ACTIVE':
        return Icons.timer_rounded;
      case 'SERVICE_LOGGED_OUT':
        return Icons.logout_rounded;
      default:
        return Icons.notifications_rounded;
    }
  }
}
