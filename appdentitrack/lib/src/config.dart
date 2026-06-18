import 'package:flutter/foundation.dart';

class AppConfig {
  static String get baseUrl {
    if (kIsWeb) return 'http://127.0.0.1/identitrack';

    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        // Local Device / Emulator testing
        return 'http://192.168.1.10/identitrack';
      default:
        return 'http://192.168.1.10/identitrack';
    }
  }

  static String get requestOtpUrl => '$baseUrl/api/student/request_otp.php';
  static String get verifyOtpUrl => '$baseUrl/api/student/verify_otp.php';
  static String get dashboardSummaryUrl => '$baseUrl/api/student/dashboard_summary.php';
  static String get offenseListUrl => '$baseUrl/api/student/offense_list.php';
  static String get communityServiceOverviewUrl => '$baseUrl/api/student/community_service_overview.php';
  static String get communityServiceLoginUrl => '$baseUrl/api/student/community_service_login.php';
  static String get submitAppealUrl => '$baseUrl/api/student/submit_appeal.php';
  static String get acceptOffenseUrl => '$baseUrl/api/student/accept_offense.php';
  static String get acceptUpccCaseUrl => '$baseUrl/api/student/accept_upcc_case.php';
  static String get hideOffenseUrl => '$baseUrl/api/student/hide_offense.php';
  static String get alertsUrl => '$baseUrl/api/student/alerts.php';
  static String get acknowledgeAppealUrl => '$baseUrl/api/student/acknowledge_appeal.php';
  static String get deleteOffenseUrl => '$baseUrl/api/student/delete_offense.php';
}