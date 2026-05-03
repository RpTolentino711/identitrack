import 'dart:convert';
import 'package:http/http.dart' as http;
import '../../config.dart';

class CommunityServiceApi {
  Future<CommunityServiceOverview> getOverview(String studentId) async {
    final res = await http.post(
      Uri.parse(AppConfig.communityServiceOverviewUrl),
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({'student_id': studentId}),
    );
    final decoded = jsonDecode(res.body);
    if (!(decoded['ok'] ?? false)) throw Exception(decoded['message']);
    return CommunityServiceOverview.fromJson(decoded['data']);
  }

  Future<LoginRequestResult> requestManualLogin({
    required String studentId,
    required int requirementId,
    String reason = '',
  }) async {
    final res = await http.post(
      Uri.parse(AppConfig.communityServiceLoginUrl),
      headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
      body: jsonEncode({
        'student_id': studentId,
        'requirement_id': requirementId,
        'login_method': 'MANUAL',
        'reason': reason,
      }),
    );

    final body = res.body.trim();
    if (body.isEmpty) throw Exception('Server returned empty response.');

    final decoded = jsonDecode(body);
    if (!(decoded['ok'] ?? false)) throw Exception(decoded['message']);

    final data = ((decoded['data'] ?? {}) as Map).cast<String, dynamic>();
    return LoginRequestResult(
      startsTimer: data['starts_timer'] == true,
      awaitingAdmin: data['awaiting_admin'] == true,
      message: (decoded['message'] ?? '').toString(),
    );
  }
}

class CommunityServiceOverview {
  final List<ServiceRequirement> requirements;
  final List<ServiceSession> sessions;
  final double hoursAssigned;
  final double hoursCompleted;
  final bool hasAssignment;
  final bool isUnderInvestigation;
  final String investigationMessage;
  final bool hasActiveAdmin;
  final ActiveServiceSession? activeSession;
  final PendingManualRequest? pendingManualRequest;
  CommunityServiceOverview({
    required this.requirements,
    required this.sessions,
    required this.hoursAssigned,
    required this.hoursCompleted,
    required this.hasAssignment,
    required this.isUnderInvestigation,
    required this.investigationMessage,
    required this.hasActiveAdmin,
    required this.activeSession,
    required this.pendingManualRequest,
  });
  factory CommunityServiceOverview.fromJson(Map<String, dynamic> json) => CommunityServiceOverview(
    requirements: (json['requirements'] as List).map((e) => ServiceRequirement.fromJson(e)).toList(),
    sessions: (json['sessions'] as List).map((e) => ServiceSession.fromJson(e)).toList(),
    hoursAssigned: double.tryParse(json['hours_assigned'].toString()) ?? 0.0,
    hoursCompleted: double.tryParse(json['hours_completed'].toString()) ?? 0.0,
    hasAssignment: json['has_assignment'] == true,
    isUnderInvestigation: json['is_under_investigation'] == true,
    investigationMessage: (json['investigation_message'] ?? '').toString(),
    hasActiveAdmin: json['has_active_admin'] == true,
    activeSession: json['active_session'] is Map
        ? ActiveServiceSession.fromJson((json['active_session'] as Map).cast<String, dynamic>())
        : null,
    pendingManualRequest: json['pending_manual_request'] is Map
        ? PendingManualRequest.fromJson((json['pending_manual_request'] as Map).cast<String, dynamic>())
        : null,
  );
}

class ActiveServiceSession {
  final int sessionId;
  final int requirementId;
  final String timeIn;
  final String loginMethod;
  final String taskName;
  final String location;

  ActiveServiceSession({
    required this.sessionId,
    required this.requirementId,
    required this.timeIn,
    required this.loginMethod,
    required this.taskName,
    required this.location,
  });

  factory ActiveServiceSession.fromJson(Map<String, dynamic> json) => ActiveServiceSession(
    sessionId: int.tryParse((json['session_id'] ?? 0).toString()) ?? 0,
    requirementId: int.tryParse((json['requirement_id'] ?? 0).toString()) ?? 0,
    timeIn: (json['time_in'] ?? '').toString(),
    loginMethod: (json['login_method'] ?? '').toString(),
    taskName: (json['task_name'] ?? '').toString(),
    location: (json['location'] ?? '').toString(),
  );
}

class PendingManualRequest {
  final int requestId;
  final int requirementId;
  final String requestedAt;
  final String status;
  final String reason;

  PendingManualRequest({
    required this.requestId,
    required this.requirementId,
    required this.requestedAt,
    required this.status,
    required this.reason,
  });

  factory PendingManualRequest.fromJson(Map<String, dynamic> json) => PendingManualRequest(
    requestId: int.tryParse((json['request_id'] ?? 0).toString()) ?? 0,
    requirementId: int.tryParse((json['requirement_id'] ?? 0).toString()) ?? 0,
    requestedAt: (json['requested_at'] ?? '').toString(),
    status: (json['status'] ?? '').toString(),
    reason: (json['reason'] ?? '').toString(),
  );
}

class LoginRequestResult {
  final bool startsTimer;
  final bool awaitingAdmin;
  final String message;

  LoginRequestResult({
    required this.startsTimer,
    required this.awaitingAdmin,
    required this.message,
  });
}

class ServiceRequirement {
  final int requirementId;
  final String taskName, location, status;
  final double hoursRequired;
  final String assignedAt, completedAt;
  ServiceRequirement({
    required this.requirementId,
    required this.taskName,
    required this.location,
    required this.hoursRequired,
    required this.status,
    required this.assignedAt,
    required this.completedAt,
  });
  factory ServiceRequirement.fromJson(Map<String, dynamic> json) => ServiceRequirement(
    requirementId: json['requirement_id'],
    taskName: json['task_name'],
    location: json['location'] ?? '',
    hoursRequired: double.tryParse(json['hours_required'].toString()) ?? 0,
    status: json['status'],
    assignedAt: json['assigned_at'],
    completedAt: json['completed_at'] ?? '',
  );
}

class ServiceSession {
  final int sessionId;
  final int requirementId;
  final String timeIn;
  final String timeOut;
  final String loginMethod;
  final int validatedBy;
  final String sdoNotes;
  final double hoursDone;
  ServiceSession({
    required this.sessionId,
    required this.requirementId,
    required this.timeIn,
    required this.timeOut,
    required this.loginMethod,
    required this.validatedBy,
    required this.sdoNotes,
    required this.hoursDone,
  });
  factory ServiceSession.fromJson(Map<String, dynamic> json) => ServiceSession(
    sessionId: json['session_id'],
    requirementId: json['requirement_id'],
    timeIn: json['time_in'],
    timeOut: json['time_out'] ?? '',
    loginMethod: json['login_method'],
    validatedBy: int.tryParse(json['validated_by'].toString()) ?? 0,
    sdoNotes: json['sdo_notes'] ?? '',
    hoursDone: double.tryParse(json['hours_done'].toString()) ?? 0.0,
  );
}