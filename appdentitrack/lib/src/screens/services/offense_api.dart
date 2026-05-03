import 'dart:convert';
import 'package:http/http.dart' as http;

import '../../config.dart';

class OffenseApi {
  Future<OffenseListResponse> getOffenses({required String studentId}) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.offenseListUrl),
          headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);

    final data = (decoded['data'] as Map).cast<String, dynamic>();

    final student = (data['student'] as Map).cast<String, dynamic>();
    final counts = (data['counts'] as Map).cast<String, dynamic>();
    final itemsRaw = (data['items'] as List).cast<dynamic>();

    return OffenseListResponse(
      studentId: (student['student_id'] ?? '').toString(),
      studentName: (student['student_name'] ?? '').toString(),
      program: (student['program'] ?? '').toString(),
      yearLevel: int.tryParse((student['year_level'] ?? 0).toString()) ?? 0,
      total: int.tryParse((counts['total_offense'] ?? 0).toString()) ?? 0,
      minor: int.tryParse((counts['minor_offense'] ?? 0).toString()) ?? 0,
      major: int.tryParse((counts['major_offense'] ?? 0).toString()) ?? 0,
      items: itemsRaw.map((e) {
        final m = (e as Map).cast<String, dynamic>();
        return OffenseItem(
          offenseId: int.tryParse((m['offense_id'] ?? 0).toString()) ?? 0,
          level: (m['level'] ?? '').toString(),
          status: (m['status'] ?? '').toString(),
          dateCommitted: (m['date_committed'] ?? '').toString(),
          title: (m['title'] ?? '').toString(),
          description: (m['description'] ?? '').toString(),
          acknowledgedAt: m['acknowledged_at']?.toString(),
          isDeletedByStudent: m['is_deleted_by_student'] == true,
          isBundle: m['is_bundle'] == true,
          appealStatus: (m['appeal_status'] ?? '').toString(),
          upccCaseId: m['upcc_case_id'] != null ? int.tryParse(m['upcc_case_id'].toString()) : null,
          explanationText: m['explanation_text']?.toString(),
          explanationImage: m['explanation_image']?.toString(),
          explanationPdf: m['explanation_pdf']?.toString(),
          explanationAt: m['explanation_at']?.toString(),
        );
      }).toList(),
    );
  }

  Future<void> submitExplanation({
    required String studentId,
    required int caseId,
    required String explanation,
    String? filePath,
    List<int>? fileBytes,
    String? fileName,
  }) async {
    final uri = Uri.parse('${AppConfig.baseUrl}/api/student/submit_explanation.php');
    final req = http.MultipartRequest('POST', uri);

    req.fields['student_id'] = studentId;
    req.fields['case_id'] = caseId.toString();
    req.fields['explanation_text'] = explanation;

    if (fileName != null) {
      final ext = fileName.split('.').last.toLowerCase();
      final fieldName = ext == 'pdf' ? 'explanation_pdf' : 'explanation_image';

      if (fileBytes != null) {
        req.files.add(http.MultipartFile.fromBytes(
          fieldName,
          fileBytes,
          filename: fileName,
        ));
      } else if (filePath != null) {
        req.files.add(await http.MultipartFile.fromPath(
          fieldName,
          filePath,
          filename: fileName,
        ));
      }
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Explanation submission failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }

  Future<void> submitAppeal({
    required String studentId,
    required int offenseId,
    required String reason,
    String? filePath,
    List<int>? fileBytes,
    String? fileName,
  }) async {
    final uri = Uri.parse(AppConfig.submitAppealUrl);
    final req = http.MultipartRequest('POST', uri);

    req.fields['student_id'] = studentId;
    req.fields['offense_id'] = offenseId.toString();
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Appeal request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }

  Future<void> acceptOffense({
    required String studentId,
    required int offenseId,
  }) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.acceptOffenseUrl),
          headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({
            'student_id': studentId,
            'offense_id': offenseId,
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }

  Future<void> hideOffense({
    required String studentId,
    required int offenseId,
  }) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.hideOffenseUrl),
          headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({
            'student_id': studentId,
            'offense_id': offenseId,
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Hide request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }

  Future<void> acceptUpccCase({
    required String studentId,
    required int caseId,
  }) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.acceptUpccCaseUrl),
          headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({
            'student_id': studentId,
            'case_id': caseId,
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Accept request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }

  Future<void> deleteOffense({
    required String studentId,
    required int offenseId,
  }) async {
    final res = await http
        .post(
          Uri.parse(AppConfig.deleteOffenseUrl),
          headers: const {'Content-Type': 'application/json', 'Accept': 'application/json'},
          body: jsonEncode({
            'student_id': studentId,
            'offense_id': offenseId,
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

    if (decoded is! Map) throw Exception('Invalid server response.');

    final ok = decoded['ok'] == true;
    final msg = (decoded['message'] ?? 'Delete request failed').toString();
    if (!ok || res.statusCode < 200 || res.statusCode >= 300) throw Exception(msg);
  }
}

class OffenseListResponse {
  final String studentId;
  final String studentName;
  final String program;
  final int yearLevel;

  final int total;
  final int minor;
  final int major;

  final List<OffenseItem> items;

  OffenseListResponse({
    required this.studentId,
    required this.studentName,
    required this.program,
    required this.yearLevel,
    required this.total,
    required this.minor,
    required this.major,
    required this.items,
  });
}

class OffenseItem {
  final int offenseId;
  final String level;
  final String status;
  final String dateCommitted;
  final String title;
  final String description;
  final String? acknowledgedAt;
  final bool isDeletedByStudent;
  final bool isBundle;
  final String appealStatus;
  final int? upccCaseId;
  final String? explanationText;
  final String? explanationImage;
  final String? explanationPdf;
  final String? explanationAt;

  OffenseItem({
    required this.offenseId,
    required this.level,
    required this.status,
    required this.dateCommitted,
    required this.title,
    required this.description,
    this.acknowledgedAt,
    this.isDeletedByStudent = false,
    this.isBundle = false,
    this.appealStatus = '',
    this.upccCaseId,
    this.explanationText,
    this.explanationImage,
    this.explanationPdf,
    this.explanationAt,
  });
}