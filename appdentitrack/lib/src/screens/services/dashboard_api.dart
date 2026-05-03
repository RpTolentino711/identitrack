import 'dart:convert';
import 'package:http/http.dart' as http;

import '../../config.dart';

class DashboardApi {
  Future<DashboardSummary> getSummary({required String studentId}) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.dashboardSummaryUrl),
          headers: const {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({'student_id': studentId}),
        )
        .timeout(const Duration(seconds: 30));

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
    final msg = (decoded['message'] ?? 'Failed to load dashboard.').toString();

    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }

    final dataRaw = decoded['data'];
    if (dataRaw is! Map) throw Exception('Missing data from server.');

    final data = dataRaw.cast<String, dynamic>();

    HearingNotice? hearingNotice;
    final hearingRaw = data['hearing_notice'];
    if (hearingRaw is Map) {
      final h = hearingRaw.cast<String, dynamic>();
      hearingNotice = HearingNotice(
        caseId: int.tryParse((h['case_id'] ?? 0).toString()) ?? 0,
        hearingDate: (h['hearing_date'] ?? '').toString(),
        hearingTime: (h['hearing_time'] ?? '').toString(),
        hearingType: (h['hearing_type'] ?? '').toString(),
        title: (h['title'] ?? '').toString(),
        message: (h['message'] ?? '').toString(),
        popup: h['popup'] == true,
        adminOpened: h['admin_opened'] == true,
        hasExplanation: h['has_explanation'] == true,
      );
    }

    LatestPunishment? latestPunishment;
    final punishmentRaw = data['latest_punishment'];
    if (punishmentRaw is Map) {
      latestPunishment = LatestPunishment.fromJson(
        (punishmentRaw as Map).cast<String, dynamic>(),
      );
    }

    final unseenAppealsRaw = data['unseen_appeals'] as List?;
    final unseenAppeals = (unseenAppealsRaw ?? []).map((e) {
      final m = (e as Map).cast<String, dynamic>();
      return UnseenAppeal(
        appealId: int.tryParse((m['appeal_id'] ?? 0).toString()) ?? 0,
        appealKind: (m['appeal_kind'] ?? '').toString(),
        status: (m['status'] ?? '').toString(),
        adminResponse: (m['admin_response'] ?? '').toString(),
        caseId: int.tryParse((m['case_id'] ?? 0).toString()) ?? 0,
        offenseId: int.tryParse((m['offense_id'] ?? 0).toString()) ?? 0,
        category: int.tryParse((m['decided_category'] ?? 0).toString()) ?? 0,
      );
    }).toList();

    return DashboardSummary(
      studentId: (data['student_id'] ?? '').toString(),
      studentName: (data['student_name'] ?? '').toString(),
      totalOffense: int.tryParse((data['total_offense'] ?? 0).toString()) ?? 0,
      minorOffense: int.tryParse((data['minor_offense'] ?? 0).toString()) ?? 0,
      majorOffense: int.tryParse((data['major_offense'] ?? 0).toString()) ?? 0,
      unseenOffensesCount: int.tryParse((data['unseen_offenses_count'] ?? 0).toString()) ?? 0,
      totalAlertsCount: int.tryParse((data['total_alerts_count'] ?? 0).toString()) ?? 0,
      communityServiceHours:
          double.tryParse((data['community_service_hours'] ?? 0).toString()) ??
          0,
      accountMode: (data['account_mode'] ?? 'FULL_ACCESS').toString(),
      accountMessage: (data['account_message'] ?? '').toString(),
      hearingNotice: hearingNotice,
      latestPunishment: latestPunishment,
      unseenAppeals: unseenAppeals,
      activeServiceSession: data['active_service_session'] == true,
      recentServiceLogout: data['recent_service_logout'] == true,
      activeServiceSessionId: (data['active_service_session_id'] ?? '').toString(),
      recentServiceLogoutId: (data['recent_service_logout_id'] ?? '').toString(),
    );
  }

  Future<void> submitUpccCaseAppeal({
    required String studentId,
    required int caseId,
    required String reason,
    String? filePath,
    List<int>? fileBytes,
    String? fileName,
  }) async {
    final uri = Uri.parse(AppConfig.submitAppealUrl);
    final req = http.MultipartRequest('POST', uri);

    req.fields['student_id'] = studentId;
    req.fields['case_id'] = caseId.toString();
    req.fields['reason'] = reason;

    if (fileBytes != null && fileName != null) {
      req.files.add(http.MultipartFile.fromBytes(
        'attachment',
        fileBytes,
        filename: fileName,
      ));
    } else if (filePath != null && fileName != null) {
      req.files.add(await http.MultipartFile.fromPath(
        'attachment',
        filePath,
        filename: fileName,
      ));
    }

    final streamRes = await req.send().timeout(const Duration(seconds: 30));
    final res = await http.Response.fromStream(streamRes);

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
    final msg = (decoded['message'] ?? 'Appeal request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }
  }

  Future<void> acknowledgeAppeal({
    required String studentId,
    required int appealId,
  }) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.acknowledgeAppealUrl),
          headers: const {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
          },
          body: jsonEncode({
            'student_id': studentId,
            'appeal_id': appealId,
          }),
        )
        .timeout(const Duration(seconds: 30));

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
    final msg = (decoded['message'] ?? 'Acknowledge failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) {
      throw Exception(msg);
    }
  }
}

class DashboardSummary {
  final String studentId;
  final String studentName;
  final int totalOffense;
  final int minorOffense;
  final int majorOffense;
  final int unseenOffensesCount;
  final int totalAlertsCount;
  final double communityServiceHours;
  final String accountMode;
  final String accountMessage;
  final HearingNotice? hearingNotice;
  final LatestPunishment? latestPunishment;
  final List<UnseenAppeal> unseenAppeals;
  final bool activeServiceSession;
  final bool recentServiceLogout;
  final String activeServiceSessionId;
  final String recentServiceLogoutId;

  DashboardSummary({
    required this.studentId,
    required this.studentName,
    required this.totalOffense,
    required this.minorOffense,
    required this.majorOffense,
    required this.unseenOffensesCount,
    required this.totalAlertsCount,
    required this.communityServiceHours,
    required this.accountMode,
    required this.accountMessage,
    required this.hearingNotice,
    required this.latestPunishment,
    required this.unseenAppeals,
    required this.activeServiceSession,
    required this.recentServiceLogout,
    required this.activeServiceSessionId,
    required this.recentServiceLogoutId,
  });
}

class LatestPunishment {
  final int caseId;
  final int category;
  final String decisionText;
  final Map<String, dynamic> details;
  final String resolvedAt;
  final bool canAppeal;
  final String appealStatus;

  LatestPunishment({
    required this.caseId,
    required this.category,
    required this.decisionText,
    required this.details,
    required this.resolvedAt,
    required this.canAppeal,
    required this.appealStatus,
  });

  factory LatestPunishment.fromJson(Map<String, dynamic> json) =>
      LatestPunishment(
        caseId: int.tryParse((json['case_id'] ?? 0).toString()) ?? 0,
        category: int.tryParse((json['category'] ?? 0).toString()) ?? 0,
        decisionText: (json['decision_text'] ?? '').toString(),
        details: json['details'] is Map
            ? (json['details'] as Map).cast<String, dynamic>()
            : <String, dynamic>{},
        resolvedAt: (json['resolved_at'] ?? '').toString(),
        canAppeal: json['can_appeal'] == true,
        appealStatus: (json['appeal_status'] ?? '').toString(),
      );
}

class HearingNotice {
  final int caseId;
  final String hearingDate;
  final String hearingTime;
  final String hearingType;
  final String title;
  final String message;
  final bool popup;
  final bool adminOpened;
  final bool hasExplanation;

  HearingNotice({
    required this.caseId,
    required this.hearingDate,
    required this.hearingTime,
    required this.hearingType,
    required this.title,
    required this.message,
    required this.popup,
    required this.adminOpened,
    required this.hasExplanation,
  });
}

class UnseenAppeal {
  final int appealId;
  final String appealKind;
  final String status;
  final String adminResponse;
  final int caseId;
  final int offenseId;
  final int category;

  UnseenAppeal({
    required this.appealId,
    required this.appealKind,
    required this.status,
    required this.adminResponse,
    required this.caseId,
    required this.offenseId,
    required this.category,
  });
}
