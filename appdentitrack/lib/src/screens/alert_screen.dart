import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import 'services/alerts_api.dart';
import 'services/offense_api.dart';
import 'offense_detail_screen.dart';
import 'shared_bottom_nav.dart';

const Color blue = Color(0xFF1A73E8); // adjust to your actual color
const Color blueDark = Color(0xFF0D47A1); // adjust to your actual color

class AlertsScreen extends StatefulWidget {
  final String studentId;
  final String studentName;

  const AlertsScreen({
    super.key,
    required this.studentId,
    required this.studentName,
  });

  @override
  State<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends State<AlertsScreen> {
  final _api = AlertsApi();
  List<StudentAlert> _alerts = [];
  Set<String> _dismissedAlerts = {};
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      final prefs = await SharedPreferences.getInstance();
      final dismissed = prefs.getStringList('dismissed_alerts') ?? [];
      _dismissedAlerts = dismissed.toSet();

      final alerts = await _api.getAlerts(widget.studentId);
      
      bool hasPending = false;
      for (final a in alerts) {
        if ((a.alertType == 'HEARING_SCHEDULE' || a.alertType == 'HEARING_REMINDER') &&
            a.metadata != null &&
            a.metadata!['student_hearing_response'] == 'PENDING') {
          hasPending = true;
          break;
        }
      }
      await prefs.setBool('has_pending_hearing', hasPending);

      setState(() {
        _alerts = alerts
            .where(
              (a) =>
                  !_dismissedAlerts.contains('${a.alertType}_${a.createdAt}'),
            )
            .toList();
        _loading = false;
      });
    } catch (e) {
      setState(() {
        _error = 'Failed to load alerts. Please try again.';
        _loading = false;
      });
    }
  }

  String _formatDate(dynamic date) {
    // Adjust to your actual date formatting logic
    return date.toString();
  }

  Widget _alertCard(StudentAlert alert) {
    final metadata = alert.metadata;
    final int? offenseId = metadata != null && metadata['offense_id'] != null
        ? int.tryParse(metadata['offense_id'].toString())
        : null;
    final int? caseId = metadata != null && metadata['case_id'] != null
        ? int.tryParse(metadata['case_id'].toString())
        : null;

    final cardContent = Padding(
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: alert.badgeColor.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  alert.badgeIcon,
                  color: alert.badgeColor,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Flexible(
                          child: Text(
                            alert.title,
                            style: const TextStyle(
                              fontWeight: FontWeight.w800,
                              fontSize: 14,
                              color: blueDark,
                            ),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                        if ((alert.alertType == 'HEARING_SCHEDULE' || alert.alertType == 'HEARING_REMINDER') &&
                            metadata != null &&
                            metadata['student_hearing_response'] == 'PENDING') ...[
                          const SizedBox(width: 6),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                            decoration: BoxDecoration(
                              color: Colors.red,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: const Text(
                              'ACTION REQUIRED',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 8,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                    Text(
                      _formatDate(alert.createdAt),
                      style: TextStyle(
                        fontSize: 11,
                        color: Colors.grey.shade500,
                      ),
                    ),
                  ],
                ),
              ),
              if (alert.alertType != 'HEARING_SCHEDULE' && alert.alertType != 'HEARING_REMINDER')
                InkWell(
                  onTap: () async {
                    final id = '${alert.alertType}_${alert.createdAt}';
                    final prefs = await SharedPreferences.getInstance();
                    final current =
                        prefs.getStringList('dismissed_alerts') ?? [];
                    if (!current.contains(id)) {
                      current.add(id);
                      await prefs.setStringList('dismissed_alerts', current);
                    }
                    setState(() {
                      _dismissedAlerts.add(id);
                      _alerts.remove(alert);
                    });
                  },
                  borderRadius: BorderRadius.circular(20),
                  child: Padding(
                    padding: const EdgeInsets.all(8.0),
                    child: Icon(
                      Icons.close_rounded,
                      size: 20,
                      color: Colors.grey.shade400,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            alert.message,
            style: TextStyle(
              fontSize: 13,
              height: 1.4,
              color: Colors.grey.shade800,
            ),
            maxLines: 3,
            overflow: TextOverflow.ellipsis,
          ),
          if (alert.alertType == 'HEARING_SCHEDULE' || alert.alertType == 'HEARING_REMINDER')
            _buildHearingActions(alert),
          if (offenseId != null) ...[
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Text(
                  'Tap to view offense details',
                  style: TextStyle(
                    fontSize: 11,
                    color: alert.badgeColor,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 4),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 14,
                  color: alert.badgeColor,
                ),
              ],
            ),
          ] else if (caseId != null) ...[
            const SizedBox(height: 10),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                Text(
                  'Tap to view details and submit explanation',
                  style: TextStyle(
                    fontSize: 11,
                    color: alert.badgeColor,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(width: 4),
                Icon(
                  Icons.chevron_right_rounded,
                  size: 14,
                  color: alert.badgeColor,
                ),
              ],
            ),
          ],
        ],
      ),
    );

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border(
          left: BorderSide(color: alert.badgeColor, width: 4),
          top: BorderSide(color: Colors.grey.shade200),
          right: BorderSide(color: Colors.grey.shade200),
          bottom: BorderSide(color: Colors.grey.shade200),
        ),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: (offenseId != null || caseId != null)
          ? Material(
              color: Colors.transparent,
              child: InkWell(
                onTap: () async {
                  showDialog(
                    context: context,
                    barrierDismissible: false,
                    builder: (context) => const Center(
                      child: CircularProgressIndicator(),
                    ),
                  );
                  try {
                    final res = await OffenseApi().getOffenses(studentId: widget.studentId);
                    if (!mounted) return;
                    Navigator.of(context).pop(); // dismiss loading dialog
                    
                    OffenseItem? targetOffense;
                    if (offenseId != null) {
                      targetOffense = res.items.firstWhere(
                        (o) => o.offenseId == offenseId,
                      );
                    } else if (caseId != null) {
                      for (final item in res.items) {
                        if (item.upccCaseId == caseId) {
                          targetOffense = item;
                          break;
                        }
                      }
                      if (targetOffense == null) {
                        for (final item in res.items) {
                          if (item.level.toUpperCase() == 'MAJOR' || item.isBundle) {
                            targetOffense = item;
                            break;
                          }
                        }
                      }
                    }

                    if (targetOffense != null) {
                      await Navigator.of(context).push(
                        MaterialPageRoute(
                          builder: (_) => OffenseDetailScreen(
                            offense: targetOffense!,
                            studentId: widget.studentId,
                            studentName: widget.studentName,
                            program: res.program,
                            yearLevel: res.yearLevel,
                          ),
                        ),
                      );
                      _load();
                    } else {
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Details not found.')),
                      );
                    }
                  } catch (e) {
                    if (mounted) {
                      Navigator.of(context).pop(); // dismiss loading dialog
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Details not found or deleted.')),
                      );
                    }
                  }
                },
                child: cardContent,
              ),
            )
          : cardContent,
    );
  }

  Future<void> _submitHearingResponse(int caseId, String response) async {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (context) => const Center(
        child: CircularProgressIndicator(),
      ),
    );

    try {
      await _api.respondToHearing(widget.studentId, caseId, response);
      if (!mounted) return;
      Navigator.of(context).pop(); // pop loading dialog
      
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(response == 'ACCEPTED' 
              ? 'Hearing schedule accepted successfully.' 
              : 'Hearing schedule declined.'),
          backgroundColor: response == 'ACCEPTED' ? Colors.green.shade800 : Colors.red.shade800,
        ),
      );
      _load(); // reload the list
    } catch (e) {
      if (!mounted) return;
      Navigator.of(context).pop(); // pop loading dialog
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e.toString().replaceAll('Exception: ', ''))),
      );
    }
  }

  Widget _buildHearingActions(StudentAlert alert) {
    final meta = alert.metadata;
    if (meta == null) return const SizedBox.shrink();

    final int? caseId = meta['case_id'] != null
        ? int.tryParse(meta['case_id'].toString())
        : null;
    if (caseId == null) return const SizedBox.shrink();

    final isOnline = meta['hearing_type'] == 'ONLINE';
    final locationOrLink = meta['hearing_link_or_location']?.toString() ?? '';
    final studentResponse = meta['student_hearing_response']?.toString() ?? 'PENDING';

    if (studentResponse == 'ACCEPTED') {
      return Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.check_circle_rounded, color: Colors.green.shade700, size: 16),
                const SizedBox(width: 6),
                Text(
                  'You accepted this hearing schedule.',
                  style: TextStyle(color: Colors.green.shade800, fontWeight: FontWeight.bold, fontSize: 13),
                ),
              ],
            ),
            const SizedBox(height: 10),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: () async {
                  if (isOnline && locationOrLink.isNotEmpty) {
                    final uri = Uri.tryParse(locationOrLink);
                    if (uri != null && await canLaunchUrl(uri)) {
                      await launchUrl(uri, mode: LaunchMode.externalApplication);
                    } else {
                      if (mounted) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Invalid or empty meeting link.')),
                        );
                      }
                    }
                  } else {
                    if (mounted) {
                      showDialog(
                        context: context,
                        builder: (c) => AlertDialog(
                          title: const Text('Hearing Location'),
                          content: Text(locationOrLink.isNotEmpty ? locationOrLink : 'Wait for instructions from Admin.'),
                          actions: [
                            TextButton(
                              onPressed: () => Navigator.pop(c),
                              child: const Text('OK'),
                            ),
                          ],
                        ),
                      );
                    }
                  }
                },
                icon: Icon(isOnline ? Icons.video_camera_front_rounded : Icons.location_on_rounded, size: 18),
                label: Text(isOnline ? 'Join Online' : 'View Location'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: isOnline ? blueDark : Colors.amber.shade800,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                ),
              ),
            ),
          ],
        ),
      );
    }

    if (studentResponse == 'DECLINED') {
      return Padding(
        padding: const EdgeInsets.only(top: 12),
        child: Row(
          children: [
            Icon(Icons.cancel_rounded, color: Colors.red.shade700, size: 16),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                'You declined this hearing. Awaiting final punishment.',
                style: TextStyle(color: Colors.red.shade800, fontWeight: FontWeight.bold, fontSize: 13),
              ),
            ),
          ],
        ),
      );
    }

    // PENDING
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Row(
        children: [
          Expanded(
            child: ElevatedButton.icon(
              onPressed: () {
                showDialog(
                  context: context,
                  builder: (c) => AlertDialog(
                    title: const Text('Accept Hearing'),
                    content: const Text('Are you sure you want to accept this hearing schedule? This confirms your attendance.'),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(c),
                        child: const Text('Cancel'),
                      ),
                      TextButton(
                        onPressed: () {
                          Navigator.pop(c);
                          _submitHearingResponse(caseId, 'ACCEPTED');
                        },
                        child: const Text('Accept', style: TextStyle(fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                );
              },
              icon: const Icon(Icons.check_circle_outline_rounded, size: 18),
              label: const Text('Accept'),
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.green.shade700,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: OutlinedButton.icon(
              onPressed: () {
                showDialog(
                  context: context,
                  builder: (c) => AlertDialog(
                    title: const Text('Decline Hearing'),
                    content: const Text(
                      'Warning: By declining this hearing, you will lose the opportunity to present your side. '
                      'Your case will be decided, and you will just wait for the final disciplinary action/punishment.\n\n'
                      'Are you sure you want to decline?'
                    ),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(c),
                        child: const Text('Cancel'),
                      ),
                      TextButton(
                        onPressed: () {
                          Navigator.pop(c);
                          _submitHearingResponse(caseId, 'DECLINED');
                        },
                        child: Text('Decline', style: TextStyle(color: Colors.red.shade700, fontWeight: FontWeight.bold)),
                      ),
                    ],
                  ),
                );
              },
              icon: const Icon(Icons.cancel_rounded, size: 18),
              label: const Text('Decline'),
              style: OutlinedButton.styleFrom(
                foregroundColor: Colors.red.shade700,
                side: BorderSide(color: Colors.red.shade200),
                padding: const EdgeInsets.symmetric(vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: blue,
      appBar: AppBar(
        automaticallyImplyLeading: false,
        backgroundColor: blue,
        foregroundColor: Colors.white,
        elevation: 0,
        title: const Text(
          'Alerts',
          style: TextStyle(fontWeight: FontWeight.w900),
        ),
      ),
      body: SafeArea(
        child: RefreshIndicator(
          onRefresh: _load,
          color: blue,
          backgroundColor: Colors.white,
          child: Container(
            width: double.infinity,
            decoration: const BoxDecoration(
              color: Color(0xFFF5F6FB),
              borderRadius: BorderRadius.only(
                topLeft: Radius.circular(28),
                topRight: Radius.circular(28),
              ),
            ),
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(18),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(_error!, textAlign: TextAlign.center),
                          const SizedBox(height: 10),
                          ElevatedButton(
                            onPressed: _load,
                            child: const Text('Retry'),
                          ),
                        ],
                      ),
                    ),
                  )
                : _alerts.isEmpty
                ? Center(
                    child: Padding(
                      padding: const EdgeInsets.all(28),
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(
                            Icons.notifications_none_rounded,
                            size: 64,
                            color: Colors.grey.shade300,
                          ),
                          const SizedBox(height: 16),
                          const Text(
                            'No alerts yet',
                            style: TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w700,
                              color: blueDark,
                            ),
                          ),
                          const SizedBox(height: 8),
                          Text(
                            'You\'re all caught up!',
                            style: TextStyle(color: Colors.grey.shade600),
                          ),
                        ],
                      ),
                    ),
                  )
                : ListView(
                    padding: const EdgeInsets.fromLTRB(18, 18, 18, 18),
                    children: [
                      Text(
                        'Notifications',
                        style: TextStyle(
                          color: Colors.grey.shade700,
                          fontWeight: FontWeight.w900,
                          fontSize: 16,
                        ),
                      ),
                      const SizedBox(height: 12),
                      ..._alerts.map((alert) => _alertCard(alert)),
                    ],
                  ),
          ),
        ),
      ),
      bottomNavigationBar: SharedBottomNav(
        currentIndex: 3,
        studentId: widget.studentId,
        studentName: widget.studentName,
      ),
    );
  }
}
